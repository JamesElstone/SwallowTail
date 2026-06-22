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

static void sb_trim(char *text);
static int sb_is_sha256_hash(const char *hash);
static int sb_is_legacy_fnv_hash(const char *hash);

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

typedef struct SbSha256 {
    unsigned int state[8];
    unsigned int bitcount_high;
    unsigned int bitcount_low;
    unsigned char buffer[64];
} SbSha256;

static unsigned int sb_rotr32(unsigned int value, unsigned int bits)
{
    return (value >> bits) | (value << (32U - bits));
}

static unsigned int sb_load_be32(const unsigned char *bytes)
{
    return ((unsigned int)bytes[0] << 24)
        | ((unsigned int)bytes[1] << 16)
        | ((unsigned int)bytes[2] << 8)
        | (unsigned int)bytes[3];
}

static void sb_store_be32(unsigned char *bytes, unsigned int value)
{
    bytes[0] = (unsigned char)(value >> 24);
    bytes[1] = (unsigned char)(value >> 16);
    bytes[2] = (unsigned char)(value >> 8);
    bytes[3] = (unsigned char)value;
}

static void sb_sha256_transform(SbSha256 *ctx, const unsigned char block[64])
{
    static const unsigned int k[64] = {
        0x428a2f98U, 0x71374491U, 0xb5c0fbcfU, 0xe9b5dba5U,
        0x3956c25bU, 0x59f111f1U, 0x923f82a4U, 0xab1c5ed5U,
        0xd807aa98U, 0x12835b01U, 0x243185beU, 0x550c7dc3U,
        0x72be5d74U, 0x80deb1feU, 0x9bdc06a7U, 0xc19bf174U,
        0xe49b69c1U, 0xefbe4786U, 0x0fc19dc6U, 0x240ca1ccU,
        0x2de92c6fU, 0x4a7484aaU, 0x5cb0a9dcU, 0x76f988daU,
        0x983e5152U, 0xa831c66dU, 0xb00327c8U, 0xbf597fc7U,
        0xc6e00bf3U, 0xd5a79147U, 0x06ca6351U, 0x14292967U,
        0x27b70a85U, 0x2e1b2138U, 0x4d2c6dfcU, 0x53380d13U,
        0x650a7354U, 0x766a0abbU, 0x81c2c92eU, 0x92722c85U,
        0xa2bfe8a1U, 0xa81a664bU, 0xc24b8b70U, 0xc76c51a3U,
        0xd192e819U, 0xd6990624U, 0xf40e3585U, 0x106aa070U,
        0x19a4c116U, 0x1e376c08U, 0x2748774cU, 0x34b0bcb5U,
        0x391c0cb3U, 0x4ed8aa4aU, 0x5b9cca4fU, 0x682e6ff3U,
        0x748f82eeU, 0x78a5636fU, 0x84c87814U, 0x8cc70208U,
        0x90befffaU, 0xa4506cebU, 0xbef9a3f7U, 0xc67178f2U
    };
    unsigned int w[64];
    unsigned int a, b, c, d, e, f, g, h;
    unsigned int i;

    for (i = 0; i < 16; i++) {
        w[i] = sb_load_be32(block + (i * 4));
    }
    for (i = 16; i < 64; i++) {
        unsigned int s0 = sb_rotr32(w[i - 15], 7) ^ sb_rotr32(w[i - 15], 18) ^ (w[i - 15] >> 3);
        unsigned int s1 = sb_rotr32(w[i - 2], 17) ^ sb_rotr32(w[i - 2], 19) ^ (w[i - 2] >> 10);
        w[i] = w[i - 16] + s0 + w[i - 7] + s1;
    }

    a = ctx->state[0];
    b = ctx->state[1];
    c = ctx->state[2];
    d = ctx->state[3];
    e = ctx->state[4];
    f = ctx->state[5];
    g = ctx->state[6];
    h = ctx->state[7];

    for (i = 0; i < 64; i++) {
        unsigned int s1 = sb_rotr32(e, 6) ^ sb_rotr32(e, 11) ^ sb_rotr32(e, 25);
        unsigned int ch = (e & f) ^ ((~e) & g);
        unsigned int temp1 = h + s1 + ch + k[i] + w[i];
        unsigned int s0 = sb_rotr32(a, 2) ^ sb_rotr32(a, 13) ^ sb_rotr32(a, 22);
        unsigned int maj = (a & b) ^ (a & c) ^ (b & c);
        unsigned int temp2 = s0 + maj;
        h = g;
        g = f;
        f = e;
        e = d + temp1;
        d = c;
        c = b;
        b = a;
        a = temp1 + temp2;
    }

    ctx->state[0] += a;
    ctx->state[1] += b;
    ctx->state[2] += c;
    ctx->state[3] += d;
    ctx->state[4] += e;
    ctx->state[5] += f;
    ctx->state[6] += g;
    ctx->state[7] += h;
}

static void sb_sha256_init(SbSha256 *ctx)
{
    memset(ctx, 0, sizeof(*ctx));
    ctx->state[0] = 0x6a09e667U;
    ctx->state[1] = 0xbb67ae85U;
    ctx->state[2] = 0x3c6ef372U;
    ctx->state[3] = 0xa54ff53aU;
    ctx->state[4] = 0x510e527fU;
    ctx->state[5] = 0x9b05688cU;
    ctx->state[6] = 0x1f83d9abU;
    ctx->state[7] = 0x5be0cd19U;
}

static void sb_sha256_update(SbSha256 *ctx, const unsigned char *data, size_t len)
{
    unsigned int used = (ctx->bitcount_low >> 3) & 63U;
    unsigned int bits = (unsigned int)(len << 3);
    ctx->bitcount_low += bits;
    if (ctx->bitcount_low < bits) {
        ctx->bitcount_high++;
    }
    ctx->bitcount_high += (unsigned int)(len >> 29);

    if (used != 0) {
        unsigned int available = 64U - used;
        if (len < available) {
            memcpy(ctx->buffer + used, data, len);
            return;
        }
        memcpy(ctx->buffer + used, data, available);
        sb_sha256_transform(ctx, ctx->buffer);
        data += available;
        len -= available;
    }

    while (len >= 64) {
        sb_sha256_transform(ctx, data);
        data += 64;
        len -= 64;
    }

    if (len > 0) {
        memcpy(ctx->buffer, data, len);
    }
}

static void sb_sha256_final(SbSha256 *ctx, unsigned char digest[32])
{
    unsigned char length[8];
    unsigned int used = (ctx->bitcount_low >> 3) & 63U;
    unsigned int pad_len;
    unsigned int i;

    sb_store_be32(length, ctx->bitcount_high);
    sb_store_be32(length + 4, ctx->bitcount_low);
    ctx->buffer[used++] = 0x80U;
    if (used > 56U) {
        memset(ctx->buffer + used, 0, 64U - used);
        sb_sha256_transform(ctx, ctx->buffer);
        used = 0;
    }
    pad_len = 56U - used;
    memset(ctx->buffer + used, 0, pad_len);
    memcpy(ctx->buffer + 56, length, sizeof(length));
    sb_sha256_transform(ctx, ctx->buffer);

    for (i = 0; i < 8; i++) {
        sb_store_be32(digest + (i * 4), ctx->state[i]);
    }
}

int sb_compute_sha256(const char *path, char *hex, size_t hex_size, sb_u64 *size_bytes)
{
    unsigned char buffer[65536];
    unsigned char digest[32];
    size_t got;
    FILE *file = fopen(path, "rb");
    sb_u64 total = 0;
    size_t i;
    static const char digits[] = "0123456789abcdef";
    SbSha256 ctx;

    if (file == NULL || hex_size < 65) {
        if (file != NULL) {
            fclose(file);
        }
        return 0;
    }
    sb_sha256_init(&ctx);
    while ((got = fread(buffer, 1, sizeof(buffer), file)) > 0) {
        sb_sha256_update(&ctx, buffer, got);
        total += (sb_u64)got;
    }
    if (ferror(file)) {
        fclose(file);
        return 0;
    }
    fclose(file);
    sb_sha256_final(&ctx, digest);
    for (i = 0; i < sizeof(digest); i++) {
        hex[i * 2] = digits[(digest[i] >> 4) & 15];
        hex[(i * 2) + 1] = digits[digest[i] & 15];
    }
    hex[64] = '\0';
    if (size_bytes != NULL) {
        *size_bytes = total;
    }
    return 1;
}

static int sb_is_sha256_hash(const char *hash)
{
    size_t i;
    if (hash == NULL || strlen(hash) != 64) {
        return 0;
    }
    for (i = 0; i < 64; i++) {
        if (!isxdigit((unsigned char)hash[i])) {
            return 0;
        }
    }
    return 1;
}

static int sb_is_legacy_fnv_hash(const char *hash)
{
    size_t i;
    if (hash == NULL || strlen(hash) != 16) {
        return 0;
    }
    for (i = 0; i < 16; i++) {
        if (!isxdigit((unsigned char)hash[i])) {
            return 0;
        }
    }
    return 1;
}

static int sb_hash_bucket_path(const SpiceBushConfig *config, const char *hash, char *path, size_t path_size)
{
    char name[8];
    if (hash == NULL
        || strlen(hash) < 2
        || !isxdigit((unsigned char)hash[0])
        || !isxdigit((unsigned char)hash[1])) {
        return 0;
    }
    if (!sb_mkdir_if_needed(config->uploaded_dir)) {
        return 0;
    }
    name[0] = (char)tolower((unsigned char)hash[0]);
    name[1] = (char)tolower((unsigned char)hash[1]);
    name[2] = '.';
    name[3] = 't';
    name[4] = 's';
    name[5] = 'v';
    name[6] = '\0';
    sb_path_join(path, path_size, config->uploaded_dir, name, '/');
    return 1;
}

static void sb_parse_uploaded_line(char *line, SpiceBushUploadedRecord *record)
{
    char *hash;
    char *size;
    char *photo_id;
    char *status;
    char *source;

    memset(record, 0, sizeof(*record));
    sb_trim(line);
    hash = line;
    size = strchr(hash, '\t');
    if (size == NULL) {
        return;
    }
    *size++ = '\0';
    photo_id = strchr(size, '\t');
    if (photo_id == NULL) {
        return;
    }
    *photo_id++ = '\0';
    status = strchr(photo_id, '\t');
    if (status == NULL) {
        sb_safe_copy(record->hash, sizeof(record->hash), hash);
        record->size_bytes = (sb_u64)strtoull(size, NULL, 10);
        record->photo_id = 0;
        sb_safe_copy(record->status, sizeof(record->status), "uploaded");
        sb_safe_copy(record->source_path, sizeof(record->source_path), photo_id);
        return;
    }
    *status++ = '\0';
    source = strchr(status, '\t');
    if (source == NULL) {
        return;
    }
    *source++ = '\0';
    sb_safe_copy(record->hash, sizeof(record->hash), hash);
    record->size_bytes = (sb_u64)strtoull(size, NULL, 10);
    record->photo_id = strtoul(photo_id, NULL, 10);
    sb_safe_copy(record->status, sizeof(record->status), status);
    sb_safe_copy(record->source_path, sizeof(record->source_path), source);
}

int sb_uploaded_lookup(const SpiceBushConfig *config, const char *hash, sb_u64 size_bytes, SpiceBushUploadedRecord *record)
{
    FILE *file;
    char path[SB_PATH];
    char line[SB_PATH + 256];

    if (!sb_hash_bucket_path(config, hash, path, sizeof(path))) {
        return 0;
    }
    file = fopen(path, "r");
    if (file == NULL) {
        return 0;
    }
    while (fgets(line, sizeof(line), file) != NULL) {
        SpiceBushUploadedRecord candidate;
        sb_parse_uploaded_line(line, &candidate);
        if (sb_is_sha256_hash(candidate.hash) && strcmp(candidate.hash, hash) == 0 && candidate.size_bytes == size_bytes) {
            if (record != NULL) {
                *record = candidate;
            }
            fclose(file);
            return 1;
        }
    }
    fclose(file);
    return 0;
}

int sb_uploaded_contains(const SpiceBushConfig *config, const char *hash, sb_u64 size_bytes)
{
    return sb_uploaded_lookup(config, hash, size_bytes, NULL);
}

int sb_mark_uploaded(const SpiceBushConfig *config, const char *hash, sb_u64 size_bytes, unsigned long photo_id, const char *status, const char *path)
{
    FILE *file;
    char bucket_path[SB_PATH];

    if (!sb_is_sha256_hash(hash)) {
        return 0;
    }
    if (sb_uploaded_contains(config, hash, size_bytes)) {
        return 1;
    }
    if (!sb_hash_bucket_path(config, hash, bucket_path, sizeof(bucket_path))) {
        return 0;
    }
    file = fopen(bucket_path, "a");
    if (file == NULL) {
        return 0;
    }
    fprintf(file, "%s\t%llu\t%lu\t%s\t%s\n",
        hash,
        (unsigned long long)size_bytes,
        photo_id,
        status != NULL && status[0] != '\0' ? status : "uploaded",
        path != NULL ? path : "");
    fclose(file);
    return 1;
}

int sb_migrate_uploaded_cache(SpiceBushConfig *config)
{
    FILE *file;
    char migrated[SB_PATH];
    char line[SB_PATH + 256];
    int migrated_count = 0;

    if (config->uploaded_path[0] == '\0') {
        return 0;
    }
    file = fopen(config->uploaded_path, "r");
    if (file == NULL) {
        return 0;
    }
    while (fgets(line, sizeof(line), file) != NULL) {
        SpiceBushUploadedRecord record;
        sb_parse_uploaded_line(line, &record);
        if (!sb_is_sha256_hash(record.hash) || record.size_bytes == 0) {
            continue;
        }
        if (record.status[0] == '\0') {
            sb_safe_copy(record.status, sizeof(record.status), "uploaded");
        }
        if (sb_mark_uploaded(config, record.hash, record.size_bytes, record.photo_id, record.status, record.source_path)) {
            migrated_count++;
        }
    }
    fclose(file);
    snprintf(migrated, sizeof(migrated), "%s.migrated", config->uploaded_path);
    remove(migrated);
    rename(config->uploaded_path, migrated);
    return migrated_count;
}

static int sb_count_legacy_rows_in_file(const char *path)
{
    FILE *file;
    char line[SB_PATH + 256];
    int count = 0;

    file = fopen(path, "r");
    if (file == NULL) {
        return 0;
    }
    while (fgets(line, sizeof(line), file) != NULL) {
        SpiceBushUploadedRecord record;
        sb_parse_uploaded_line(line, &record);
        if (sb_is_legacy_fnv_hash(record.hash)) {
            count++;
        }
    }
    fclose(file);
    return count;
}

static int sb_clear_uploaded_cache(const SpiceBushConfig *config)
{
    int deleted = 0;

    if (config->uploaded_path[0] != '\0' && remove(config->uploaded_path) == 0) {
        deleted++;
    }

#ifndef _WIN32
    if (config->uploaded_dir[0] != '\0') {
        DIR *dir = opendir(config->uploaded_dir);
        struct dirent *entry;
        if (dir != NULL) {
            while ((entry = readdir(dir)) != NULL) {
                char path[SB_PATH];
                if (entry->d_name[0] == '.') {
                    continue;
                }
                if (!sb_ends_with_nocase(entry->d_name, ".tsv")) {
                    continue;
                }
                sb_path_join(path, sizeof(path), config->uploaded_dir, entry->d_name, '/');
                if (remove(path) == 0) {
                    deleted++;
                }
            }
            closedir(dir);
        }
    }
#endif

    return deleted;
}

static int sb_count_legacy_uploaded_rows(const SpiceBushConfig *config)
{
    int count = 0;

    if (config->uploaded_path[0] != '\0') {
        count += sb_count_legacy_rows_in_file(config->uploaded_path);
    }

#ifndef _WIN32
    if (config->uploaded_dir[0] != '\0') {
        DIR *dir = opendir(config->uploaded_dir);
        struct dirent *entry;
        if (dir != NULL) {
            while ((entry = readdir(dir)) != NULL) {
                char path[SB_PATH];
                if (entry->d_name[0] == '.') {
                    continue;
                }
                if (!sb_ends_with_nocase(entry->d_name, ".tsv")) {
                    continue;
                }
                sb_path_join(path, sizeof(path), config->uploaded_dir, entry->d_name, '/');
                count += sb_count_legacy_rows_in_file(path);
            }
            closedir(dir);
        }
    }
#endif

    return count;
}

int sb_reset_hash_state_if_needed(SpiceBushConfig *config, int *deleted_files, int *legacy_rows)
{
    int deleted = 0;
    int legacy = sb_count_legacy_uploaded_rows(config);
    int needs_reset = strcmp(config->hash_algorithm, "sha256") != 0 || legacy > 0;
    FILE *next_id;

    if (deleted_files != NULL) {
        *deleted_files = 0;
    }
    if (legacy_rows != NULL) {
        *legacy_rows = legacy;
    }
    if (!needs_reset) {
        return 0;
    }

    if (config->queue_path[0] != '\0' && remove(config->queue_path) == 0) {
        deleted++;
    }
    if (config->queue_done_path[0] != '\0' && remove(config->queue_done_path) == 0) {
        deleted++;
    }
    if (config->queue_next_id_path[0] != '\0' && remove(config->queue_next_id_path) == 0) {
        deleted++;
    }
    deleted += sb_clear_uploaded_cache(config);

    next_id = fopen(config->queue_next_id_path, "w");
    if (next_id != NULL) {
        fputs("1\n", next_id);
        fclose(next_id);
    }
    sb_safe_copy(config->hash_algorithm, sizeof(config->hash_algorithm), "sha256");
    if (deleted_files != NULL) {
        *deleted_files = deleted;
    }
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
        } else if (strcmp(key, "hash_algorithm") == 0) {
            sb_safe_copy(config->hash_algorithm, sizeof(config->hash_algorithm), value);
        } else if (strcmp(key, "server_max_raw_upload_bytes") == 0) {
            config->server_max_raw_upload_bytes = (sb_u64)strtoull(value, NULL, 10);
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
    fprintf(file, "hash_algorithm=sha256\n");
    fprintf(file, "server_max_raw_upload_bytes=%llu\n", (unsigned long long)config->server_max_raw_upload_bytes);
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
        if (strlen(endpoint) + strlen("/register-for-token.php") + 1 < endpoint_size) {
            strcat(endpoint, "/register-for-token.php");
        }
    } else if (strlen(endpoint) + strlen("/api/register-for-token.php") + 1 < endpoint_size) {
        strcat(endpoint, "/api/register-for-token.php");
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

int sb_json_ulong_value(const char *json, const char *key, unsigned long *value)
{
    char needle[128];
    const char *p;
    unsigned long parsed = 0;
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
    while (*p != '\0' && isspace((unsigned char)*p)) {
        p++;
    }
    if (!isdigit((unsigned char)*p)) {
        return 0;
    }
    while (isdigit((unsigned char)*p)) {
        parsed = parsed * 10UL + (unsigned long)(*p - '0');
        p++;
    }
    if (value != NULL) {
        *value = parsed;
    }
    return 1;
}

int sb_json_u64_value(const char *json, const char *key, sb_u64 *value)
{
    char needle[128];
    const char *p;
    sb_u64 parsed = 0;
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
    while (*p != '\0' && isspace((unsigned char)*p)) {
        p++;
    }
    if (!isdigit((unsigned char)*p)) {
        return 0;
    }
    while (isdigit((unsigned char)*p)) {
        parsed = parsed * 10ULL + (sb_u64)(*p - '0');
        p++;
    }
    if (value != NULL) {
        *value = parsed;
    }
    return 1;
}
