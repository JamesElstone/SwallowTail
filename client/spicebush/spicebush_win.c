#define WIN32_LEAN_AND_MEAN
#define WINVER 0x0501
#define _WIN32_WINNT 0x0501

#include <windows.h>
#include <shellapi.h>
#include <shlobj.h>
#include <dbt.h>
#include <wininet.h>
#include <stdarg.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#define APP_NAME "SpiceBush"
#define WM_TRAYICON (WM_APP + 10)
#define WM_REFRESH_STATS (WM_APP + 11)
#define WM_REGISTER_DONE (WM_APP + 12)
#define WM_PING_DONE (WM_APP + 13)
#define WM_REGISTER_BALLOON (WM_APP + 14)

#define IDR_SPICEBUSH_ICON 101

#define ID_TRAY_REGISTER 1001
#define ID_TRAY_STATS 1002
#define ID_TRAY_EXIT 1004

#define ID_REGISTER_URL 2001
#define ID_REGISTER_USER 2002
#define ID_REGISTER_PASSWORD 2003
#define ID_REGISTER_OTP 2004
#define ID_REGISTER_SAVE 2005
#define ID_REGISTER_STATUS 2006
#define ID_REGISTER_QUIT 2007

#define ID_STATS_SCAN 3001
#define ID_STATS_PING 3002
#define ID_STATS_CLEAR_HISTORY 3003
#define MAX_TEXT 1024
#define QUEUE_INITIAL 128
#define STATS_LABEL_COUNT 17

typedef unsigned __int64 U64;

#ifndef NOTIFYICON_VERSION
#define NOTIFYICON_VERSION 3
#endif

typedef struct QueueItem {
    DWORD id;
    char path[MAX_PATH];
} QueueItem;

typedef struct ScanStats {
    DWORD folders;
    DWORD files;
    DWORD cr2;
    DWORD queued;
    DWORD duplicateQueue;
    DWORD errors;
} ScanStats;

typedef struct AppState {
    HINSTANCE instance;
    HWND mainWindow;
    HWND registerWindow;
    HWND statsWindow;
    HWND balloonWindow;
    HWND registerStatus;
    HWND statsLabels[STATS_LABEL_COUNT];
    HWND statsPingButton;
    HICON registerLogoIcon;
    HFONT uiFont;
    HFONT boldUiFont;
    HANDLE instanceMutex;
    CRITICAL_SECTION lock;
    HANDLE queueEvent;
    HANDLE stopEvent;
    QueueItem *queue;
    DWORD queueCount;
    DWORD queueCapacity;
    LONG totalFound;
    LONG totalUploaded;
    LONG totalKnown;
    LONG totalFailed;
    LONG totalSkippedLocal;
    LONG totalRejectedOversize;
    LONG totalScannedDrives;
    LONG activeScans;
    LONG processing;
    U64 totalUploadMillis;
    U64 serverMaxRawUploadBytes;
    DWORD nextQueueId;
    DWORD queueDoneSinceCompact;
    int statsPingState;
    int serverMaxRawUploadState;
    DWORD statsPingStateExpiresAt;
    int registerQuitMode;
    char appDir[MAX_PATH];
    char iniPath[MAX_PATH];
    char queuePath[MAX_PATH];
    char queueDonePath[MAX_PATH];
    char queueNextIdPath[MAX_PATH];
    char uploadedPath[MAX_PATH];
    char uploadedDir[MAX_PATH];
    char logPath[MAX_PATH];
    char siteUrl[MAX_TEXT];
    char apiUrl[MAX_TEXT];
    char uploadToken[MAX_TEXT];
    char deviceId[128];
    char balloonTitle[128];
    char balloonMessage[256];
} AppState;

static AppState g_app;

static LRESULT CALLBACK MainWndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp);
static LRESULT CALLBACK RegisterWndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp);
static LRESULT CALLBACK RegisterEditWndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp);
static LRESULT CALLBACK StatsWndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp);
static LRESULT CALLBACK BalloonWndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp);
static void ShowRegisterWindow(int quitMode);
static DWORD WINAPI ProcessorThread(LPVOID param);
static DWORD WINAPI ScanDriveThread(LPVOID param);
static DWORD WINAPI RegisterThread(LPVOID param);
static DWORD WINAPI PingThread(LPVOID param);
static void LogMessage(const char *format, ...);
static void BuildTrayTooltip(char *tip, DWORD tipSize);
static void UpdateTrayTooltip(HWND hwnd);
static void MigrateUploadedCache(void);
static void CompactQueueIfNeeded(void);
static DWORD ClearUploadedHistoryCache(void);
static U64 ParseU64(const char *text);

static HICON AppIcon(void)
{
    if (!g_app.registerLogoIcon) {
        g_app.registerLogoIcon = (HICON)LoadImageA(g_app.instance, MAKEINTRESOURCEA(IDR_SPICEBUSH_ICON), IMAGE_ICON, 64, 64, LR_DEFAULTCOLOR);
    }
    return g_app.registerLogoIcon ? g_app.registerLogoIcon : LoadIcon(NULL, IDI_APPLICATION);
}

static HFONT AppFont(void)
{
    HDC hdc;
    int height;

    if (g_app.uiFont) return g_app.uiFont;

    hdc = GetDC(NULL);
    height = hdc ? -MulDiv(8, GetDeviceCaps(hdc, LOGPIXELSY), 72) : -11;
    if (hdc) ReleaseDC(NULL, hdc);

    g_app.uiFont = CreateFontA(
        height,
        0,
        0,
        0,
        FW_NORMAL,
        FALSE,
        FALSE,
        FALSE,
        ANSI_CHARSET,
        OUT_TT_PRECIS,
        CLIP_DEFAULT_PRECIS,
        DEFAULT_QUALITY,
        DEFAULT_PITCH | FF_SWISS,
        "Tahoma");

    return g_app.uiFont ? g_app.uiFont : (HFONT)GetStockObject(DEFAULT_GUI_FONT);
}

static HFONT AppBoldFont(void)
{
    HDC hdc;
    int height;

    if (g_app.boldUiFont) return g_app.boldUiFont;

    hdc = GetDC(NULL);
    height = hdc ? -MulDiv(8, GetDeviceCaps(hdc, LOGPIXELSY), 72) : -11;
    if (hdc) ReleaseDC(NULL, hdc);

    g_app.boldUiFont = CreateFontA(
        height,
        0,
        0,
        0,
        FW_BOLD,
        FALSE,
        FALSE,
        FALSE,
        ANSI_CHARSET,
        OUT_TT_PRECIS,
        CLIP_DEFAULT_PRECIS,
        DEFAULT_QUALITY,
        DEFAULT_PITCH | FF_SWISS,
        "Tahoma");

    return g_app.boldUiFont ? g_app.boldUiFont : AppFont();
}

static HWND SetAppFont(HWND hwnd)
{
    if (hwnd) SendMessageA(hwnd, WM_SETFONT, (WPARAM)AppFont(), TRUE);
    return hwnd;
}

static int SbSnprintf(char *buffer, size_t bufferSize, const char *format, ...)
{
    int written;
    va_list args;

    if (bufferSize == 0) return -1;

    va_start(args, format);
#if defined(_MSC_VER) && _MSC_VER >= 1400
    written = _vsnprintf_s(buffer, bufferSize, _TRUNCATE, format, args);
#elif defined(_MSC_VER)
    written = _vsnprintf(buffer, bufferSize - 1, format, args);
    buffer[bufferSize - 1] = '\0';
#else
    written = vsnprintf(buffer, bufferSize, format, args);
    if (written < 0 || (size_t)written >= bufferSize) {
        buffer[bufferSize - 1] = '\0';
    }
#endif
    va_end(args);

    return written;
}

static void SafeCopy(char *dst, DWORD dstSize, const char *src)
{
    if (dstSize == 0) return;
    if (src == NULL) src = "";
    lstrcpynA(dst, src, (int)dstSize);
    dst[dstSize - 1] = '\0';
}

static void PathJoin(char *dst, DWORD dstSize, const char *left, const char *right)
{
    DWORD len;
    SafeCopy(dst, dstSize, left);
    len = lstrlenA(dst);
    if (len > 0 && dst[len - 1] != '\\' && dst[len - 1] != '/') {
        lstrcatA(dst, "\\");
    }
    lstrcatA(dst, right);
}

static int EndsWithNoCase(const char *text, const char *suffix)
{
    int textLen = lstrlenA(text);
    int suffixLen = lstrlenA(suffix);
    if (suffixLen > textLen) return 0;
    return lstrcmpiA(text + textLen - suffixLen, suffix) == 0;
}

static void TrimTrailingSlashes(char *text)
{
    int len = lstrlenA(text);
    while (len > 0 && (text[len - 1] == '/' || text[len - 1] == '\\')) {
        text[len - 1] = '\0';
        len--;
    }
}

static void SetStatus(HWND hwnd, const char *text)
{
    HWND status = GetDlgItem(hwnd, ID_REGISTER_STATUS);
    SYSTEMTIME now;
    char stamped[640];
    if (!status) status = g_app.registerStatus;
    if (status) {
        GetLocalTime(&now);
        SbSnprintf(stamped, sizeof(stamped), "[%02u/%02u/%02u %02u:%02u:%02u] %s",
            (unsigned)now.wDay,
            (unsigned)now.wMonth,
            (unsigned)(now.wYear % 100),
            (unsigned)now.wHour,
            (unsigned)now.wMinute,
            (unsigned)now.wSecond,
            text ? text : "");
        SetWindowTextA(status, stamped);
    }
}

static int NormaliseDeviceId(char *deviceId, DWORD deviceIdSize)
{
    const char *prefix = "spicebush-";
    int prefixLen = lstrlenA(prefix);
    char normalised[128];
    int i;

    if (deviceIdSize == 0 || lstrlenA(deviceId) <= prefixLen) {
        return 0;
    }

    for (i = 0; i < prefixLen; i++) {
        char a = deviceId[i];
        char b = prefix[i];
        if (a >= 'A' && a <= 'Z') a = (char)(a - 'A' + 'a');
        if (a != b) return 0;
    }

    SafeCopy(normalised, sizeof(normalised), deviceId + prefixLen);
    SafeCopy(deviceId, deviceIdSize, normalised);
    return 1;
}

static int IsDigitChar(char ch)
{
    return ch >= '0' && ch <= '9';
}

static int IsRotatedLogName(const char *name)
{
    return lstrlenA(name) == 24
        && strncmp(name, "spicebush-", 10) == 0
        && IsDigitChar(name[10])
        && IsDigitChar(name[11])
        && IsDigitChar(name[12])
        && IsDigitChar(name[13])
        && name[14] == '-'
        && IsDigitChar(name[15])
        && IsDigitChar(name[16])
        && name[17] == '-'
        && IsDigitChar(name[18])
        && IsDigitChar(name[19])
        && lstrcmpiA(name + 20, ".log") == 0;
}

static void BuildDailyLogPath(void)
{
    SYSTEMTIME now;
    char fileName[32];

    GetLocalTime(&now);
    SbSnprintf(fileName, sizeof(fileName), "spicebush-%04u-%02u-%02u.log",
        (unsigned)now.wYear,
        (unsigned)now.wMonth,
        (unsigned)now.wDay);
    PathJoin(g_app.logPath, sizeof(g_app.logPath), g_app.appDir, fileName);
}

static void CleanupOldLogs(void)
{
    char pattern[MAX_PATH];
    char path[MAX_PATH];
    WIN32_FIND_DATAA data;
    HANDLE find;
    FILETIME nowFt;
    ULARGE_INTEGER nowValue;
    ULARGE_INTEGER cutoffValue;
    ULARGE_INTEGER fileValue;
    const ULONGLONG dayTicks = 24ULL * 60ULL * 60ULL * 10000000ULL;

    GetSystemTimeAsFileTime(&nowFt);
    nowValue.LowPart = nowFt.dwLowDateTime;
    nowValue.HighPart = nowFt.dwHighDateTime;
    if (nowValue.QuadPart < (31ULL * dayTicks)) return;
    cutoffValue.QuadPart = nowValue.QuadPart - (31ULL * dayTicks);

    PathJoin(pattern, sizeof(pattern), g_app.appDir, "spicebush-*.log");
    find = FindFirstFileA(pattern, &data);
    if (find == INVALID_HANDLE_VALUE) return;

    do {
        if ((data.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) || !IsRotatedLogName(data.cFileName)) continue;
        fileValue.LowPart = data.ftLastWriteTime.dwLowDateTime;
        fileValue.HighPart = data.ftLastWriteTime.dwHighDateTime;
        if (fileValue.QuadPart < cutoffValue.QuadPart) {
            PathJoin(path, sizeof(path), g_app.appDir, data.cFileName);
            if (DeleteFileA(path)) {
                LogMessage("Deleted old log file: path=%s", path);
            } else {
                LogMessage("Could not delete old log file: path=%s error=%lu", path, GetLastError());
            }
        }
    } while (FindNextFileA(find, &data));

    FindClose(find);
}

static void EnsureAppPaths(void)
{
    char appData[MAX_PATH];
    char computer[128];
    DWORD computerLen = sizeof(computer);

    appData[0] = '\0';
    SHGetFolderPathA(NULL, CSIDL_APPDATA, NULL, SHGFP_TYPE_CURRENT, appData);
    PathJoin(g_app.appDir, sizeof(g_app.appDir), appData, APP_NAME);
    CreateDirectoryA(g_app.appDir, NULL);
    PathJoin(g_app.iniPath, sizeof(g_app.iniPath), g_app.appDir, "spicebush.ini");
    PathJoin(g_app.queuePath, sizeof(g_app.queuePath), g_app.appDir, "queue.tsv");
    PathJoin(g_app.queueDonePath, sizeof(g_app.queueDonePath), g_app.appDir, "queue-done.tsv");
    PathJoin(g_app.queueNextIdPath, sizeof(g_app.queueNextIdPath), g_app.appDir, "queue-next-id.txt");
    PathJoin(g_app.uploadedPath, sizeof(g_app.uploadedPath), g_app.appDir, "uploaded.tsv");
    PathJoin(g_app.uploadedDir, sizeof(g_app.uploadedDir), g_app.appDir, "uploaded");
    BuildDailyLogPath();

    computer[0] = '\0';
    GetComputerNameA(computer, &computerLen);
    if (computer[0] == '\0') SafeCopy(computer, sizeof(computer), "windows-client");
    SafeCopy(g_app.deviceId, sizeof(g_app.deviceId), computer);
}

static void EnsureAppStorage(void)
{
    EnsureAppPaths();
    CleanupOldLogs();
    CreateDirectoryA(g_app.uploadedDir, NULL);
    MigrateUploadedCache();
    if (GetFileAttributesA(g_app.iniPath) == INVALID_FILE_ATTRIBUTES) {
        WritePrivateProfileStringA("spicebush", "site_url", "", g_app.iniPath);
        WritePrivateProfileStringA("spicebush", "api_url", "", g_app.iniPath);
        WritePrivateProfileStringA("spicebush", "upload_token", "", g_app.iniPath);
        WritePrivateProfileStringA("spicebush", "device_id", g_app.deviceId, g_app.iniPath);
        WritePrivateProfileStringA("spicebush", "server_max_raw_upload_bytes", "0", g_app.iniPath);
    }
}

static void LoadConfig(void)
{
    char serverLimit[64];
    GetPrivateProfileStringA("spicebush", "site_url", "", g_app.siteUrl, sizeof(g_app.siteUrl), g_app.iniPath);
    GetPrivateProfileStringA("spicebush", "api_url", "", g_app.apiUrl, sizeof(g_app.apiUrl), g_app.iniPath);
    GetPrivateProfileStringA("spicebush", "upload_token", "", g_app.uploadToken, sizeof(g_app.uploadToken), g_app.iniPath);
    GetPrivateProfileStringA("spicebush", "device_id", g_app.deviceId, g_app.deviceId, sizeof(g_app.deviceId), g_app.iniPath);
    GetPrivateProfileStringA("spicebush", "server_max_raw_upload_bytes", "0", serverLimit, sizeof(serverLimit), g_app.iniPath);
    g_app.serverMaxRawUploadBytes = ParseU64(serverLimit);
    g_app.serverMaxRawUploadState = g_app.serverMaxRawUploadBytes > 0 ? 1 : 0;
    if (NormaliseDeviceId(g_app.deviceId, sizeof(g_app.deviceId))) {
        WritePrivateProfileStringA("spicebush", "device_id", g_app.deviceId, g_app.iniPath);
        LogMessage("Normalised legacy device_id prefix; device_id=%s", g_app.deviceId);
    }
    LogMessage("Loaded config: site_url=%s api_url=%s token_present=%s token_length=%u device_id=%s server_max_raw_upload_bytes=%I64u",
        g_app.siteUrl,
        g_app.apiUrl,
        g_app.uploadToken[0] != '\0' ? "yes" : "no",
        (unsigned)lstrlenA(g_app.uploadToken),
        g_app.deviceId,
        g_app.serverMaxRawUploadBytes);
}

static void SaveConfig(void)
{
    char serverLimit[64];
    SbSnprintf(serverLimit, sizeof(serverLimit), "%I64u", g_app.serverMaxRawUploadBytes);
    WritePrivateProfileStringA("spicebush", "site_url", g_app.siteUrl, g_app.iniPath);
    WritePrivateProfileStringA("spicebush", "api_url", g_app.apiUrl, g_app.iniPath);
    WritePrivateProfileStringA("spicebush", "upload_token", g_app.uploadToken, g_app.iniPath);
    WritePrivateProfileStringA("spicebush", "device_id", g_app.deviceId, g_app.iniPath);
    WritePrivateProfileStringA("spicebush", "server_max_raw_upload_bytes", serverLimit, g_app.iniPath);
    LogMessage("Saved config: site_url=%s api_url=%s token_present=%s token_length=%u device_id=%s server_max_raw_upload_bytes=%I64u",
        g_app.siteUrl,
        g_app.apiUrl,
        g_app.uploadToken[0] != '\0' ? "yes" : "no",
        (unsigned)lstrlenA(g_app.uploadToken),
        g_app.deviceId,
        g_app.serverMaxRawUploadBytes);
}

static void AppendLine(const char *path, const char *line)
{
    HANDLE file = CreateFileA(path, FILE_APPEND_DATA, FILE_SHARE_READ, NULL, OPEN_ALWAYS, FILE_ATTRIBUTE_NORMAL, NULL);
    DWORD written;
    if (file == INVALID_HANDLE_VALUE) return;
    WriteFile(file, line, lstrlenA(line), &written, NULL);
    CloseHandle(file);
}

static void LogMessage(const char *format, ...)
{
    char text[1400], line[1700], stamp[64];
    SYSTEMTIME now;
    va_list args;

    if (g_app.logPath[0] == '\0') return;

    va_start(args, format);
#if defined(_MSC_VER) && _MSC_VER >= 1400
    _vsnprintf_s(text, sizeof(text), _TRUNCATE, format, args);
#elif defined(_MSC_VER)
    _vsnprintf(text, sizeof(text) - 1, format, args);
    text[sizeof(text) - 1] = '\0';
#else
    vsnprintf(text, sizeof(text), format, args);
    text[sizeof(text) - 1] = '\0';
#endif
    va_end(args);

    GetLocalTime(&now);
    SbSnprintf(stamp, sizeof(stamp) - 1, "%04u-%02u-%02u %02u:%02u:%02u",
        (unsigned)now.wYear,
        (unsigned)now.wMonth,
        (unsigned)now.wDay,
        (unsigned)now.wHour,
        (unsigned)now.wMinute,
        (unsigned)now.wSecond);
    stamp[sizeof(stamp) - 1] = '\0';
    SbSnprintf(line, sizeof(line) - 1, "%s %s\r\n", stamp, text);
    line[sizeof(line) - 1] = '\0';
    AppendLine(g_app.logPath, line);
}

static int QueueContainsLocked(const char *path)
{
    DWORD i;
    for (i = 0; i < g_app.queueCount; i++) {
        if (lstrcmpiA(g_app.queue[i].path, path) == 0) return 1;
    }
    return 0;
}

static int IsHexChar(char ch)
{
    return (ch >= '0' && ch <= '9')
        || (ch >= 'a' && ch <= 'f')
        || (ch >= 'A' && ch <= 'F');
}

static char LowerHexChar(char ch)
{
    if (ch >= 'A' && ch <= 'F') return (char)(ch - 'A' + 'a');
    return ch;
}

static U64 ParseU64(const char *text)
{
#if defined(_MSC_VER)
    return _strtoui64(text, NULL, 10);
#else
    return strtoull(text, NULL, 10);
#endif
}

static int UploadedBucketPath(const char *hash, char *path, DWORD pathSize)
{
    char name[8];
    if (!hash || !IsHexChar(hash[0]) || !IsHexChar(hash[1])) return 0;
    CreateDirectoryA(g_app.uploadedDir, NULL);
    name[0] = LowerHexChar(hash[0]);
    name[1] = LowerHexChar(hash[1]);
    SafeCopy(name + 2, sizeof(name) - 2, ".tsv");
    PathJoin(path, pathSize, g_app.uploadedDir, name);
    return 1;
}

static int ParseUploadedLine(char *line, char *hash, DWORD hashSize, U64 *sizeBytes, DWORD *photoId, char *status, DWORD statusSize, char *path, DWORD pathSize)
{
    char *size;
    char *id;
    char *state;
    char *source;
    char *end;
    if (hashSize > 0) hash[0] = '\0';
    if (statusSize > 0) status[0] = '\0';
    if (pathSize > 0) path[0] = '\0';
    if (sizeBytes) *sizeBytes = 0;
    if (photoId) *photoId = 0;
    size = strchr(line, '\t');
    if (!size) return 0;
    *size++ = '\0';
    id = strchr(size, '\t');
    if (!id) return 0;
    *id++ = '\0';
    state = strchr(id, '\t');
    if (!state) {
        SafeCopy(hash, hashSize, line);
        if (sizeBytes) *sizeBytes = ParseU64(size);
        SafeCopy(status, statusSize, "uploaded");
        SafeCopy(path, pathSize, id);
        return 1;
    }
    *state++ = '\0';
    source = strchr(state, '\t');
    if (!source) return 0;
    *source++ = '\0';
    end = strpbrk(source, "\r\n");
    if (end) *end = '\0';
    SafeCopy(hash, hashSize, line);
    if (sizeBytes) *sizeBytes = ParseU64(size);
    if (photoId) *photoId = strtoul(id, NULL, 10);
    SafeCopy(status, statusSize, state);
    SafeCopy(path, pathSize, source);
    return 1;
}

static int ReadWholeFileFirstLine(const char *path, char *line, DWORD lineSize)
{
    HANDLE file;
    DWORD got = 0;
    DWORD i;
    if (lineSize == 0) return 0;
    line[0] = '\0';
    file = CreateFileA(path, GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE, NULL, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL, NULL);
    if (file == INVALID_HANDLE_VALUE) return 0;
    if (ReadFile(file, line, lineSize - 1, &got, NULL)) {
        line[got] = '\0';
        for (i = 0; i < got; i++) {
            if (line[i] == '\r' || line[i] == '\n') {
                line[i] = '\0';
                break;
            }
        }
    }
    CloseHandle(file);
    return line[0] != '\0';
}

static void SaveNextQueueId(void)
{
    char line[64];
    HANDLE file;
    DWORD written;
    SbSnprintf(line, sizeof(line), "%lu\r\n", (unsigned long)g_app.nextQueueId);
    file = CreateFileA(g_app.queueNextIdPath, GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, FILE_ATTRIBUTE_NORMAL, NULL);
    if (file == INVALID_HANDLE_VALUE) {
        LogMessage("Could not save next queue id: path=%s error=%lu", g_app.queueNextIdPath, GetLastError());
        return;
    }
    WriteFile(file, line, lstrlenA(line), &written, NULL);
    CloseHandle(file);
}

static void LoadNextQueueId(void)
{
    char line[64];
    DWORD value;
    if (ReadWholeFileFirstLine(g_app.queueNextIdPath, line, sizeof(line))) {
        value = strtoul(line, NULL, 10);
        if (value > 0) {
            g_app.nextQueueId = value;
            return;
        }
    }
    g_app.nextQueueId = 1;
}

static DWORD AllocateQueueId(void)
{
    DWORD id;
    EnterCriticalSection(&g_app.lock);
    id = g_app.nextQueueId++;
    if (g_app.nextQueueId == 0) g_app.nextQueueId = 1;
    LeaveCriticalSection(&g_app.lock);
    SaveNextQueueId();
    return id;
}

static void AppendQueueRecord(DWORD id, const char *path)
{
    char line[MAX_PATH + 64];
    SbSnprintf(line, sizeof(line), "%lu\t%s\r\n", (unsigned long)id, path);
    AppendLine(g_app.queuePath, line);
}

static void AppendQueueDone(DWORD id, const char *result)
{
    char line[128];
    SbSnprintf(line, sizeof(line), "%lu\t%s\r\n", (unsigned long)id, result);
    AppendLine(g_app.queueDonePath, line);
    g_app.queueDoneSinceCompact++;
}

static int UploadedContains(const char *hash, U64 sizeBytes)
{
    HANDLE file;
    char bucket[MAX_PATH], buffer[4096], line[MAX_PATH + 256];
    DWORD got, i, lineLen = 0;
    int found = 0;
    if (!UploadedBucketPath(hash, bucket, sizeof(bucket))) return 0;
    file = CreateFileA(bucket, GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE, NULL, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL, NULL);
    if (file == INVALID_HANDLE_VALUE) return 0;
    while (!found && ReadFile(file, buffer, sizeof(buffer), &got, NULL) && got > 0) {
        for (i = 0; i < got && !found; i++) {
            char ch = buffer[i];
            if (ch == '\r') continue;
            if (ch == '\n') {
                char storedHash[32], status[32], path[MAX_PATH];
                U64 storedSize = 0;
                DWORD photoId = 0;
                line[lineLen] = '\0';
                if (ParseUploadedLine(line, storedHash, sizeof(storedHash), &storedSize, &photoId, status, sizeof(status), path, sizeof(path))
                    && lstrcmpiA(storedHash, hash) == 0
                    && storedSize == sizeBytes) found = 1;
                lineLen = 0;
            } else if (lineLen + 1 < sizeof(line)) {
                line[lineLen++] = ch;
            }
        }
    }
    if (!found && lineLen > 0) {
        char storedHash[32], status[32], path[MAX_PATH];
        U64 storedSize = 0;
        DWORD photoId = 0;
        line[lineLen] = '\0';
        if (ParseUploadedLine(line, storedHash, sizeof(storedHash), &storedSize, &photoId, status, sizeof(status), path, sizeof(path))
            && lstrcmpiA(storedHash, hash) == 0
            && storedSize == sizeBytes) found = 1;
    }
    CloseHandle(file);
    if (found) LogMessage("Local uploaded bucket dedupe hit: bucket=%s hash=%s size=%I64u", bucket, hash, sizeBytes);
    return found;
}

static void MarkUploadedStatus(const char *hash, U64 sizeBytes, DWORD photoId, const char *status, const char *path)
{
    char bucket[MAX_PATH];
    char line[MAX_PATH + 256];
    if (UploadedContains(hash, sizeBytes)) return;
    if (!UploadedBucketPath(hash, bucket, sizeof(bucket))) {
        LogMessage("Could not build uploaded bucket path: hash=%s", hash);
        return;
    }
    SbSnprintf(line, sizeof(line), "%s\t%I64u\t%lu\t%s\t%s\r\n", hash, sizeBytes, (unsigned long)photoId, status, path);
    AppendLine(bucket, line);
    LogMessage("Marked uploaded bucket: hash=%s size=%I64u photo_id=%lu status=%s path=%s", hash, sizeBytes, (unsigned long)photoId, status, path);
}

static DWORD ClearUploadedHistoryCache(void)
{
    char pattern[MAX_PATH];
    char path[MAX_PATH];
    WIN32_FIND_DATAA data;
    HANDLE find;
    DWORD deleted = 0;
    DWORD failed = 0;

    CreateDirectoryA(g_app.uploadedDir, NULL);
    PathJoin(pattern, sizeof(pattern), g_app.uploadedDir, "*.tsv");
    find = FindFirstFileA(pattern, &data);
    if (find != INVALID_HANDLE_VALUE) {
        do {
            if (data.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) continue;
            PathJoin(path, sizeof(path), g_app.uploadedDir, data.cFileName);
            if (DeleteFileA(path)) {
                deleted++;
                LogMessage("Deleted local uploaded history file: path=%s", path);
            } else {
                failed++;
                LogMessage("Could not delete local uploaded history file: path=%s error=%lu", path, GetLastError());
            }
        } while (FindNextFileA(find, &data));
        FindClose(find);
    }

    if (GetFileAttributesA(g_app.uploadedPath) != INVALID_FILE_ATTRIBUTES) {
        if (DeleteFileA(g_app.uploadedPath)) {
            deleted++;
            LogMessage("Deleted legacy local uploaded history file: path=%s", g_app.uploadedPath);
        } else {
            failed++;
            LogMessage("Could not delete legacy local uploaded history file: path=%s error=%lu", g_app.uploadedPath, GetLastError());
        }
    }

    LogMessage("Local uploaded history cache clear complete: deleted=%lu failed=%lu", (unsigned long)deleted, (unsigned long)failed);
    return deleted;
}

static void MigrateUploadedCache(void)
{
    HANDLE file;
    char buffer[4096], line[MAX_PATH + 256], migrated[MAX_PATH];
    DWORD got, i, lineLen = 0, migratedCount = 0, malformed = 0;

    if (GetFileAttributesA(g_app.uploadedPath) == INVALID_FILE_ATTRIBUTES) return;
    file = CreateFileA(g_app.uploadedPath, GENERIC_READ, FILE_SHARE_READ, NULL, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL, NULL);
    if (file == INVALID_HANDLE_VALUE) {
        LogMessage("Uploaded cache migration skipped: could not open path=%s error=%lu", g_app.uploadedPath, GetLastError());
        return;
    }
    LogMessage("Uploaded cache migration start: source=%s target_dir=%s", g_app.uploadedPath, g_app.uploadedDir);
    while (ReadFile(file, buffer, sizeof(buffer), &got, NULL) && got > 0) {
        for (i = 0; i < got; i++) {
            char ch = buffer[i];
            if (ch == '\r') continue;
            if (ch == '\n') {
                char storedHash[32], status[32], source[MAX_PATH];
                U64 storedSize = 0;
                DWORD photoId = 0;
                line[lineLen] = '\0';
                if (lineLen > 0 && ParseUploadedLine(line, storedHash, sizeof(storedHash), &storedSize, &photoId, status, sizeof(status), source, sizeof(source))) {
                    MarkUploadedStatus(storedHash, storedSize, photoId, status[0] ? status : "uploaded", source);
                    migratedCount++;
                } else if (lineLen > 0) {
                    malformed++;
                    LogMessage("Uploaded cache migration ignored malformed row: %s", line);
                }
                lineLen = 0;
            } else if (lineLen + 1 < sizeof(line)) {
                line[lineLen++] = ch;
            }
        }
    }
    if (lineLen > 0) {
        char storedHash[32], status[32], source[MAX_PATH];
        U64 storedSize = 0;
        DWORD photoId = 0;
        line[lineLen] = '\0';
        if (ParseUploadedLine(line, storedHash, sizeof(storedHash), &storedSize, &photoId, status, sizeof(status), source, sizeof(source))) {
            MarkUploadedStatus(storedHash, storedSize, photoId, status[0] ? status : "uploaded", source);
            migratedCount++;
        } else {
            malformed++;
            LogMessage("Uploaded cache migration ignored malformed row: %s", line);
        }
    }
    CloseHandle(file);
    SafeCopy(migrated, sizeof(migrated), g_app.uploadedPath);
    lstrcatA(migrated, ".migrated");
    DeleteFileA(migrated);
    if (MoveFileA(g_app.uploadedPath, migrated)) {
        LogMessage("Uploaded cache migration complete: migrated=%lu malformed=%lu archived=%s", (unsigned long)migratedCount, (unsigned long)malformed, migrated);
    } else {
        LogMessage("Uploaded cache migration completed but archive rename failed: migrated=%lu malformed=%lu error=%lu", (unsigned long)migratedCount, (unsigned long)malformed, GetLastError());
    }
}

static int QueuePushInternal(DWORD id, const char *path, int persist, int countFound)
{
    int result = 0;
    DWORD queueCount = 0;
    DWORD queueCapacity = 0;
    if (id == 0) id = AllocateQueueId();
    EnterCriticalSection(&g_app.lock);
    if (!QueueContainsLocked(path)) {
        if (g_app.queueCount == g_app.queueCapacity) {
            DWORD next = g_app.queueCapacity == 0 ? QUEUE_INITIAL : g_app.queueCapacity * 2;
            QueueItem *items = g_app.queue
                ? (QueueItem *)HeapReAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, g_app.queue, sizeof(QueueItem) * next)
                : (QueueItem *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, sizeof(QueueItem) * next);
            if (items) {
                g_app.queue = items;
                g_app.queueCapacity = next;
            }
        }
        if (g_app.queueCount < g_app.queueCapacity) {
            g_app.queue[g_app.queueCount].id = id;
            SafeCopy(g_app.queue[g_app.queueCount].path, sizeof(g_app.queue[g_app.queueCount].path), path);
            g_app.queueCount++;
            if (persist) {
                AppendQueueRecord(id, path);
            }
            if (countFound) {
                InterlockedIncrement(&g_app.totalFound);
            }
            SetEvent(g_app.queueEvent);
            result = 1;
        }
    } else {
        result = -1;
    }
    queueCount = g_app.queueCount;
    queueCapacity = g_app.queueCapacity;
    LeaveCriticalSection(&g_app.lock);
    PostMessageA(g_app.mainWindow, WM_REFRESH_STATS, 0, 0);
    if (!persist && !countFound) {
        return result;
    }
    if (result == 1) {
        LogMessage("Queue add: id=%lu path=%s persist=%s count_found=%s", (unsigned long)id, path, persist ? "yes" : "no", countFound ? "yes" : "no");
    } else if (result == -1) {
        LogMessage("Queue duplicate suppressed: path=%s", path);
    } else {
        LogMessage("Queue add failed: path=%s capacity=%lu count=%lu", path, (unsigned long)queueCapacity, (unsigned long)queueCount);
    }
    return result;
}

static void QueuePush(const char *path)
{
    QueuePushInternal(0, path, 1, 1);
}

static void QueueRequeue(DWORD id, const char *path)
{
    QueuePushInternal(id, path, 0, 0);
}

static int QueuePop(DWORD *id, char *path, DWORD pathSize)
{
    DWORD i;
    DWORD remaining = 0;
    int hasItem = 0;
    EnterCriticalSection(&g_app.lock);
    if (g_app.queueCount > 0) {
        if (id) *id = g_app.queue[0].id;
        SafeCopy(path, pathSize, g_app.queue[0].path);
        for (i = 1; i < g_app.queueCount; i++) {
            g_app.queue[i - 1] = g_app.queue[i];
        }
        g_app.queueCount--;
        remaining = g_app.queueCount;
        hasItem = 1;
    }
    LeaveCriticalSection(&g_app.lock);
    if (hasItem) LogMessage("Queue pop: id=%lu path=%s remaining=%lu", id ? (unsigned long)*id : 0, path, (unsigned long)remaining);
    return hasItem;
}

static int CompareDword(const void *a, const void *b)
{
    DWORD left = *(const DWORD *)a;
    DWORD right = *(const DWORD *)b;
    return left < right ? -1 : (left > right ? 1 : 0);
}

static int DoneIdContains(const DWORD *ids, DWORD count, DWORD id)
{
    DWORD low = 0;
    DWORD high = count;
    while (low < high) {
        DWORD mid = low + (high - low) / 2;
        if (ids[mid] == id) return 1;
        if (ids[mid] < id) low = mid + 1;
        else high = mid;
    }
    return 0;
}

static int ParseQueueLine(char *line, DWORD *id, char *path, DWORD pathSize, int assignLegacyId)
{
    char *tab;
    char *end;
    if (id) *id = 0;
    if (pathSize > 0) path[0] = '\0';
    end = strpbrk(line, "\r\n");
    if (end) *end = '\0';
    if (line[0] == '\0') return 0;
    tab = strchr(line, '\t');
    if (!tab) {
        if (!assignLegacyId) return 0;
        if (id) *id = AllocateQueueId();
        SafeCopy(path, pathSize, line);
        return 1;
    }
    *tab++ = '\0';
    if (id) *id = strtoul(line, NULL, 10);
    SafeCopy(path, pathSize, tab);
    return id && *id > 0 && path[0] != '\0';
}

static DWORD *LoadDoneIds(DWORD *doneCount)
{
    HANDLE file = CreateFileA(g_app.queueDonePath, GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE, NULL, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL, NULL);
    char buffer[4096], line[256];
    DWORD got, i, lineLen = 0, count = 0, capacity = 0;
    DWORD *ids = NULL;
    if (doneCount) *doneCount = 0;
    if (file == INVALID_HANDLE_VALUE) return NULL;
    while (ReadFile(file, buffer, sizeof(buffer), &got, NULL) && got > 0) {
        for (i = 0; i < got; i++) {
            char ch = buffer[i];
            if (ch == '\r') continue;
            if (ch == '\n') {
                line[lineLen] = '\0';
                if (lineLen > 0) {
                    DWORD id = strtoul(line, NULL, 10);
                    if (id > 0) {
                        if (id >= g_app.nextQueueId) g_app.nextQueueId = id + 1;
                        if (count == capacity) {
                            DWORD next = capacity == 0 ? 256 : capacity * 2;
                            DWORD *newIds = ids
                                ? (DWORD *)HeapReAlloc(GetProcessHeap(), 0, ids, sizeof(DWORD) * next)
                                : (DWORD *)HeapAlloc(GetProcessHeap(), 0, sizeof(DWORD) * next);
                            if (newIds) {
                                ids = newIds;
                                capacity = next;
                            }
                        }
                        if (count < capacity) ids[count++] = id;
                    }
                }
                lineLen = 0;
            } else if (lineLen + 1 < sizeof(line)) {
                line[lineLen++] = ch;
            }
        }
    }
    if (lineLen > 0) {
        DWORD id;
        line[lineLen] = '\0';
        id = strtoul(line, NULL, 10);
        if (id > 0) {
            if (id >= g_app.nextQueueId) g_app.nextQueueId = id + 1;
            if (count == capacity) {
                DWORD *newIds = ids
                    ? (DWORD *)HeapReAlloc(GetProcessHeap(), 0, ids, sizeof(DWORD) * (capacity + 1))
                    : (DWORD *)HeapAlloc(GetProcessHeap(), 0, sizeof(DWORD));
                if (newIds) {
                    ids = newIds;
                    capacity++;
                }
            }
            if (count < capacity) ids[count++] = id;
        }
    }
    CloseHandle(file);
    if (count > 1) qsort(ids, count, sizeof(DWORD), CompareDword);
    if (doneCount) *doneCount = count;
    return ids;
}

static void LoadQueue(void)
{
    HANDLE file;
    DWORD *doneIds;
    DWORD doneCount = 0;
    char buffer[4096];
    char line[MAX_PATH + 64];
    DWORD got, i, lineLen = 0, loaded = 0, skipped = 0, malformed = 0, legacyRows = 0;
    LoadNextQueueId();
    doneIds = LoadDoneIds(&doneCount);
    file = CreateFileA(g_app.queuePath, GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE, NULL, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL, NULL);
    if (file == INVALID_HANDLE_VALUE) {
        LogMessage("Queue loaded from disk: path=%s count=0 done_count=%lu", g_app.queuePath, (unsigned long)doneCount);
        if (doneIds) HeapFree(GetProcessHeap(), 0, doneIds);
        return;
    }
    while (ReadFile(file, buffer, sizeof(buffer), &got, NULL) && got > 0) {
        for (i = 0; i < got; i++) {
            char ch = buffer[i];
            if (ch == '\r') continue;
            if (ch == '\n') {
                DWORD id = 0;
                char path[MAX_PATH];
                line[lineLen] = '\0';
                if (lineLen > 0 && strchr(line, '\t') == NULL) legacyRows++;
                if (lineLen > 0 && ParseQueueLine(line, &id, path, sizeof(path), 1)) {
                    if (id >= g_app.nextQueueId) g_app.nextQueueId = id + 1;
                    if (!DoneIdContains(doneIds, doneCount, id)) {
                        QueuePushInternal(id, path, 0, 0);
                        loaded++;
                    } else {
                        skipped++;
                    }
                } else if (lineLen > 0) {
                    malformed++;
                    LogMessage("Queue malformed row ignored: %s", line);
                }
                lineLen = 0;
            } else if (lineLen + 1 < sizeof(line)) {
                line[lineLen++] = ch;
            }
        }
    }
    if (lineLen > 0) {
        DWORD id = 0;
        char path[MAX_PATH];
        line[lineLen] = '\0';
        if (strchr(line, '\t') == NULL) legacyRows++;
        if (ParseQueueLine(line, &id, path, sizeof(path), 1)) {
            if (id >= g_app.nextQueueId) g_app.nextQueueId = id + 1;
            if (!DoneIdContains(doneIds, doneCount, id)) {
                QueuePushInternal(id, path, 0, 0);
                loaded++;
            } else {
                skipped++;
            }
        } else {
            malformed++;
            LogMessage("Queue malformed row ignored: %s", line);
        }
    }
    CloseHandle(file);
    if (doneIds) HeapFree(GetProcessHeap(), 0, doneIds);
    SaveNextQueueId();
    LogMessage("Queue loaded from disk: path=%s loaded=%lu skipped_done=%lu done_count=%lu malformed=%lu legacy_rows=%lu memory_count=%lu",
        g_app.queuePath,
        (unsigned long)loaded,
        (unsigned long)skipped,
        (unsigned long)doneCount,
        (unsigned long)malformed,
        (unsigned long)legacyRows,
        (unsigned long)g_app.queueCount);
    if (legacyRows > 0) {
        g_app.queueDoneSinceCompact = 1000;
        CompactQueueIfNeeded();
    }
}

static U64 FileSizeOrZero(const char *path)
{
    WIN32_FILE_ATTRIBUTE_DATA data;
    ULARGE_INTEGER size;
    if (!GetFileAttributesExA(path, GetFileExInfoStandard, &data)) return 0;
    size.LowPart = data.nFileSizeLow;
    size.HighPart = data.nFileSizeHigh;
    return size.QuadPart;
}

static void CompactQueueIfNeeded(void)
{
    char tmp[MAX_PATH];
    HANDLE file;
    HANDLE doneFile;
    DWORD i, written;
    DWORD pendingCount;
    U64 doneSize;

    doneSize = FileSizeOrZero(g_app.queueDonePath);
    if (g_app.queueDoneSinceCompact < 1000 && doneSize <= 1048576ULL) return;

    PathJoin(tmp, sizeof(tmp), g_app.appDir, "queue.tmp");
    EnterCriticalSection(&g_app.lock);
    pendingCount = g_app.queueCount;
    file = CreateFileA(tmp, GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, FILE_ATTRIBUTE_NORMAL, NULL);
    if (file == INVALID_HANDLE_VALUE) {
        LeaveCriticalSection(&g_app.lock);
        LogMessage("Queue compaction failed: could not create temp path=%s error=%lu", tmp, GetLastError());
        return;
    }
    for (i = 0; i < g_app.queueCount; i++) {
        char line[MAX_PATH + 64];
        SbSnprintf(line, sizeof(line), "%lu\t%s\r\n", (unsigned long)g_app.queue[i].id, g_app.queue[i].path);
        if (!WriteFile(file, line, lstrlenA(line), &written, NULL)) {
            CloseHandle(file);
            DeleteFileA(tmp);
            LeaveCriticalSection(&g_app.lock);
            LogMessage("Queue compaction failed: write error=%lu", GetLastError());
            return;
        }
    }
    FlushFileBuffers(file);
    CloseHandle(file);
    if (!MoveFileExA(tmp, g_app.queuePath, MOVEFILE_REPLACE_EXISTING)) {
        DeleteFileA(tmp);
        LeaveCriticalSection(&g_app.lock);
        LogMessage("Queue compaction failed: replace error=%lu", GetLastError());
        return;
    }
    doneFile = CreateFileA(g_app.queueDonePath, GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, FILE_ATTRIBUTE_NORMAL, NULL);
    if (doneFile != INVALID_HANDLE_VALUE) CloseHandle(doneFile);
    g_app.queueDoneSinceCompact = 0;
    LeaveCriticalSection(&g_app.lock);
    LogMessage("Queue compaction complete: pending=%lu done_size=%I64u", (unsigned long)pendingCount, doneSize);
}

static int ComputeFnv1a64(const char *path, char *hex, DWORD hexSize, U64 *sizeBytes)
{
    HANDLE file = CreateFileA(path, GENERIC_READ, FILE_SHARE_READ, NULL, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL | FILE_FLAG_SEQUENTIAL_SCAN, NULL);
    BYTE buffer[65536];
    DWORD got, i;
    U64 hash = 14695981039346656037ULL;
    U64 prime = 1099511628211ULL;
    U64 total = 0;
    if (file == INVALID_HANDLE_VALUE) return 0;
    while (ReadFile(file, buffer, sizeof(buffer), &got, NULL) && got > 0) {
        for (i = 0; i < got; i++) {
            hash ^= (U64)buffer[i];
            hash *= prime;
        }
        total += got;
    }
    CloseHandle(file);
    SbSnprintf(hex, hexSize - 1, "%016I64x", hash);
    hex[hexSize - 1] = '\0';
    if (sizeBytes) *sizeBytes = total;
    return 1;
}

typedef struct ParsedUrl {
    int secure;
    INTERNET_PORT port;
    char host[256];
    char path[2048];
    char extra[2048];
} ParsedUrl;

static int ParseUrl(const char *url, ParsedUrl *parsed)
{
    URL_COMPONENTSA uc;
    char scheme[16];
    ZeroMemory(&uc, sizeof(uc));
    ZeroMemory(parsed, sizeof(*parsed));
    uc.dwStructSize = sizeof(uc);
    uc.lpszScheme = scheme;
    uc.dwSchemeLength = sizeof(scheme);
    uc.lpszHostName = parsed->host;
    uc.dwHostNameLength = sizeof(parsed->host);
    uc.lpszUrlPath = parsed->path;
    uc.dwUrlPathLength = sizeof(parsed->path);
    uc.lpszExtraInfo = parsed->extra;
    uc.dwExtraInfoLength = sizeof(parsed->extra);
    uc.dwSchemeLength = sizeof(scheme);
    if (!InternetCrackUrlA(url, 0, 0, &uc)) return 0;
    parsed->secure = (uc.nScheme == INTERNET_SCHEME_HTTPS);
    parsed->port = uc.nPort;
    if (parsed->path[0] == '\0') SafeCopy(parsed->path, sizeof(parsed->path), "/");
    if (parsed->extra[0] != '\0' && lstrlenA(parsed->path) + lstrlenA(parsed->extra) + 1 < (int)sizeof(parsed->path)) {
        lstrcatA(parsed->path, parsed->extra);
    }
    return parsed->host[0] != '\0';
}

static int HttpSimpleRequest(const char *method, const char *url, const char *headers, const BYTE *body, DWORD bodyLen, DWORD *status, char *response, DWORD responseSize)
{
    ParsedUrl parsed;
    HINTERNET internet = NULL, connect = NULL, request = NULL;
    DWORD flags = INTERNET_FLAG_RELOAD | INTERNET_FLAG_NO_CACHE_WRITE;
    DWORD got, used = 0, statusSize = sizeof(DWORD);
    int ok = 0;
    if (!ParseUrl(url, &parsed)) {
        LogMessage("HTTP %s failed before send: could not parse URL %s", method, url);
        return 0;
    }
    if (parsed.secure) flags |= INTERNET_FLAG_SECURE;
    internet = InternetOpenA("SpiceBush/1.0", INTERNET_OPEN_TYPE_PRECONFIG, NULL, NULL, 0);
    if (!internet) {
        LogMessage("HTTP %s %s failed: InternetOpen error=%lu", method, url, GetLastError());
        goto done;
    }
    connect = InternetConnectA(internet, parsed.host, parsed.port, NULL, NULL, INTERNET_SERVICE_HTTP, 0, 0);
    if (!connect) {
        LogMessage("HTTP %s %s failed: InternetConnect host=%s port=%u error=%lu", method, url, parsed.host, (unsigned)parsed.port, GetLastError());
        goto done;
    }
    request = HttpOpenRequestA(connect, method, parsed.path, "HTTP/1.1", NULL, NULL, flags, 0);
    if (!request) {
        LogMessage("HTTP %s %s failed: HttpOpenRequest path=%s error=%lu", method, url, parsed.path, GetLastError());
        goto done;
    }
    if (!HttpSendRequestA(request, headers, headers ? lstrlenA(headers) : 0, (LPVOID)body, bodyLen)) {
        LogMessage("HTTP %s %s failed: HttpSendRequest error=%lu header_length=%u body_length=%lu", method, url, GetLastError(), (unsigned)(headers ? lstrlenA(headers) : 0), bodyLen);
        goto done;
    }
    *status = 0;
    HttpQueryInfoA(request, HTTP_QUERY_STATUS_CODE | HTTP_QUERY_FLAG_NUMBER, status, &statusSize, NULL);
    if (response && responseSize > 0) response[0] = '\0';
    while (response && used + 1 < responseSize && InternetReadFile(request, response + used, responseSize - used - 1, &got) && got > 0) {
        used += got;
        response[used] = '\0';
    }
    LogMessage("HTTP %s %s completed: status=%lu response_bytes=%lu", method, url, *status, used);
    ok = 1;
done:
    if (request) InternetCloseHandle(request);
    if (connect) InternetCloseHandle(connect);
    if (internet) InternetCloseHandle(internet);
    return ok;
}

static int JsonStringValue(const char *json, const char *key, char *out, DWORD outSize)
{
    char needle[128];
    const char *p;
    DWORD i = 0;
    SbSnprintf(needle, sizeof(needle) - 1, "\"%s\"", key);
    needle[sizeof(needle) - 1] = '\0';
    p = strstr(json, needle);
    if (!p) return 0;
    p = strchr(p + lstrlenA(needle), ':');
    if (!p) return 0;
    p++;
    while (*p == ' ' || *p == '\t') p++;
    if (*p != '"') return 0;
    p++;
    while (*p && *p != '"' && i + 1 < outSize) {
        if (*p == '\\' && p[1]) p++;
        out[i++] = *p++;
    }
    out[i] = '\0';
    return i > 0;
}

static int JsonFirstArrayStringValue(const char *json, const char *key, char *out, DWORD outSize)
{
    char needle[128];
    const char *p;
    DWORD i = 0;
    SbSnprintf(needle, sizeof(needle) - 1, "\"%s\"", key);
    needle[sizeof(needle) - 1] = '\0';
    p = strstr(json, needle);
    if (!p) return 0;
    p = strchr(p + lstrlenA(needle), ':');
    if (!p) return 0;
    p++;
    while (*p == ' ' || *p == '\t' || *p == '\r' || *p == '\n') p++;
    if (*p != '[') return 0;
    p++;
    while (*p == ' ' || *p == '\t' || *p == '\r' || *p == '\n') p++;
    if (*p != '"') return 0;
    p++;
    while (*p && *p != '"' && i + 1 < outSize) {
        if (*p == '\\' && p[1]) p++;
        out[i++] = *p++;
    }
    out[i] = '\0';
    return i > 0;
}

static void LogResponseSummary(const char *label, DWORD status, const char *response)
{
    char errorText[512];
    char preview[512];
    DWORD i = 0;
    DWORD j = 0;
    int previousSpace = 0;

    if (JsonFirstArrayStringValue(response, "errors", errorText, sizeof(errorText))) {
        LogMessage("%s failed response: status=%lu error=%s", label, status, errorText);
        return;
    }

    while (response && response[i] && j + 1 < sizeof(preview)) {
        unsigned char ch = (unsigned char)response[i++];
        if (ch < 32 || ch == 127) {
            if (!previousSpace && j > 0) {
                preview[j++] = ' ';
                previousSpace = 1;
            }
            continue;
        }
        preview[j++] = (char)ch;
        previousSpace = ch == ' ' || ch == '\t';
    }
    preview[j] = '\0';

    LogMessage("%s failed response: status=%lu preview=%s", label, status, preview[0] ? preview : "(empty)");
}

static int JsonBoolValue(const char *json, const char *key, int *value)
{
    char needle[128];
    const char *p;
    SbSnprintf(needle, sizeof(needle) - 1, "\"%s\"", key);
    needle[sizeof(needle) - 1] = '\0';
    p = strstr(json, needle);
    if (!p) return 0;
    p = strchr(p + lstrlenA(needle), ':');
    if (!p) return 0;
    p++;
    while (*p == ' ' || *p == '\t') p++;
    if (strncmp(p, "true", 4) == 0) { *value = 1; return 1; }
    if (strncmp(p, "false", 5) == 0) { *value = 0; return 1; }
    return 0;
}

static int JsonDwordValue(const char *json, const char *key, DWORD *value)
{
    char needle[128];
    const char *p;
    DWORD parsed = 0;
    SbSnprintf(needle, sizeof(needle) - 1, "\"%s\"", key);
    needle[sizeof(needle) - 1] = '\0';
    p = strstr(json, needle);
    if (!p) return 0;
    p = strchr(p + lstrlenA(needle), ':');
    if (!p) return 0;
    p++;
    while (*p == ' ' || *p == '\t' || *p == '\r' || *p == '\n') p++;
    if (*p < '0' || *p > '9') return 0;
    while (*p >= '0' && *p <= '9') {
        parsed = parsed * 10 + (DWORD)(*p - '0');
        p++;
    }
    if (value) *value = parsed;
    return 1;
}

static int JsonU64Value(const char *json, const char *key, U64 *value)
{
    char needle[128];
    const char *p;
    U64 parsed = 0;
    SbSnprintf(needle, sizeof(needle) - 1, "\"%s\"", key);
    needle[sizeof(needle) - 1] = '\0';
    p = strstr(json, needle);
    if (!p) return 0;
    p = strchr(p + lstrlenA(needle), ':');
    if (!p) return 0;
    p++;
    while (*p == ' ' || *p == '\t' || *p == '\r' || *p == '\n') p++;
    if (*p < '0' || *p > '9') return 0;
    while (*p >= '0' && *p <= '9') {
        parsed = parsed * 10ULL + (U64)(*p - '0');
        p++;
    }
    if (value) *value = parsed;
    return 1;
}

static void UrlEncode(const char *src, char *dst, DWORD dstSize)
{
    static const char hex[] = "0123456789ABCDEF";
    DWORD used = 0;
    while (*src && used + 4 < dstSize) {
        unsigned char c = (unsigned char)*src++;
        if ((c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') || c == '-' || c == '_' || c == '.' || c == '~') {
            dst[used++] = (char)c;
        } else {
            dst[used++] = '%';
            dst[used++] = hex[(c >> 4) & 15];
            dst[used++] = hex[c & 15];
        }
    }
    dst[used] = '\0';
}

static void JsonEscape(const char *src, char *dst, DWORD dstSize)
{
    DWORD used = 0;
    while (*src && used + 2 < dstSize) {
        char c = *src++;
        if (c == '"' || c == '\\') {
            dst[used++] = '\\';
            dst[used++] = c;
        } else if ((unsigned char)c >= 32) {
            dst[used++] = c;
        }
    }
    dst[used] = '\0';
}

static void BuildRegisterEndpoint(const char *siteUrl, char *endpoint, DWORD endpointSize)
{
    if (strncmp(siteUrl, "http://", 7) != 0 && strncmp(siteUrl, "https://", 8) != 0) {
        SafeCopy(endpoint, endpointSize, "http://");
        lstrcatA(endpoint, siteUrl);
    } else {
        SafeCopy(endpoint, endpointSize, siteUrl);
    }
    TrimTrailingSlashes(endpoint);
    if (EndsWithNoCase(endpoint, "/api")) {
        lstrcatA(endpoint, "/register-for-token.php");
    } else {
        lstrcatA(endpoint, "/api/register-for-token.php");
    }
}

static int CheckServerKnowsFile(const char *hash, U64 sizeBytes, DWORD *photoId)
{
    char url[2048], encodedHash[128], headers[1800], response[4096];
    DWORD status = 0;
    int exists = 0;
    if (photoId) *photoId = 0;
    if (g_app.apiUrl[0] == '\0' || g_app.uploadToken[0] == '\0') return 0;
    UrlEncode(hash, encodedHash, sizeof(encodedHash));
    SbSnprintf(url, sizeof(url) - 1, "%s/quick-checksum.php?algorithm=fnv1a64&hash=%s&size_bytes=%I64u", g_app.apiUrl, encodedHash, sizeBytes);
    url[sizeof(url) - 1] = '\0';
    SbSnprintf(headers, sizeof(headers) - 1,
        "Authorization: Bearer %s\r\n"
        "X-SwallowTail-Upload-Token: %s\r\n",
        g_app.uploadToken,
        g_app.uploadToken);
    headers[sizeof(headers) - 1] = '\0';
    LogMessage("Quick checksum request prepared: url=%s token_present=%s token_length=%u auth_header=yes fallback_header=yes",
        url,
        g_app.uploadToken[0] != '\0' ? "yes" : "no",
        (unsigned)lstrlenA(g_app.uploadToken));
    if (!HttpSimpleRequest("GET", url, headers, NULL, 0, &status, response, sizeof(response))) {
        LogMessage("Quick checksum request failed before response: hash=%s size=%I64u", hash, sizeBytes);
        return 0;
    }
    if (status == 200 && JsonBoolValue(response, "exists", &exists) && exists) {
        JsonDwordValue(response, "photo_id", photoId);
        LogMessage("Server dedupe hit: hash=%s size=%I64u photo_id=%lu", hash, sizeBytes, photoId ? (unsigned long)*photoId : 0);
        return 1;
    }
    LogMessage("Server dedupe miss or unavailable: status=%lu hash=%s size=%I64u", status, hash, sizeBytes);
    return 0;
}

static int PerformPingCheck(U64 *maxRawUploadBytesOut, char *errorMessage, DWORD errorMessageSize)
{
    char url[MAX_TEXT * 2], headers[(MAX_TEXT * 2) + 160], response[4096], errorText[512];
    DWORD status = 0;
    int requestOk;
    int pong = 0;
    U64 maxRawUploadBytes = 0;

    if (maxRawUploadBytesOut) *maxRawUploadBytesOut = 0;
    if (errorMessage && errorMessageSize > 0) errorMessage[0] = '\0';

    if (g_app.apiUrl[0] == '\0' || g_app.uploadToken[0] == '\0') {
        SafeCopy(errorMessage, errorMessageSize, "Connection check failed: SpiceBush is not registered.");
        return 0;
    }

    SbSnprintf(url, sizeof(url) - 1, "%s/ping.php", g_app.apiUrl);
    url[sizeof(url) - 1] = '\0';
    SbSnprintf(headers, sizeof(headers) - 1,
        "Authorization: Bearer %s\r\n"
        "X-SwallowTail-Upload-Token: %s\r\n",
        g_app.uploadToken,
        g_app.uploadToken);
    headers[sizeof(headers) - 1] = '\0';
    LogMessage("Ping request prepared: url=%s token_present=%s token_length=%u auth_header=yes fallback_header=yes",
        url,
        g_app.uploadToken[0] != '\0' ? "yes" : "no",
        (unsigned)lstrlenA(g_app.uploadToken));

    requestOk = HttpSimpleRequest("GET", url, headers, NULL, 0, &status, response, sizeof(response));
    if (requestOk
        && status == 200
        && JsonBoolValue(response, "pong", &pong)
        && pong
        && JsonU64Value(response, "max_raw_upload_bytes", &maxRawUploadBytes)
        && maxRawUploadBytes > 0) {
        EnterCriticalSection(&g_app.lock);
        g_app.serverMaxRawUploadBytes = maxRawUploadBytes;
        g_app.serverMaxRawUploadState = maxRawUploadBytes > 0 ? 1 : -1;
        LeaveCriticalSection(&g_app.lock);
        SaveConfig();
        if (maxRawUploadBytesOut) *maxRawUploadBytesOut = maxRawUploadBytes;
        LogMessage("Ping succeeded: status=%lu max_raw_upload_bytes=%I64u", status, maxRawUploadBytes);
        return 1;
    }

    EnterCriticalSection(&g_app.lock);
    g_app.serverMaxRawUploadBytes = 0;
    g_app.serverMaxRawUploadState = -1;
    LeaveCriticalSection(&g_app.lock);

    if (requestOk && status == 200 && pong) {
        LogMessage("Ping failed without max_raw_upload_bytes: status=%lu", status);
        if (errorMessage && errorMessageSize > 0) {
            SafeCopy(errorMessage, errorMessageSize, "Connection check failed: server did not report an upload limit.");
        }
    } else if (requestOk && JsonFirstArrayStringValue(response, "errors", errorText, sizeof(errorText))) {
        LogMessage("Ping failed with server error: status=%lu error=%s", status, errorText);
        if (errorMessage && errorMessageSize > 0) {
            SbSnprintf(errorMessage, errorMessageSize, "Connection check failed: %s", errorText);
        }
    } else {
        LogMessage("Ping failed without JSON error: request_ok=%s status=%lu", requestOk ? "yes" : "no", status);
        if (errorMessage && errorMessageSize > 0) {
            if (requestOk && status > 0) {
                SbSnprintf(errorMessage, errorMessageSize, "Connection check failed: server returned HTTP %lu.", status);
            } else {
                SafeCopy(errorMessage, errorMessageSize, "Connection check failed: could not connect to the SwallowTail server.");
            }
        }
    }
    return 0;
}

static DWORD WINAPI PingThread(LPVOID param)
{
    char errorText[512];
    char *postedError = NULL;
    U64 maxRawUploadBytes = 0;
    (void)param;

    if (PerformPingCheck(&maxRawUploadBytes, errorText, sizeof(errorText))) {
        PostMessageA(g_app.mainWindow, WM_PING_DONE, 1, 0);
        return 0;
    }

    postedError = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, lstrlenA(errorText) + 1);
    if (postedError) {
        SafeCopy(postedError, lstrlenA(errorText) + 1, errorText);
    }
    PostMessageA(g_app.mainWindow, WM_PING_DONE, 0, (LPARAM)postedError);
    return 0;
}

static void StartPingCheck(void)
{
    HANDLE thread;
    if (g_app.uploadToken[0] == '\0' || g_app.apiUrl[0] == '\0') return;
    thread = CreateThread(NULL, 0, PingThread, NULL, 0, NULL);
    if (thread) CloseHandle(thread);
    else ShowRegisterWindow(0);
}

static int UploadFileRaw(const char *path, const char *hash, U64 sizeBytes, int *permanentReject)
{
    ParsedUrl parsed;
    char url[2048], headers[1800], filename[MAX_PATH], response[4096];
    HINTERNET internet = NULL, connect = NULL, request = NULL;
    HANDLE file = INVALID_HANDLE_VALUE;
    INTERNET_BUFFERSA buffers;
    DWORD flags = INTERNET_FLAG_RELOAD | INTERNET_FLAG_NO_CACHE_WRITE;
    DWORD got, wrote, status = 0, statusSize = sizeof(DWORD), used = 0;
    BOOL readOk;
    BYTE buf[65536];
    int ok = 0;
    int duplicate = 0;
    DWORD photoId = 0;
    const char *slash = strrchr(path, '\\');
    if (permanentReject) *permanentReject = 0;
    SafeCopy(filename, sizeof(filename), slash ? slash + 1 : path);
    SbSnprintf(url, sizeof(url) - 1, "%s/raw-upload.php", g_app.apiUrl);
    url[sizeof(url) - 1] = '\0';
    if (!ParseUrl(url, &parsed)) {
        LogMessage("Raw upload failed before send: could not parse url=%s", url);
        return 0;
    }
    if (parsed.secure) flags |= INTERNET_FLAG_SECURE;
    SbSnprintf(headers, sizeof(headers) - 1,
        "Authorization: Bearer %s\r\n"
        "X-SwallowTail-Upload-Token: %s\r\n"
        "Content-Type: application/octet-stream\r\n"
        "X-Swallowtail-Filename: %s\r\n"
        "X-Swallowtail-Device-ID: %s\r\n"
        "X-Requested-With: XMLHttpRequest\r\n",
        g_app.uploadToken, g_app.uploadToken, filename, g_app.deviceId);
    headers[sizeof(headers) - 1] = '\0';
    LogMessage("Raw upload request prepared: url=%s filename=%s token_present=%s token_length=%u auth_header=yes fallback_header=yes",
        url,
        filename,
        g_app.uploadToken[0] != '\0' ? "yes" : "no",
        (unsigned)lstrlenA(g_app.uploadToken));

    file = CreateFileA(path, GENERIC_READ, FILE_SHARE_READ, NULL, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL | FILE_FLAG_SEQUENTIAL_SCAN, NULL);
    if (file == INVALID_HANDLE_VALUE) {
        LogMessage("Raw upload failed before send: could not open file path=%s error=%lu", path, GetLastError());
        goto done;
    }
    internet = InternetOpenA("SpiceBush/1.0", INTERNET_OPEN_TYPE_PRECONFIG, NULL, NULL, 0);
    if (!internet) {
        LogMessage("Raw upload failed before send: InternetOpen error=%lu", GetLastError());
        goto done;
    }
    connect = InternetConnectA(internet, parsed.host, parsed.port, NULL, NULL, INTERNET_SERVICE_HTTP, 0, 0);
    if (!connect) {
        LogMessage("Raw upload failed before send: InternetConnect host=%s port=%u error=%lu", parsed.host, (unsigned)parsed.port, GetLastError());
        goto done;
    }
    request = HttpOpenRequestA(connect, "POST", parsed.path, "HTTP/1.1", NULL, NULL, flags, 0);
    if (!request) {
        LogMessage("Raw upload failed before send: HttpOpenRequest path=%s error=%lu", parsed.path, GetLastError());
        goto done;
    }
    ZeroMemory(&buffers, sizeof(buffers));
    buffers.dwStructSize = sizeof(buffers);
    buffers.lpcszHeader = headers;
    buffers.dwHeadersLength = lstrlenA(headers);
    buffers.dwBufferTotal = (DWORD)sizeBytes;
    if (!HttpSendRequestExA(request, &buffers, NULL, 0, 0)) {
        LogMessage("Raw upload failed before body send: HttpSendRequestEx error=%lu header_length=%u total_bytes=%I64u",
            GetLastError(),
            (unsigned)buffers.dwHeadersLength,
            sizeBytes);
        goto done;
    }
    while ((readOk = ReadFile(file, buf, sizeof(buf), &got, NULL)) && got > 0) {
        if (!InternetWriteFile(request, buf, got, &wrote) || wrote != got) {
            LogMessage("Raw upload failed during body send: wrote=%lu expected=%lu error=%lu", wrote, got, GetLastError());
            goto done;
        }
    }
    if (!readOk) {
        LogMessage("Raw upload failed during file read: path=%s error=%lu", path, GetLastError());
        goto done;
    }
    if (!HttpEndRequestA(request, NULL, 0, 0)) {
        LogMessage("Raw upload failed after body send: HttpEndRequest error=%lu", GetLastError());
        goto done;
    }
    HttpQueryInfoA(request, HTTP_QUERY_STATUS_CODE | HTTP_QUERY_FLAG_NUMBER, &status, &statusSize, NULL);
    while (used + 1 < sizeof(response) && InternetReadFile(request, response + used, sizeof(response) - used - 1, &got) && got > 0) {
        used += got;
        response[used] = '\0';
    }
    ok = (status == 200 || status == 201) && strstr(response, "\"success\":true") != NULL;
    if (ok) {
        JsonDwordValue(response, "photo_id", &photoId);
        JsonBoolValue(response, "duplicate", &duplicate);
    }
    LogMessage("Raw upload completed: status=%lu ok=%s response_bytes=%lu path=%s hash=%s size=%I64u",
        status,
        ok ? "yes" : "no",
        used,
        path,
        hash,
        sizeBytes);
    if (!ok) {
        if (status == 413 && permanentReject) *permanentReject = 1;
        LogResponseSummary("Raw upload", status, response);
    }
done:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    if (request) InternetCloseHandle(request);
    if (connect) InternetCloseHandle(connect);
    if (internet) InternetCloseHandle(internet);
    if (ok) MarkUploadedStatus(hash, sizeBytes, photoId, duplicate ? "duplicate" : "uploaded", path);
    return ok;
}

static void ProcessPath(DWORD queueId, const char *path)
{
    char hash[32];
    U64 sizeBytes = 0;
    U64 maxRawUploadBytes = 0;
    int serverMaxRawUploadState = 0;
    int permanentReject = 0;
    DWORD photoId = 0;
    DWORD start = GetTickCount();
    LogMessage("Process start: queue_id=%lu path=%s", (unsigned long)queueId, path);
    if (!ComputeFnv1a64(path, hash, sizeof(hash), &sizeBytes)) {
        InterlockedIncrement(&g_app.totalFailed);
        LogMessage("Process failed: could not hash path=%s", path);
        AppendQueueDone(queueId, "failed_permanent");
        CompactQueueIfNeeded();
        return;
    }
    LogMessage("Process hash complete: path=%s hash=%s size=%I64u", path, hash, sizeBytes);
    if (UploadedContains(hash, sizeBytes)) {
        InterlockedIncrement(&g_app.totalSkippedLocal);
        LogMessage("Process skipped local dedupe: path=%s hash=%s size=%I64u", path, hash, sizeBytes);
        AppendQueueDone(queueId, "local_duplicate");
        CompactQueueIfNeeded();
        return;
    }
    if (CheckServerKnowsFile(hash, sizeBytes, &photoId)) {
        MarkUploadedStatus(hash, sizeBytes, photoId, "server_known", path);
        InterlockedIncrement(&g_app.totalKnown);
        LogMessage("Process skipped server dedupe: path=%s hash=%s size=%I64u", path, hash, sizeBytes);
        AppendQueueDone(queueId, "server_known");
        CompactQueueIfNeeded();
        return;
    }

    EnterCriticalSection(&g_app.lock);
    serverMaxRawUploadState = g_app.serverMaxRawUploadState;
    maxRawUploadBytes = g_app.serverMaxRawUploadBytes;
    LeaveCriticalSection(&g_app.lock);
    if (serverMaxRawUploadState == 0) {
        char pingError[256];
        if (!PerformPingCheck(&maxRawUploadBytes, pingError, sizeof(pingError))) {
            LogMessage("Process could not refresh upload limit before upload: %s", pingError);
        }
        EnterCriticalSection(&g_app.lock);
        serverMaxRawUploadState = g_app.serverMaxRawUploadState;
        maxRawUploadBytes = g_app.serverMaxRawUploadBytes;
        LeaveCriticalSection(&g_app.lock);
    }
    if (serverMaxRawUploadState > 0 && maxRawUploadBytes > 0 && sizeBytes > maxRawUploadBytes) {
        InterlockedIncrement(&g_app.totalRejectedOversize);
        LogMessage("Process rejected over upload limit: path=%s hash=%s size=%I64u max_raw_upload_bytes=%I64u",
            path,
            hash,
            sizeBytes,
            maxRawUploadBytes);
        AppendQueueDone(queueId, "rejected_oversize");
        CompactQueueIfNeeded();
        return;
    }

    if (UploadFileRaw(path, hash, sizeBytes, &permanentReject)) {
        DWORD elapsed = GetTickCount() - start;
        EnterCriticalSection(&g_app.lock);
        g_app.totalUploadMillis += elapsed;
        LeaveCriticalSection(&g_app.lock);
        InterlockedIncrement(&g_app.totalUploaded);
        LogMessage("Process uploaded: path=%s hash=%s size=%I64u elapsed_ms=%lu", path, hash, sizeBytes, elapsed);
        AppendQueueDone(queueId, "uploaded");
        CompactQueueIfNeeded();
    } else if (permanentReject) {
        InterlockedIncrement(&g_app.totalRejectedOversize);
        LogMessage("Process rejected after permanent upload failure: path=%s hash=%s size=%I64u", path, hash, sizeBytes);
        AppendQueueDone(queueId, "rejected_oversize");
        CompactQueueIfNeeded();
    } else {
        InterlockedIncrement(&g_app.totalFailed);
        LogMessage("Process upload failed; requeueing: path=%s hash=%s size=%I64u", path, hash, sizeBytes);
        QueueRequeue(queueId, path);
        Sleep(5000);
    }
}

static DWORD WINAPI ProcessorThread(LPVOID param)
{
    HANDLE handles[2];
    char path[MAX_PATH];
    DWORD queueId = 0;
    (void)param;
    handles[0] = g_app.stopEvent;
    handles[1] = g_app.queueEvent;
    for (;;) {
        DWORD wait = WaitForMultipleObjects(2, handles, FALSE, INFINITE);
        if (wait == WAIT_OBJECT_0) break;
        while (QueuePop(&queueId, path, sizeof(path))) {
            InterlockedExchange(&g_app.processing, 1);
            ProcessPath(queueId, path);
            InterlockedExchange(&g_app.processing, 0);
            PostMessageA(g_app.mainWindow, WM_REFRESH_STATS, 0, 0);
            if (WaitForSingleObject(g_app.stopEvent, 0) == WAIT_OBJECT_0) return 0;
        }
    }
    return 0;
}

static void ScanFolder(const char *folder, int depth, int maxDepth, ScanStats *stats)
{
    char pattern[MAX_PATH], child[MAX_PATH];
    WIN32_FIND_DATAA data;
    HANDLE find;
    if (WaitForSingleObject(g_app.stopEvent, 0) == WAIT_OBJECT_0) return;
    PathJoin(pattern, sizeof(pattern), folder, "*");
    find = FindFirstFileA(pattern, &data);
    if (find == INVALID_HANDLE_VALUE) {
        if (stats) stats->errors++;
        LogMessage("Scan folder open failed: folder=%s error=%lu", folder, GetLastError());
        return;
    }
    if (stats) stats->folders++;
    do {
        if (lstrcmpA(data.cFileName, ".") == 0 || lstrcmpA(data.cFileName, "..") == 0) continue;
        PathJoin(child, sizeof(child), folder, data.cFileName);
        if (data.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) {
            if (depth < maxDepth) ScanFolder(child, depth + 1, maxDepth, stats);
        } else if (EndsWithNoCase(data.cFileName, ".cr2")) {
            int queued;
            if (stats) {
                stats->files++;
                stats->cr2++;
            }
            queued = QueuePushInternal(0, child, 1, 1);
            if (stats) {
                if (queued == 1) stats->queued++;
                else if (queued == -1) stats->duplicateQueue++;
            }
        } else if (stats) {
            stats->files++;
        }
    } while (FindNextFileA(find, &data));
    FindClose(find);
}

typedef struct ScanRequest {
    char root[8];
    int maxDepth;
} ScanRequest;

static DWORD WINAPI ScanDriveThread(LPVOID param)
{
    ScanRequest *request = (ScanRequest *)param;
    ScanStats stats;
    ZeroMemory(&stats, sizeof(stats));
    InterlockedIncrement(&g_app.activeScans);
    InterlockedIncrement(&g_app.totalScannedDrives);
    LogMessage("Scan drive start: root=%s max_depth=%d", request->root, request->maxDepth);
    ScanFolder(request->root, 0, request->maxDepth, &stats);
    LogMessage("Scan drive complete: root=%s folders=%lu files=%lu cr2=%lu queued=%lu duplicate_queue=%lu errors=%lu",
        request->root,
        (unsigned long)stats.folders,
        (unsigned long)stats.files,
        (unsigned long)stats.cr2,
        (unsigned long)stats.queued,
        (unsigned long)stats.duplicateQueue,
        (unsigned long)stats.errors);
    InterlockedDecrement(&g_app.activeScans);
    HeapFree(GetProcessHeap(), 0, request);
    PostMessageA(g_app.mainWindow, WM_REFRESH_STATS, 0, 0);
    return 0;
}

static void StartScanDrive(char letter, int maxDepth)
{
    ScanRequest *request = (ScanRequest *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, sizeof(ScanRequest));
    HANDLE thread;
    if (!request) return;
    request->root[0] = letter;
    request->root[1] = ':';
    request->root[2] = '\\';
    request->root[3] = '\0';
    request->maxDepth = maxDepth;
    LogMessage("Scan drive queued: root=%s max_depth=%d", request->root, maxDepth);
    thread = CreateThread(NULL, 0, ScanDriveThread, request, 0, NULL);
    if (thread) CloseHandle(thread);
    else {
        LogMessage("Scan drive thread create failed: root=%s error=%lu", request->root, GetLastError());
        HeapFree(GetProcessHeap(), 0, request);
    }
}

static void ScanExistingDrives(int recursive)
{
    DWORD mask = GetLogicalDrives();
    int i;
    LogMessage("Scan existing drives requested: recursive=%s mask=0x%08lx", recursive ? "yes" : "no", (unsigned long)mask);
    for (i = 0; i < 26; i++) {
        if (mask & (1UL << i)) {
            char root[] = "A:\\";
            UINT type;
            root[0] = (char)('A' + i);
            type = GetDriveTypeA(root);
            LogMessage("Scan existing drive candidate: root=%s type=%u", root, (unsigned)type);
            if (type == DRIVE_REMOVABLE || type == DRIVE_FIXED) {
                StartScanDrive((char)('A' + i), recursive ? 255 : 3);
            }
        }
    }
}

static void HandleDeviceArrival(LPARAM lp)
{
    DEV_BROADCAST_HDR *hdr = (DEV_BROADCAST_HDR *)lp;
    DWORD mask;
    int i;
    if (!hdr) {
        LogMessage("Device arrival ignored: missing header.");
        return;
    }
    if (hdr->dbch_devicetype != DBT_DEVTYP_VOLUME) {
        LogMessage("Device arrival ignored: devicetype=%lu", (unsigned long)hdr->dbch_devicetype);
        return;
    }
    mask = ((DEV_BROADCAST_VOLUME *)hdr)->dbcv_unitmask;
    LogMessage("Device arrival volume mask=0x%08lx", (unsigned long)mask);
    for (i = 0; i < 26; i++) {
        if (mask & (1UL << i)) StartScanDrive((char)('A' + i), 3);
    }
}

static DWORD NotifyIconDataSize(void)
{
#ifdef NOTIFYICONDATA_V2_SIZE
    return NOTIFYICONDATA_V2_SIZE;
#else
    return sizeof(NOTIFYICONDATAA);
#endif
}

static void InitNotifyIconData(NOTIFYICONDATAA *nid, HWND hwnd)
{
    ZeroMemory(nid, sizeof(*nid));
    nid->cbSize = NotifyIconDataSize();
    nid->hWnd = hwnd;
    nid->uID = 1;
}

static void AddTrayIcon(HWND hwnd)
{
    NOTIFYICONDATAA nid;
    BOOL ok;
    char tip[128];
    InitNotifyIconData(&nid, hwnd);
    nid.uFlags = NIF_MESSAGE | NIF_ICON | NIF_TIP;
    nid.uCallbackMessage = WM_TRAYICON;
    nid.hIcon = AppIcon();
    BuildTrayTooltip(tip, sizeof(tip));
    SafeCopy(nid.szTip, sizeof(nid.szTip), tip);
    ok = Shell_NotifyIconA(NIM_ADD, &nid);
    LogMessage("Tray icon add: ok=%s error=%lu cbSize=%lu", ok ? "yes" : "no", ok ? 0 : GetLastError(), (unsigned long)nid.cbSize);
    if (ok) {
        nid.uVersion = NOTIFYICON_VERSION;
        ok = Shell_NotifyIconA(NIM_SETVERSION, &nid);
        LogMessage("Tray icon set version: ok=%s error=%lu version=%u", ok ? "yes" : "no", ok ? 0 : GetLastError(), (unsigned)nid.uVersion);
    }
}

static void RemoveTrayIcon(HWND hwnd)
{
    NOTIFYICONDATAA nid;
    BOOL ok;
    InitNotifyIconData(&nid, hwnd);
    ok = Shell_NotifyIconA(NIM_DELETE, &nid);
    LogMessage("Tray icon remove: ok=%s error=%lu", ok ? "yes" : "no", ok ? 0 : GetLastError());
}

static const char *PluralPhoto(LONG value)
{
    return value == 1 ? "photo" : "photos";
}

static void BuildTrayTooltip(char *tip, DWORD tipSize)
{
    LONG found;
    LONG alreadyUploaded;
    LONG pending;
    LONG active;
    LONG processing;
    const char *status = "Idle";

    EnterCriticalSection(&g_app.lock);
    found = g_app.totalFound;
    alreadyUploaded = g_app.totalKnown + g_app.totalSkippedLocal;
    pending = (LONG)g_app.queueCount;
    active = g_app.activeScans;
    processing = g_app.processing;
    LeaveCriticalSection(&g_app.lock);

    if (active > 0 && (processing || pending > 0)) status = "Scanning/uploading";
    else if (processing || pending > 0) status = "Uploading";
    else if (active > 0) status = "Scanning";

    SbSnprintf(tip, tipSize, "SpiceBush: %s. %ld %s found, %ld already uploaded, %ld waiting.",
        status,
        found,
        PluralPhoto(found),
        alreadyUploaded,
        pending);
}

static void UpdateTrayTooltip(HWND hwnd)
{
    NOTIFYICONDATAA nid;
    char tip[128];
    if (!hwnd) return;
    InitNotifyIconData(&nid, hwnd);
    nid.uFlags = NIF_TIP;
    BuildTrayTooltip(tip, sizeof(tip));
    SafeCopy(nid.szTip, sizeof(nid.szTip), tip);
    Shell_NotifyIconA(NIM_MODIFY, &nid);
}

static void ShowFallbackBalloon(HWND owner, const char *title, const char *message, UINT timeoutMillis)
{
    RECT workArea;
    HDC hdc;
    SIZE titleSize;
    SIZE messageSize;
    int margin = 10;
    int titleGap = 6;
    int minWidth = 120;
    int maxWidth;
    int width;
    int height;
    int x;
    int y;

    SafeCopy(g_app.balloonTitle, sizeof(g_app.balloonTitle), title);
    SafeCopy(g_app.balloonMessage, sizeof(g_app.balloonMessage), message);

    if (!SystemParametersInfoA(SPI_GETWORKAREA, 0, &workArea, 0)) {
        workArea.left = 0;
        workArea.top = 0;
        workArea.right = GetSystemMetrics(SM_CXSCREEN);
        workArea.bottom = GetSystemMetrics(SM_CYSCREEN);
    }
    maxWidth = (workArea.right - workArea.left) / 2;
    if (maxWidth < minWidth) maxWidth = minWidth;

    ZeroMemory(&titleSize, sizeof(titleSize));
    ZeroMemory(&messageSize, sizeof(messageSize));
    hdc = GetDC(NULL);
    if (hdc) {
        HFONT oldFont = (HFONT)SelectObject(hdc, AppBoldFont());
        GetTextExtentPoint32A(hdc, g_app.balloonTitle, lstrlenA(g_app.balloonTitle), &titleSize);
        SelectObject(hdc, AppFont());
        GetTextExtentPoint32A(hdc, g_app.balloonMessage, lstrlenA(g_app.balloonMessage), &messageSize);
        if (oldFont) SelectObject(hdc, oldFont);
        ReleaseDC(NULL, hdc);
    }

    width = max(titleSize.cx, messageSize.cx) + (margin * 2) + 2;
    if (width < minWidth) width = minWidth;
    if (width > maxWidth) width = maxWidth;
    height = margin + titleSize.cy + titleGap + messageSize.cy + margin + 2;
    if (height < 48) height = 48;

    if (!g_app.balloonWindow) {
        g_app.balloonWindow = CreateWindowExA(
            WS_EX_TOPMOST | WS_EX_TOOLWINDOW | WS_EX_NOACTIVATE,
            "SpiceBushBalloon",
            APP_NAME,
            WS_POPUP | WS_BORDER,
            0,
            0,
            width,
            height,
            owner,
            NULL,
            g_app.instance,
            NULL);
    }

    if (!g_app.balloonWindow) {
        LogMessage("Fallback balloon create failed: error=%lu", GetLastError());
        return;
    }

    x = workArea.right - width - 12;
    y = workArea.bottom - height - 12;
    if (x < workArea.left) x = workArea.left;
    if (y < workArea.top) y = workArea.top;

    SetWindowPos(g_app.balloonWindow, HWND_TOPMOST, x, y, width, height, SWP_NOACTIVATE | SWP_SHOWWINDOW);
    InvalidateRect(g_app.balloonWindow, NULL, TRUE);
    SetTimer(g_app.balloonWindow, 1, timeoutMillis > 0 ? timeoutMillis : 10000, NULL);
    LogMessage("Fallback balloon show: title=%s message=%s timeout=%u width=%d height=%d", title, message, (unsigned)timeoutMillis, width, height);
}

static void ShowTrayBalloon(HWND hwnd, const char *title, const char *message, UINT timeoutMillis)
{
    NOTIFYICONDATAA nid;
    BOOL ok;
    InitNotifyIconData(&nid, hwnd);
    nid.uFlags = NIF_INFO | NIF_ICON | NIF_TIP;
    nid.hIcon = AppIcon();
    SafeCopy(nid.szTip, sizeof(nid.szTip), APP_NAME);
    SafeCopy(nid.szInfoTitle, sizeof(nid.szInfoTitle), title);
    SafeCopy(nid.szInfo, sizeof(nid.szInfo), message);
    nid.uTimeout = timeoutMillis;
    nid.dwInfoFlags = NIIF_INFO;
    ok = Shell_NotifyIconA(NIM_MODIFY, &nid);
    LogMessage("Tray balloon show: ok=%s error=%lu title=%s message=%s timeout=%u cbSize=%lu",
        ok ? "yes" : "no",
        ok ? 0 : GetLastError(),
        title,
        message,
        (unsigned)timeoutMillis,
        (unsigned long)nid.cbSize);
    ShowFallbackBalloon(hwnd, title, message, timeoutMillis);
}

static void ShowTrayMenu(HWND hwnd)
{
    HMENU menu = CreatePopupMenu();
    POINT pt;
    SetForegroundWindow(hwnd);
    AppendMenuA(menu, MF_STRING, ID_TRAY_REGISTER, "Change SwallowTail Server...");
    AppendMenuA(menu, MF_STRING, ID_TRAY_STATS, "Statistics");
    AppendMenuA(menu, MF_SEPARATOR, 0, NULL);
    AppendMenuA(menu, MF_STRING, ID_TRAY_EXIT, "Exit");
    SetMenuDefaultItem(menu, ID_TRAY_STATS, FALSE);
    GetCursorPos(&pt);
    TrackPopupMenu(menu, TPM_RIGHTBUTTON, pt.x, pt.y, 0, hwnd, NULL);
    DestroyMenu(menu);
}

static HWND Label(HWND parent, const char *text, int x, int y, int w, int h)
{
    return SetAppFont(CreateWindowA("STATIC", text, WS_CHILD | WS_VISIBLE, x, y, w, h, parent, NULL, g_app.instance, NULL));
}

static HWND StatusLabel(HWND parent, const char *text, int x, int y, int w, int h)
{
    return SetAppFont(CreateWindowExA(WS_EX_CLIENTEDGE, "STATIC", text, WS_CHILD | WS_VISIBLE | SS_LEFT, x, y, w, h, parent, (HMENU)ID_REGISTER_STATUS, g_app.instance, NULL));
}

static HWND Edit(HWND parent, int id, const char *text, int x, int y, int w, int h, DWORD style)
{
    HWND edit = CreateWindowExA(WS_EX_CLIENTEDGE, "EDIT", text, WS_CHILD | WS_VISIBLE | WS_TABSTOP | ES_AUTOHSCROLL | style, x, y, w, h, parent, (HMENU)(INT_PTR)id, g_app.instance, NULL);
    SetAppFont(edit);
    SetWindowLongPtrA(edit, GWLP_USERDATA, SetWindowLongPtrA(edit, GWLP_WNDPROC, (LONG_PTR)RegisterEditWndProc));
    return edit;
}

static HWND Button(HWND parent, int id, const char *text, int x, int y, int w, int h, DWORD style)
{
    return SetAppFont(CreateWindowA("BUTTON", text, WS_CHILD | WS_VISIBLE | WS_TABSTOP | style, x, y, w, h, parent, (HMENU)(INT_PTR)id, g_app.instance, NULL));
}

static void SetStatsPingState(int state)
{
    g_app.statsPingState = state;
    g_app.statsPingStateExpiresAt = state == 0 ? 0 : GetTickCount() + 10000;
    if (g_app.statsWindow) {
        if (state == 0) KillTimer(g_app.statsWindow, 2);
        else SetTimer(g_app.statsWindow, 2, 10000, NULL);
    }
    if (g_app.statsPingButton) {
        InvalidateRect(g_app.statsPingButton, NULL, TRUE);
    }
}

static int StatsPingStateExpired(void)
{
    return g_app.statsPingState != 0
        && g_app.statsPingStateExpiresAt != 0
        && (LONG)(GetTickCount() - g_app.statsPingStateExpiresAt) >= 0;
}

static void DrawPingButton(const DRAWITEMSTRUCT *item)
{
    HBRUSH brush;
    COLORREF textColor = RGB(0, 0, 0);
    RECT fillRect;
    RECT textRect;
    UINT edge = EDGE_RAISED;

    if (!item || item->CtlID != ID_STATS_PING) return;

    if (g_app.statsPingState > 0) {
        brush = CreateSolidBrush(RGB(82, 174, 88));
    } else if (g_app.statsPingState < 0) {
        brush = CreateSolidBrush(RGB(202, 72, 72));
        textColor = RGB(255, 255, 255);
    } else {
        brush = CreateSolidBrush(GetSysColor(COLOR_BTNFACE));
    }

    if (item->itemState & ODS_SELECTED) edge = EDGE_SUNKEN;
    fillRect = item->rcItem;
    DrawEdge(item->hDC, &fillRect, edge, BF_RECT | BF_ADJUST);
    FillRect(item->hDC, &fillRect, brush ? brush : (HBRUSH)(COLOR_BTNFACE + 1));
    if (brush) DeleteObject(brush);

    textRect = fillRect;
    if (item->itemState & ODS_SELECTED) {
        OffsetRect(&textRect, 1, 1);
    }
    SelectObject(item->hDC, AppFont());
    SetBkMode(item->hDC, TRANSPARENT);
    SetTextColor(item->hDC, textColor);
    DrawTextA(item->hDC, "Test Server Connectivity", -1, &textRect, DT_CENTER | DT_VCENTER | DT_SINGLELINE);

    if (item->itemState & ODS_FOCUS) {
        RECT focusRect = item->rcItem;
        InflateRect(&focusRect, -4, -4);
        DrawFocusRect(item->hDC, &focusRect);
    }
}

static LRESULT CALLBACK RegisterEditWndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp)
{
    WNDPROC original = (WNDPROC)GetWindowLongPtrA(hwnd, GWLP_USERDATA);

    if (msg == WM_GETDLGCODE) {
        MSG *keyMsg = (MSG *)lp;
        LRESULT code = CallWindowProcA(original, hwnd, msg, wp, lp);
        if (keyMsg && keyMsg->message == WM_KEYDOWN && keyMsg->wParam == VK_RETURN) {
            return code | DLGC_WANTMESSAGE;
        }
        return code;
    }

    if (msg == WM_KEYDOWN && wp == VK_RETURN) {
        HWND parent = GetParent(hwnd);
        if (parent) {
            SendMessageA(parent, WM_COMMAND, MAKEWPARAM(ID_REGISTER_SAVE, BN_CLICKED), (LPARAM)GetDlgItem(parent, ID_REGISTER_SAVE));
            return 0;
        }
    }

    return CallWindowProcA(original, hwnd, msg, wp, lp);
}

static void ShowRegisterWindow(int quitMode)
{
    g_app.registerQuitMode = quitMode ? 1 : 0;
    if (!g_app.registerWindow) {
        g_app.registerWindow = CreateWindowExA(WS_EX_CONTROLPARENT, "SpiceBushRegister", "Register with SwallowTail", WS_OVERLAPPED | WS_CAPTION | WS_SYSMENU, CW_USEDEFAULT, CW_USEDEFAULT, 540, 330, NULL, NULL, g_app.instance, NULL);
    }
    if (g_app.registerWindow) {
        SetWindowTextA(GetDlgItem(g_app.registerWindow, ID_REGISTER_QUIT), g_app.registerQuitMode ? "Quit" : "Cancel");
    }
    ShowWindow(g_app.registerWindow, SW_SHOWNORMAL);
    SetForegroundWindow(g_app.registerWindow);
}

static void ShowStatsWindow(void)
{
    if (!g_app.statsWindow) {
        DWORD style = WS_OVERLAPPED | WS_CAPTION | WS_SYSMENU;
        RECT rect;
        rect.left = 0;
        rect.top = 0;
        rect.right = 18 + 180 + 18;
        rect.bottom = 540;
        AdjustWindowRect(&rect, style, FALSE);
        g_app.statsWindow = CreateWindowA("SpiceBushStats", "Statistics", style, CW_USEDEFAULT, CW_USEDEFAULT, rect.right - rect.left, rect.bottom - rect.top, NULL, NULL, g_app.instance, NULL);
    }
    ShowWindow(g_app.statsWindow, SW_SHOWNORMAL);
    SetForegroundWindow(g_app.statsWindow);
    PostMessageA(g_app.mainWindow, WM_REFRESH_STATS, 0, 0);
}

static void FormatCount(LONG value, char *out, DWORD outSize)
{
    char raw[32];
    char tmp[40];
    int rawLen;
    int rawPos;
    int tmpPos = 0;
    int digits = 0;

    SbSnprintf(raw, sizeof(raw), "%ld", value);
    rawLen = lstrlenA(raw);
    for (rawPos = rawLen - 1; rawPos >= 0 && tmpPos < (int)sizeof(tmp) - 1; rawPos--) {
        if (digits == 3) {
            tmp[tmpPos++] = ',';
            digits = 0;
        }
        tmp[tmpPos++] = raw[rawPos];
        digits++;
    }
    rawPos = 0;
    while (tmpPos > 0 && rawPos < (int)outSize - 1) {
        out[rawPos++] = tmp[--tmpPos];
    }
    if (outSize > 0) out[rawPos] = '\0';
}

static void FormatDuration(U64 millis, char *out, DWORD outSize)
{
    U64 minutes = millis / 60000ULL;
    U64 hours = minutes / 60ULL;
    U64 days = hours / 24ULL;

    minutes %= 60ULL;
    hours %= 24ULL;

    if (millis == 0) {
        SafeCopy(out, outSize, "0 minutes");
    } else if (days > 0) {
        SbSnprintf(out, outSize, "%I64u days %I64u hours", days, hours);
    } else if (hours > 0) {
        SbSnprintf(out, outSize, "%I64u hours %I64u minutes", hours, minutes);
    } else if (minutes > 0) {
        SbSnprintf(out, outSize, "%I64u minutes", minutes);
    } else {
        SafeCopy(out, outSize, "less than 1 minute");
    }
}

static void FormatBytes(U64 bytes, char *out, DWORD outSize)
{
    double value = (double)bytes;
    const char *units[] = {"B", "KB", "MB", "GB", "TB"};
    int unitIndex = 0;

    while (value >= 1024.0 && unitIndex < 4) {
        value /= 1024.0;
        unitIndex++;
    }

    if (unitIndex == 0) {
        SbSnprintf(out, outSize, "%I64u B", bytes);
    } else {
        SbSnprintf(out, outSize, "%.1f %s", value, units[unitIndex]);
    }
}

static void RefreshStats(void)
{
    char text[256];
    char foundText[32];
    char uploadedText[32];
    char alreadyText[32];
    char pendingText[32];
    char failedText[32];
    char rejectedOversizeText[32];
    char scannedText[32];
    char activeText[32];
    char queueText[32];
    char etaText[80];
    char serverLimitText[80];
    const char *status = "Idle";
    LONG pending;
    LONG found;
    LONG uploaded;
    LONG alreadyUploaded;
    LONG failed;
    LONG rejectedOversize;
    LONG scanned;
    LONG active;
    LONG processing;
    int serverMaxRawUploadState;
    LONG progress = 0;
    U64 avg = 0;
    U64 etaMillis = 0;
    U64 serverMaxRawUploadBytes;
    EnterCriticalSection(&g_app.lock);
    pending = (LONG)g_app.queueCount;
    found = g_app.totalFound;
    uploaded = g_app.totalUploaded;
    alreadyUploaded = g_app.totalKnown + g_app.totalSkippedLocal;
    failed = g_app.totalFailed;
    rejectedOversize = g_app.totalRejectedOversize;
    scanned = g_app.totalScannedDrives;
    active = g_app.activeScans;
    processing = g_app.processing;
    serverMaxRawUploadState = g_app.serverMaxRawUploadState;
    serverMaxRawUploadBytes = g_app.serverMaxRawUploadBytes;
    if (g_app.totalUploaded > 0) avg = g_app.totalUploadMillis / (U64)g_app.totalUploaded;
    LeaveCriticalSection(&g_app.lock);
    UpdateTrayTooltip(g_app.mainWindow);
    if (!g_app.statsWindow) return;

    if (found > 0) progress = ((uploaded + alreadyUploaded) * 100L) / found;
    if (progress > 100) progress = 100;
    if (active > 0 && (processing || pending > 0)) status = "Scanning and uploading";
    else if (processing || pending > 0) status = "Uploading";
    else if (active > 0) status = "Scanning";

    FormatCount(found, foundText, sizeof(foundText));
    FormatCount(uploaded, uploadedText, sizeof(uploadedText));
    FormatCount(alreadyUploaded, alreadyText, sizeof(alreadyText));
    FormatCount(pending, pendingText, sizeof(pendingText));
    FormatCount(failed, failedText, sizeof(failedText));
    FormatCount(rejectedOversize, rejectedOversizeText, sizeof(rejectedOversizeText));
    FormatCount(scanned, scannedText, sizeof(scannedText));
    FormatCount(active, activeText, sizeof(activeText));
    FormatCount(pending, queueText, sizeof(queueText));
    etaMillis = avg * (U64)pending;
    FormatDuration(etaMillis, etaText, sizeof(etaText));
    if (serverMaxRawUploadState > 0 && serverMaxRawUploadBytes > 0) {
        FormatBytes(serverMaxRawUploadBytes, serverLimitText, sizeof(serverLimitText));
    } else if (serverMaxRawUploadState < 0) {
        SafeCopy(serverLimitText, sizeof(serverLimitText), "Unavailable");
    } else {
        SafeCopy(serverLimitText, sizeof(serverLimitText), "Not checked");
    }

    SetWindowTextA(g_app.statsLabels[0], "SwallowTail RAW CR2 Photo Uploads");
    SbSnprintf(text, sizeof(text), "Status: %s", status);
    SetWindowTextA(g_app.statsLabels[1], text);
    SbSnprintf(text, sizeof(text), "Upload progress: %ld%%", progress);
    SetWindowTextA(g_app.statsLabels[2], text);
    SbSnprintf(text, sizeof(text), "Upload queue: %s waiting", queueText);
    SetWindowTextA(g_app.statsLabels[3], text);
    SetWindowTextA(g_app.statsLabels[4], "");
    SbSnprintf(text, sizeof(text), "Files found: %s", foundText);
    SetWindowTextA(g_app.statsLabels[5], text);
    SbSnprintf(text, sizeof(text), "Uploaded this session: %s", uploadedText);
    SetWindowTextA(g_app.statsLabels[6], text);
    SbSnprintf(text, sizeof(text), "Already uploaded: %s", alreadyText);
    SetWindowTextA(g_app.statsLabels[7], text);
    SbSnprintf(text, sizeof(text), "Waiting to upload: %s", pendingText);
    SetWindowTextA(g_app.statsLabels[8], text);
    SbSnprintf(text, sizeof(text), "Failed uploads: %s", failedText);
    SetWindowTextA(g_app.statsLabels[9], text);
    SbSnprintf(text, sizeof(text), "Over-size rejects: %s", rejectedOversizeText);
    SetWindowTextA(g_app.statsLabels[10], text);
    SetWindowTextA(g_app.statsLabels[11], "");
    SbSnprintf(text, sizeof(text), "Drives scanned this session: %s", scannedText);
    SetWindowTextA(g_app.statsLabels[12], text);
    SbSnprintf(text, sizeof(text), "Active scans: %s", activeText);
    SetWindowTextA(g_app.statsLabels[13], text);
    SbSnprintf(text, sizeof(text), "Server upload limit: %s", serverLimitText);
    SetWindowTextA(g_app.statsLabels[14], text);
    SbSnprintf(text, sizeof(text), "Average upload time: %I64u ms per file", avg);
    SetWindowTextA(g_app.statsLabels[15], text);
    SbSnprintf(text, sizeof(text), "Estimated time remaining: %s", etaText);
    SetWindowTextA(g_app.statsLabels[16], text);
}

typedef struct RegisterRequest {
    HWND hwnd;
    char siteUrl[MAX_TEXT];
    char username[MAX_TEXT];
    char password[MAX_TEXT];
    char otpCode[32];
} RegisterRequest;

static DWORD WINAPI RegisterThread(LPVOID param)
{
    RegisterRequest *rr = (RegisterRequest *)param;
    char endpoint[2048], u[MAX_TEXT * 2], p[MAX_TEXT * 2], o[80], d[256], json[4096], headers[256], response[8192];
    char token[MAX_TEXT], apiUrl[MAX_TEXT];
    char errorText[512];
    char *postedError = NULL;
    int requestOk;
    DWORD status = 0;
    BuildRegisterEndpoint(rr->siteUrl, endpoint, sizeof(endpoint));
    JsonEscape(rr->username, u, sizeof(u));
    JsonEscape(rr->password, p, sizeof(p));
    JsonEscape(rr->otpCode, o, sizeof(o));
    JsonEscape(g_app.deviceId, d, sizeof(d));
    SbSnprintf(json, sizeof(json) - 1, "{\"username\":\"%s\",\"password\":\"%s\",\"otp_code\":\"%s\",\"device_id\":\"%s\",\"token_label\":\"SpiceBush %s\"}", u, p, o, d, d);
    json[sizeof(json) - 1] = '\0';
    SafeCopy(headers, sizeof(headers), "Content-Type: application/json\r\n");
    requestOk = HttpSimpleRequest("POST", endpoint, headers, (const BYTE *)json, lstrlenA(json), &status, response, sizeof(response));
    if (requestOk
        && status == 200
        && JsonStringValue(response, "token", token, sizeof(token))
        && JsonStringValue(response, "api_url", apiUrl, sizeof(apiUrl))) {
        SafeCopy(g_app.siteUrl, sizeof(g_app.siteUrl), rr->siteUrl);
        SafeCopy(g_app.uploadToken, sizeof(g_app.uploadToken), token);
        SafeCopy(g_app.apiUrl, sizeof(g_app.apiUrl), apiUrl);
        SaveConfig();
        PostMessageA(rr->hwnd, WM_REGISTER_DONE, 1, 0);
    } else {
        if (requestOk && JsonFirstArrayStringValue(response, "errors", errorText, sizeof(errorText))) {
            postedError = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, lstrlenA(errorText) + 32);
            if (postedError) {
                SbSnprintf(postedError, lstrlenA(errorText) + 32, "Registration failed: %s", errorText);
            }
        } else if (requestOk && JsonStringValue(response, "message", errorText, sizeof(errorText))) {
            postedError = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, lstrlenA(errorText) + 32);
            if (postedError) {
                SbSnprintf(postedError, lstrlenA(errorText) + 32, "Registration failed: %s", errorText);
            }
        } else {
            postedError = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, 160);
            if (postedError) {
                if (requestOk && status > 0) {
                    SbSnprintf(postedError, 160, "Registration failed: server returned HTTP %lu.", status);
                } else {
                    SafeCopy(postedError, 160, "Registration failed: could not connect to the SwallowTail server.");
                }
            }
        }
        PostMessageA(rr->hwnd, WM_REGISTER_DONE, 0, (LPARAM)postedError);
    }
    SecureZeroMemory(rr->password, sizeof(rr->password));
    SecureZeroMemory(rr->otpCode, sizeof(rr->otpCode));
    HeapFree(GetProcessHeap(), 0, rr);
    return 0;
}

static void BeginRegister(HWND hwnd)
{
    RegisterRequest *rr = (RegisterRequest *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, sizeof(RegisterRequest));
    HANDLE thread;
    if (!rr) return;
    rr->hwnd = hwnd;
    GetDlgItemTextA(hwnd, ID_REGISTER_URL, rr->siteUrl, sizeof(rr->siteUrl));
    GetDlgItemTextA(hwnd, ID_REGISTER_USER, rr->username, sizeof(rr->username));
    GetDlgItemTextA(hwnd, ID_REGISTER_PASSWORD, rr->password, sizeof(rr->password));
    GetDlgItemTextA(hwnd, ID_REGISTER_OTP, rr->otpCode, sizeof(rr->otpCode));
    if (rr->siteUrl[0] == '\0' || rr->username[0] == '\0' || rr->password[0] == '\0') {
        HeapFree(GetProcessHeap(), 0, rr);
        SetStatus(hwnd, "URL, E-mail, and password are required.");
        return;
    }
    SetStatus(hwnd, "Registering...");
    EnableWindow(GetDlgItem(hwnd, ID_REGISTER_SAVE), FALSE);
    thread = CreateThread(NULL, 0, RegisterThread, rr, 0, NULL);
    if (thread) CloseHandle(thread);
    else {
        EnableWindow(GetDlgItem(hwnd, ID_REGISTER_SAVE), TRUE);
        HeapFree(GetProcessHeap(), 0, rr);
        SetStatus(hwnd, "Could not start registration thread.");
    }
}

static LRESULT CALLBACK RegisterWndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp)
{
    (void)lp;
    switch (msg) {
    case WM_CREATE:
        {
            HWND logo = CreateWindowA("STATIC", "", WS_CHILD | WS_VISIBLE | SS_ICON, 20, 28, 72, 72, hwnd, NULL, g_app.instance, NULL);
            SendMessageA(logo, STM_SETICON, (WPARAM)AppIcon(), 0);
        }
        Label(hwnd, "URL", 110, 20, 90, 20);
        Edit(hwnd, ID_REGISTER_URL, g_app.siteUrl, 210, 18, 270, 22, 0);
        Label(hwnd, "E-mail", 110, 55, 90, 20);
        Edit(hwnd, ID_REGISTER_USER, "", 210, 53, 270, 22, 0);
        Label(hwnd, "Password", 110, 90, 90, 20);
        Edit(hwnd, ID_REGISTER_PASSWORD, "", 210, 88, 270, 22, ES_PASSWORD);
        Label(hwnd, "OTP", 110, 125, 90, 20);
        Edit(hwnd, ID_REGISTER_OTP, "", 210, 123, 120, 22, 0);
        Button(hwnd, ID_REGISTER_SAVE, "Register", 210, 161, 100, 28, BS_DEFPUSHBUTTON);
        Button(hwnd, ID_REGISTER_QUIT, "Quit", 320, 161, 80, 28, 0);
        g_app.registerStatus = StatusLabel(hwnd, "", 18, 202, 500, 72);
        SetStatus(hwnd, "Enter registration details, then click Register.");
        return 0;
    case WM_COMMAND:
        if (LOWORD(wp) == ID_REGISTER_SAVE && IsWindowEnabled(GetDlgItem(hwnd, ID_REGISTER_SAVE))) BeginRegister(hwnd);
        else if (LOWORD(wp) == ID_REGISTER_QUIT) {
            if (g_app.registerQuitMode) DestroyWindow(g_app.mainWindow);
            else ShowWindow(hwnd, SW_HIDE);
        }
        return 0;
    case WM_REGISTER_DONE:
        EnableWindow(GetDlgItem(hwnd, ID_REGISTER_SAVE), TRUE);
        if (wp) {
            SetStatus(hwnd, "Registered. Checking server upload limit...");
            ShowWindow(hwnd, SW_HIDE);
            PostMessageA(g_app.mainWindow, WM_REGISTER_BALLOON, 0, 0);
            StartPingCheck();
        } else if (lp) {
            SetStatus(hwnd, (const char *)lp);
            HeapFree(GetProcessHeap(), 0, (LPVOID)lp);
        } else {
            SetStatus(hwnd, "Registration failed. Check URL, credentials, OTP, role, and CIDR policy.");
        }
        return 0;
    case WM_CLOSE:
        if (g_app.registerQuitMode && (g_app.uploadToken[0] == '\0' || g_app.apiUrl[0] == '\0')) {
            DestroyWindow(g_app.mainWindow);
            return 0;
        }
        ShowWindow(hwnd, SW_HIDE);
        return 0;
    }
    return DefWindowProcA(hwnd, msg, wp, lp);
}

static LRESULT CALLBACK StatsWndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp)
{
    int i;
    (void)lp;
    switch (msg) {
    case WM_CREATE:
        {
            int margin = 18;
            int scanWidth = 160;
            int pingWidth = 180;
            int buttonY = 410;
            int clearY = buttonY + 36;
            int pingY = clearY + 36;
            int labelWidth = pingWidth;
            for (i = 0; i < STATS_LABEL_COUNT; i++) {
                g_app.statsLabels[i] = Label(hwnd, "", margin, 18 + i * 22, labelWidth, 20);
            }
            Button(hwnd, ID_STATS_SCAN, "Scan Existing Drives", margin, buttonY, scanWidth, 28, 0);
            Button(hwnd, ID_STATS_CLEAR_HISTORY, "Clear local history cache", margin, clearY, pingWidth, 28, 0);
            g_app.statsPingButton = Button(hwnd, ID_STATS_PING, "Test Server Connectivity", margin, pingY, pingWidth, 28, BS_OWNERDRAW);
        }
        SetTimer(hwnd, 1, 1000, NULL);
        if (StatsPingStateExpired()) {
            SetStatsPingState(0);
        } else if (g_app.statsPingState != 0 && g_app.statsPingStateExpiresAt != 0) {
            DWORD remaining = g_app.statsPingStateExpiresAt - GetTickCount();
            SetTimer(hwnd, 2, remaining > 0 ? remaining : 1, NULL);
        }
        RefreshStats();
        return 0;
    case WM_COMMAND:
        if (LOWORD(wp) == ID_STATS_SCAN) ScanExistingDrives(1);
        else if (LOWORD(wp) == ID_STATS_CLEAR_HISTORY) {
            DWORD deleted = ClearUploadedHistoryCache();
            char message[128];
            SbSnprintf(message, sizeof(message), "Local history cache cleared: %lu file%s deleted.",
                (unsigned long)deleted,
                deleted == 1 ? "" : "s");
            ShowTrayBalloon(g_app.mainWindow, APP_NAME, message, 10000);
            RefreshStats();
        }
        else if (LOWORD(wp) == ID_STATS_PING) {
            if (g_app.uploadToken[0] == '\0' || g_app.apiUrl[0] == '\0') {
                SetStatsPingState(-1);
                ShowRegisterWindow(0);
                SetStatus(g_app.registerWindow, "Register with SwallowTail before pinging the API.");
            } else {
                StartPingCheck();
            }
        }
        return 0;
    case WM_DRAWITEM:
        if (wp == ID_STATS_PING) {
            DrawPingButton((const DRAWITEMSTRUCT *)lp);
            return TRUE;
        }
        break;
    case WM_TIMER:
        if (wp == 2) {
            SetStatsPingState(0);
        } else {
            RefreshStats();
        }
        return 0;
    case WM_CLOSE:
        ShowWindow(hwnd, SW_HIDE);
        return 0;
    }
    return DefWindowProcA(hwnd, msg, wp, lp);
}

static LRESULT CALLBACK BalloonWndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp)
{
    switch (msg) {
    case WM_PAINT:
        {
            PAINTSTRUCT ps;
            HDC hdc = BeginPaint(hwnd, &ps);
            RECT rc;
            RECT titleRc;
            RECT messageRc;
            RECT measureRc;
            int margin = 10;
            int titleGap = 6;
            HBRUSH brush = CreateSolidBrush(RGB(255, 255, 225));

            GetClientRect(hwnd, &rc);
            FillRect(hdc, &rc, brush ? brush : (HBRUSH)(COLOR_INFOBK + 1));
            if (brush) DeleteObject(brush);

            SelectObject(hdc, AppBoldFont());
            SetBkMode(hdc, TRANSPARENT);
            SetTextColor(hdc, RGB(0, 0, 0));

            titleRc = rc;
            titleRc.left += margin;
            titleRc.top += margin;
            titleRc.right -= margin;
            titleRc.bottom -= margin;
            DrawTextA(hdc, g_app.balloonTitle, -1, &titleRc, DT_LEFT | DT_SINGLELINE | DT_END_ELLIPSIS);

            measureRc = titleRc;
            DrawTextA(hdc, g_app.balloonTitle, -1, &measureRc, DT_LEFT | DT_SINGLELINE | DT_CALCRECT);

            SelectObject(hdc, AppFont());
            messageRc = rc;
            messageRc.left += margin;
            messageRc.top = measureRc.bottom + titleGap;
            messageRc.right -= margin;
            messageRc.bottom -= margin;
            DrawTextA(hdc, g_app.balloonMessage, -1, &messageRc, DT_LEFT | DT_SINGLELINE | DT_END_ELLIPSIS);

            EndPaint(hwnd, &ps);
        }
        return 0;
    case WM_TIMER:
        KillTimer(hwnd, 1);
        ShowWindow(hwnd, SW_HIDE);
        return 0;
    case WM_LBUTTONDOWN:
    case WM_RBUTTONDOWN:
        KillTimer(hwnd, 1);
        ShowWindow(hwnd, SW_HIDE);
        return 0;
    }

    return DefWindowProcA(hwnd, msg, wp, lp);
}

static LRESULT CALLBACK MainWndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp)
{
    switch (msg) {
    case WM_CREATE:
        AddTrayIcon(hwnd);
        return 0;
    case WM_DEVICECHANGE:
        if (wp == DBT_DEVICEARRIVAL) HandleDeviceArrival(lp);
        return 0;
    case WM_TRAYICON:
        if (lp == WM_RBUTTONUP) ShowTrayMenu(hwnd);
        else if (lp == WM_LBUTTONDBLCLK) ShowStatsWindow();
        return 0;
    case WM_COMMAND:
        switch (LOWORD(wp)) {
        case ID_TRAY_REGISTER: ShowRegisterWindow(0); break;
        case ID_TRAY_STATS: ShowStatsWindow(); break;
        case ID_TRAY_EXIT: DestroyWindow(hwnd); break;
        }
        return 0;
    case WM_REFRESH_STATS:
        RefreshStats();
        return 0;
    case WM_REGISTER_BALLOON:
        ShowTrayBalloon(hwnd, APP_NAME, "Registered OK!", 10000);
        return 0;
    case WM_PING_DONE:
        if (wp) {
            SetStatsPingState(1);
            ShowTrayBalloon(hwnd, APP_NAME, "SwallowTail - Connected OK!", 10000);
        } else {
            SetStatsPingState(-1);
            ShowRegisterWindow(0);
            if (lp) {
                SetStatus(g_app.registerWindow, (const char *)lp);
                HeapFree(GetProcessHeap(), 0, (LPVOID)lp);
            } else {
                SetStatus(g_app.registerWindow, "Connection check failed. Please register with SwallowTail again.");
            }
        }
        return 0;
    case WM_DESTROY:
        SetEvent(g_app.stopEvent);
        RemoveTrayIcon(hwnd);
        PostQuitMessage(0);
        return 0;
    }
    return DefWindowProcA(hwnd, msg, wp, lp);
}

static void RegisterClasses(void)
{
    WNDCLASSA wc;
    ZeroMemory(&wc, sizeof(wc));
    wc.hInstance = g_app.instance;
    wc.hIcon = AppIcon();
    wc.hCursor = LoadCursor(NULL, IDC_ARROW);
    wc.hbrBackground = (HBRUSH)(COLOR_BTNFACE + 1);
    wc.lpfnWndProc = MainWndProc;
    wc.lpszClassName = "SpiceBushMain";
    RegisterClassA(&wc);
    wc.lpfnWndProc = RegisterWndProc;
    wc.lpszClassName = "SpiceBushRegister";
    RegisterClassA(&wc);
    wc.lpfnWndProc = StatsWndProc;
    wc.lpszClassName = "SpiceBushStats";
    RegisterClassA(&wc);
    wc.lpfnWndProc = BalloonWndProc;
    wc.lpszClassName = "SpiceBushBalloon";
    RegisterClassA(&wc);
}

int WINAPI WinMain(HINSTANCE instance, HINSTANCE previous, LPSTR cmdLine, int show)
{
    MSG msg;
    HANDLE processor;
    (void)previous;
    (void)cmdLine;
    (void)show;
    ZeroMemory(&g_app, sizeof(g_app));
    g_app.instance = instance;
    EnsureAppPaths();
    g_app.instanceMutex = CreateMutexA(NULL, TRUE, "Local\\SpiceBush.SingleInstance");
    if (!g_app.instanceMutex) {
        LogMessage("SpiceBush could not create single-instance mutex; exiting. error=%lu", GetLastError());
        return 1;
    }
    if (GetLastError() == ERROR_ALREADY_EXISTS) {
        LogMessage("SpiceBush duplicate instance detected; exiting before startup.");
        CloseHandle(g_app.instanceMutex);
        return 0;
    }
    InitializeCriticalSection(&g_app.lock);
    g_app.queueEvent = CreateEventA(NULL, FALSE, FALSE, NULL);
    g_app.stopEvent = CreateEventA(NULL, TRUE, FALSE, NULL);
    EnsureAppStorage();
    LogMessage("SpiceBush starting: app_dir=%s ini_path=%s log_path=%s", g_app.appDir, g_app.iniPath, g_app.logPath);
    LoadConfig();
    RegisterClasses();
    g_app.mainWindow = CreateWindowA("SpiceBushMain", APP_NAME, WS_OVERLAPPEDWINDOW, 0, 0, 0, 0, NULL, NULL, instance, NULL);
    LoadQueue();
    processor = CreateThread(NULL, 0, ProcessorThread, NULL, 0, NULL);
    if (processor) CloseHandle(processor);
    if (g_app.uploadToken[0] == '\0' || g_app.apiUrl[0] == '\0') ShowRegisterWindow(1);
    else StartPingCheck();
    while (GetMessageA(&msg, NULL, 0, 0)) {
        if ((g_app.registerWindow && IsWindowVisible(g_app.registerWindow) && IsDialogMessageA(g_app.registerWindow, &msg))
            || (g_app.statsWindow && IsWindowVisible(g_app.statsWindow) && IsDialogMessageA(g_app.statsWindow, &msg))) {
            continue;
        }
        TranslateMessage(&msg);
        DispatchMessageA(&msg);
    }
    DeleteCriticalSection(&g_app.lock);
    if (g_app.balloonWindow) DestroyWindow(g_app.balloonWindow);
    if (g_app.registerLogoIcon) DestroyIcon(g_app.registerLogoIcon);
    if (g_app.boldUiFont) DeleteObject(g_app.boldUiFont);
    if (g_app.uiFont) DeleteObject(g_app.uiFont);
    if (g_app.queue) HeapFree(GetProcessHeap(), 0, g_app.queue);
    if (g_app.queueEvent) CloseHandle(g_app.queueEvent);
    if (g_app.stopEvent) CloseHandle(g_app.stopEvent);
    if (g_app.instanceMutex) CloseHandle(g_app.instanceMutex);
    LogMessage("SpiceBush exiting.");
    return 0;
}
