#include "spicebush_http.h"
#include "spicebush_shared.h"

#include <sys/param.h>
#include <sys/mount.h>
#include <sys/stat.h>

#include <errno.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <termios.h>
#include <time.h>
#include <unistd.h>

#define RAW_UPLOAD_RETRY 0
#define RAW_UPLOAD_OK 1
#define RAW_UPLOAD_REJECT_OVERSIZE 2

typedef struct SpiceBushCli {
    SpiceBushConfig config;
    SpiceBushStats stats;
} SpiceBushCli;

static unsigned long tick_millis(void)
{
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (unsigned long)(ts.tv_sec * 1000UL + ts.tv_nsec / 1000000UL);
}

static void usage(const char *argv0)
{
    printf("SpiceBush FreeBSD CLI\n");
    printf("\n");
    printf("Usage:\n");
    printf("  %s --register <url> <username> [password] [otp]\n", argv0);
    printf("  %s --scan <path>\n", argv0);
    printf("  %s --scan-existing\n", argv0);
    printf("  %s --watch [seconds]\n", argv0);
    printf("  %s --stats\n", argv0);
    printf("\n");
    printf("Config: ~/.spicebush/spicebush.ini\n");
    printf("TLS: FreeBSD base OpenSSL, no ports required.\n");
}

static int prompt_input(const char *prompt, char *buffer, size_t buffer_size, int hide_input)
{
    struct termios old_term;
    struct termios new_term;
    int restore_term = 0;
    char *newline;

    if (buffer_size == 0) {
        return 0;
    }
    buffer[0] = '\0';
    fputs(prompt, stdout);
    fflush(stdout);

    if (hide_input && isatty(STDIN_FILENO) && tcgetattr(STDIN_FILENO, &old_term) == 0) {
        new_term = old_term;
        new_term.c_lflag &= (tcflag_t)~ECHO;
        if (tcsetattr(STDIN_FILENO, TCSAFLUSH, &new_term) == 0) {
            restore_term = 1;
        }
    }

    if (fgets(buffer, (int)buffer_size, stdin) == NULL) {
        if (restore_term) {
            tcsetattr(STDIN_FILENO, TCSAFLUSH, &old_term);
            fputc('\n', stdout);
        }
        buffer[0] = '\0';
        return 0;
    }

    if (restore_term) {
        tcsetattr(STDIN_FILENO, TCSAFLUSH, &old_term);
        fputc('\n', stdout);
    }

    newline = strpbrk(buffer, "\r\n");
    if (newline != NULL) {
        *newline = '\0';
    }

    return 1;
}

static void init_config(SpiceBushConfig *config)
{
    const char *home = getenv("HOME");
    char hostname[80];

    memset(config, 0, sizeof(*config));
    if (home == NULL || home[0] == '\0') {
        home = ".";
    }
    sb_path_join(config->app_dir, sizeof(config->app_dir), home, ".spicebush", '/');
    sb_mkdir_if_needed(config->app_dir);
    sb_path_join(config->ini_path, sizeof(config->ini_path), config->app_dir, "spicebush.ini", '/');
    sb_path_join(config->queue_path, sizeof(config->queue_path), config->app_dir, "queue.tsv", '/');
    sb_path_join(config->queue_done_path, sizeof(config->queue_done_path), config->app_dir, "queue-done.tsv", '/');
    sb_path_join(config->queue_next_id_path, sizeof(config->queue_next_id_path), config->app_dir, "queue-next-id.txt", '/');
    sb_path_join(config->uploaded_path, sizeof(config->uploaded_path), config->app_dir, "uploaded.tsv", '/');
    sb_path_join(config->uploaded_dir, sizeof(config->uploaded_dir), config->app_dir, "uploaded", '/');
    sb_mkdir_if_needed(config->uploaded_dir);
    sb_migrate_uploaded_cache(config);

    hostname[0] = '\0';
    gethostname(hostname, sizeof(hostname) - 1);
    hostname[sizeof(hostname) - 1] = '\0';
    if (hostname[0] == '\0') {
        sb_safe_copy(hostname, sizeof(hostname), "freebsd");
    }
    sb_safe_copy(config->device_id, sizeof(config->device_id), hostname);

    if (!sb_load_config(config)) {
        sb_save_config(config);
        sb_load_config(config);
    }
    {
        int deleted_files = 0;
        int legacy_rows = 0;
        int changed = 0;
        if (sb_reset_hash_state_if_needed(config, &deleted_files, &legacy_rows)) {
            printf(
                "SpiceBush reset legacy hash state for SHA-256: deleted_files=%d legacy_rows=%d\n",
                deleted_files,
                legacy_rows
            );
            changed = 1;
        }
        if (sb_normalise_device_id(config->device_id, sizeof(config->device_id))) {
            changed = 1;
        }
        if (changed) {
            sb_save_config(config);
        }
    }
}

static int require_registered(const SpiceBushConfig *config)
{
    if (config->api_url[0] == '\0' || config->upload_token[0] == '\0') {
        fprintf(stderr, "SpiceBush is not registered. Run --register first.\n");
        return 0;
    }
    return 1;
}

static int ping_server(SpiceBushCli *cli, int quiet)
{
    char url[SB_TEXT * 2];
    char headers[SB_TEXT * 2];
    char response[4096];
    long status = 0;
    int pong = 0;
    sb_u64 max_raw_upload_bytes = 0;

    if (!require_registered(&cli->config)) {
        return 0;
    }

    snprintf(url, sizeof(url), "%s/ping.php", cli->config.api_url);
    snprintf(
        headers,
        sizeof(headers),
        "Authorization: Bearer %s\r\n"
        "X-SwallowTail-Upload-Token: %s\r\n",
        cli->config.upload_token,
        cli->config.upload_token
    );

    if (!sb_http_request("GET", url, headers, NULL, 0, &status, response, sizeof(response))) {
        if (!quiet) {
            fprintf(stderr, "Ping request failed before an HTTP response was received.\n");
        }
        return 0;
    }
    if (status != 200 || !sb_json_bool_value(response, "pong", &pong) || !pong) {
        if (!quiet) {
            fprintf(stderr, "Ping failed with HTTP %ld.\n", status);
            fprintf(stderr, "%s\n", response);
        }
        return 0;
    }

    if (!sb_json_u64_value(response, "max_raw_upload_bytes", &max_raw_upload_bytes) || max_raw_upload_bytes == 0) {
        if (!quiet) {
            fprintf(stderr, "Ping succeeded but did not report a usable max_raw_upload_bytes value.\n");
        }
        return 0;
    }

    cli->config.server_max_raw_upload_bytes = max_raw_upload_bytes;
    sb_save_config(&cli->config);
    if (!quiet) {
        printf("Server RAW upload limit: %llu bytes\n", (unsigned long long)max_raw_upload_bytes);
    }
    return 1;
}

static unsigned long next_queue_id(SpiceBushConfig *config)
{
    FILE *file;
    unsigned long id = 1;

    file = fopen(config->queue_next_id_path, "r");
    if (file != NULL) {
        if (fscanf(file, "%lu", &id) != 1 || id == 0) {
            id = 1;
        }
        fclose(file);
    }

    file = fopen(config->queue_next_id_path, "w");
    if (file != NULL) {
        fprintf(file, "%lu\n", id + 1);
        fclose(file);
    }

    return id;
}

static void queue_retry(SpiceBushConfig *config, const char *path)
{
    FILE *queue = fopen(config->queue_path, "a");
    if (queue != NULL) {
        fprintf(queue, "%lu\t%s\n", next_queue_id(config), path);
        fclose(queue);
    }
}

static int register_client(SpiceBushCli *cli, const char *url, const char *username, const char *password, const char *otp_code)
{
    char endpoint[SB_TEXT * 2];
    char escaped_user[SB_TEXT * 2];
    char escaped_password[SB_TEXT * 2];
    char escaped_otp[80];
    char escaped_device[256];
    char json[SB_TEXT * 6];
    char response[8192];
    long status = 0;

    sb_build_register_endpoint(url, endpoint, sizeof(endpoint));
    sb_json_escape(username, escaped_user, sizeof(escaped_user));
    sb_json_escape(password, escaped_password, sizeof(escaped_password));
    sb_json_escape(otp_code, escaped_otp, sizeof(escaped_otp));
    sb_json_escape(cli->config.device_id, escaped_device, sizeof(escaped_device));
    snprintf(
        json,
        sizeof(json),
        "{\"username\":\"%s\",\"password\":\"%s\",\"otp_code\":\"%s\",\"device_id\":\"%s\",\"token_label\":\"SpiceBush %s\"}",
        escaped_user,
        escaped_password,
        escaped_otp,
        escaped_device,
        escaped_device
    );

    printf("Registering with %s\n", endpoint);
    if (!sb_http_request(
            "POST",
            endpoint,
            "Content-Type: application/json\r\n",
            (const unsigned char *)json,
            strlen(json),
            &status,
            response,
            sizeof(response))) {
        fprintf(stderr, "Registration request failed before an HTTP response was received.\n");
        return 0;
    }

    if (status != 200
        || !sb_json_string_value(response, "token", cli->config.upload_token, sizeof(cli->config.upload_token))
        || !sb_json_string_value(response, "api_url", cli->config.api_url, sizeof(cli->config.api_url))) {
        fprintf(stderr, "Registration failed with HTTP %ld.\n", status);
        fprintf(stderr, "%s\n", response);
        return 0;
    }

    sb_safe_copy(cli->config.site_url, sizeof(cli->config.site_url), url);
    if (!sb_save_config(&cli->config)) {
        fprintf(stderr, "Registration succeeded, but config could not be saved at %s\n", cli->config.ini_path);
        return 0;
    }

    printf("Registered. API URL: %s\n", cli->config.api_url);
    return ping_server(cli, 0);
}

static int server_knows_file(const SpiceBushConfig *config, const char *hash, sb_u64 size_bytes, unsigned long *photo_id)
{
    char encoded_hash[128];
    char url[SB_TEXT * 3];
    char headers[SB_TEXT + 128];
    char response[4096];
    long status = 0;
    int exists = 0;
    if (photo_id != NULL) {
        *photo_id = 0;
    }

    sb_url_encode(hash, encoded_hash, sizeof(encoded_hash));
    snprintf(
        url,
        sizeof(url),
        "%s/quick-checksum.php?algorithm=sha256&hash=%s&size_bytes=%llu",
        config->api_url,
        encoded_hash,
        (unsigned long long)size_bytes
    );
    snprintf(headers, sizeof(headers), "Authorization: Bearer %s\r\n", config->upload_token);

    if (!sb_http_request("GET", url, headers, NULL, 0, &status, response, sizeof(response))) {
        return 0;
    }
    if (status == 200 && sb_json_bool_value(response, "exists", &exists) && exists) {
        sb_json_ulong_value(response, "photo_id", photo_id);
        return 1;
    }
    return 0;
}

static int upload_file(const SpiceBushConfig *config, const char *path, const char *hash, sb_u64 size_bytes)
{
    char url[SB_TEXT * 2];
    char headers[SB_TEXT * 2];
    char response[4096];
    long status = 0;
    unsigned long photo_id = 0;
    int duplicate = 0;

    snprintf(url, sizeof(url), "%s/raw-upload.php", config->api_url);
    snprintf(
        headers,
        sizeof(headers),
        "Authorization: Bearer %s\r\n"
        "Content-Type: application/octet-stream\r\n"
        "X-Swallowtail-Filename: %s\r\n"
        "X-Swallowtail-Checksum-SHA256: %s\r\n"
        "X-Swallowtail-Device-ID: %s\r\n",
        config->upload_token,
        sb_basename(path),
        hash,
        config->device_id
    );

    if (!sb_http_upload_file(url, headers, path, &status, response, sizeof(response))) {
        return 0;
    }
    if ((status == 200 || status == 201) && strstr(response, "\"success\":true") != NULL) {
        sb_json_ulong_value(response, "photo_id", &photo_id);
        sb_json_bool_value(response, "duplicate", &duplicate);
        sb_mark_uploaded(config, hash, size_bytes, photo_id, duplicate ? "duplicate" : "uploaded", path);
        return RAW_UPLOAD_OK;
    }
    fprintf(stderr, "Upload failed with HTTP %ld for %s\n%s\n", status, path, response);
    if (status == 413
        || strstr(response, "exceeded the configured size limit") != NULL
        || strstr(response, "exceeded the configured upload limit") != NULL) {
        return RAW_UPLOAD_REJECT_OVERSIZE;
    }
    return RAW_UPLOAD_RETRY;
}

static int process_cr2(const char *path, void *context)
{
    SpiceBushCli *cli = (SpiceBushCli *)context;
    char hash[65];
    sb_u64 size_bytes = 0;
    unsigned long start;
    unsigned long photo_id = 0;
    int upload_result = RAW_UPLOAD_RETRY;

    cli->stats.found++;
    printf("found: %s\n", path);

    if (!sb_compute_sha256(path, hash, sizeof(hash), &size_bytes)) {
        cli->stats.failed++;
        printf("  failed: could not checksum\n");
        return 1;
    }

    if (sb_uploaded_contains(&cli->config, hash, size_bytes)) {
        cli->stats.skipped_local++;
        printf("  skipped: local uploaded cache has sha256=%s %llu\n", hash, (unsigned long long)size_bytes);
        return 1;
    }

    if (server_knows_file(&cli->config, hash, size_bytes, &photo_id)) {
        cli->stats.known++;
        sb_mark_uploaded(&cli->config, hash, size_bytes, photo_id, "server_known", path);
        printf("  known: SwallowTail already has sha256=%s\n", hash);
        return 1;
    }

    if (cli->config.server_max_raw_upload_bytes == 0) {
        ping_server(cli, 1);
    }
    if (cli->config.server_max_raw_upload_bytes > 0 && size_bytes > cli->config.server_max_raw_upload_bytes) {
        cli->stats.rejected_oversize++;
        printf(
            "  rejected: file is over the SwallowTail upload limit (%llu > %llu bytes)\n",
            (unsigned long long)size_bytes,
            (unsigned long long)cli->config.server_max_raw_upload_bytes
        );
        return 1;
    }

    start = tick_millis();
    printf("  uploading: sha256=%s bytes=%llu\n", hash, (unsigned long long)size_bytes);
    upload_result = upload_file(&cli->config, path, hash, size_bytes);
    if (upload_result == RAW_UPLOAD_OK) {
        cli->stats.uploaded++;
        cli->stats.upload_millis += (sb_u64)(tick_millis() - start);
        printf("  uploaded\n");
    } else if (upload_result == RAW_UPLOAD_REJECT_OVERSIZE) {
        cli->stats.rejected_oversize++;
        printf("  rejected: upload exceeded the SwallowTail upload limit\n");
    } else {
        cli->stats.failed++;
        queue_retry(&cli->config, path);
        printf("  queued for retry: %s\n", cli->config.queue_path);
    }

    return 1;
}

static void print_stats(const SpiceBushCli *cli)
{
    unsigned long avg = cli->stats.uploaded > 0
        ? (unsigned long)(cli->stats.upload_millis / cli->stats.uploaded)
        : 0;

    printf("\nSpiceBush statistics\n");
    printf("  CR2 found:              %lu\n", cli->stats.found);
    printf("  Uploaded:               %lu\n", cli->stats.uploaded);
    printf("  Already known:          %lu\n", cli->stats.known);
    printf("  Skipped local cache:    %lu\n", cli->stats.skipped_local);
    printf("  Failed or queued:       %lu\n", cli->stats.failed);
    printf("  Rejected over limit:    %lu\n", cli->stats.rejected_oversize);
    printf("  Scanned roots:          %lu\n", cli->stats.scanned_roots);
    printf("  Server upload limit:    %llu bytes\n", (unsigned long long)cli->config.server_max_raw_upload_bytes);
    printf("  Average upload time:    %lu ms\n", avg);
    printf("  Config:                 %s\n", cli->config.ini_path);
}

static int scan_path(SpiceBushCli *cli, const char *path, int max_depth)
{
    cli->stats.scanned_roots++;
    printf("Scanning %s\n", path);
    sb_scan_tree(path, max_depth, process_cr2, cli);
    return 1;
}

static int scan_existing_mounts(SpiceBushCli *cli)
{
    struct statfs *mounts = NULL;
    int count = getmntinfo(&mounts, MNT_NOWAIT);
    int i;

    if (count <= 0 || mounts == NULL) {
        fprintf(stderr, "No mounted filesystems were reported by getmntinfo().\n");
        return 0;
    }

    for (i = 0; i < count; i++) {
        if ((mounts[i].f_flags & MNT_LOCAL) == 0) {
            continue;
        }
        scan_path(cli, mounts[i].f_mntonname, -1);
    }
    return 1;
}

static int mount_seen(char seen[][MNAMELEN], int seen_count, const char *path)
{
    int i;
    for (i = 0; i < seen_count; i++) {
        if (strcmp(seen[i], path) == 0) {
            return 1;
        }
    }
    return 0;
}

static void watch_mounts(SpiceBushCli *cli, int seconds)
{
    char seen[256][MNAMELEN];
    int seen_count = 0;
    struct statfs *initial_mounts = NULL;
    int initial_count = getmntinfo(&initial_mounts, MNT_NOWAIT);
    int initial_i;

    for (initial_i = 0; initial_i < initial_count && initial_mounts != NULL; initial_i++) {
        if ((initial_mounts[initial_i].f_flags & MNT_LOCAL) == 0 || seen_count >= 256) {
            continue;
        }
        sb_safe_copy(seen[seen_count], sizeof(seen[seen_count]), initial_mounts[initial_i].f_mntonname);
        seen_count++;
    }

    printf("Watching local mount points every %d seconds. Press Ctrl+C to stop.\n", seconds);
    for (;;) {
        struct statfs *mounts = NULL;
        int count = getmntinfo(&mounts, MNT_NOWAIT);
        int i;

        for (i = 0; i < count && mounts != NULL; i++) {
            if ((mounts[i].f_flags & MNT_LOCAL) == 0) {
                continue;
            }
            if (!mount_seen(seen, seen_count, mounts[i].f_mntonname)) {
                if (seen_count < 256) {
                    sb_safe_copy(seen[seen_count], sizeof(seen[seen_count]), mounts[i].f_mntonname);
                    seen_count++;
                }
                printf("New local mount: %s\n", mounts[i].f_mntonname);
                scan_path(cli, mounts[i].f_mntonname, 3);
                print_stats(cli);
            }
        }
        sleep((unsigned int)seconds);
    }
}

int main(int argc, char **argv)
{
    SpiceBushCli cli;

    memset(&cli, 0, sizeof(cli));
    init_config(&cli.config);

    if (argc < 2 || strcmp(argv[1], "--help") == 0 || strcmp(argv[1], "-h") == 0) {
        usage(argv[0]);
        return argc < 2 ? 1 : 0;
    }

    if (strcmp(argv[1], "--register") == 0) {
        char password_buffer[SB_TEXT];
        char otp_buffer[80];
        const char *password;
        const char *otp_code;

        if (argc < 4 || argc > 6) {
            usage(argv[0]);
            return 1;
        }

        password = argc >= 5 ? argv[4] : password_buffer;
        otp_code = argc >= 6 ? argv[5] : otp_buffer;

        if (argc < 5 && !prompt_input("Password: ", password_buffer, sizeof(password_buffer), 1)) {
            fprintf(stderr, "Could not read password.\n");
            return 1;
        }
        if (password[0] == '\0') {
            fprintf(stderr, "Password is required.\n");
            return 1;
        }
        if (argc < 6 && !prompt_input("OTP code, or press Enter if not enabled: ", otp_buffer, sizeof(otp_buffer), 0)) {
            memset(password_buffer, 0, sizeof(password_buffer));
            fprintf(stderr, "Could not read OTP code.\n");
            return 1;
        }

        if (register_client(&cli, argv[2], argv[3], password, otp_code)) {
            memset(password_buffer, 0, sizeof(password_buffer));
            memset(otp_buffer, 0, sizeof(otp_buffer));
            return 0;
        }

        memset(password_buffer, 0, sizeof(password_buffer));
        memset(otp_buffer, 0, sizeof(otp_buffer));
        return 1;
    }

    if (strcmp(argv[1], "--stats") == 0) {
        print_stats(&cli);
        return 0;
    }

    if (!require_registered(&cli.config)) {
        return 1;
    }

    if (strcmp(argv[1], "--scan") == 0) {
        const char *path = argc >= 3 ? argv[2] : ".";
        scan_path(&cli, path, -1);
        print_stats(&cli);
        return 0;
    }

    if (strcmp(argv[1], "--scan-existing") == 0) {
        scan_existing_mounts(&cli);
        print_stats(&cli);
        return 0;
    }

    if (strcmp(argv[1], "--watch") == 0) {
        int seconds = argc >= 3 ? atoi(argv[2]) : 5;
        if (seconds <= 0) {
            seconds = 5;
        }
        watch_mounts(&cli, seconds);
        return 0;
    }

    usage(argv[0]);
    return 1;
}
