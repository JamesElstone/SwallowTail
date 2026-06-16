#include "spicebush_shared.h"

#include <ctype.h>
#include <errno.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>

#ifdef _WIN32
#include <direct.h>
#else
#include <dirent.h>
#include <unistd.h>
#endif

void sb_safe_copy(char *dst, size_t dst_size, const char *src)
{
    if (dst_size == 0) {
        return;
    }
    if (src == NULL) {
        src = "";
    }
    strncpy(dst, src, dst_size - 1);
    dst[dst_size - 1] = '\0';
}

int sb_normalise_device_id(char *device_id, size_t device_id_size)
{
    const char *prefix = "spicebush-";
    size_t prefix_len = strlen(prefix);
    char normalised[128];
    size_t i;

    if (device_id_size == 0 || strlen(device_id) <= prefix_len) {
        return 0;
    }

    for (i = 0; i < prefix_len; i++) {
        if (tolower((unsigned char)device_id[i]) != (unsigned char)prefix[i]) {
            return 0;
        }
    }

    sb_safe_copy(normalised, sizeof(normalised), device_id + prefix_len);
    sb_safe_copy(device_id, device_id_size, normalised);
    return 1;
}

void sb_path_join(char *dst, size_t dst_size, const char *left, const char *right, char separator)
{
    size_t len;
    sb_safe_copy(dst, dst_size, left);
    len = strlen(dst);
    if (len > 0 && dst[len - 1] != '/' && dst[len - 1] != '\\' && len + 1 < dst_size) {
        dst[len] = separator;
        dst[len + 1] = '\0';
    }
    if (strlen(dst) + strlen(right) + 1 < dst_size) {
        strcat(dst, right);
    }
}

int sb_ends_with_nocase(const char *text, const char *suffix)
{
    size_t text_len = strlen(text);
    size_t suffix_len = strlen(suffix);
    size_t i;
    if (suffix_len > text_len) {
        return 0;
    }
    text += text_len - suffix_len;
    for (i = 0; i < suffix_len; i++) {
        if (tolower((unsigned char)text[i]) != tolower((unsigned char)suffix[i])) {
            return 0;
        }
    }
    return 1;
}

void sb_trim_trailing_slashes(char *text)
{
    size_t len = strlen(text);
    while (len > 0 && (text[len - 1] == '/' || text[len - 1] == '\\')) {
        text[len - 1] = '\0';
        len--;
    }
}

const char *sb_basename(const char *path)
{
    const char *slash = strrchr(path, '/');
    const char *backslash = strrchr(path, '\\');
    const char *base = slash > backslash ? slash : backslash;
    return base ? base + 1 : path;
}

int sb_mkdir_if_needed(const char *path)
{
#ifdef _WIN32
    return _mkdir(path) == 0 || errno == EEXIST;
#else
    return mkdir(path, 0700) == 0 || errno == EEXIST;
#endif
}

int sb_compute_fnv1a64(const char *path, char *hex, size_t hex_size, sb_u64 *size_bytes)
{
    unsigned char buffer[65536];
    size_t got;
    FILE *file = fopen(path, "rb");
    sb_u64 hash = 14695981039346656037ULL;
    sb_u64 total = 0;
    size_t i;

    if (file == NULL) {
        return 0;
    }
    while ((got = fread(buffer, 1, sizeof(buffer), file)) > 0) {
        for (i = 0; i < got; i++) {
            hash ^= (sb_u64)buffer[i];
            hash *= 1099511628211ULL;
        }
        total += (sb_u64)got;
    }
    if (ferror(file)) {
        fclose(file);
        return 0;
    }
    fclose(file);
    snprintf(hex, hex_size, "%016llx", (unsigned long long)hash);
    if (size_bytes != NULL) {
        *size_bytes = total;
    }
    return 1;
}

int sb_uploaded_contains(const char *uploaded_path, const char *hash, sb_u64 size_bytes)
{
    FILE *file = fopen(uploaded_path, "r");
    char line[SB_PATH + 128];
    char needle[128];
    if (file == NULL) {
        return 0;
    }
    snprintf(needle, sizeof(needle), "%s\t%llu\t", hash, (unsigned long long)size_bytes);
    while (fgets(line, sizeof(line), file) != NULL) {
        if (strncmp(line, needle, strlen(needle)) == 0) {
            fclose(file);
            return 1;
        }
    }
    fclose(file);
    return 0;
}

int sb_mark_uploaded(const char *uploaded_path, const char *hash, sb_u64 size_bytes, const char *path)
{
    FILE *file = fopen(uploaded_path, "a");
    if (file == NULL) {
        return 0;
    }
    fprintf(file, "%s\t%llu\t%s\n", hash, (unsigned long long)size_bytes, path);
    fclose(file);
    return 1;
}

int sb_scan_tree(const char *root, int max_depth, SpiceBushScanCallback callback, void *context)
{
#ifdef _WIN32
    (void)root;
    (void)max_depth;
    (void)callback;
    (void)context;
    return 0;
#else
    DIR *dir;
    struct dirent *entry;
    int found = 0;

    dir = opendir(root);
    if (dir == NULL) {
        return 0;
    }
    while ((entry = readdir(dir)) != NULL) {
        char child[SB_PATH];
        struct stat st;

        if (strcmp(entry->d_name, ".") == 0 || strcmp(entry->d_name, "..") == 0) {
            continue;
        }
        sb_path_join(child, sizeof(child), root, entry->d_name, '/');
        if (lstat(child, &st) != 0) {
            continue;
        }
        if (S_ISDIR(st.st_mode)) {
            if (max_depth != 0) {
                found += sb_scan_tree(child, max_depth > 0 ? max_depth - 1 : -1, callback, context);
            }
        } else if (S_ISREG(st.st_mode) && sb_ends_with_nocase(entry->d_name, ".cr2")) {
            found++;
            if (callback(child, context) == 0) {
                break;
            }
        }
    }
    closedir(dir);
    return found;
#endif
}

static void sb_trim(char *text)
{
    char *start = text;
    char *end;
    while (*start != '\0' && isspace((unsigned char)*start)) {
        start++;
    }
    if (start != text) {
        memmove(text, start, strlen(start) + 1);
    }
    end = text + strlen(text);
    while (end > text && isspace((unsigned char)end[-1])) {
        *--end = '\0';
    }
}

int sb_load_config(SpiceBushConfig *config)
{
    FILE *file = fopen(config->ini_path, "r");
    char line[SB_TEXT * 2];
    if (file == NULL) {
        return 0;
    }
    while (fgets(line, sizeof(line), file) != NULL) {
        char *equals;
        char *key;
        char *value;
        sb_trim(line);
        if (line[0] == '\0' || line[0] == '[' || line[0] == ';' || line[0] == '#') {
            continue;
        }
        equals = strchr(line, '=');
        if (equals == NULL) {
            continue;
        }
        *equals = '\0';
        key = line;
        value = equals + 1;
        sb_trim(key);
        sb_trim(value);
        if (strcmp(key, "site_url") == 0) {
            sb_safe_copy(config->site_url, sizeof(config->site_url), value);
        } else if (strcmp(key, "api_url") == 0) {
            sb_safe_copy(config->api_url, sizeof(config->api_url), value);
        } else if (strcmp(key, "upload_token") == 0) {
            sb_safe_copy(config->upload_token, sizeof(config->upload_token), value);
        } else if (strcmp(key, "device_id") == 0) {
            sb_safe_copy(config->device_id, sizeof(config->device_id), value);
        }
    }
    fclose(file);
    return 1;
}

int sb_save_config(const SpiceBushConfig *config)
{
    FILE *file = fopen(config->ini_path, "w");
    if (file == NULL) {
        return 0;
    }
    fprintf(file, "[spicebush]\n");
    fprintf(file, "site_url=%s\n", config->site_url);
    fprintf(file, "api_url=%s\n", config->api_url);
    fprintf(file, "upload_token=%s\n", config->upload_token);
    fprintf(file, "device_id=%s\n", config->device_id);
    fclose(file);
    return 1;
}

void sb_build_register_endpoint(const char *site_url, char *endpoint, size_t endpoint_size)
{
    if (strncmp(site_url, "http://", 7) != 0 && strncmp(site_url, "https://", 8) != 0) {
        sb_safe_copy(endpoint, endpoint_size, "https://");
        if (strlen(endpoint) + strlen(site_url) + 1 < endpoint_size) {
            strcat(endpoint, site_url);
        }
    } else {
        sb_safe_copy(endpoint, endpoint_size, site_url);
    }
    sb_trim_trailing_slashes(endpoint);
    if (sb_ends_with_nocase(endpoint, "/api")) {
        if (strlen(endpoint) + strlen("/spicebush-register.php") + 1 < endpoint_size) {
            strcat(endpoint, "/spicebush-register.php");
        }
    } else if (strlen(endpoint) + strlen("/api/spicebush-register.php") + 1 < endpoint_size) {
        strcat(endpoint, "/api/spicebush-register.php");
    }
}

void sb_url_encode(const char *src, char *dst, size_t dst_size)
{
    static const char hex[] = "0123456789ABCDEF";
    size_t used = 0;
    while (*src != '\0' && used + 4 < dst_size) {
        unsigned char c = (unsigned char)*src++;
        if (isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~') {
            dst[used++] = (char)c;
        } else {
            dst[used++] = '%';
            dst[used++] = hex[(c >> 4) & 15];
            dst[used++] = hex[c & 15];
        }
    }
    dst[used] = '\0';
}

void sb_json_escape(const char *src, char *dst, size_t dst_size)
{
    size_t used = 0;
    while (*src != '\0' && used + 2 < dst_size) {
        unsigned char c = (unsigned char)*src++;
        if (c == '"' || c == '\\') {
            dst[used++] = '\\';
            dst[used++] = (char)c;
        } else if (c >= 32) {
            dst[used++] = (char)c;
        }
    }
    dst[used] = '\0';
}

int sb_json_string_value(const char *json, const char *key, char *out, size_t out_size)
{
    char needle[128];
    const char *p;
    size_t used = 0;
    snprintf(needle, sizeof(needle), "\"%s\"", key);
    p = strstr(json, needle);
    if (p == NULL) {
        return 0;
    }
    p = strchr(p + strlen(needle), ':');
    if (p == NULL) {
        return 0;
    }
    p++;
    while (*p == ' ' || *p == '\t') {
        p++;
    }
    if (*p != '"') {
        return 0;
    }
    p++;
    while (*p != '\0' && *p != '"' && used + 1 < out_size) {
        if (*p == '\\' && p[1] != '\0') {
            p++;
        }
        out[used++] = *p++;
    }
    out[used] = '\0';
    return used > 0;
}

int sb_json_bool_value(const char *json, const char *key, int *value)
{
    char needle[128];
    const char *p;
    snprintf(needle, sizeof(needle), "\"%s\"", key);
    p = strstr(json, needle);
    if (p == NULL) {
        return 0;
    }
    p = strchr(p + strlen(needle), ':');
    if (p == NULL) {
        return 0;
    }
    p++;
    while (*p == ' ' || *p == '\t') {
        p++;
    }
    if (strncmp(p, "true", 4) == 0) {
        *value = 1;
        return 1;
    }
    if (strncmp(p, "false", 5) == 0) {
        *value = 0;
        return 1;
    }
    return 0;
}
