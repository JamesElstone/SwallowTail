#ifndef SPICEBUSH_SHARED_H
#define SPICEBUSH_SHARED_H

#include <stddef.h>

#ifdef _WIN32
typedef unsigned __int64 sb_u64;
#else
typedef unsigned long long sb_u64;
#endif

#define SB_TEXT 1024
#define SB_PATH 4096

typedef struct SpiceBushConfig {
    char app_dir[SB_PATH];
    char ini_path[SB_PATH];
    char queue_path[SB_PATH];
    char uploaded_path[SB_PATH];
    char site_url[SB_TEXT];
    char api_url[SB_TEXT];
    char upload_token[SB_TEXT];
    char device_id[128];
} SpiceBushConfig;

typedef struct SpiceBushStats {
    unsigned long found;
    unsigned long uploaded;
    unsigned long known;
    unsigned long skipped_local;
    unsigned long failed;
    unsigned long scanned_roots;
    sb_u64 upload_millis;
} SpiceBushStats;

typedef int (*SpiceBushScanCallback)(const char *path, void *context);

void sb_safe_copy(char *dst, size_t dst_size, const char *src);
void sb_path_join(char *dst, size_t dst_size, const char *left, const char *right, char separator);
int sb_ends_with_nocase(const char *text, const char *suffix);
void sb_trim_trailing_slashes(char *text);
const char *sb_basename(const char *path);
int sb_mkdir_if_needed(const char *path);

int sb_compute_fnv1a64(const char *path, char *hex, size_t hex_size, sb_u64 *size_bytes);
int sb_uploaded_contains(const char *uploaded_path, const char *hash, sb_u64 size_bytes);
int sb_mark_uploaded(const char *uploaded_path, const char *hash, sb_u64 size_bytes, const char *path);

int sb_scan_tree(const char *root, int max_depth, SpiceBushScanCallback callback, void *context);

int sb_load_config(SpiceBushConfig *config);
int sb_save_config(const SpiceBushConfig *config);
void sb_build_register_endpoint(const char *site_url, char *endpoint, size_t endpoint_size);
void sb_url_encode(const char *src, char *dst, size_t dst_size);
void sb_json_escape(const char *src, char *dst, size_t dst_size);
int sb_json_string_value(const char *json, const char *key, char *out, size_t out_size);
int sb_json_bool_value(const char *json, const char *key, int *value);

#endif
