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

#include "spicebush_shared.h"

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
#define ID_STATS_PAUSE 3004
#define ID_MAIN_SHUTDOWN_TIMER 4001
#define MAX_TEXT 1024
#define QUEUE_INITIAL 128
#define STATS_LABEL_COUNT 18
#define RAW_UPLOAD_RETRY 0
#define RAW_UPLOAD_OK 1
#define RAW_UPLOAD_REJECT_OVERSIZE 2
#define RAW_UPLOAD_SOURCE_INTERRUPTED 3
#define RAW_UPLOAD_BUFFER_BYTES (4 * 1024 * 1024)
#define RAW_UPLOAD_RETRY_DELAY_MS 30000
#define RAW_UPLOAD_WRITE_CHUNK_BYTES (256 * 1024)
#define PREPARED_UPLOAD_LOOKAHEAD 128
#define PREPARED_UPLOAD_CAPACITY (PREPARED_UPLOAD_LOOKAHEAD + 1)
#define UPLOAD_TIME_WINDOW_SIZE 30
#define PROCESS_STAGE_IDLE 0
#define PROCESS_STAGE_CHECKSUM 1
#define PROCESS_STAGE_LOCAL_CHECK 2
#define PROCESS_STAGE_SERVER_CHECK 3
#define PROCESS_STAGE_UPLOAD 4
#define PROCESS_STAGE_RETRY_WAIT 5
#define PROCESS_STAGE_BUFFER_WAIT 6
#define UPLOAD_STAGE_IDLE 0
#define UPLOAD_STAGE_SERVER_CHECK 1
#define UPLOAD_STAGE_BODY 2

typedef unsigned __int64 U64;

#ifndef NOTIFYICON_VERSION
#define NOTIFYICON_VERSION 3
#endif

typedef struct QueueItem {
    DWORD id;
    DWORD volumeSerial;
    U64 modifiedTime;
    BYTE volumeSerialKnown;
    char path[MAX_PATH];
} QueueItem;

typedef struct PreparedUpload {
    DWORD queueId;
    DWORD notBefore;
    DWORD volumeSerial;
    U64 modifiedTime;
    U64 sizeBytes;
    BYTE volumeSerialKnown;
    char hash[65];
    char path[MAX_PATH];
} PreparedUpload;

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
    HWND statsPauseButton;
    HICON registerLogoIcon;
    HFONT uiFont;
    HFONT boldUiFont;
    HANDLE instanceMutex;
    HANDLE processorThread;
    HANDLE uploaderThread;
    CRITICAL_SECTION lock;
    HANDLE queueEvent;
    HANDLE uploadQueueEvent;
    HANDLE uploadSpaceEvent;
    HANDLE stopEvent;
    QueueItem *queue;
    DWORD queueCount;
    DWORD queueCapacity;
    PreparedUpload prepared[PREPARED_UPLOAD_CAPACITY];
    DWORD preparedCount;
    char processingPath[MAX_PATH];
    char uploadPath[MAX_PATH];
    DWORD processingQueueId;
    DWORD uploadQueueId;
    DWORD processingVolumeSerial;
    DWORD uploadVolumeSerial;
    BYTE processingVolumeSerialKnown;
    BYTE uploadVolumeSerialKnown;
    LONG totalFound;
    LONG totalUploaded;
    LONG totalKnown;
    LONG totalFailed;
    LONG totalVerificationFailed;
    LONG totalSkippedLocal;
    LONG totalRejectedOversize;
    LONG totalSourceInterrupted;
    LONG totalScannedDrives;
    LONG activeScans;
    LONG processing;
    LONG uploading;
    LONG processingStage;
    LONG uploadStage;
    LONG uploadsPaused;
    LONG shutdownRequested;
    U64 currentUploadBytesSent;
    U64 currentUploadTotalBytes;
    DWORD currentUploadStartedAt;
    DWORD currentUploadLastLoggedPercent;
    U64 uploadMillisWindow[UPLOAD_TIME_WINDOW_SIZE];
    DWORD uploadMillisWindowCount;
    DWORD uploadMillisWindowNext;
    U64 uploadMillisWindowTotal;
    U64 serverMaxRawUploadBytes;
    DWORD unavailableDriveWarningMask;
    DWORD unavailableDriveMask;
    DWORD volumeSerial[26];
    DWORD volumeRemovalGeneration[26];
    BYTE volumeSerialKnown[26];
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
    char hashAlgorithm[16];
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
static DWORD WINAPI UploaderThread(LPVOID param);
static DWORD WINAPI ScanDriveThread(LPVOID param);
static DWORD WINAPI RegisterThread(LPVOID param);
static DWORD WINAPI PingThread(LPVOID param);
static void LogMessage(const char *format, ...);
static void BuildTrayTooltip(char *tip, DWORD tipSize);
static void UpdateTrayTooltip(HWND hwnd);
static void ShowTrayBalloon(HWND hwnd, const char *title, const char *message, UINT timeoutMillis);
static void MigrateUploadedCache(void);
static void EnsureSha256HashState(void);
static void CompactQueueIfNeeded(void);
static int UploadsPaused(void);
static int ShutdownRequested(void);
static DWORD ClearUploadedHistoryCache(void);
static U64 ParseU64(const char *text);
static void RecordSuccessfulUpload(DWORD elapsedMillis);
static void SetProcessingStage(LONG stage, U64 uploadTotalBytes);
static DWORD PendingQueueCountForDrive(char letter, DWORD volumeSerial, int volumeSerialKnown, int *processing);
static int SourceDriveUnavailable(const char *path, char *letterOut);
static void WarnIfUnavailableSourceDriveHasPendingWork(const char *path);
static int ReadVolumeSerial(char letter, DWORD *serial);

static HWND ForegroundAlertOwner(void)
{
    if (g_app.statsWindow && IsWindowVisible(g_app.statsWindow)) return g_app.statsWindow;
    if (g_app.registerWindow && IsWindowVisible(g_app.registerWindow)) return g_app.registerWindow;
    return NULL;
}

static void BringAlertOwnerForward(HWND owner)
{
    if (!owner) return;
    ShowWindow(owner, SW_SHOWNORMAL);
    SetWindowPos(owner, HWND_TOPMOST, 0, 0, 0, 0, SWP_NOMOVE | SWP_NOSIZE);
    SetWindowPos(owner, HWND_NOTOPMOST, 0, 0, 0, 0, SWP_NOMOVE | SWP_NOSIZE);
    SetForegroundWindow(owner);
}

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

static void SetWindowTextIfChanged(HWND hwnd, const char *text)
{
    char current[512];
    if (!hwnd) return;
    if (!text) text = "";
    current[0] = '\0';
    GetWindowTextA(hwnd, current, sizeof(current));
    if (lstrcmpA(current, text) != 0) {
        SetWindowTextA(hwnd, text);
    }
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
        WritePrivateProfileStringA("spicebush", "hash_algorithm", "sha256", g_app.iniPath);
        WritePrivateProfileStringA("spicebush", "server_max_raw_upload_bytes", "0", g_app.iniPath);
    }
    EnsureSha256HashState();
}

static void LoadConfig(void)
{
    char serverLimit[64];
    GetPrivateProfileStringA("spicebush", "site_url", "", g_app.siteUrl, sizeof(g_app.siteUrl), g_app.iniPath);
    GetPrivateProfileStringA("spicebush", "api_url", "", g_app.apiUrl, sizeof(g_app.apiUrl), g_app.iniPath);
    GetPrivateProfileStringA("spicebush", "upload_token", "", g_app.uploadToken, sizeof(g_app.uploadToken), g_app.iniPath);
    GetPrivateProfileStringA("spicebush", "device_id", g_app.deviceId, g_app.deviceId, sizeof(g_app.deviceId), g_app.iniPath);
    GetPrivateProfileStringA("spicebush", "hash_algorithm", "", g_app.hashAlgorithm, sizeof(g_app.hashAlgorithm), g_app.iniPath);
    GetPrivateProfileStringA("spicebush", "server_max_raw_upload_bytes", "0", serverLimit, sizeof(serverLimit), g_app.iniPath);
    g_app.serverMaxRawUploadBytes = ParseU64(serverLimit);
    g_app.serverMaxRawUploadState = g_app.serverMaxRawUploadBytes > 0 ? 1 : 0;
    if (NormaliseDeviceId(g_app.deviceId, sizeof(g_app.deviceId))) {
        WritePrivateProfileStringA("spicebush", "device_id", g_app.deviceId, g_app.iniPath);
        LogMessage("Normalised legacy device_id prefix; device_id=%s", g_app.deviceId);
    }
    LogMessage("Loaded config: site_url=%s api_url=%s token_present=%s token_length=%u device_id=%s hash_algorithm=%s server_max_raw_upload_bytes=%I64u",
        g_app.siteUrl,
        g_app.apiUrl,
        g_app.uploadToken[0] != '\0' ? "yes" : "no",
        (unsigned)lstrlenA(g_app.uploadToken),
        g_app.deviceId,
        g_app.hashAlgorithm,
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
    WritePrivateProfileStringA("spicebush", "hash_algorithm", "sha256", g_app.iniPath);
    WritePrivateProfileStringA("spicebush", "server_max_raw_upload_bytes", serverLimit, g_app.iniPath);
    SafeCopy(g_app.hashAlgorithm, sizeof(g_app.hashAlgorithm), "sha256");
    LogMessage("Saved config: site_url=%s api_url=%s token_present=%s token_length=%u device_id=%s hash_algorithm=%s server_max_raw_upload_bytes=%I64u",
        g_app.siteUrl,
        g_app.apiUrl,
        g_app.uploadToken[0] != '\0' ? "yes" : "no",
        (unsigned)lstrlenA(g_app.uploadToken),
        g_app.deviceId,
        g_app.hashAlgorithm,
        g_app.serverMaxRawUploadBytes);
}

static void AppendLine(const char *path, const char *line)
{
    HANDLE file = CreateFileA(path, FILE_APPEND_DATA, FILE_SHARE_READ | FILE_SHARE_WRITE, NULL, OPEN_ALWAYS, FILE_ATTRIBUTE_NORMAL, NULL);
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

static DWORD DriveMaskForPath(const char *path)
{
    char letter;
    if (!path || path[0] == '\0' || path[1] != ':') return 0;
    letter = path[0];
    if (letter >= 'a' && letter <= 'z') letter = (char)(letter - 'a' + 'A');
    if (letter < 'A' || letter > 'Z') return 0;
    return 1UL << (letter - 'A');
}

static int SameVolumeIdentity(DWORD leftSerial, int leftKnown, DWORD rightSerial, int rightKnown)
{
    if (!leftKnown || !rightKnown) return 1;
    return leftSerial == rightSerial;
}

static int SourceItemAvailableLocked(const char *path, DWORD volumeSerial, int volumeSerialKnown)
{
    DWORD mask = DriveMaskForPath(path);
    int index;
    if (mask == 0) return 1;
    if ((g_app.unavailableDriveMask & mask) != 0) return 0;
    if (!volumeSerialKnown) return 1;
    index = path[0] >= 'a' && path[0] <= 'z' ? path[0] - 'a' : path[0] - 'A';
    if (index < 0 || index >= 26 || !g_app.volumeSerialKnown[index]) return 0;
    return g_app.volumeSerial[index] == volumeSerial;
}

static int QueueContainsLocked(const char *path, DWORD volumeSerial, int volumeSerialKnown, int ignoreProcessing)
{
    DWORD i;
    for (i = 0; i < g_app.queueCount; i++) {
        if (lstrcmpiA(g_app.queue[i].path, path) == 0
            && SameVolumeIdentity(g_app.queue[i].volumeSerial, g_app.queue[i].volumeSerialKnown, volumeSerial, volumeSerialKnown)) return 1;
    }
    for (i = 0; i < g_app.preparedCount; i++) {
        if (lstrcmpiA(g_app.prepared[i].path, path) == 0
            && SameVolumeIdentity(g_app.prepared[i].volumeSerial, g_app.prepared[i].volumeSerialKnown, volumeSerial, volumeSerialKnown)) return 1;
    }
    if (!ignoreProcessing && g_app.processing && lstrcmpiA(g_app.processingPath, path) == 0
        && SameVolumeIdentity(g_app.processingVolumeSerial, g_app.processingVolumeSerialKnown, volumeSerial, volumeSerialKnown)) return 1;
    if (g_app.uploading && lstrcmpiA(g_app.uploadPath, path) == 0
        && SameVolumeIdentity(g_app.uploadVolumeSerial, g_app.uploadVolumeSerialKnown, volumeSerial, volumeSerialKnown)) return 1;
    return 0;
}

static int IsHexChar(char ch)
{
    return (ch >= '0' && ch <= '9')
        || (ch >= 'a' && ch <= 'f')
        || (ch >= 'A' && ch <= 'F');
}

static int IsLegacyFnvHash(const char *hash)
{
    int i;
    if (!hash || lstrlenA(hash) != 16) return 0;
    for (i = 0; i < 16; i++) {
        if (!IsHexChar(hash[i])) return 0;
    }
    return 1;
}

static int IsSha256Hash(const char *hash)
{
    int i;
    if (!hash || lstrlenA(hash) != 64) return 0;
    for (i = 0; i < 64; i++) {
        if (!IsHexChar(hash[i])) return 0;
    }
    return 1;
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

static void AppendQueueRecord(DWORD id, const char *path, DWORD volumeSerial, int volumeSerialKnown)
{
    char line[MAX_PATH + 64];
    if (volumeSerialKnown) {
        SbSnprintf(line, sizeof(line), "%lu\t%08lX\t%s\r\n", (unsigned long)id, (unsigned long)volumeSerial, path);
    } else {
        SbSnprintf(line, sizeof(line), "%lu\t%s\r\n", (unsigned long)id, path);
    }
    AppendLine(g_app.queuePath, line);
}

static void AppendQueueDone(DWORD id, const char *result)
{
    char line[128];
    SbSnprintf(line, sizeof(line), "%lu\t%s\r\n", (unsigned long)id, result);
    EnterCriticalSection(&g_app.lock);
    AppendLine(g_app.queueDonePath, line);
    g_app.queueDoneSinceCompact++;
    LeaveCriticalSection(&g_app.lock);
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
                char storedHash[80], status[32], path[MAX_PATH];
                U64 storedSize = 0;
                DWORD photoId = 0;
                line[lineLen] = '\0';
                if (ParseUploadedLine(line, storedHash, sizeof(storedHash), &storedSize, &photoId, status, sizeof(status), path, sizeof(path))
                    && IsSha256Hash(storedHash)
                    && lstrcmpiA(storedHash, hash) == 0
                    && storedSize == sizeBytes) found = 1;
                lineLen = 0;
            } else if (lineLen + 1 < sizeof(line)) {
                line[lineLen++] = ch;
            }
        }
    }
    if (!found && lineLen > 0) {
        char storedHash[80], status[32], path[MAX_PATH];
        U64 storedSize = 0;
        DWORD photoId = 0;
        line[lineLen] = '\0';
        if (ParseUploadedLine(line, storedHash, sizeof(storedHash), &storedSize, &photoId, status, sizeof(status), path, sizeof(path))
            && IsSha256Hash(storedHash)
            && lstrcmpiA(storedHash, hash) == 0
            && storedSize == sizeBytes) found = 1;
    }
    CloseHandle(file);
    if (found) LogMessage("Local uploaded bucket dedupe hit: bucket=%s sha256=%s size=%I64u", bucket, hash, sizeBytes);
    return found;
}

static void MarkUploadedStatus(const char *hash, U64 sizeBytes, DWORD photoId, const char *status, const char *path)
{
    char bucket[MAX_PATH];
    char line[MAX_PATH + 256];
    if (!IsSha256Hash(hash)) {
        LogMessage("Ignored uploaded bucket write with non-SHA-256 hash: hash=%s", hash ? hash : "");
        return;
    }
    if (UploadedContains(hash, sizeBytes)) return;
    if (!UploadedBucketPath(hash, bucket, sizeof(bucket))) {
        LogMessage("Could not build uploaded bucket path: sha256=%s", hash);
        return;
    }
    SbSnprintf(line, sizeof(line), "%s\t%I64u\t%lu\t%s\t%s\r\n", hash, sizeBytes, (unsigned long)photoId, status, path);
    AppendLine(bucket, line);
    LogMessage("Marked uploaded bucket: sha256=%s size=%I64u photo_id=%lu status=%s path=%s", hash, sizeBytes, (unsigned long)photoId, status, path);
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

static int UploadedCacheFileHasLegacyFnvRows(const char *path, DWORD *legacyRows)
{
    HANDLE file;
    char buffer[4096], line[MAX_PATH + 256];
    DWORD got, i, lineLen = 0;
    int found = 0;

    file = CreateFileA(path, GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE, NULL, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL, NULL);
    if (file == INVALID_HANDLE_VALUE) return 0;
    while (ReadFile(file, buffer, sizeof(buffer), &got, NULL) && got > 0) {
        for (i = 0; i < got; i++) {
            char ch = buffer[i];
            if (ch == '\r') continue;
            if (ch == '\n') {
                char storedHash[80], status[32], source[MAX_PATH];
                U64 storedSize = 0;
                DWORD photoId = 0;
                line[lineLen] = '\0';
                if (lineLen > 0
                    && ParseUploadedLine(line, storedHash, sizeof(storedHash), &storedSize, &photoId, status, sizeof(status), source, sizeof(source))
                    && IsLegacyFnvHash(storedHash)) {
                    found = 1;
                    if (legacyRows) (*legacyRows)++;
                }
                lineLen = 0;
            } else if (lineLen + 1 < sizeof(line)) {
                line[lineLen++] = ch;
            }
        }
    }
    if (lineLen > 0) {
        char storedHash[80], status[32], source[MAX_PATH];
        U64 storedSize = 0;
        DWORD photoId = 0;
        line[lineLen] = '\0';
        if (ParseUploadedLine(line, storedHash, sizeof(storedHash), &storedSize, &photoId, status, sizeof(status), source, sizeof(source))
            && IsLegacyFnvHash(storedHash)) {
            found = 1;
            if (legacyRows) (*legacyRows)++;
        }
    }
    CloseHandle(file);
    return found;
}

static int UploadedCacheHasLegacyFnvRows(DWORD *legacyRows)
{
    char pattern[MAX_PATH];
    char path[MAX_PATH];
    WIN32_FIND_DATAA data;
    HANDLE find;
    int found = 0;

    if (legacyRows) *legacyRows = 0;
    if (UploadedCacheFileHasLegacyFnvRows(g_app.uploadedPath, legacyRows)) {
        found = 1;
    }

    PathJoin(pattern, sizeof(pattern), g_app.uploadedDir, "*.tsv");
    find = FindFirstFileA(pattern, &data);
    if (find == INVALID_HANDLE_VALUE) return found;
    do {
        if (data.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) continue;
        PathJoin(path, sizeof(path), g_app.uploadedDir, data.cFileName);
        if (UploadedCacheFileHasLegacyFnvRows(path, legacyRows)) {
            found = 1;
        }
    } while (FindNextFileA(find, &data));
    FindClose(find);
    return found;
}

static DWORD DeleteQueueStateFiles(void)
{
    DWORD deleted = 0;
    DWORD failed = 0;
    const char *paths[3];
    int i;

    paths[0] = g_app.queuePath;
    paths[1] = g_app.queueDonePath;
    paths[2] = g_app.queueNextIdPath;
    for (i = 0; i < 3; i++) {
        if (GetFileAttributesA(paths[i]) == INVALID_FILE_ATTRIBUTES) continue;
        if (DeleteFileA(paths[i])) {
            deleted++;
            LogMessage("Deleted legacy queue state file: path=%s", paths[i]);
        } else {
            failed++;
            LogMessage("Could not delete legacy queue state file: path=%s error=%lu", paths[i], GetLastError());
        }
    }
    LogMessage("Legacy queue state clear complete: deleted=%lu failed=%lu", (unsigned long)deleted, (unsigned long)failed);
    return deleted;
}

static void EnsureSha256HashState(void)
{
    char hashAlgorithm[16];
    DWORD legacyRows = 0;
    DWORD deletedQueueFiles = 0;
    DWORD deletedUploadedFiles = 0;
    int missingOrOldMarker;
    int hasLegacyRows;

    GetPrivateProfileStringA("spicebush", "hash_algorithm", "", hashAlgorithm, sizeof(hashAlgorithm), g_app.iniPath);
    missingOrOldMarker = lstrcmpiA(hashAlgorithm, "sha256") != 0;
    hasLegacyRows = UploadedCacheHasLegacyFnvRows(&legacyRows);
    if (!missingOrOldMarker && !hasLegacyRows) {
        SafeCopy(g_app.hashAlgorithm, sizeof(g_app.hashAlgorithm), "sha256");
        return;
    }

    LogMessage(
        "Legacy hash state detected; resetting queue and uploaded cache for SHA-256: marker=%s legacy_rows=%lu",
        hashAlgorithm[0] ? hashAlgorithm : "(missing)",
        (unsigned long)legacyRows);
    deletedQueueFiles = DeleteQueueStateFiles();
    deletedUploadedFiles = ClearUploadedHistoryCache();
    g_app.nextQueueId = 1;
    SaveNextQueueId();
    WritePrivateProfileStringA("spicebush", "hash_algorithm", "sha256", g_app.iniPath);
    SafeCopy(g_app.hashAlgorithm, sizeof(g_app.hashAlgorithm), "sha256");
    LogMessage(
        "SHA-256 state reset complete: queue_files_deleted=%lu uploaded_files_deleted=%lu next_queue_id=1",
        (unsigned long)deletedQueueFiles,
        (unsigned long)deletedUploadedFiles);
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
                char storedHash[80], status[32], source[MAX_PATH];
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
        char storedHash[80], status[32], source[MAX_PATH];
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

static U64 FileModifiedTimeOrZero(const char *path)
{
    WIN32_FILE_ATTRIBUTE_DATA data;
    ULARGE_INTEGER modified;
    if (!GetFileAttributesExA(path, GetFileExInfoStandard, &data)) return 0;
    modified.LowPart = data.ftLastWriteTime.dwLowDateTime;
    modified.HighPart = data.ftLastWriteTime.dwHighDateTime;
    return modified.QuadPart;
}

static int QueuePushInternal(
    DWORD id,
    const char *path,
    int persist,
    int countFound,
    int newestFirst,
    int requeue,
    DWORD volumeSerial,
    int volumeSerialKnown
)
{
    int result = 0;
    int volumeIndex = -1;
    DWORD observedSerial = 0;
    int observedKnown = 0;
    DWORD queueCount = 0;
    DWORD queueCapacity = 0;
    U64 modifiedTime = newestFirst ? FileModifiedTimeOrZero(path) : 0;
    if (id == 0) id = AllocateQueueId();
    if (DriveMaskForPath(path) != 0) {
        char letter = path[0];
        if (letter >= 'a' && letter <= 'z') letter = (char)(letter - 'a' + 'A');
        volumeIndex = letter - 'A';
        EnterCriticalSection(&g_app.lock);
        if (volumeIndex >= 0 && volumeIndex < 26
            && g_app.volumeSerialKnown[volumeIndex]
            && (g_app.unavailableDriveMask & (1UL << volumeIndex)) == 0) {
            observedSerial = g_app.volumeSerial[volumeIndex];
            observedKnown = 1;
        }
        LeaveCriticalSection(&g_app.lock);
        if (!observedKnown) observedKnown = ReadVolumeSerial(letter, &observedSerial);
        if (!volumeSerialKnown && observedKnown) {
            volumeSerial = observedSerial;
            volumeSerialKnown = 1;
        }
    }
    EnterCriticalSection(&g_app.lock);
    if (volumeIndex >= 0 && volumeIndex < 26 && observedKnown) {
        g_app.volumeSerial[volumeIndex] = observedSerial;
        g_app.volumeSerialKnown[volumeIndex] = 1;
        g_app.unavailableDriveMask &= ~(1UL << volumeIndex);
    }
    if (!QueueContainsLocked(path, volumeSerial, volumeSerialKnown, requeue)) {
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
            DWORD insertIndex = g_app.queueCount;
            if (newestFirst && modifiedTime > 0) {
                while (insertIndex > 0 && g_app.queue[insertIndex - 1].modifiedTime < modifiedTime) {
                    g_app.queue[insertIndex] = g_app.queue[insertIndex - 1];
                    insertIndex--;
                }
            }
            g_app.queue[insertIndex].id = id;
            g_app.queue[insertIndex].volumeSerial = volumeSerial;
            g_app.queue[insertIndex].modifiedTime = modifiedTime;
            g_app.queue[insertIndex].volumeSerialKnown = (BYTE)(volumeSerialKnown ? 1 : 0);
            SafeCopy(g_app.queue[insertIndex].path, sizeof(g_app.queue[insertIndex].path), path);
            g_app.queueCount++;
            if (persist) {
                AppendQueueRecord(id, path, volumeSerial, volumeSerialKnown);
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
        LogMessage("Queue add: id=%lu path=%s persist=%s count_found=%s priority=%s modified_time=%I64u volume_serial=%s%08lx",
            (unsigned long)id,
            path,
            persist ? "yes" : "no",
            countFound ? "yes" : "no",
            newestFirst ? "newest_first" : "back",
            modifiedTime,
            volumeSerialKnown ? "" : "unknown/",
            (unsigned long)volumeSerial);
    } else if (result == -1) {
        LogMessage("Queue duplicate suppressed: path=%s", path);
    } else {
        LogMessage("Queue add failed: path=%s capacity=%lu count=%lu", path, (unsigned long)queueCapacity, (unsigned long)queueCount);
    }
    return result;
}

static void QueuePush(const char *path)
{
    QueuePushInternal(0, path, 1, 1, 1, 0, 0, 0);
}

static void QueueRequeue(DWORD id, const char *path, DWORD volumeSerial, int volumeSerialKnown)
{
    QueuePushInternal(id, path, 0, 0, 0, 1, volumeSerial, volumeSerialKnown);
}

static int QueuePop(QueueItem *item)
{
    DWORD i, selected = (DWORD)-1;
    DWORD remaining = 0;
    int hasItem = 0;
    EnterCriticalSection(&g_app.lock);
    for (i = 0; i < g_app.queueCount; i++) {
        if (SourceItemAvailableLocked(g_app.queue[i].path, g_app.queue[i].volumeSerial, g_app.queue[i].volumeSerialKnown)) {
            selected = i;
            break;
        }
    }
    if (selected != (DWORD)-1) {
        if (item) *item = g_app.queue[selected];
        for (i = selected + 1; i < g_app.queueCount; i++) {
            g_app.queue[i - 1] = g_app.queue[i];
        }
        g_app.queueCount--;
        remaining = g_app.queueCount;
        hasItem = 1;
    }
    LeaveCriticalSection(&g_app.lock);
    if (hasItem && item) LogMessage("Queue pop: id=%lu path=%s volume_serial=%s%08lx remaining=%lu",
        (unsigned long)item->id,
        item->path,
        item->volumeSerialKnown ? "" : "unknown/",
        (unsigned long)item->volumeSerial,
        (unsigned long)remaining);
    return hasItem;
}

static int PreparedPush(const PreparedUpload *item, int retry, int reportVerificationWait)
{
    DWORD limit = retry ? PREPARED_UPLOAD_CAPACITY : PREPARED_UPLOAD_LOOKAHEAD;
    HANDLE waits[2];
    int waitReported = 0;
    if (!item) return 0;
    waits[0] = g_app.stopEvent;
    waits[1] = g_app.uploadSpaceEvent;
    while (!ShutdownRequested()) {
        int pushed = 0;
        EnterCriticalSection(&g_app.lock);
        if (g_app.preparedCount < limit) {
            g_app.prepared[g_app.preparedCount++] = *item;
            pushed = 1;
        }
        LeaveCriticalSection(&g_app.lock);
        if (pushed) {
            SetEvent(g_app.uploadQueueEvent);
            PostMessageA(g_app.mainWindow, WM_REFRESH_STATS, 0, 0);
            return 1;
        }
        if (reportVerificationWait && !waitReported) {
            DWORD preparedCount;
            SetProcessingStage(PROCESS_STAGE_BUFFER_WAIT, 0);
            EnterCriticalSection(&g_app.lock);
            preparedCount = g_app.preparedCount;
            LeaveCriticalSection(&g_app.lock);
            LogMessage("Verification waiting for prepared upload buffer space: queue_id=%lu path=%s prepared_buffer=%lu capacity=%lu",
                (unsigned long)item->queueId,
                item->path,
                (unsigned long)preparedCount,
                (unsigned long)limit);
            waitReported = 1;
        }
        if (WaitForMultipleObjects(2, waits, FALSE, INFINITE) == WAIT_OBJECT_0) break;
    }
    return 0;
}

static int PreparedPop(PreparedUpload *item, DWORD *waitMillis)
{
    DWORD i, selected = (DWORD)-1, now = GetTickCount(), shortest = INFINITE;
    if (waitMillis) *waitMillis = INFINITE;
    EnterCriticalSection(&g_app.lock);
    for (i = 0; i < g_app.preparedCount; i++) {
        DWORD delay;
        if (!SourceItemAvailableLocked(g_app.prepared[i].path, g_app.prepared[i].volumeSerial, g_app.prepared[i].volumeSerialKnown)) continue;
        if ((LONG)(now - g_app.prepared[i].notBefore) >= 0) {
            selected = i;
            break;
        }
        delay = g_app.prepared[i].notBefore - now;
        if (delay < shortest) shortest = delay;
    }
    if (selected != (DWORD)-1) {
        *item = g_app.prepared[selected];
        for (i = selected + 1; i < g_app.preparedCount; i++) {
            g_app.prepared[i - 1] = g_app.prepared[i];
        }
        g_app.preparedCount--;
    }
    LeaveCriticalSection(&g_app.lock);
    if (selected != (DWORD)-1) {
        SetEvent(g_app.uploadSpaceEvent);
        return 1;
    }
    if (waitMillis) *waitMillis = shortest;
    return 0;
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

static int ParseQueueLine(
    char *line,
    DWORD *id,
    char *path,
    DWORD pathSize,
    DWORD *volumeSerial,
    int *volumeSerialKnown,
    int assignLegacyId
)
{
    char *tab;
    char *secondTab;
    char *end;
    int i;
    if (id) *id = 0;
    if (volumeSerial) *volumeSerial = 0;
    if (volumeSerialKnown) *volumeSerialKnown = 0;
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
    secondTab = strchr(tab, '\t');
    if (secondTab && secondTab - tab == 8) {
        int validSerial = 1;
        for (i = 0; i < 8; i++) {
            if (!IsHexChar(tab[i])) {
                validSerial = 0;
                break;
            }
        }
        if (validSerial) {
            *secondTab++ = '\0';
            if (volumeSerial) *volumeSerial = strtoul(tab, NULL, 16);
            if (volumeSerialKnown) *volumeSerialKnown = 1;
            tab = secondTab;
        }
    }
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
                DWORD volumeSerial = 0;
                int volumeSerialKnown = 0;
                char path[MAX_PATH];
                line[lineLen] = '\0';
                if (lineLen > 0 && strchr(line, '\t') == NULL) legacyRows++;
                if (lineLen > 0 && ParseQueueLine(line, &id, path, sizeof(path), &volumeSerial, &volumeSerialKnown, 1)) {
                    if (!volumeSerialKnown) legacyRows++;
                    if (id >= g_app.nextQueueId) g_app.nextQueueId = id + 1;
                    if (!DoneIdContains(doneIds, doneCount, id)) {
                        QueuePushInternal(id, path, 0, 0, 1, 0, volumeSerial, volumeSerialKnown);
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
        DWORD volumeSerial = 0;
        int volumeSerialKnown = 0;
        char path[MAX_PATH];
        line[lineLen] = '\0';
        if (strchr(line, '\t') == NULL) legacyRows++;
        if (ParseQueueLine(line, &id, path, sizeof(path), &volumeSerial, &volumeSerialKnown, 1)) {
            if (!volumeSerialKnown) legacyRows++;
            if (id >= g_app.nextQueueId) g_app.nextQueueId = id + 1;
            if (!DoneIdContains(doneIds, doneCount, id)) {
                QueuePushInternal(id, path, 0, 0, 1, 0, volumeSerial, volumeSerialKnown);
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

static int WritePendingQueueLine(HANDLE file, DWORD id, const char *path, DWORD volumeSerial, int volumeSerialKnown)
{
    char line[MAX_PATH + 64];
    DWORD written;
    DWORD length;
    if (volumeSerialKnown) {
        SbSnprintf(line, sizeof(line), "%lu\t%08lX\t%s\r\n", (unsigned long)id, (unsigned long)volumeSerial, path);
    } else {
        SbSnprintf(line, sizeof(line), "%lu\t%s\r\n", (unsigned long)id, path);
    }
    length = (DWORD)lstrlenA(line);
    return WriteFile(file, line, length, &written, NULL) != 0 && written == length;
}

static void CompactQueueIfNeeded(void)
{
    char tmp[MAX_PATH];
    HANDLE file;
    HANDLE doneFile;
    DWORD i;
    DWORD pendingCount = 0;
    DWORD doneCount = 0;
    DWORD *doneIds = NULL;
    U64 doneSize;

    doneSize = FileSizeOrZero(g_app.queueDonePath);
    if (g_app.queueDoneSinceCompact < 1000 && doneSize <= 1048576ULL) return;

    PathJoin(tmp, sizeof(tmp), g_app.appDir, "queue.tmp");
    EnterCriticalSection(&g_app.lock);
    doneIds = LoadDoneIds(&doneCount);
    if (doneSize > 0 && !doneIds) {
        LeaveCriticalSection(&g_app.lock);
        LogMessage("Queue compaction deferred: completion journal could not be loaded safely.");
        return;
    }
    file = CreateFileA(tmp, GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, FILE_ATTRIBUTE_NORMAL, NULL);
    if (file == INVALID_HANDLE_VALUE) {
        if (doneIds) HeapFree(GetProcessHeap(), 0, doneIds);
        LeaveCriticalSection(&g_app.lock);
        LogMessage("Queue compaction failed: could not create temp path=%s error=%lu", tmp, GetLastError());
        return;
    }
    for (i = 0; i < g_app.queueCount; i++) {
        if (DoneIdContains(doneIds, doneCount, g_app.queue[i].id)) continue;
        if (!WritePendingQueueLine(file, g_app.queue[i].id, g_app.queue[i].path, g_app.queue[i].volumeSerial, g_app.queue[i].volumeSerialKnown)) {
            CloseHandle(file);
            DeleteFileA(tmp);
            if (doneIds) HeapFree(GetProcessHeap(), 0, doneIds);
            LeaveCriticalSection(&g_app.lock);
            LogMessage("Queue compaction failed: write error=%lu", GetLastError());
            return;
        }
        pendingCount++;
    }
    for (i = 0; i < g_app.preparedCount; i++) {
        if (DoneIdContains(doneIds, doneCount, g_app.prepared[i].queueId)) continue;
        if (!WritePendingQueueLine(file, g_app.prepared[i].queueId, g_app.prepared[i].path, g_app.prepared[i].volumeSerial, g_app.prepared[i].volumeSerialKnown)) {
            CloseHandle(file);
            DeleteFileA(tmp);
            if (doneIds) HeapFree(GetProcessHeap(), 0, doneIds);
            LeaveCriticalSection(&g_app.lock);
            LogMessage("Queue compaction failed while writing prepared uploads: error=%lu", GetLastError());
            return;
        }
        pendingCount++;
    }
    if (g_app.processing && !DoneIdContains(doneIds, doneCount, g_app.processingQueueId)) {
        if (!WritePendingQueueLine(file, g_app.processingQueueId, g_app.processingPath, g_app.processingVolumeSerial, g_app.processingVolumeSerialKnown)) {
            CloseHandle(file);
            DeleteFileA(tmp);
            if (doneIds) HeapFree(GetProcessHeap(), 0, doneIds);
            LeaveCriticalSection(&g_app.lock);
            LogMessage("Queue compaction failed while writing active verification: error=%lu", GetLastError());
            return;
        }
        pendingCount++;
    }
    if (g_app.uploading && !DoneIdContains(doneIds, doneCount, g_app.uploadQueueId)) {
        if (!WritePendingQueueLine(file, g_app.uploadQueueId, g_app.uploadPath, g_app.uploadVolumeSerial, g_app.uploadVolumeSerialKnown)) {
            CloseHandle(file);
            DeleteFileA(tmp);
            if (doneIds) HeapFree(GetProcessHeap(), 0, doneIds);
            LeaveCriticalSection(&g_app.lock);
            LogMessage("Queue compaction failed while writing active upload: error=%lu", GetLastError());
            return;
        }
        pendingCount++;
    }
    FlushFileBuffers(file);
    CloseHandle(file);
    if (!MoveFileExA(tmp, g_app.queuePath, MOVEFILE_REPLACE_EXISTING)) {
        DeleteFileA(tmp);
        if (doneIds) HeapFree(GetProcessHeap(), 0, doneIds);
        LeaveCriticalSection(&g_app.lock);
        LogMessage("Queue compaction failed: replace error=%lu", GetLastError());
        return;
    }
    doneFile = CreateFileA(g_app.queueDonePath, GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, FILE_ATTRIBUTE_NORMAL, NULL);
    if (doneFile != INVALID_HANDLE_VALUE) CloseHandle(doneFile);
    g_app.queueDoneSinceCompact = 0;
    if (doneIds) HeapFree(GetProcessHeap(), 0, doneIds);
    LeaveCriticalSection(&g_app.lock);
    LogMessage("Queue compaction complete: pending=%lu done_size=%I64u", (unsigned long)pendingCount, doneSize);
}

static int ComputeSha256(const char *path, char *hex, DWORD hexSize, U64 *sizeBytes)
{
    sb_u64 sharedSize = 0;
    if (!sb_compute_sha256(path, hex, (size_t)hexSize, &sharedSize)) return 0;
    if (sizeBytes) *sizeBytes = (U64)sharedSize;
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

static int HttpSimpleRequestDetailed(const char *method, const char *url, const char *headers, const BYTE *body, DWORD bodyLen, DWORD *status, char *response, DWORD responseSize, char *effectiveUrl, DWORD effectiveUrlSize)
{
    ParsedUrl parsed;
    HINTERNET internet = NULL, connect = NULL, request = NULL;
    DWORD flags = INTERNET_FLAG_RELOAD | INTERNET_FLAG_NO_CACHE_WRITE;
    DWORD got, used = 0, statusSize = sizeof(DWORD);
    int ok = 0;
    if (effectiveUrl && effectiveUrlSize > 0) SafeCopy(effectiveUrl, effectiveUrlSize, url);
    if (!ParseUrl(url, &parsed)) {
        LogMessage("HTTP %s failed before send: could not parse URL %s", method, url);
        return 0;
    }
    if (parsed.secure) flags |= INTERNET_FLAG_SECURE;
    if (lstrcmpiA(method, "POST") == 0) flags |= INTERNET_FLAG_NO_AUTO_REDIRECT;
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
    if (effectiveUrl && effectiveUrlSize > 0) {
        DWORD queriedUrlSize = effectiveUrlSize;
        if (!InternetQueryOptionA(request, INTERNET_OPTION_URL, effectiveUrl, &queriedUrlSize)) {
            SafeCopy(effectiveUrl, effectiveUrlSize, url);
        } else {
            effectiveUrl[effectiveUrlSize - 1] = '\0';
        }
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

static int HttpSimpleRequest(const char *method, const char *url, const char *headers, const BYTE *body, DWORD bodyLen, DWORD *status, char *response, DWORD responseSize)
{
    return HttpSimpleRequestDetailed(method, url, headers, body, bodyLen, status, response, responseSize, NULL, 0);
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

static int JsonU64Value(const char *json, const char *key, U64 *value);

static void LogJsonStringDiagnostic(const char *label, const char *response, const char *key)
{
    char value[512];
    if (JsonStringValue(response, key, value, sizeof(value))) {
        LogMessage("%s diagnostic: %s=%s", label, key, value);
    }
}

static void LogJsonU64Diagnostic(const char *label, const char *response, const char *key)
{
    U64 value = 0;
    if (JsonU64Value(response, key, &value)) {
        LogMessage("%s diagnostic: %s=%I64u", label, key, value);
    }
}

static void LogResponseDiagnostics(const char *label, const char *response)
{
    if (!response || !response[0]) return;

    LogJsonStringDiagnostic(label, response, "storage_error");
    LogJsonStringDiagnostic(label, response, "storage_error_type");
    LogJsonStringDiagnostic(label, response, "upload_mode");
    LogJsonStringDiagnostic(label, response, "upload_token_label");
    LogJsonU64Diagnostic(label, response, "upload_token_id");
    LogJsonU64Diagnostic(label, response, "upload_token_created_by_user_id");
    LogJsonU64Diagnostic(label, response, "upload_size_bytes");
    LogJsonU64Diagnostic(label, response, "content_length");
    LogJsonU64Diagnostic(label, response, "max_raw_bytes");
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
        LogResponseDiagnostics(label, response);
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
    LogResponseDiagnostics(label, response);
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
        lstrcatA(endpoint, "/upload-register.php");
    } else {
        lstrcatA(endpoint, "/api/upload-register.php");
    }
}

static int IsRegisterEndpointUrl(const ParsedUrl *parsed)
{
    char path[2048];
    char *query;
    SafeCopy(path, sizeof(path), parsed->path);
    query = strpbrk(path, "?#");
    if (query) *query = '\0';
    return EndsWithNoCase(path, "/api/upload-register.php");
}

static int ResolveRegisterEndpoint(const char *requestedEndpoint, char *resolvedEndpoint, DWORD resolvedEndpointSize, char *errorMessage, DWORD errorMessageSize)
{
    char response[1024];
    char effectiveUrl[2048];
    DWORD status = 0;
    ParsedUrl requested;
    ParsedUrl effective;

    SafeCopy(resolvedEndpoint, resolvedEndpointSize, requestedEndpoint);
    if (errorMessage && errorMessageSize > 0) errorMessage[0] = '\0';

    if (!HttpSimpleRequestDetailed("GET", requestedEndpoint, NULL, NULL, 0, &status, response, sizeof(response), effectiveUrl, sizeof(effectiveUrl))) {
        SafeCopy(errorMessage, errorMessageSize, "Could not resolve the registration URL before sending credentials.");
        return 0;
    }
    if (lstrcmpA(requestedEndpoint, effectiveUrl) == 0) return 1;
    if (!ParseUrl(requestedEndpoint, &requested) || !ParseUrl(effectiveUrl, &effective)) {
        SafeCopy(errorMessage, errorMessageSize, "The registration URL redirected to an invalid URL.");
        return 0;
    }
    if (lstrcmpiA(requested.host, effective.host) != 0) {
        SbSnprintf(errorMessage, errorMessageSize, "The registration URL redirected to a different host. Enter the final site URL directly: %s", effectiveUrl);
        return 0;
    }
    if (requested.secure && !effective.secure) {
        SafeCopy(errorMessage, errorMessageSize, "The registration URL attempted to redirect from HTTPS to insecure HTTP.");
        return 0;
    }
    if (!IsRegisterEndpointUrl(&effective)) {
        SbSnprintf(errorMessage, errorMessageSize, "The registration URL redirected away from the SpiceBush registration API. Enter the final site URL directly: %s", effectiveUrl);
        return 0;
    }

    SafeCopy(resolvedEndpoint, resolvedEndpointSize, effectiveUrl);
    LogMessage("Registration endpoint resolved through redirect: requested=%s effective=%s", requestedEndpoint, resolvedEndpoint);
    return 1;
}

static void SiteUrlFromRegisterEndpoint(const char *endpoint, char *siteUrl, DWORD siteUrlSize)
{
    static const char suffix[] = "/api/upload-register.php";
    char *query;
    size_t length;
    SafeCopy(siteUrl, siteUrlSize, endpoint);
    query = strpbrk(siteUrl, "?#");
    if (query) *query = '\0';
    length = strlen(siteUrl);
    if (length >= sizeof(suffix) - 1 && EndsWithNoCase(siteUrl, suffix)) {
        siteUrl[length - (sizeof(suffix) - 1)] = '\0';
    }
    TrimTrailingSlashes(siteUrl);
}

static void ApiUrlFromRegisterEndpoint(const char *endpoint, char *apiUrl, DWORD apiUrlSize)
{
    static const char suffix[] = "/upload-register.php";
    char *query;
    size_t length;
    SafeCopy(apiUrl, apiUrlSize, endpoint);
    query = strpbrk(apiUrl, "?#");
    if (query) *query = '\0';
    length = strlen(apiUrl);
    if (length >= sizeof(suffix) - 1 && EndsWithNoCase(apiUrl, suffix)) {
        apiUrl[length - (sizeof(suffix) - 1)] = '\0';
    }
    TrimTrailingSlashes(apiUrl);
}

static int CheckServerKnowsFile(const char *hash, U64 sizeBytes, DWORD *photoId)
{
    char url[2048], encodedHash[128], headers[1800], response[4096];
    DWORD status = 0;
    int exists = 0;
    if (photoId) *photoId = 0;
    if (g_app.apiUrl[0] == '\0' || g_app.uploadToken[0] == '\0') return 0;
    UrlEncode(hash, encodedHash, sizeof(encodedHash));
    SbSnprintf(url, sizeof(url) - 1, "%s/upload-checksum.php?algorithm=sha256&hash=%s&size_bytes=%I64u", g_app.apiUrl, encodedHash, sizeBytes);
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
        LogMessage("Quick checksum request failed before response: sha256=%s size=%I64u", hash, sizeBytes);
        return 0;
    }
    if (status == 200 && JsonBoolValue(response, "exists", &exists) && exists) {
        JsonDwordValue(response, "photo_id", photoId);
        LogMessage("Server dedupe hit: sha256=%s size=%I64u photo_id=%lu", hash, sizeBytes, photoId ? (unsigned long)*photoId : 0);
        return 1;
    }
    LogMessage("Server dedupe miss or unavailable: status=%lu sha256=%s size=%I64u", status, hash, sizeBytes);
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

    SbSnprintf(url, sizeof(url) - 1, "%s/remote-ping.php", g_app.apiUrl);
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

static const char *ProcessingStageLabel(LONG stage)
{
    switch (stage) {
    case PROCESS_STAGE_CHECKSUM: return "Calculating checksum";
    case PROCESS_STAGE_LOCAL_CHECK: return "Checking local history";
    case PROCESS_STAGE_SERVER_CHECK: return "Checking server";
    case PROCESS_STAGE_UPLOAD: return "Uploading";
    case PROCESS_STAGE_RETRY_WAIT: return "Waiting to retry";
    case PROCESS_STAGE_BUFFER_WAIT: return "Waiting for upload buffer space";
    default: return "Processing";
    }
}

static void SetProcessingStage(LONG stage, U64 uploadTotalBytes)
{
    (void)uploadTotalBytes;
    EnterCriticalSection(&g_app.lock);
    g_app.processingStage = stage;
    LeaveCriticalSection(&g_app.lock);
    if (g_app.mainWindow) PostMessageA(g_app.mainWindow, WM_REFRESH_STATS, 0, 0);
}

static void SetUploadActive(DWORD queueId, const char *path, U64 totalBytes, DWORD volumeSerial, int volumeSerialKnown)
{
    EnterCriticalSection(&g_app.lock);
    g_app.uploadQueueId = queueId;
    g_app.uploadVolumeSerial = volumeSerial;
    g_app.uploadVolumeSerialKnown = (BYTE)(volumeSerialKnown ? 1 : 0);
    SafeCopy(g_app.uploadPath, sizeof(g_app.uploadPath), path ? path : "");
    g_app.currentUploadBytesSent = 0;
    g_app.currentUploadTotalBytes = totalBytes;
    g_app.currentUploadStartedAt = 0;
    g_app.currentUploadLastLoggedPercent = 0;
    g_app.uploadStage = UPLOAD_STAGE_SERVER_CHECK;
    g_app.uploading = 1;
    LeaveCriticalSection(&g_app.lock);
    if (g_app.mainWindow) PostMessageA(g_app.mainWindow, WM_REFRESH_STATS, 0, 0);
}

static void StartUploadBody(void)
{
    EnterCriticalSection(&g_app.lock);
    g_app.currentUploadBytesSent = 0;
    g_app.currentUploadStartedAt = GetTickCount();
    g_app.currentUploadLastLoggedPercent = 0;
    g_app.uploadStage = UPLOAD_STAGE_BODY;
    LeaveCriticalSection(&g_app.lock);
    if (g_app.mainWindow) PostMessageA(g_app.mainWindow, WM_REFRESH_STATS, 0, 0);
}

static void ClearUploadActive(void)
{
    EnterCriticalSection(&g_app.lock);
    g_app.uploading = 0;
    g_app.uploadPath[0] = '\0';
    g_app.uploadQueueId = 0;
    g_app.uploadVolumeSerial = 0;
    g_app.uploadVolumeSerialKnown = 0;
    g_app.currentUploadBytesSent = 0;
    g_app.currentUploadTotalBytes = 0;
    g_app.currentUploadStartedAt = 0;
    g_app.currentUploadLastLoggedPercent = 0;
    g_app.uploadStage = UPLOAD_STAGE_IDLE;
    LeaveCriticalSection(&g_app.lock);
    if (g_app.mainWindow) PostMessageA(g_app.mainWindow, WM_REFRESH_STATS, 0, 0);
}

static int IsSourceInterruptionError(const char *path, DWORD error)
{
    if (DriveMaskForPath(path) == 0) return 0;
    if (SourceDriveUnavailable(path, NULL)) return 1;
    return error == ERROR_FILE_INVALID
        || error == ERROR_DEVICE_NOT_CONNECTED
        || error == ERROR_NOT_READY
        || error == ERROR_MEDIA_CHANGED
        || error == ERROR_UNRECOGNIZED_MEDIA;
}

static void MarkSourceUnavailable(const char *path)
{
    DWORD mask = DriveMaskForPath(path);
    if (mask == 0) return;
    EnterCriticalSection(&g_app.lock);
    g_app.unavailableDriveMask |= mask;
    LeaveCriticalSection(&g_app.lock);
    WarnIfUnavailableSourceDriveHasPendingWork(path);
}

static DWORD SourceRemovalGeneration(const char *path)
{
    DWORD mask = DriveMaskForPath(path);
    DWORD generation = 0;
    int i;
    if (mask == 0) return 0;
    for (i = 0; i < 26; i++) {
        if (mask & (1UL << i)) {
            EnterCriticalSection(&g_app.lock);
            generation = g_app.volumeRemovalGeneration[i];
            LeaveCriticalSection(&g_app.lock);
            break;
        }
    }
    return generation;
}

static void AddCurrentUploadBytes(DWORD bytesWritten)
{
    U64 bytesSent;
    U64 totalBytes;
    DWORD percent = 0;
    int logProgress = 0;
    EnterCriticalSection(&g_app.lock);
    g_app.currentUploadBytesSent += (U64)bytesWritten;
    if (g_app.currentUploadBytesSent > g_app.currentUploadTotalBytes) {
        g_app.currentUploadBytesSent = g_app.currentUploadTotalBytes;
    }
    bytesSent = g_app.currentUploadBytesSent;
    totalBytes = g_app.currentUploadTotalBytes;
    if (totalBytes > 0) {
        percent = (DWORD)((bytesSent * 100ULL) / totalBytes);
        if (percent >= g_app.currentUploadLastLoggedPercent + 10 || bytesSent >= totalBytes) {
            g_app.currentUploadLastLoggedPercent = percent;
            logProgress = 1;
        }
    }
    LeaveCriticalSection(&g_app.lock);
    if (logProgress) {
        LogMessage("Raw upload progress: bytes_sent=%I64u total_bytes=%I64u percent=%lu", bytesSent, totalBytes, (unsigned long)percent);
    }
}

static int UploadFileRaw(const char *path, const char *hash, U64 sizeBytes)
{
    ParsedUrl parsed;
    char url[2048], headers[1800], filename[MAX_PATH], response[4096];
    HINTERNET internet = NULL, connect = NULL, request = NULL;
    HANDLE file = INVALID_HANDLE_VALUE;
    INTERNET_BUFFERSA buffers;
    DWORD flags = INTERNET_FLAG_RELOAD | INTERNET_FLAG_NO_CACHE_WRITE;
    DWORD got, wrote, status = 0, statusSize = sizeof(DWORD), used = 0;
    DWORD uploadStart = 0, headerMs = 0, bodyMs = 0, endRequestMs = 0, responseMs = 0;
    DWORD phaseStart = 0, writeRemaining = 0;
    DWORD fileError = ERROR_SUCCESS;
    BOOL readOk;
    BYTE *buf = NULL;
    BYTE *writePtr = NULL;
    int ok = 0;
    int duplicate = 0;
    int result = RAW_UPLOAD_RETRY;
    DWORD photoId = 0;
    U64 throughputMbpsX10 = 0;
    const char *slash = strrchr(path, '\\');
    SafeCopy(filename, sizeof(filename), slash ? slash + 1 : path);
    SbSnprintf(url, sizeof(url) - 1, "%s/upload-raw.php", g_app.apiUrl);
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
        "X-Swallowtail-Checksum-SHA256: %s\r\n"
        "X-Swallowtail-Device-ID: %s\r\n"
        "X-Requested-With: XMLHttpRequest\r\n",
        g_app.uploadToken, g_app.uploadToken, filename, hash, g_app.deviceId);
    headers[sizeof(headers) - 1] = '\0';
    LogMessage("Raw upload request prepared: url=%s filename=%s token_present=%s token_length=%u auth_header=yes fallback_header=yes",
        url,
        filename,
        g_app.uploadToken[0] != '\0' ? "yes" : "no",
        (unsigned)lstrlenA(g_app.uploadToken));

    buf = (BYTE *)HeapAlloc(GetProcessHeap(), 0, RAW_UPLOAD_BUFFER_BYTES);
    if (!buf) {
        LogMessage("Raw upload failed before send: could not allocate upload buffer bytes=%lu error=%lu",
            (unsigned long)RAW_UPLOAD_BUFFER_BYTES,
            GetLastError());
        goto done;
    }
    file = CreateFileA(path, GENERIC_READ, FILE_SHARE_READ, NULL, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL | FILE_FLAG_SEQUENTIAL_SCAN, NULL);
    if (file == INVALID_HANDLE_VALUE) {
        fileError = GetLastError();
        LogMessage("Raw upload failed before send: could not open file path=%s error=%lu", path, fileError);
        if (IsSourceInterruptionError(path, fileError)) result = RAW_UPLOAD_SOURCE_INTERRUPTED;
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
    uploadStart = GetTickCount();
    phaseStart = uploadStart;
    if (!HttpSendRequestExA(request, &buffers, NULL, 0, 0)) {
        LogMessage("Raw upload failed before body send: HttpSendRequestEx error=%lu header_length=%u total_bytes=%I64u",
            GetLastError(),
            (unsigned)buffers.dwHeadersLength,
            sizeBytes);
        goto done;
    }
    headerMs = GetTickCount() - phaseStart;
    phaseStart = GetTickCount();
    while ((readOk = ReadFile(file, buf, RAW_UPLOAD_BUFFER_BYTES, &got, NULL)) && got > 0) {
        writePtr = buf;
        writeRemaining = got;
        while (writeRemaining > 0) {
            DWORD writeChunk = writeRemaining > RAW_UPLOAD_WRITE_CHUNK_BYTES ? RAW_UPLOAD_WRITE_CHUNK_BYTES : writeRemaining;
            wrote = 0;
            if (!InternetWriteFile(request, writePtr, writeChunk, &wrote) || wrote == 0) {
                LogMessage("Raw upload failed during body send: wrote=%lu expected_remaining=%lu error=%lu", wrote, writeRemaining, GetLastError());
                goto done;
            }
            writePtr += wrote;
            writeRemaining -= wrote;
            AddCurrentUploadBytes(wrote);
        }
    }
    bodyMs = GetTickCount() - phaseStart;
    if (!readOk) {
        fileError = GetLastError();
        LogMessage("Raw upload interrupted during file read: path=%s error=%lu", path, fileError);
        if (IsSourceInterruptionError(path, fileError)) result = RAW_UPLOAD_SOURCE_INTERRUPTED;
        goto done;
    }
    phaseStart = GetTickCount();
    if (!HttpEndRequestA(request, NULL, 0, 0)) {
        LogMessage("Raw upload failed after body send: HttpEndRequest error=%lu", GetLastError());
        goto done;
    }
    endRequestMs = GetTickCount() - phaseStart;
    phaseStart = GetTickCount();
    HttpQueryInfoA(request, HTTP_QUERY_STATUS_CODE | HTTP_QUERY_FLAG_NUMBER, &status, &statusSize, NULL);
    while (used + 1 < sizeof(response) && InternetReadFile(request, response + used, sizeof(response) - used - 1, &got) && got > 0) {
        used += got;
        response[used] = '\0';
    }
    responseMs = GetTickCount() - phaseStart;
    if (bodyMs > 0) {
        throughputMbpsX10 = (sizeBytes * 80ULL) / ((U64)bodyMs * 1000ULL);
    }
    LogMessage("Raw upload timings: filename=%s bytes=%I64u buffer_bytes=%lu send_headers_ms=%lu body_ms=%lu end_request_ms=%lu response_ms=%lu throughput_mbps=%I64u.%I64u",
        filename,
        sizeBytes,
        (unsigned long)RAW_UPLOAD_BUFFER_BYTES,
        headerMs,
        bodyMs,
        endRequestMs,
        responseMs,
        throughputMbpsX10 / 10ULL,
        throughputMbpsX10 % 10ULL);
    ok = (status == 200 || status == 201) && strstr(response, "\"success\":true") != NULL;
    if (ok) {
        JsonDwordValue(response, "photo_id", &photoId);
        JsonBoolValue(response, "duplicate", &duplicate);
    }
    LogMessage("Raw upload completed: status=%lu ok=%s response_bytes=%lu path=%s sha256=%s size=%I64u",
        status,
        ok ? "yes" : "no",
        used,
        path,
        hash,
        sizeBytes);
    if (!ok) {
        char errorText[512];
        errorText[0] = '\0';
        JsonFirstArrayStringValue(response, "errors", errorText, sizeof(errorText));
        if (status == 413 || strstr(errorText, "exceeded the configured size limit") != NULL || strstr(errorText, "exceeded the configured upload limit") != NULL) {
            result = RAW_UPLOAD_REJECT_OVERSIZE;
        }
        LogResponseSummary("Raw upload", status, response);
    }
done:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    if (buf) HeapFree(GetProcessHeap(), 0, buf);
    if (request) InternetCloseHandle(request);
    if (connect) InternetCloseHandle(connect);
    if (internet) InternetCloseHandle(internet);
    if (ok) {
        MarkUploadedStatus(hash, sizeBytes, photoId, duplicate ? "duplicate" : "uploaded", path);
        result = RAW_UPLOAD_OK;
    }
    return result;
}

static void ProcessPath(DWORD queueId, const char *path, DWORD volumeSerial, int volumeSerialKnown)
{
    PreparedUpload prepared;
    char hash[65];
    U64 sizeBytes = 0;
    U64 maxRawUploadBytes = 0;
    int serverMaxRawUploadState = 0;
    DWORD photoId = 0;
    DWORD sourceGeneration = SourceRemovalGeneration(path);
    LogMessage("Verification start: queue_id=%lu path=%s", (unsigned long)queueId, path);
    SetProcessingStage(PROCESS_STAGE_CHECKSUM, 0);
    if (!ComputeSha256(path, hash, sizeof(hash), &sizeBytes)) {
        if (SourceDriveUnavailable(path, NULL) || SourceRemovalGeneration(path) != sourceGeneration) {
            InterlockedIncrement(&g_app.totalSourceInterrupted);
            LogMessage("Verification interrupted: source unavailable during SHA-256 path=%s", path);
            MarkSourceUnavailable(path);
            QueueRequeue(queueId, path, volumeSerial, volumeSerialKnown);
            return;
        }
        InterlockedIncrement(&g_app.totalVerificationFailed);
        LogMessage("Verification failed permanently: could not calculate SHA-256 path=%s", path);
        AppendQueueDone(queueId, "failed_permanent");
        CompactQueueIfNeeded();
        return;
    }
    LogMessage("Verification SHA-256 complete: path=%s sha256=%s size=%I64u", path, hash, sizeBytes);
    SetProcessingStage(PROCESS_STAGE_LOCAL_CHECK, 0);
    if (UploadedContains(hash, sizeBytes)) {
        InterlockedIncrement(&g_app.totalSkippedLocal);
        LogMessage("Verification resolved by local history: path=%s sha256=%s size=%I64u", path, hash, sizeBytes);
        AppendQueueDone(queueId, "local_duplicate");
        CompactQueueIfNeeded();
        return;
    }
    SetProcessingStage(PROCESS_STAGE_SERVER_CHECK, 0);
    if (CheckServerKnowsFile(hash, sizeBytes, &photoId)) {
        MarkUploadedStatus(hash, sizeBytes, photoId, "server_known", path);
        InterlockedIncrement(&g_app.totalKnown);
        LogMessage("Verification resolved by server dedupe: path=%s sha256=%s size=%I64u", path, hash, sizeBytes);
        AppendQueueDone(queueId, "server_known");
        CompactQueueIfNeeded();
        return;
    }

    SetProcessingStage(PROCESS_STAGE_SERVER_CHECK, 0);
    EnterCriticalSection(&g_app.lock);
    serverMaxRawUploadState = g_app.serverMaxRawUploadState;
    maxRawUploadBytes = g_app.serverMaxRawUploadBytes;
    LeaveCriticalSection(&g_app.lock);
    if (serverMaxRawUploadState == 0) {
        char pingError[256];
        if (!PerformPingCheck(&maxRawUploadBytes, pingError, sizeof(pingError))) {
            LogMessage("Verification could not refresh upload limit: %s", pingError);
        }
        EnterCriticalSection(&g_app.lock);
        serverMaxRawUploadState = g_app.serverMaxRawUploadState;
        maxRawUploadBytes = g_app.serverMaxRawUploadBytes;
        LeaveCriticalSection(&g_app.lock);
    }
    if (serverMaxRawUploadState > 0 && maxRawUploadBytes > 0 && sizeBytes > maxRawUploadBytes) {
        InterlockedIncrement(&g_app.totalRejectedOversize);
        LogMessage("Verification rejected over upload limit: path=%s sha256=%s size=%I64u max_raw_upload_bytes=%I64u",
            path,
            hash,
            sizeBytes,
            maxRawUploadBytes);
        AppendQueueDone(queueId, "rejected_oversize");
        CompactQueueIfNeeded();
        return;
    }

    ZeroMemory(&prepared, sizeof(prepared));
    prepared.queueId = queueId;
    prepared.volumeSerial = volumeSerial;
    prepared.volumeSerialKnown = (BYTE)(volumeSerialKnown ? 1 : 0);
    prepared.modifiedTime = FileModifiedTimeOrZero(path);
    prepared.sizeBytes = sizeBytes;
    SafeCopy(prepared.hash, sizeof(prepared.hash), hash);
    SafeCopy(prepared.path, sizeof(prepared.path), path);
    if (PreparedPush(&prepared, 0, 1)) {
        LogMessage("Verification prepared upload: queue_id=%lu path=%s sha256=%s size=%I64u prepared_buffer=%lu",
            (unsigned long)queueId,
            path,
            hash,
            sizeBytes,
            (unsigned long)g_app.preparedCount);
    } else {
        LogMessage("Verification stopped before prepared upload could be buffered: queue_id=%lu path=%s", (unsigned long)queueId, path);
    }
}

static void RecordSuccessfulUpload(DWORD elapsedMillis)
{
    U64 elapsed = (U64)elapsedMillis;

    EnterCriticalSection(&g_app.lock);
    if (g_app.uploadMillisWindowCount < UPLOAD_TIME_WINDOW_SIZE) {
        g_app.uploadMillisWindowCount++;
    } else {
        g_app.uploadMillisWindowTotal -= g_app.uploadMillisWindow[g_app.uploadMillisWindowNext];
    }
    g_app.uploadMillisWindow[g_app.uploadMillisWindowNext] = elapsed;
    g_app.uploadMillisWindowTotal += elapsed;
    g_app.uploadMillisWindowNext++;
    if (g_app.uploadMillisWindowNext >= UPLOAD_TIME_WINDOW_SIZE) {
        g_app.uploadMillisWindowNext = 0;
    }
    g_app.totalUploaded++;
    LeaveCriticalSection(&g_app.lock);
}

static DWORD WINAPI ProcessorThread(LPVOID param)
{
    HANDLE handles[2];
    QueueItem item;
    (void)param;
    handles[0] = g_app.stopEvent;
    handles[1] = g_app.queueEvent;
    for (;;) {
        DWORD wait = WaitForMultipleObjects(2, handles, FALSE, INFINITE);
        if (wait == WAIT_OBJECT_0) break;
        while (!ShutdownRequested() && !UploadsPaused() && QueuePop(&item)) {
            EnterCriticalSection(&g_app.lock);
            g_app.processingQueueId = item.id;
            g_app.processingVolumeSerial = item.volumeSerial;
            g_app.processingVolumeSerialKnown = item.volumeSerialKnown;
            SafeCopy(g_app.processingPath, sizeof(g_app.processingPath), item.path);
            g_app.processing = 1;
            LeaveCriticalSection(&g_app.lock);
            ProcessPath(item.id, item.path, item.volumeSerial, item.volumeSerialKnown);
            EnterCriticalSection(&g_app.lock);
            g_app.processing = 0;
            g_app.processingPath[0] = '\0';
            g_app.processingQueueId = 0;
            g_app.processingVolumeSerial = 0;
            g_app.processingVolumeSerialKnown = 0;
            LeaveCriticalSection(&g_app.lock);
            SetProcessingStage(PROCESS_STAGE_IDLE, 0);
            if (WaitForSingleObject(g_app.stopEvent, 0) == WAIT_OBJECT_0) return 0;
        }
    }
    return 0;
}

static DWORD WINAPI UploaderThread(LPVOID param)
{
    HANDLE handles[2];
    PreparedUpload item;
    (void)param;
    handles[0] = g_app.stopEvent;
    handles[1] = g_app.uploadQueueEvent;
    for (;;) {
        DWORD waitMillis = INFINITE;
        DWORD wait;
        while (!ShutdownRequested() && !UploadsPaused() && PreparedPop(&item, &waitMillis)) {
            DWORD photoId = 0;
            DWORD started;
            int uploadResult;
            SetUploadActive(item.queueId, item.path, item.sizeBytes, item.volumeSerial, item.volumeSerialKnown);
            LogMessage("Upload worker final checksum recheck: queue_id=%lu path=%s sha256=%s size=%I64u",
                (unsigned long)item.queueId, item.path, item.hash, item.sizeBytes);
            if (CheckServerKnowsFile(item.hash, item.sizeBytes, &photoId)) {
                MarkUploadedStatus(item.hash, item.sizeBytes, photoId, "server_known", item.path);
                InterlockedIncrement(&g_app.totalKnown);
                LogMessage("Upload avoided by final server dedupe: path=%s sha256=%s size=%I64u", item.path, item.hash, item.sizeBytes);
                AppendQueueDone(item.queueId, "server_known_final");
                CompactQueueIfNeeded();
                ClearUploadActive();
                continue;
            }
            started = GetTickCount();
            StartUploadBody();
            uploadResult = UploadFileRaw(item.path, item.hash, item.sizeBytes);
            if (uploadResult == RAW_UPLOAD_OK) {
                DWORD elapsed = GetTickCount() - started;
                RecordSuccessfulUpload(elapsed);
                LogMessage("Upload worker completed: path=%s sha256=%s size=%I64u elapsed_ms=%lu", item.path, item.hash, item.sizeBytes, (unsigned long)elapsed);
                AppendQueueDone(item.queueId, "uploaded");
                CompactQueueIfNeeded();
            } else if (uploadResult == RAW_UPLOAD_REJECT_OVERSIZE) {
                InterlockedIncrement(&g_app.totalRejectedOversize);
                LogMessage("Upload worker rejected by server limit: path=%s sha256=%s size=%I64u", item.path, item.hash, item.sizeBytes);
                AppendQueueDone(item.queueId, "rejected_oversize");
                CompactQueueIfNeeded();
            } else if (uploadResult == RAW_UPLOAD_SOURCE_INTERRUPTED) {
                InterlockedIncrement(&g_app.totalSourceInterrupted);
                item.notBefore = 0;
                LogMessage("Upload source interrupted; returning to prepared queue without failure: path=%s sha256=%s size=%I64u", item.path, item.hash, item.sizeBytes);
                if (SourceDriveUnavailable(item.path, NULL)) MarkSourceUnavailable(item.path);
                PreparedPush(&item, 1, 0);
            } else {
                InterlockedIncrement(&g_app.totalFailed);
                item.notBefore = GetTickCount() + RAW_UPLOAD_RETRY_DELAY_MS;
                LogMessage("Network/server upload failed; retry moved to back: path=%s sha256=%s size=%I64u delay_ms=%lu",
                    item.path, item.hash, item.sizeBytes, (unsigned long)RAW_UPLOAD_RETRY_DELAY_MS);
                PreparedPush(&item, 1, 0);
            }
            ClearUploadActive();
            if (WaitForSingleObject(g_app.stopEvent, 0) == WAIT_OBJECT_0) return 0;
        }
        wait = WaitForMultipleObjects(2, handles, FALSE, waitMillis);
        if (wait == WAIT_OBJECT_0) break;
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
            queued = QueuePushInternal(0, child, 1, 1, 1, 0, 0, 0);
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

static int PathOnDrive(const char *path, char letter)
{
    char pathLetter;
    if (!path || !path[0]) return 0;
    pathLetter = path[0];
    if (pathLetter >= 'a' && pathLetter <= 'z') pathLetter = (char)(pathLetter - 'a' + 'A');
    if (letter >= 'a' && letter <= 'z') letter = (char)(letter - 'a' + 'A');
    return pathLetter == letter
        && path[1] == ':'
        && (path[2] == '\\' || path[2] == '/');
}

static int ReadVolumeSerial(char letter, DWORD *serial)
{
    char root[] = "A:\\";
    DWORD value = 0;
    root[0] = letter;
    if (!GetVolumeInformationA(root, NULL, 0, &value, NULL, NULL, NULL, 0)) return 0;
    if (serial) *serial = value;
    return 1;
}

static void RememberVolumeSerial(char letter)
{
    DWORD serial;
    int index;
    if (letter >= 'a' && letter <= 'z') letter = (char)(letter - 'a' + 'A');
    if (letter < 'A' || letter > 'Z' || !ReadVolumeSerial(letter, &serial)) return;
    index = letter - 'A';
    EnterCriticalSection(&g_app.lock);
    g_app.volumeSerial[index] = serial;
    g_app.volumeSerialKnown[index] = 1;
    g_app.unavailableDriveMask &= ~(1UL << index);
    g_app.unavailableDriveWarningMask &= ~(1UL << index);
    LeaveCriticalSection(&g_app.lock);
    if (g_app.queueEvent) SetEvent(g_app.queueEvent);
    if (g_app.uploadQueueEvent) SetEvent(g_app.uploadQueueEvent);
    LogMessage("Remembered source volume: drive=%c serial=%08lx", letter, (unsigned long)serial);
}

static int SourceDriveUnavailable(const char *path, char *letterOut)
{
    char letter;
    char root[] = "A:\\";
    UINT type;
    DWORD attrs;
    DWORD error;

    if (!path || !path[0] || path[1] != ':' || (path[2] != '\\' && path[2] != '/')) return 0;
    letter = path[0];
    if (letter >= 'a' && letter <= 'z') letter = (char)(letter - 'a' + 'A');
    if (letter < 'A' || letter > 'Z') return 0;

    root[0] = letter;
    type = GetDriveTypeA(root);
    if (type == DRIVE_NO_ROOT_DIR) {
        if (letterOut) *letterOut = letter;
        return 1;
    }

    attrs = GetFileAttributesA(root);
    if (attrs == INVALID_FILE_ATTRIBUTES) {
        error = GetLastError();
        if (type == DRIVE_UNKNOWN
            || error == ERROR_PATH_NOT_FOUND
            || error == ERROR_FILE_NOT_FOUND
            || error == ERROR_NOT_READY
            || error == ERROR_DEVICE_NOT_CONNECTED) {
            if (letterOut) *letterOut = letter;
            return 1;
        }
    }

    return 0;
}

static void ShowWarningAlert(const char *message)
{
    HWND owner = ForegroundAlertOwner();
    UINT flags = MB_ICONWARNING | MB_OK | MB_SETFOREGROUND | MB_TOPMOST | MB_TASKMODAL;

    BringAlertOwnerForward(owner);
    MessageBeep(MB_ICONWARNING);
    MessageBoxA(owner, message, APP_NAME, flags);
}

static void WarnIfUnavailableSourceDriveHasPendingWork(const char *path)
{
    char letter = '\0';
    char root[] = "A:\\";
    DWORD bit;
    DWORD pending;
    DWORD unuploaded;
    DWORD volumeSerial = 0;
    int volumeSerialKnown = 0;
    int volumeIndex;
    int processing = 0;
    int shouldWarn = 0;
    char message[512];

    if (!SourceDriveUnavailable(path, &letter)) return;
    bit = 1UL << (letter - 'A');
    volumeIndex = letter - 'A';
    root[0] = letter;

    EnterCriticalSection(&g_app.lock);
    if ((g_app.unavailableDriveWarningMask & bit) == 0) {
        g_app.unavailableDriveWarningMask |= bit;
        shouldWarn = 1;
    }
    volumeSerial = g_app.volumeSerial[volumeIndex];
    volumeSerialKnown = g_app.volumeSerialKnown[volumeIndex] != 0;
    LeaveCriticalSection(&g_app.lock);

    pending = PendingQueueCountForDrive(letter, volumeSerial, volumeSerialKnown, &processing);
    unuploaded = pending + (DWORD)processing;
    if (unuploaded == 0) unuploaded = 1;

    if (!shouldWarn) {
        LogMessage("Unavailable source drive warning suppressed: root=%s pending=%lu processing=%s",
            root,
            (unsigned long)pending,
            processing ? "yes" : "no");
        return;
    }

    SbSnprintf(message, sizeof(message),
        "%s is no longer available while %lu file%s from it %s still awaiting verification or upload.\r\n\r\n"
        "Reinsert the card and let SpiceBush finish before formatting it.",
        root,
        (unsigned long)unuploaded,
        unuploaded == 1 ? "" : "s",
        unuploaded == 1 ? "was" : "were");

    LogMessage("Unavailable source drive warning: root=%s pending=%lu processing=%s",
        root,
        (unsigned long)pending,
        processing ? "yes" : "no");
    ShowWarningAlert(message);
    ShowTrayBalloon(g_app.mainWindow, APP_NAME, message, 10000);
}

static DWORD WINAPI ScanDriveThread(LPVOID param)
{
    ScanRequest *request = (ScanRequest *)param;
    ScanStats stats;
    ZeroMemory(&stats, sizeof(stats));
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
    RememberVolumeSerial(letter);
    LogMessage("Scan drive queued: root=%s max_depth=%d", request->root, maxDepth);
    InterlockedIncrement(&g_app.activeScans);
    thread = CreateThread(NULL, 0, ScanDriveThread, request, 0, NULL);
    if (thread) {
        CloseHandle(thread);
        PostMessageA(g_app.mainWindow, WM_REFRESH_STATS, 0, 0);
    } else {
        InterlockedDecrement(&g_app.activeScans);
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
        if (mask & (1UL << i)) {
            char letter = (char)('A' + i);
            DWORD serial = 0;
            DWORD pending = 0;
            int active = 0;
            int serialKnown = 0;
            int sameVolume = 0;
            serialKnown = ReadVolumeSerial(letter, &serial);
            if (serialKnown) {
                EnterCriticalSection(&g_app.lock);
                g_app.volumeSerial[i] = serial;
                g_app.volumeSerialKnown[i] = 1;
                LeaveCriticalSection(&g_app.lock);
                pending = PendingQueueCountForDrive(letter, serial, 1, &active);
            }
            EnterCriticalSection(&g_app.lock);
            g_app.unavailableDriveWarningMask &= ~(1UL << i);
            g_app.unavailableDriveMask &= ~(1UL << i);
            LeaveCriticalSection(&g_app.lock);
            SetEvent(g_app.queueEvent);
            SetEvent(g_app.uploadQueueEvent);
            sameVolume = pending + (DWORD)active > 0;
            if (sameVolume) {
                LogMessage("Source volume resumed without automatic rescan: drive=%c serial=%08lx pending=%lu active=%d",
                    letter, (unsigned long)serial, (unsigned long)pending, active);
            } else {
                LogMessage("Source volume arrival requires scan: drive=%c serial_known=%s same_volume=%s pending=%lu active=%d",
                    letter, serialKnown ? "yes" : "no", sameVolume ? "yes" : "no", (unsigned long)pending, active);
                StartScanDrive(letter, 3);
            }
        }
    }
}

static DWORD PendingQueueCountForDrive(char letter, DWORD volumeSerial, int volumeSerialKnown, int *processing)
{
    DWORD pending = 0;
    DWORD i;
    if (processing) *processing = 0;

    EnterCriticalSection(&g_app.lock);
    for (i = 0; i < g_app.queueCount; i++) {
        if (PathOnDrive(g_app.queue[i].path, letter)
            && SameVolumeIdentity(g_app.queue[i].volumeSerial, g_app.queue[i].volumeSerialKnown, volumeSerial, volumeSerialKnown)) pending++;
    }
    for (i = 0; i < g_app.preparedCount; i++) {
        if (PathOnDrive(g_app.prepared[i].path, letter)
            && SameVolumeIdentity(g_app.prepared[i].volumeSerial, g_app.prepared[i].volumeSerialKnown, volumeSerial, volumeSerialKnown)) pending++;
    }
    if (processing && PathOnDrive(g_app.processingPath, letter)
        && SameVolumeIdentity(g_app.processingVolumeSerial, g_app.processingVolumeSerialKnown, volumeSerial, volumeSerialKnown)) {
        (*processing)++;
    }
    if (processing && PathOnDrive(g_app.uploadPath, letter)
        && SameVolumeIdentity(g_app.uploadVolumeSerial, g_app.uploadVolumeSerialKnown, volumeSerial, volumeSerialKnown)) {
        (*processing)++;
    }
    LeaveCriticalSection(&g_app.lock);

    return pending;
}

static void WarnIfRemovedDriveHasPendingWork(char letter)
{
    DWORD pending;
    DWORD unuploaded;
    DWORD volumeSerial = 0;
    int volumeSerialKnown = 0;
    int processing = 0;
    char root[] = "A:\\";
    char message[512];

    root[0] = letter;
    EnterCriticalSection(&g_app.lock);
    volumeSerial = g_app.volumeSerial[letter - 'A'];
    volumeSerialKnown = g_app.volumeSerialKnown[letter - 'A'] != 0;
    LeaveCriticalSection(&g_app.lock);
    pending = PendingQueueCountForDrive(letter, volumeSerial, volumeSerialKnown, &processing);
    unuploaded = pending + (DWORD)processing;
    if (unuploaded == 0) {
        LogMessage("Device removal: root=%s pending=0 processing=no", root);
        return;
    }

    SbSnprintf(message, sizeof(message),
        "%s was removed while %lu file%s from it %s still awaiting verification or upload.\r\n\r\n"
        "Reinsert the card and let SpiceBush finish before formatting it.",
        root,
        (unsigned long)unuploaded,
        unuploaded == 1 ? "" : "s",
        unuploaded == 1 ? "was" : "were");

    LogMessage("Device removal warning: root=%s pending=%lu processing=%s",
        root,
        (unsigned long)pending,
        processing ? "yes" : "no");
    ShowWarningAlert(message);
    ShowTrayBalloon(g_app.mainWindow, APP_NAME, message, 10000);
}

static void HandleDeviceRemoval(LPARAM lp)
{
    DEV_BROADCAST_HDR *hdr = (DEV_BROADCAST_HDR *)lp;
    DWORD mask;
    int i;
    if (!hdr) {
        LogMessage("Device removal ignored: missing header.");
        return;
    }
    if (hdr->dbch_devicetype != DBT_DEVTYP_VOLUME) {
        LogMessage("Device removal ignored: devicetype=%lu", (unsigned long)hdr->dbch_devicetype);
        return;
    }
    mask = ((DEV_BROADCAST_VOLUME *)hdr)->dbcv_unitmask;
    LogMessage("Device removal volume mask=0x%08lx", (unsigned long)mask);
    EnterCriticalSection(&g_app.lock);
    g_app.unavailableDriveMask |= mask;
    for (i = 0; i < 26; i++) {
        if (mask & (1UL << i)) g_app.volumeRemovalGeneration[i]++;
    }
    LeaveCriticalSection(&g_app.lock);
    for (i = 0; i < 26; i++) {
        if (mask & (1UL << i)) WarnIfRemovedDriveHasPendingWork((char)('A' + i));
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

static void BuildTrayTooltip(char *tip, DWORD tipSize)
{
    LONG found;
    LONG alreadyUploaded;
    LONG pending;
    LONG active;
    LONG processing;
    LONG uploading;
    LONG prepared;
    LONG processingStage;
    LONG uploadStage;
    LONG uploadsPaused;
    LONG shutdownRequested;
    DWORD unavailablePendingMask = 0;
    DWORD i;
    char waitingStatus[32];
    const char *status = "Idle";

    EnterCriticalSection(&g_app.lock);
    found = g_app.totalFound;
    alreadyUploaded = g_app.totalKnown + g_app.totalSkippedLocal;
    pending = (LONG)g_app.queueCount;
    prepared = (LONG)g_app.preparedCount;
    active = g_app.activeScans;
    processing = g_app.processing;
    uploading = g_app.uploading;
    processingStage = g_app.processingStage;
    uploadStage = g_app.uploadStage;
    uploadsPaused = g_app.uploadsPaused;
    shutdownRequested = g_app.shutdownRequested;
    for (i = 0; i < g_app.queueCount; i++) {
        if (!SourceItemAvailableLocked(g_app.queue[i].path, g_app.queue[i].volumeSerial, g_app.queue[i].volumeSerialKnown)) {
            unavailablePendingMask |= DriveMaskForPath(g_app.queue[i].path);
        }
    }
    for (i = 0; i < g_app.preparedCount; i++) {
        if (!SourceItemAvailableLocked(g_app.prepared[i].path, g_app.prepared[i].volumeSerial, g_app.prepared[i].volumeSerialKnown)) {
            unavailablePendingMask |= DriveMaskForPath(g_app.prepared[i].path);
        }
    }
    LeaveCriticalSection(&g_app.lock);

    if (shutdownRequested && (processing || uploading)) status = "Exiting after current work";
    else if (shutdownRequested) status = "Exiting";
    else if (uploadsPaused) status = "Paused";
    else if (uploading && processing && processingStage == PROCESS_STAGE_BUFFER_WAIT) {
        status = uploadStage == UPLOAD_STAGE_BODY ? "Uploading; buffer full" : "Final check; buffer full";
    }
    else if (uploading && processing) status = uploadStage == UPLOAD_STAGE_BODY ? "Uploading and checking" : "Final-checking and checking";
    else if (uploading) status = uploadStage == UPLOAD_STAGE_BODY ? "Uploading" : "Final server check";
    else if (processing) status = ProcessingStageLabel(processingStage);
    else if (active > 0) status = "Scanning";
    else if (unavailablePendingMask != 0) {
        for (i = 0; i < 26; i++) if (unavailablePendingMask & (1UL << i)) break;
        SbSnprintf(waitingStatus, sizeof(waitingStatus), "Waiting for source %c:\\", i < 26 ? (char)('A' + i) : '?');
        status = waitingStatus;
    }
    else if (pending > 0 || prepared > 0) status = "Waiting";

    SbSnprintf(tip, tipSize, "SpiceBush: %s. %ld found, %ld known, %ld unchecked, %ld ready.",
        status,
        found,
        alreadyUploaded,
        pending,
        prepared);
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

static int UploadsPaused(void)
{
    return InterlockedCompareExchange(&g_app.uploadsPaused, 0, 0) != 0;
}

static int ShutdownRequested(void)
{
    return InterlockedCompareExchange(&g_app.shutdownRequested, 0, 0) != 0;
}

static void UpdateStatsPauseButton(void)
{
    if (!g_app.statsPauseButton) return;
    SetWindowTextIfChanged(g_app.statsPauseButton, UploadsPaused() ? "Resume" : "Pause");
    EnableWindow(g_app.statsPauseButton, !ShutdownRequested());
}

static void SetUploadsPaused(int paused)
{
    if (ShutdownRequested()) return;
    InterlockedExchange(&g_app.uploadsPaused, paused ? 1 : 0);
    UpdateStatsPauseButton();
    if (!paused && g_app.queueEvent) SetEvent(g_app.queueEvent);
    if (!paused && g_app.uploadQueueEvent) SetEvent(g_app.uploadQueueEvent);
    PostMessageA(g_app.mainWindow, WM_REFRESH_STATS, 0, 0);
}

static void ToggleUploadsPaused(void)
{
    SetUploadsPaused(!UploadsPaused());
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

static int CompleteGracefulShutdownIfReady(HWND hwnd)
{
    if (g_app.processorThread) {
        DWORD wait = WaitForSingleObject(g_app.processorThread, 0);
        if (wait != WAIT_OBJECT_0) return 0;
        CloseHandle(g_app.processorThread);
        g_app.processorThread = NULL;
    }
    if (g_app.uploaderThread) {
        DWORD wait = WaitForSingleObject(g_app.uploaderThread, 0);
        if (wait != WAIT_OBJECT_0) return 0;
        CloseHandle(g_app.uploaderThread);
        g_app.uploaderThread = NULL;
    }
    KillTimer(hwnd, ID_MAIN_SHUTDOWN_TIMER);
    DestroyWindow(hwnd);
    return 1;
}

static DWORD PendingQueueCount(void)
{
    DWORD pending;
    EnterCriticalSection(&g_app.lock);
    pending = g_app.queueCount + g_app.preparedCount;
    if (g_app.processing) pending++;
    if (g_app.uploading) pending++;
    LeaveCriticalSection(&g_app.lock);
    return pending;
}

static int ConfirmGracefulShutdown(HWND hwnd)
{
    DWORD pending = PendingQueueCount();
    LONG activeScans = InterlockedCompareExchange(&g_app.activeScans, 0, 0);
    DWORD unuploaded = pending;
    HWND owner;
    UINT flags = MB_ICONWARNING | MB_YESNO | MB_DEFBUTTON2 | MB_SETFOREGROUND | MB_TOPMOST | MB_TASKMODAL;
    char message[512];
    int answer;
    (void)hwnd;

    if (unuploaded == 0 && activeScans == 0) return 1;

    if (unuploaded > 0 && activeScans > 0) {
        SbSnprintf(message, sizeof(message),
            "%lu file%s still waiting to upload, and %ld scan%s still running.\r\n\r\n"
            "If you remove or format the card now, those files may not be safely in SwallowTail yet.\r\n\r\n"
            "Exit SpiceBush anyway?",
            (unsigned long)unuploaded,
            unuploaded == 1 ? " is" : "s are",
            activeScans,
            activeScans == 1 ? " is" : "s are");
    } else if (unuploaded > 0) {
        SbSnprintf(message, sizeof(message),
            "%lu file%s still waiting to upload.\r\n\r\n"
            "If you remove or format the card now, those files may not be safely in SwallowTail yet.\r\n\r\n"
            "Exit SpiceBush anyway?",
            (unsigned long)unuploaded,
            unuploaded == 1 ? " is" : "s are");
    } else {
        SbSnprintf(message, sizeof(message),
            "%ld scan%s still running.\r\n\r\n"
            "SpiceBush may not have found every CR2 on the card yet.\r\n\r\n"
            "Exit SpiceBush anyway?",
            activeScans,
            activeScans == 1 ? " is" : "s are");
    }

    owner = ForegroundAlertOwner();
    BringAlertOwnerForward(owner);
    MessageBeep(MB_ICONWARNING);
    answer = MessageBoxA(owner, message, APP_NAME, flags);
    LogMessage("Shutdown warning: unuploaded=%lu active_scans=%ld answer=%s",
        (unsigned long)unuploaded,
        activeScans,
        answer == IDYES ? "exit" : "cancel");
    return answer == IDYES;
}

static void BeginGracefulShutdown(HWND hwnd)
{
    if (!ShutdownRequested() && !ConfirmGracefulShutdown(hwnd)) return;
    if (InterlockedExchange(&g_app.shutdownRequested, 1) != 0) return;
    UpdateStatsPauseButton();
    if (g_app.mainWindow) PostMessageA(g_app.mainWindow, WM_REFRESH_STATS, 0, 0);
    if (g_app.stopEvent) SetEvent(g_app.stopEvent);
    if (g_app.queueEvent) SetEvent(g_app.queueEvent);
    if (g_app.uploadQueueEvent) SetEvent(g_app.uploadQueueEvent);
    if (g_app.uploadSpaceEvent) SetEvent(g_app.uploadSpaceEvent);
    if (!CompleteGracefulShutdownIfReady(hwnd)) {
        SetTimer(hwnd, ID_MAIN_SHUTDOWN_TIMER, 250, NULL);
    }
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
        rect.right = 18 + 520 + 18;
        rect.bottom = 580;
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
        SafeCopy(out, outSize, "0m");
    } else if (days > 0) {
        SbSnprintf(out, outSize, "%I64ud %I64uh", days, hours);
    } else if (hours > 0) {
        SbSnprintf(out, outSize, "%I64uh %I64um", hours, minutes);
    } else if (minutes > 0) {
        SbSnprintf(out, outSize, "%I64um", minutes);
    } else {
        SafeCopy(out, outSize, "<1m");
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
    char text[512], status[128], checkPath[MAX_PATH];
    char foundText[32], uploadedText[32], localText[32], remoteText[32];
    char candidateText[32], preparedText[32], failedText[32], verifyFailedText[32];
    char rejectedText[32], interruptedText[32], scannedText[32], activeText[32];
    char etaText[80], serverLimitText[80], uploadSentText[32], uploadTotalText[32];
    LONG candidates, prepared, found, uploaded, local, remote, failed, verifyFailed;
    LONG rejected, interrupted, scanned, active, processing, uploading, stage, uploadStage, paused, shutdown;
    LONG resolved, unresolved, progress = 0;
    DWORD uploadPercent = 0, uploadStartedAt = 0, unavailableMask, i;
    U64 avg = 0, etaMillis = 0, serverLimit, uploadSent, uploadTotal, uploadMbpsX10 = 0;
    int serverLimitState;

    EnterCriticalSection(&g_app.lock);
    candidates = (LONG)g_app.queueCount;
    prepared = (LONG)g_app.preparedCount;
    found = g_app.totalFound;
    uploaded = g_app.totalUploaded;
    local = g_app.totalSkippedLocal;
    remote = g_app.totalKnown;
    failed = g_app.totalFailed;
    verifyFailed = g_app.totalVerificationFailed;
    rejected = g_app.totalRejectedOversize;
    interrupted = g_app.totalSourceInterrupted;
    scanned = g_app.totalScannedDrives;
    active = g_app.activeScans;
    processing = g_app.processing;
    uploading = g_app.uploading;
    stage = g_app.processingStage;
    uploadStage = g_app.uploadStage;
    paused = g_app.uploadsPaused;
    shutdown = g_app.shutdownRequested;
    uploadSent = g_app.currentUploadBytesSent;
    uploadTotal = g_app.currentUploadTotalBytes;
    uploadStartedAt = g_app.currentUploadStartedAt;
    unavailableMask = 0;
    for (i = 0; i < g_app.queueCount; i++) {
        if (!SourceItemAvailableLocked(g_app.queue[i].path, g_app.queue[i].volumeSerial, g_app.queue[i].volumeSerialKnown)) {
            unavailableMask |= DriveMaskForPath(g_app.queue[i].path);
        }
    }
    for (i = 0; i < g_app.preparedCount; i++) {
        if (!SourceItemAvailableLocked(g_app.prepared[i].path, g_app.prepared[i].volumeSerial, g_app.prepared[i].volumeSerialKnown)) {
            unavailableMask |= DriveMaskForPath(g_app.prepared[i].path);
        }
    }
    SafeCopy(checkPath, sizeof(checkPath), g_app.processingPath);
    serverLimitState = g_app.serverMaxRawUploadState;
    serverLimit = g_app.serverMaxRawUploadBytes;
    if (g_app.uploadMillisWindowCount > 0) avg = g_app.uploadMillisWindowTotal / (U64)g_app.uploadMillisWindowCount;
    LeaveCriticalSection(&g_app.lock);

    UpdateTrayTooltip(g_app.mainWindow);
    if (!g_app.statsWindow) return;
    UpdateStatsPauseButton();

    resolved = uploaded + local + remote + rejected + verifyFailed;
    unresolved = candidates + prepared + (processing ? 1 : 0) + (uploading ? 1 : 0);
    if (resolved + unresolved > 0) progress = (resolved * 100L) / (resolved + unresolved);
    SafeCopy(status, sizeof(status), "Idle");
    if (shutdown && (processing || uploading)) SafeCopy(status, sizeof(status), "Exiting after current work");
    else if (shutdown) SafeCopy(status, sizeof(status), "Exiting");
    else if (paused) SafeCopy(status, sizeof(status), "Paused");
    else if (uploading && processing) SbSnprintf(status, sizeof(status), "%s; %s",
        uploadStage == UPLOAD_STAGE_BODY ? "Uploading" : "Final server upload check",
        ProcessingStageLabel(stage));
    else if (uploading) SafeCopy(status, sizeof(status), uploadStage == UPLOAD_STAGE_BODY ? "Uploading" : "Final server upload check");
    else if (processing) SafeCopy(status, sizeof(status), ProcessingStageLabel(stage));
    else if (active > 0) SafeCopy(status, sizeof(status), "Scanning");
    else if (unavailableMask && (candidates > 0 || prepared > 0)) {
        for (i = 0; i < 26; i++) if (unavailableMask & (1UL << i)) break;
        SbSnprintf(status, sizeof(status), "Waiting for source %c:\\", i < 26 ? (char)('A' + i) : '?');
    } else if (candidates > 0) SafeCopy(status, sizeof(status), "Waiting to check candidates");
    else if (prepared > 0) SafeCopy(status, sizeof(status), "Waiting to upload confirmed files");

    FormatCount(found, foundText, sizeof(foundText));
    FormatCount(uploaded, uploadedText, sizeof(uploadedText));
    FormatCount(local, localText, sizeof(localText));
    FormatCount(remote, remoteText, sizeof(remoteText));
    FormatCount(candidates, candidateText, sizeof(candidateText));
    FormatCount(prepared, preparedText, sizeof(preparedText));
    FormatCount(failed, failedText, sizeof(failedText));
    FormatCount(verifyFailed, verifyFailedText, sizeof(verifyFailedText));
    FormatCount(rejected, rejectedText, sizeof(rejectedText));
    FormatCount(interrupted, interruptedText, sizeof(interruptedText));
    FormatCount(scanned, scannedText, sizeof(scannedText));
    FormatCount(active, activeText, sizeof(activeText));
    etaMillis = avg * (U64)prepared;
    if (uploading && avg > 0) {
        if (uploadTotal > 0 && uploadSent < uploadTotal) etaMillis += (avg * (uploadTotal - uploadSent)) / uploadTotal;
        else etaMillis += avg;
    }
    FormatDuration(etaMillis, etaText, sizeof(etaText));
    if (serverLimitState > 0 && serverLimit > 0) FormatBytes(serverLimit, serverLimitText, sizeof(serverLimitText));
    else SafeCopy(serverLimitText, sizeof(serverLimitText), serverLimitState < 0 ? "Unavailable" : "Not checked");

    SetWindowTextIfChanged(g_app.statsLabels[0], "SwallowTail RAW CR2 Photo Uploads");
    SbSnprintf(text, sizeof(text), "Status: %s", status);
    SetWindowTextIfChanged(g_app.statsLabels[1], text);
    SbSnprintf(text, sizeof(text), "Candidate resolution progress: %ld%%", progress);
    SetWindowTextIfChanged(g_app.statsLabels[2], text);
    if (processing && checkPath[0] && stage != PROCESS_STAGE_BUFFER_WAIT) {
        const char *slash = strrchr(checkPath, '\\');
        SbSnprintf(text, sizeof(text), "Candidates awaiting checksum/dedupe: %s (checking %s)", candidateText, slash ? slash + 1 : checkPath);
    } else {
        SbSnprintf(text, sizeof(text), "Candidates awaiting checksum/dedupe: %s", candidateText);
    }
    SetWindowTextIfChanged(g_app.statsLabels[3], text);
    if (uploading && uploadStage == UPLOAD_STAGE_BODY && uploadTotal > 0) {
        DWORD elapsedMs = GetTickCount() - uploadStartedAt;
        if (uploadSent > uploadTotal) uploadSent = uploadTotal;
        uploadPercent = (DWORD)((uploadSent * 100ULL) / uploadTotal);
        if (elapsedMs > 0) uploadMbpsX10 = (uploadSent * 80ULL) / ((U64)elapsedMs * 1000ULL);
        FormatBytes(uploadSent, uploadSentText, sizeof(uploadSentText));
        FormatBytes(uploadTotal, uploadTotalText, sizeof(uploadTotalText));
        SbSnprintf(text, sizeof(text), "Current upload: %s / %s (%lu%%, %I64u.%I64u Mbps)", uploadSentText, uploadTotalText,
            (unsigned long)uploadPercent, uploadMbpsX10 / 10ULL, uploadMbpsX10 % 10ULL);
        SetWindowTextIfChanged(g_app.statsLabels[4], text);
    } else if (uploading) SetWindowTextIfChanged(g_app.statsLabels[4], "Current upload: final server checksum recheck");
    else SetWindowTextIfChanged(g_app.statsLabels[4], "Current upload: none");
    SbSnprintf(text, sizeof(text), "Files found this session: %s", foundText);
    SetWindowTextIfChanged(g_app.statsLabels[5], text);
    SbSnprintf(text, sizeof(text), "Uploaded this session: %s", uploadedText);
    SetWindowTextIfChanged(g_app.statsLabels[6], text);
    SbSnprintf(text, sizeof(text), "Already uploaded (local cache): %s", localText);
    SetWindowTextIfChanged(g_app.statsLabels[7], text);
    SbSnprintf(text, sizeof(text), "Already uploaded (remote): %s", remoteText);
    SetWindowTextIfChanged(g_app.statsLabels[8], text);
    if (processing && checkPath[0] && stage == PROCESS_STAGE_BUFFER_WAIT) {
        const char *slash = strrchr(checkPath, '\\');
        SbSnprintf(text, sizeof(text), "Confirmed uploads waiting: %s buffered; %s ready, waiting for space",
            preparedText,
            slash ? slash + 1 : checkPath);
    } else {
        SbSnprintf(text, sizeof(text), "Confirmed uploads waiting: %s", preparedText);
    }
    SetWindowTextIfChanged(g_app.statsLabels[9], text);
    SbSnprintf(text, sizeof(text), "Network/server upload failures: %s", failedText);
    SetWindowTextIfChanged(g_app.statsLabels[10], text);
    SbSnprintf(text, sizeof(text), "Over-size rejects: %s", rejectedText);
    SetWindowTextIfChanged(g_app.statsLabels[11], text);
    SbSnprintf(text, sizeof(text), "Source interruptions: %s; verification errors: %s", interruptedText, verifyFailedText);
    SetWindowTextIfChanged(g_app.statsLabels[12], text);
    SbSnprintf(text, sizeof(text), "Drives scanned this session: %s", scannedText);
    SetWindowTextIfChanged(g_app.statsLabels[13], text);
    SbSnprintf(text, sizeof(text), "Active scans: %s", activeText);
    SetWindowTextIfChanged(g_app.statsLabels[14], text);
    SbSnprintf(text, sizeof(text), "Server upload limit: %s", serverLimitText);
    SetWindowTextIfChanged(g_app.statsLabels[15], text);
    SbSnprintf(text, sizeof(text), "Average completed upload time: %I64u ms", avg);
    SetWindowTextIfChanged(g_app.statsLabels[16], text);
    SbSnprintf(text, sizeof(text), "Known-upload ETA (excludes unchecked candidates): %s", etaText);
    SetWindowTextIfChanged(g_app.statsLabels[17], text);
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
    char requestedEndpoint[2048], endpoint[2048], canonicalSiteUrl[MAX_TEXT], u[MAX_TEXT * 2], p[MAX_TEXT * 2], o[80], d[256], json[4096], headers[256], response[8192];
    char token[MAX_TEXT], responseApiUrl[MAX_TEXT], publicApiUrl[MAX_TEXT];
    char errorText[512];
    char *postedError = NULL;
    int requestOk;
    DWORD status = 0;
    BuildRegisterEndpoint(rr->siteUrl, requestedEndpoint, sizeof(requestedEndpoint));
    if (!ResolveRegisterEndpoint(requestedEndpoint, endpoint, sizeof(endpoint), errorText, sizeof(errorText))) {
        postedError = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, lstrlenA(errorText) + 32);
        if (postedError) {
            SbSnprintf(postedError, lstrlenA(errorText) + 32, "Registration failed: %s", errorText);
        }
        PostMessageA(rr->hwnd, WM_REGISTER_DONE, 0, (LPARAM)postedError);
        SecureZeroMemory(rr->password, sizeof(rr->password));
        SecureZeroMemory(rr->otpCode, sizeof(rr->otpCode));
        HeapFree(GetProcessHeap(), 0, rr);
        return 0;
    }
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
        && JsonStringValue(response, "api_url", responseApiUrl, sizeof(responseApiUrl))) {
        size_t configurationErrorSize;
        SiteUrlFromRegisterEndpoint(endpoint, canonicalSiteUrl, sizeof(canonicalSiteUrl));
        ApiUrlFromRegisterEndpoint(endpoint, publicApiUrl, sizeof(publicApiUrl));
        if (publicApiUrl[0] != '\0' && lstrcmpiA(publicApiUrl, responseApiUrl) != 0) {
            LogMessage("Registration response API URL differs from the public registration endpoint: response=%s expected=%s", responseApiUrl, publicApiUrl);
            configurationErrorSize = strlen(responseApiUrl) + strlen(publicApiUrl) + strlen(canonicalSiteUrl) + 360;
            postedError = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, configurationErrorSize);
            if (postedError) {
                SbSnprintf(
                    postedError,
                    configurationErrorSize,
                    "Registration credentials were accepted, but SwallowTail returned API URL \"%s\" instead of \"%s\". In SwallowTail, set \"External Base Web URL (Blank for Automatic)\" to \"%s/\" and register again.",
                    responseApiUrl,
                    publicApiUrl,
                    canonicalSiteUrl
                );
            }
            PostMessageA(rr->hwnd, WM_REGISTER_DONE, 0, (LPARAM)postedError);
        } else {
            SafeCopy(g_app.siteUrl, sizeof(g_app.siteUrl), canonicalSiteUrl[0] ? canonicalSiteUrl : rr->siteUrl);
            SafeCopy(g_app.uploadToken, sizeof(g_app.uploadToken), token);
            SafeCopy(g_app.apiUrl, sizeof(g_app.apiUrl), publicApiUrl[0] ? publicApiUrl : responseApiUrl);
            SaveConfig();
            PostMessageA(rr->hwnd, WM_REGISTER_DONE, 1, 0);
        }
    } else {
        if (requestOk && (status == 301 || status == 302 || status == 303 || status == 307 || status == 308)) {
            SafeCopy(errorText, sizeof(errorText), "The registration POST was redirected. Enter the final HTTPS site URL directly and try again.");
            postedError = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, lstrlenA(errorText) + 32);
            if (postedError) {
                SbSnprintf(postedError, lstrlenA(errorText) + 32, "Registration failed: %s", errorText);
            }
        } else if (requestOk && JsonFirstArrayStringValue(response, "errors", errorText, sizeof(errorText))) {
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
            if (g_app.registerQuitMode) BeginGracefulShutdown(g_app.mainWindow);
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
            BeginGracefulShutdown(g_app.mainWindow);
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
            int buttonWidth = 220;
            int buttonY = 432;
            int pauseY = buttonY + 36;
            int clearY = pauseY + 36;
            int pingY = clearY + 36;
            int labelWidth = 520;
            for (i = 0; i < STATS_LABEL_COUNT; i++) {
                g_app.statsLabels[i] = Label(hwnd, "", margin, 18 + i * 22, labelWidth, 20);
            }
            Button(hwnd, ID_STATS_SCAN, "Scan Existing Drives", margin, buttonY, buttonWidth, 28, 0);
            g_app.statsPauseButton = Button(hwnd, ID_STATS_PAUSE, "Pause", margin, pauseY, buttonWidth, 28, 0);
            Button(hwnd, ID_STATS_CLEAR_HISTORY, "Clear local history cache", margin, clearY, buttonWidth, 28, 0);
            g_app.statsPingButton = Button(hwnd, ID_STATS_PING, "Test Server Connectivity", margin, pingY, buttonWidth, 28, BS_OWNERDRAW);
            UpdateStatsPauseButton();
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
        else if (LOWORD(wp) == ID_STATS_PAUSE) ToggleUploadsPaused();
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
        else if (wp == DBT_DEVICEREMOVECOMPLETE) HandleDeviceRemoval(lp);
        return 0;
    case WM_TRAYICON:
        if (lp == WM_RBUTTONUP) ShowTrayMenu(hwnd);
        else if (lp == WM_LBUTTONDBLCLK) ShowStatsWindow();
        return 0;
    case WM_COMMAND:
        switch (LOWORD(wp)) {
        case ID_TRAY_REGISTER: ShowRegisterWindow(0); break;
        case ID_TRAY_STATS: ShowStatsWindow(); break;
        case ID_TRAY_EXIT: BeginGracefulShutdown(hwnd); break;
        }
        return 0;
    case WM_CLOSE:
        BeginGracefulShutdown(hwnd);
        return 0;
    case WM_TIMER:
        if (wp == ID_MAIN_SHUTDOWN_TIMER) {
            CompleteGracefulShutdownIfReady(hwnd);
            return 0;
        }
        break;
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
        if (g_app.queueEvent) SetEvent(g_app.queueEvent);
        if (g_app.uploadQueueEvent) SetEvent(g_app.uploadQueueEvent);
        if (g_app.uploadSpaceEvent) SetEvent(g_app.uploadSpaceEvent);
        KillTimer(hwnd, ID_MAIN_SHUTDOWN_TIMER);
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
    g_app.uploadQueueEvent = CreateEventA(NULL, FALSE, FALSE, NULL);
    g_app.uploadSpaceEvent = CreateEventA(NULL, FALSE, FALSE, NULL);
    g_app.stopEvent = CreateEventA(NULL, TRUE, FALSE, NULL);
    EnsureAppStorage();
    LogMessage("SpiceBush starting: app_dir=%s ini_path=%s log_path=%s", g_app.appDir, g_app.iniPath, g_app.logPath);
    LoadConfig();
    RegisterClasses();
    g_app.mainWindow = CreateWindowA("SpiceBushMain", APP_NAME, WS_OVERLAPPEDWINDOW, 0, 0, 0, 0, NULL, NULL, instance, NULL);
    LoadQueue();
    g_app.processorThread = CreateThread(NULL, 0, ProcessorThread, NULL, 0, NULL);
    g_app.uploaderThread = CreateThread(NULL, 0, UploaderThread, NULL, 0, NULL);
    if (!g_app.processorThread || !g_app.uploaderThread) {
        LogMessage("SpiceBush pipeline thread creation failed: verifier=%s uploader=%s error=%lu",
            g_app.processorThread ? "yes" : "no",
            g_app.uploaderThread ? "yes" : "no",
            GetLastError());
    } else {
        LogMessage("SpiceBush pipeline started: verifier_workers=1 uploader_workers=1 prepared_capacity=%u prepared_entry_bytes=%u prepared_memory_bytes=%u",
            PREPARED_UPLOAD_LOOKAHEAD,
            (unsigned int)sizeof(PreparedUpload),
            (unsigned int)sizeof(g_app.prepared));
    }
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
    if (g_app.stopEvent) SetEvent(g_app.stopEvent);
    if (g_app.queueEvent) SetEvent(g_app.queueEvent);
    if (g_app.uploadQueueEvent) SetEvent(g_app.uploadQueueEvent);
    if (g_app.uploadSpaceEvent) SetEvent(g_app.uploadSpaceEvent);
    if (g_app.processorThread) {
        WaitForSingleObject(g_app.processorThread, INFINITE);
        CloseHandle(g_app.processorThread);
        g_app.processorThread = NULL;
    }
    if (g_app.uploaderThread) {
        WaitForSingleObject(g_app.uploaderThread, INFINITE);
        CloseHandle(g_app.uploaderThread);
        g_app.uploaderThread = NULL;
    }
    DeleteCriticalSection(&g_app.lock);
    if (g_app.balloonWindow) DestroyWindow(g_app.balloonWindow);
    if (g_app.registerLogoIcon) DestroyIcon(g_app.registerLogoIcon);
    if (g_app.boldUiFont) DeleteObject(g_app.boldUiFont);
    if (g_app.uiFont) DeleteObject(g_app.uiFont);
    if (g_app.queue) HeapFree(GetProcessHeap(), 0, g_app.queue);
    if (g_app.queueEvent) CloseHandle(g_app.queueEvent);
    if (g_app.uploadQueueEvent) CloseHandle(g_app.uploadQueueEvent);
    if (g_app.uploadSpaceEvent) CloseHandle(g_app.uploadSpaceEvent);
    if (g_app.stopEvent) CloseHandle(g_app.stopEvent);
    if (g_app.instanceMutex) CloseHandle(g_app.instanceMutex);
    LogMessage("SpiceBush exiting.");
    return 0;
}
