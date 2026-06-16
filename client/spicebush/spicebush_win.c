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
#define ID_TRAY_SCAN 1003
#define ID_TRAY_EXIT 1004

#define ID_REGISTER_URL 2001
#define ID_REGISTER_USER 2002
#define ID_REGISTER_PASSWORD 2003
#define ID_REGISTER_OTP 2004
#define ID_REGISTER_SAVE 2005
#define ID_REGISTER_STATUS 2006
#define ID_REGISTER_QUIT 2007

#define ID_STATS_SCAN 3001
#define MAX_TEXT 1024
#define QUEUE_INITIAL 128

typedef unsigned __int64 U64;

typedef struct QueueItem {
    char path[MAX_PATH];
} QueueItem;

typedef struct AppState {
    HINSTANCE instance;
    HWND mainWindow;
    HWND registerWindow;
    HWND statsWindow;
    HWND registerStatus;
    HWND statsLabels[10];
    HICON registerLogoIcon;
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
    LONG totalScannedDrives;
    LONG activeScans;
    LONG processing;
    U64 totalUploadMillis;
    char appDir[MAX_PATH];
    char iniPath[MAX_PATH];
    char queuePath[MAX_PATH];
    char uploadedPath[MAX_PATH];
    char siteUrl[MAX_TEXT];
    char apiUrl[MAX_TEXT];
    char uploadToken[MAX_TEXT];
    char deviceId[128];
} AppState;

static AppState g_app;

static LRESULT CALLBACK MainWndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp);
static LRESULT CALLBACK RegisterWndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp);
static LRESULT CALLBACK StatsWndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp);
static void ShowRegisterWindow(void);
static DWORD WINAPI ProcessorThread(LPVOID param);
static DWORD WINAPI ScanDriveThread(LPVOID param);
static DWORD WINAPI RegisterThread(LPVOID param);
static DWORD WINAPI PingThread(LPVOID param);

static HICON AppIcon(void)
{
    if (!g_app.registerLogoIcon) {
        g_app.registerLogoIcon = (HICON)LoadImageA(g_app.instance, MAKEINTRESOURCEA(IDR_SPICEBUSH_ICON), IMAGE_ICON, 64, 64, LR_DEFAULTCOLOR);
    }
    return g_app.registerLogoIcon ? g_app.registerLogoIcon : LoadIcon(NULL, IDI_APPLICATION);
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
    if (!status) status = g_app.registerStatus;
    if (status) SetWindowTextA(status, text);
}

static void EnsureAppStorage(void)
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
    PathJoin(g_app.uploadedPath, sizeof(g_app.uploadedPath), g_app.appDir, "uploaded.tsv");

    computer[0] = '\0';
    GetComputerNameA(computer, &computerLen);
    if (computer[0] == '\0') SafeCopy(computer, sizeof(computer), "windows-client");
    SbSnprintf(g_app.deviceId, sizeof(g_app.deviceId) - 1, "spicebush-%s", computer);
    g_app.deviceId[sizeof(g_app.deviceId) - 1] = '\0';

    if (GetFileAttributesA(g_app.iniPath) == INVALID_FILE_ATTRIBUTES) {
        WritePrivateProfileStringA("spicebush", "site_url", "", g_app.iniPath);
        WritePrivateProfileStringA("spicebush", "api_url", "", g_app.iniPath);
        WritePrivateProfileStringA("spicebush", "upload_token", "", g_app.iniPath);
        WritePrivateProfileStringA("spicebush", "device_id", g_app.deviceId, g_app.iniPath);
    }
}

static void LoadConfig(void)
{
    GetPrivateProfileStringA("spicebush", "site_url", "", g_app.siteUrl, sizeof(g_app.siteUrl), g_app.iniPath);
    GetPrivateProfileStringA("spicebush", "api_url", "", g_app.apiUrl, sizeof(g_app.apiUrl), g_app.iniPath);
    GetPrivateProfileStringA("spicebush", "upload_token", "", g_app.uploadToken, sizeof(g_app.uploadToken), g_app.iniPath);
    GetPrivateProfileStringA("spicebush", "device_id", g_app.deviceId, g_app.deviceId, sizeof(g_app.deviceId), g_app.iniPath);
}

static void SaveConfig(void)
{
    WritePrivateProfileStringA("spicebush", "site_url", g_app.siteUrl, g_app.iniPath);
    WritePrivateProfileStringA("spicebush", "api_url", g_app.apiUrl, g_app.iniPath);
    WritePrivateProfileStringA("spicebush", "upload_token", g_app.uploadToken, g_app.iniPath);
    WritePrivateProfileStringA("spicebush", "device_id", g_app.deviceId, g_app.iniPath);
}

static void AppendLine(const char *path, const char *line)
{
    HANDLE file = CreateFileA(path, FILE_APPEND_DATA, FILE_SHARE_READ, NULL, OPEN_ALWAYS, FILE_ATTRIBUTE_NORMAL, NULL);
    DWORD written;
    if (file == INVALID_HANDLE_VALUE) return;
    WriteFile(file, line, lstrlenA(line), &written, NULL);
    CloseHandle(file);
}

static int QueueContainsLocked(const char *path)
{
    DWORD i;
    for (i = 0; i < g_app.queueCount; i++) {
        if (lstrcmpiA(g_app.queue[i].path, path) == 0) return 1;
    }
    return 0;
}

static void RewriteQueueFileLocked(void)
{
    char tmp[MAX_PATH];
    HANDLE file;
    DWORD i, written;
    PathJoin(tmp, sizeof(tmp), g_app.appDir, "queue.tmp");
    file = CreateFileA(tmp, GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, FILE_ATTRIBUTE_NORMAL, NULL);
    if (file == INVALID_HANDLE_VALUE) return;
    for (i = 0; i < g_app.queueCount; i++) {
        WriteFile(file, g_app.queue[i].path, lstrlenA(g_app.queue[i].path), &written, NULL);
        WriteFile(file, "\r\n", 2, &written, NULL);
    }
    CloseHandle(file);
    MoveFileExA(tmp, g_app.queuePath, MOVEFILE_REPLACE_EXISTING);
}

static void QueuePushInternal(const char *path, int persist, int countFound)
{
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
            SafeCopy(g_app.queue[g_app.queueCount].path, sizeof(g_app.queue[g_app.queueCount].path), path);
            g_app.queueCount++;
            if (persist) {
                AppendLine(g_app.queuePath, path);
                AppendLine(g_app.queuePath, "\r\n");
            }
            if (countFound) {
                InterlockedIncrement(&g_app.totalFound);
            }
            SetEvent(g_app.queueEvent);
        }
    }
    LeaveCriticalSection(&g_app.lock);
    PostMessageA(g_app.mainWindow, WM_REFRESH_STATS, 0, 0);
}

static void QueuePush(const char *path)
{
    QueuePushInternal(path, 1, 1);
}

static void QueueRequeue(const char *path)
{
    QueuePushInternal(path, 1, 0);
}

static int QueuePop(char *path, DWORD pathSize)
{
    DWORD i;
    int hasItem = 0;
    EnterCriticalSection(&g_app.lock);
    if (g_app.queueCount > 0) {
        SafeCopy(path, pathSize, g_app.queue[0].path);
        for (i = 1; i < g_app.queueCount; i++) {
            g_app.queue[i - 1] = g_app.queue[i];
        }
        g_app.queueCount--;
        RewriteQueueFileLocked();
        hasItem = 1;
    }
    LeaveCriticalSection(&g_app.lock);
    return hasItem;
}

static void LoadQueue(void)
{
    HANDLE file = CreateFileA(g_app.queuePath, GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE, NULL, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL, NULL);
    char buffer[4096];
    char line[MAX_PATH];
    DWORD got, i, lineLen = 0;
    if (file == INVALID_HANDLE_VALUE) return;
    while (ReadFile(file, buffer, sizeof(buffer), &got, NULL) && got > 0) {
        for (i = 0; i < got; i++) {
            char ch = buffer[i];
            if (ch == '\r') continue;
            if (ch == '\n') {
                line[lineLen] = '\0';
                if (lineLen > 0) QueuePushInternal(line, 0, 0);
                lineLen = 0;
            } else if (lineLen + 1 < sizeof(line)) {
                line[lineLen++] = ch;
            }
        }
    }
    if (lineLen > 0) {
        line[lineLen] = '\0';
        QueuePushInternal(line, 0, 0);
    }
    CloseHandle(file);
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

static int UploadedContains(const char *hash, U64 sizeBytes)
{
    HANDLE file = CreateFileA(g_app.uploadedPath, GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE, NULL, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL, NULL);
    char buffer[4096], needle[64], line[1024];
    DWORD got, i, lineLen = 0;
    int found = 0;
    if (file == INVALID_HANDLE_VALUE) return 0;
    SbSnprintf(needle, sizeof(needle) - 1, "%s\t%I64u\t", hash, sizeBytes);
    needle[sizeof(needle) - 1] = '\0';
    while (!found && ReadFile(file, buffer, sizeof(buffer), &got, NULL) && got > 0) {
        for (i = 0; i < got && !found; i++) {
            char ch = buffer[i];
            if (ch == '\r') continue;
            if (ch == '\n') {
                line[lineLen] = '\0';
                if (strncmp(line, needle, lstrlenA(needle)) == 0) found = 1;
                lineLen = 0;
            } else if (lineLen + 1 < sizeof(line)) {
                line[lineLen++] = ch;
            }
        }
    }
    if (!found && lineLen > 0) {
        line[lineLen] = '\0';
        if (strncmp(line, needle, lstrlenA(needle)) == 0) found = 1;
    }
    CloseHandle(file);
    return found;
}

static void MarkUploaded(const char *hash, U64 sizeBytes, const char *path)
{
    char line[MAX_PATH + 128];
    SbSnprintf(line, sizeof(line) - 1, "%s\t%I64u\t%s\r\n", hash, sizeBytes, path);
    line[sizeof(line) - 1] = '\0';
    AppendLine(g_app.uploadedPath, line);
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
    if (!ParseUrl(url, &parsed)) return 0;
    if (parsed.secure) flags |= INTERNET_FLAG_SECURE;
    internet = InternetOpenA("SpiceBush/1.0", INTERNET_OPEN_TYPE_PRECONFIG, NULL, NULL, 0);
    if (!internet) goto done;
    connect = InternetConnectA(internet, parsed.host, parsed.port, NULL, NULL, INTERNET_SERVICE_HTTP, 0, 0);
    if (!connect) goto done;
    request = HttpOpenRequestA(connect, method, parsed.path, "HTTP/1.1", NULL, NULL, flags, 0);
    if (!request) goto done;
    if (!HttpSendRequestA(request, headers, headers ? lstrlenA(headers) : 0, (LPVOID)body, bodyLen)) goto done;
    *status = 0;
    HttpQueryInfoA(request, HTTP_QUERY_STATUS_CODE | HTTP_QUERY_FLAG_NUMBER, status, &statusSize, NULL);
    if (response && responseSize > 0) response[0] = '\0';
    while (response && used + 1 < responseSize && InternetReadFile(request, response + used, responseSize - used - 1, &got) && got > 0) {
        used += got;
        response[used] = '\0';
    }
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
        lstrcatA(endpoint, "/spicebush-register.php");
    } else {
        lstrcatA(endpoint, "/api/spicebush-register.php");
    }
}

static int CheckServerKnowsFile(const char *hash, U64 sizeBytes)
{
    char url[2048], encodedHash[128], headers[1400], response[4096];
    DWORD status = 0;
    int exists = 0;
    if (g_app.apiUrl[0] == '\0' || g_app.uploadToken[0] == '\0') return 0;
    UrlEncode(hash, encodedHash, sizeof(encodedHash));
    SbSnprintf(url, sizeof(url) - 1, "%s/quick-checksum.php?algorithm=fnv1a64&hash=%s&size_bytes=%I64u", g_app.apiUrl, encodedHash, sizeBytes);
    url[sizeof(url) - 1] = '\0';
    SbSnprintf(headers, sizeof(headers) - 1, "Authorization: Bearer %s\r\n", g_app.uploadToken);
    headers[sizeof(headers) - 1] = '\0';
    if (!HttpSimpleRequest("GET", url, headers, NULL, 0, &status, response, sizeof(response))) return 0;
    if (status == 200 && JsonBoolValue(response, "exists", &exists) && exists) return 1;
    return 0;
}

static DWORD WINAPI PingThread(LPVOID param)
{
    char url[MAX_TEXT * 2], headers[MAX_TEXT + 128], response[4096], errorText[512];
    char *postedError = NULL;
    DWORD status = 0;
    int requestOk;
    (void)param;

    SbSnprintf(url, sizeof(url) - 1, "%s/ping.php", g_app.apiUrl);
    url[sizeof(url) - 1] = '\0';
    SbSnprintf(headers, sizeof(headers) - 1, "Authorization: Bearer %s\r\n", g_app.uploadToken);
    headers[sizeof(headers) - 1] = '\0';

    requestOk = HttpSimpleRequest("GET", url, headers, NULL, 0, &status, response, sizeof(response));
    if (requestOk && status == 200 && JsonBoolValue(response, "pong", &requestOk) && requestOk) {
        PostMessageA(g_app.mainWindow, WM_PING_DONE, 1, 0);
        return 0;
    }

    if (requestOk && JsonFirstArrayStringValue(response, "errors", errorText, sizeof(errorText))) {
        postedError = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, lstrlenA(errorText) + 48);
        if (postedError) {
            SbSnprintf(postedError, lstrlenA(errorText) + 48, "Connection check failed: %s", errorText);
        }
    } else {
        postedError = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, 180);
        if (postedError) {
            if (requestOk && status > 0) {
                SbSnprintf(postedError, 180, "Connection check failed: server returned HTTP %lu.", status);
            } else {
                SafeCopy(postedError, 180, "Connection check failed: could not connect to the SwallowTail server.");
            }
        }
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
    else ShowRegisterWindow();
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
    BYTE buf[65536];
    int ok = 0;
    const char *slash = strrchr(path, '\\');
    SafeCopy(filename, sizeof(filename), slash ? slash + 1 : path);
    SbSnprintf(url, sizeof(url) - 1, "%s/raw-upload.php", g_app.apiUrl);
    url[sizeof(url) - 1] = '\0';
    if (!ParseUrl(url, &parsed)) return 0;
    if (parsed.secure) flags |= INTERNET_FLAG_SECURE;
    SbSnprintf(headers, sizeof(headers) - 1,
        "Authorization: Bearer %s\r\n"
        "Content-Type: application/octet-stream\r\n"
        "X-Swallowtail-Filename: %s\r\n"
        "X-Swallowtail-Device-ID: %s\r\n",
        g_app.uploadToken, filename, g_app.deviceId);
    headers[sizeof(headers) - 1] = '\0';

    file = CreateFileA(path, GENERIC_READ, FILE_SHARE_READ, NULL, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL | FILE_FLAG_SEQUENTIAL_SCAN, NULL);
    if (file == INVALID_HANDLE_VALUE) goto done;
    internet = InternetOpenA("SpiceBush/1.0", INTERNET_OPEN_TYPE_PRECONFIG, NULL, NULL, 0);
    if (!internet) goto done;
    connect = InternetConnectA(internet, parsed.host, parsed.port, NULL, NULL, INTERNET_SERVICE_HTTP, 0, 0);
    if (!connect) goto done;
    request = HttpOpenRequestA(connect, "POST", parsed.path, "HTTP/1.1", NULL, NULL, flags, 0);
    if (!request) goto done;
    ZeroMemory(&buffers, sizeof(buffers));
    buffers.dwStructSize = sizeof(buffers);
    buffers.lpcszHeader = headers;
    buffers.dwHeadersLength = lstrlenA(headers);
    buffers.dwBufferTotal = (DWORD)sizeBytes;
    if (!HttpSendRequestExA(request, &buffers, NULL, 0, 0)) goto done;
    while (ReadFile(file, buf, sizeof(buf), &got, NULL) && got > 0) {
        if (!InternetWriteFile(request, buf, got, &wrote) || wrote != got) goto done;
    }
    if (!HttpEndRequestA(request, NULL, 0, 0)) goto done;
    HttpQueryInfoA(request, HTTP_QUERY_STATUS_CODE | HTTP_QUERY_FLAG_NUMBER, &status, &statusSize, NULL);
    while (used + 1 < sizeof(response) && InternetReadFile(request, response + used, sizeof(response) - used - 1, &got) && got > 0) {
        used += got;
        response[used] = '\0';
    }
    ok = (status == 200 || status == 201) && strstr(response, "\"success\":true") != NULL;
done:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    if (request) InternetCloseHandle(request);
    if (connect) InternetCloseHandle(connect);
    if (internet) InternetCloseHandle(internet);
    if (ok) MarkUploaded(hash, sizeBytes, path);
    return ok;
}

static void ProcessPath(const char *path)
{
    char hash[32];
    U64 sizeBytes = 0;
    DWORD start = GetTickCount();
    if (!ComputeFnv1a64(path, hash, sizeof(hash), &sizeBytes)) {
        InterlockedIncrement(&g_app.totalFailed);
        return;
    }
    if (UploadedContains(hash, sizeBytes)) {
        InterlockedIncrement(&g_app.totalSkippedLocal);
        return;
    }
    if (CheckServerKnowsFile(hash, sizeBytes)) {
        MarkUploaded(hash, sizeBytes, path);
        InterlockedIncrement(&g_app.totalKnown);
        return;
    }
    if (UploadFileRaw(path, hash, sizeBytes)) {
        DWORD elapsed = GetTickCount() - start;
        EnterCriticalSection(&g_app.lock);
        g_app.totalUploadMillis += elapsed;
        LeaveCriticalSection(&g_app.lock);
        InterlockedIncrement(&g_app.totalUploaded);
    } else {
        InterlockedIncrement(&g_app.totalFailed);
        QueueRequeue(path);
        Sleep(5000);
    }
}

static DWORD WINAPI ProcessorThread(LPVOID param)
{
    HANDLE handles[2];
    char path[MAX_PATH];
    (void)param;
    handles[0] = g_app.stopEvent;
    handles[1] = g_app.queueEvent;
    for (;;) {
        DWORD wait = WaitForMultipleObjects(2, handles, FALSE, INFINITE);
        if (wait == WAIT_OBJECT_0) break;
        while (QueuePop(path, sizeof(path))) {
            InterlockedExchange(&g_app.processing, 1);
            ProcessPath(path);
            InterlockedExchange(&g_app.processing, 0);
            PostMessageA(g_app.mainWindow, WM_REFRESH_STATS, 0, 0);
            if (WaitForSingleObject(g_app.stopEvent, 0) == WAIT_OBJECT_0) return 0;
        }
    }
    return 0;
}

static void ScanFolder(const char *folder, int depth, int maxDepth)
{
    char pattern[MAX_PATH], child[MAX_PATH];
    WIN32_FIND_DATAA data;
    HANDLE find;
    if (WaitForSingleObject(g_app.stopEvent, 0) == WAIT_OBJECT_0) return;
    PathJoin(pattern, sizeof(pattern), folder, "*");
    find = FindFirstFileA(pattern, &data);
    if (find == INVALID_HANDLE_VALUE) return;
    do {
        if (lstrcmpA(data.cFileName, ".") == 0 || lstrcmpA(data.cFileName, "..") == 0) continue;
        PathJoin(child, sizeof(child), folder, data.cFileName);
        if (data.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) {
            if (depth < maxDepth) ScanFolder(child, depth + 1, maxDepth);
        } else if (EndsWithNoCase(data.cFileName, ".cr2")) {
            QueuePush(child);
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
    InterlockedIncrement(&g_app.activeScans);
    InterlockedIncrement(&g_app.totalScannedDrives);
    ScanFolder(request->root, 0, request->maxDepth);
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
    thread = CreateThread(NULL, 0, ScanDriveThread, request, 0, NULL);
    if (thread) CloseHandle(thread);
    else HeapFree(GetProcessHeap(), 0, request);
}

static void ScanExistingDrives(int recursive)
{
    DWORD mask = GetLogicalDrives();
    int i;
    for (i = 0; i < 26; i++) {
        if (mask & (1UL << i)) {
            char root[] = "A:\\";
            UINT type;
            root[0] = (char)('A' + i);
            type = GetDriveTypeA(root);
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
    if (!hdr || hdr->dbch_devicetype != DBT_DEVTYP_VOLUME) return;
    mask = ((DEV_BROADCAST_VOLUME *)hdr)->dbcv_unitmask;
    for (i = 0; i < 26; i++) {
        if (mask & (1UL << i)) StartScanDrive((char)('A' + i), 3);
    }
}

static void AddTrayIcon(HWND hwnd)
{
    NOTIFYICONDATAA nid;
    ZeroMemory(&nid, sizeof(nid));
    nid.cbSize = sizeof(nid);
    nid.hWnd = hwnd;
    nid.uID = 1;
    nid.uFlags = NIF_MESSAGE | NIF_ICON | NIF_TIP;
    nid.uCallbackMessage = WM_TRAYICON;
    nid.hIcon = AppIcon();
    SafeCopy(nid.szTip, sizeof(nid.szTip), APP_NAME);
    Shell_NotifyIconA(NIM_ADD, &nid);
}

static void RemoveTrayIcon(HWND hwnd)
{
    NOTIFYICONDATAA nid;
    ZeroMemory(&nid, sizeof(nid));
    nid.cbSize = sizeof(nid);
    nid.hWnd = hwnd;
    nid.uID = 1;
    Shell_NotifyIconA(NIM_DELETE, &nid);
}

static void ShowTrayBalloon(HWND hwnd, const char *title, const char *message, UINT timeoutMillis)
{
    NOTIFYICONDATAA nid;
    ZeroMemory(&nid, sizeof(nid));
    nid.cbSize = sizeof(nid);
    nid.hWnd = hwnd;
    nid.uID = 1;
    nid.uFlags = NIF_INFO;
    SafeCopy(nid.szInfoTitle, sizeof(nid.szInfoTitle), title);
    SafeCopy(nid.szInfo, sizeof(nid.szInfo), message);
    nid.uTimeout = timeoutMillis;
    nid.dwInfoFlags = NIIF_INFO;
    Shell_NotifyIconA(NIM_MODIFY, &nid);
}

static void ShowTrayMenu(HWND hwnd)
{
    HMENU menu = CreatePopupMenu();
    POINT pt;
    SetForegroundWindow(hwnd);
    AppendMenuA(menu, MF_STRING, ID_TRAY_REGISTER, "Register...");
    AppendMenuA(menu, MF_STRING, ID_TRAY_STATS, "Statistics");
    AppendMenuA(menu, MF_STRING, ID_TRAY_SCAN, "Scan Existing Drives");
    AppendMenuA(menu, MF_SEPARATOR, 0, NULL);
    AppendMenuA(menu, MF_STRING, ID_TRAY_EXIT, "Exit");
    GetCursorPos(&pt);
    TrackPopupMenu(menu, TPM_RIGHTBUTTON, pt.x, pt.y, 0, hwnd, NULL);
    DestroyMenu(menu);
}

static HWND Label(HWND parent, const char *text, int x, int y, int w, int h)
{
    return CreateWindowA("STATIC", text, WS_CHILD | WS_VISIBLE, x, y, w, h, parent, NULL, g_app.instance, NULL);
}

static HWND StatusLabel(HWND parent, const char *text, int x, int y, int w, int h)
{
    return CreateWindowExA(WS_EX_CLIENTEDGE, "STATIC", text, WS_CHILD | WS_VISIBLE | SS_LEFT, x, y, w, h, parent, (HMENU)ID_REGISTER_STATUS, g_app.instance, NULL);
}

static HWND Edit(HWND parent, int id, const char *text, int x, int y, int w, int h, DWORD style)
{
    return CreateWindowExA(WS_EX_CLIENTEDGE, "EDIT", text, WS_CHILD | WS_VISIBLE | WS_TABSTOP | ES_AUTOHSCROLL | style, x, y, w, h, parent, (HMENU)(INT_PTR)id, g_app.instance, NULL);
}

static void ShowRegisterWindow(void)
{
    if (!g_app.registerWindow) {
        g_app.registerWindow = CreateWindowExA(WS_EX_CONTROLPARENT, "SpiceBushRegister", "Register with SwallowTail", WS_OVERLAPPED | WS_CAPTION | WS_SYSMENU, CW_USEDEFAULT, CW_USEDEFAULT, 540, 330, NULL, NULL, g_app.instance, NULL);
    }
    ShowWindow(g_app.registerWindow, SW_SHOWNORMAL);
    SetForegroundWindow(g_app.registerWindow);
}

static void ShowStatsWindow(void)
{
    if (!g_app.statsWindow) {
        g_app.statsWindow = CreateWindowA("SpiceBushStats", "Statistics", WS_OVERLAPPED | WS_CAPTION | WS_SYSMENU, CW_USEDEFAULT, CW_USEDEFAULT, 430, 330, NULL, NULL, g_app.instance, NULL);
    }
    ShowWindow(g_app.statsWindow, SW_SHOWNORMAL);
    SetForegroundWindow(g_app.statsWindow);
    PostMessageA(g_app.mainWindow, WM_REFRESH_STATS, 0, 0);
}

static void RefreshStats(void)
{
    char text[256];
    LONG pending;
    U64 avg = 0;
    EnterCriticalSection(&g_app.lock);
    pending = (LONG)g_app.queueCount;
    if (g_app.totalUploaded > 0) avg = g_app.totalUploadMillis / (U64)g_app.totalUploaded;
    LeaveCriticalSection(&g_app.lock);
    if (!g_app.statsWindow) return;
    SbSnprintf(text, sizeof(text), "Total CR2 uploaded since launch: %ld", g_app.totalUploaded);
    SetWindowTextA(g_app.statsLabels[0], text);
    SbSnprintf(text, sizeof(text), "Total CR2 found since launch: %ld", g_app.totalFound);
    SetWindowTextA(g_app.statsLabels[1], text);
    SbSnprintf(text, sizeof(text), "Already known by SwallowTail: %ld", g_app.totalKnown);
    SetWindowTextA(g_app.statsLabels[2], text);
    SbSnprintf(text, sizeof(text), "Yet to upload: %ld of %ld (%ld%%)", pending, g_app.totalFound, g_app.totalFound > 0 ? (pending * 100L) / g_app.totalFound : 0L);
    SetWindowTextA(g_app.statsLabels[3], text);
    SbSnprintf(text, sizeof(text), "Average upload time: %I64u ms", avg);
    SetWindowTextA(g_app.statsLabels[4], text);
    SbSnprintf(text, sizeof(text), "Skipped from local uploaded file: %ld", g_app.totalSkippedLocal);
    SetWindowTextA(g_app.statsLabels[5], text);
    SbSnprintf(text, sizeof(text), "Failed attempts since launch: %ld", g_app.totalFailed);
    SetWindowTextA(g_app.statsLabels[6], text);
    SbSnprintf(text, sizeof(text), "Scanned drives since launch: %ld", g_app.totalScannedDrives);
    SetWindowTextA(g_app.statsLabels[7], text);
    SbSnprintf(text, sizeof(text), "Active scans: %ld", g_app.activeScans);
    SetWindowTextA(g_app.statsLabels[8], text);
    SbSnprintf(text, sizeof(text), "Uploader: %s", g_app.processing ? "processing" : "idle");
    SetWindowTextA(g_app.statsLabels[9], text);
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
        CreateWindowA("BUTTON", "Register", WS_CHILD | WS_VISIBLE | WS_TABSTOP | BS_DEFPUSHBUTTON, 210, 161, 100, 28, hwnd, (HMENU)ID_REGISTER_SAVE, g_app.instance, NULL);
        CreateWindowA("BUTTON", "Quit", WS_CHILD | WS_VISIBLE | WS_TABSTOP, 320, 161, 80, 28, hwnd, (HMENU)ID_REGISTER_QUIT, g_app.instance, NULL);
        g_app.registerStatus = StatusLabel(hwnd, "Enter registration details, then click Register.", 18, 202, 500, 72);
        return 0;
    case WM_COMMAND:
        if (LOWORD(wp) == ID_REGISTER_SAVE) BeginRegister(hwnd);
        else if (LOWORD(wp) == ID_REGISTER_QUIT) DestroyWindow(g_app.mainWindow);
        return 0;
    case WM_REGISTER_DONE:
        EnableWindow(GetDlgItem(hwnd, ID_REGISTER_SAVE), TRUE);
        if (wp) {
            SetStatus(hwnd, "Registered. SpiceBush is ready to upload.");
            ShowWindow(hwnd, SW_HIDE);
            PostMessageA(g_app.mainWindow, WM_REGISTER_BALLOON, 0, 0);
        } else if (lp) {
            SetStatus(hwnd, (const char *)lp);
            HeapFree(GetProcessHeap(), 0, (LPVOID)lp);
        } else {
            SetStatus(hwnd, "Registration failed. Check URL, credentials, OTP, role, and CIDR policy.");
        }
        return 0;
    case WM_CLOSE:
        if (g_app.uploadToken[0] == '\0' || g_app.apiUrl[0] == '\0') {
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
        for (i = 0; i < 10; i++) {
            g_app.statsLabels[i] = Label(hwnd, "", 18, 18 + i * 24, 380, 20);
        }
        CreateWindowA("BUTTON", "Scan Existing Drives", WS_CHILD | WS_VISIBLE | WS_TABSTOP, 18, 260, 160, 28, hwnd, (HMENU)ID_STATS_SCAN, g_app.instance, NULL);
        SetTimer(hwnd, 1, 1000, NULL);
        RefreshStats();
        return 0;
    case WM_COMMAND:
        if (LOWORD(wp) == ID_STATS_SCAN) ScanExistingDrives(1);
        return 0;
    case WM_TIMER:
        RefreshStats();
        return 0;
    case WM_CLOSE:
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
        case ID_TRAY_REGISTER: ShowRegisterWindow(); break;
        case ID_TRAY_STATS: ShowStatsWindow(); break;
        case ID_TRAY_SCAN: ScanExistingDrives(1); break;
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
            ShowTrayBalloon(hwnd, APP_NAME, "SwallowTail - Connected OK!", 10000);
        } else {
            ShowRegisterWindow();
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
    InitializeCriticalSection(&g_app.lock);
    g_app.queueEvent = CreateEventA(NULL, FALSE, FALSE, NULL);
    g_app.stopEvent = CreateEventA(NULL, TRUE, FALSE, NULL);
    EnsureAppStorage();
    LoadConfig();
    RegisterClasses();
    g_app.mainWindow = CreateWindowA("SpiceBushMain", APP_NAME, WS_OVERLAPPEDWINDOW, 0, 0, 0, 0, NULL, NULL, instance, NULL);
    LoadQueue();
    processor = CreateThread(NULL, 0, ProcessorThread, NULL, 0, NULL);
    if (processor) CloseHandle(processor);
    if (g_app.uploadToken[0] == '\0' || g_app.apiUrl[0] == '\0') ShowRegisterWindow();
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
    if (g_app.registerLogoIcon) DestroyIcon(g_app.registerLogoIcon);
    if (g_app.queue) HeapFree(GetProcessHeap(), 0, g_app.queue);
    if (g_app.queueEvent) CloseHandle(g_app.queueEvent);
    if (g_app.stopEvent) CloseHandle(g_app.stopEvent);
    return 0;
}
