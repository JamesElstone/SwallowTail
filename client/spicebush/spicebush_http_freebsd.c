#include "spicebush_http.h"
#include "spicebush_shared.h"

#include <errno.h>
#include <netdb.h>
#include <openssl/err.h>
#include <openssl/ssl.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <unistd.h>

#define RAW_UPLOAD_BUFFER_BYTES (4 * 1024 * 1024)

typedef struct SbUrl {
    int secure;
    char host[256];
    char port[16];
    char path[2048];
} SbUrl;

typedef struct SbConnection {
    int fd;
    SSL_CTX *ctx;
    SSL *ssl;
} SbConnection;

static int parse_url(const char *url, SbUrl *parsed)
{
    const char *p;
    const char *host_start;
    const char *path_start;
    const char *port_start;
    size_t host_len;

    memset(parsed, 0, sizeof(*parsed));
    if (strncmp(url, "https://", 8) == 0) {
        parsed->secure = 1;
        host_start = url + 8;
        sb_safe_copy(parsed->port, sizeof(parsed->port), "443");
    } else if (strncmp(url, "http://", 7) == 0) {
        parsed->secure = 0;
        host_start = url + 7;
        sb_safe_copy(parsed->port, sizeof(parsed->port), "80");
    } else {
        return 0;
    }

    path_start = strchr(host_start, '/');
    if (path_start == NULL) {
        path_start = host_start + strlen(host_start);
        sb_safe_copy(parsed->path, sizeof(parsed->path), "/");
    } else {
        sb_safe_copy(parsed->path, sizeof(parsed->path), path_start);
    }

    port_start = NULL;
    for (p = host_start; p < path_start; p++) {
        if (*p == ':') {
            port_start = p;
            break;
        }
    }

    host_len = (size_t)((port_start != NULL ? port_start : path_start) - host_start);
    if (host_len == 0 || host_len >= sizeof(parsed->host)) {
        return 0;
    }
    memcpy(parsed->host, host_start, host_len);
    parsed->host[host_len] = '\0';

    if (port_start != NULL) {
        size_t port_len = (size_t)(path_start - port_start - 1);
        if (port_len == 0 || port_len >= sizeof(parsed->port)) {
            return 0;
        }
        memcpy(parsed->port, port_start + 1, port_len);
        parsed->port[port_len] = '\0';
    }

    return parsed->host[0] != '\0';
}

static int connect_tcp(const char *host, const char *port)
{
    struct addrinfo hints;
    struct addrinfo *results = NULL;
    struct addrinfo *ai;
    int fd = -1;

    memset(&hints, 0, sizeof(hints));
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_STREAM;

    if (getaddrinfo(host, port, &hints, &results) != 0) {
        return -1;
    }
    for (ai = results; ai != NULL; ai = ai->ai_next) {
        fd = socket(ai->ai_family, ai->ai_socktype, ai->ai_protocol);
        if (fd < 0) {
            continue;
        }
        if (connect(fd, ai->ai_addr, ai->ai_addrlen) == 0) {
            break;
        }
        close(fd);
        fd = -1;
    }
    freeaddrinfo(results);
    return fd;
}

static int connection_open(const SbUrl *url, SbConnection *conn)
{
    memset(conn, 0, sizeof(*conn));
    conn->fd = -1;
    conn->fd = connect_tcp(url->host, url->port);
    if (conn->fd < 0) {
        return 0;
    }

    if (url->secure) {
        static int ssl_initialised = 0;
        if (!ssl_initialised) {
            SSL_library_init();
            SSL_load_error_strings();
            OpenSSL_add_ssl_algorithms();
            ssl_initialised = 1;
        }

        conn->ctx = SSL_CTX_new(TLS_client_method());
        if (conn->ctx == NULL) {
            return 0;
        }
        SSL_CTX_set_verify(conn->ctx, SSL_VERIFY_PEER, NULL);
        SSL_CTX_set_default_verify_paths(conn->ctx);
        conn->ssl = SSL_new(conn->ctx);
        if (conn->ssl == NULL) {
            return 0;
        }
        SSL_set_fd(conn->ssl, conn->fd);
        SSL_set_tlsext_host_name(conn->ssl, url->host);
#if OPENSSL_VERSION_NUMBER >= 0x10002000L
        X509_VERIFY_PARAM_set1_host(SSL_get0_param(conn->ssl), url->host, 0);
#endif
        if (SSL_connect(conn->ssl) != 1) {
            return 0;
        }
        if (SSL_get_verify_result(conn->ssl) != X509_V_OK) {
            return 0;
        }
    }

    return 1;
}

static void connection_close(SbConnection *conn)
{
    if (conn->ssl != NULL) {
        SSL_shutdown(conn->ssl);
        SSL_free(conn->ssl);
    }
    if (conn->ctx != NULL) {
        SSL_CTX_free(conn->ctx);
    }
    if (conn->fd >= 0) {
        close(conn->fd);
    }
}

static int connection_write(SbConnection *conn, const void *data, size_t len)
{
    const unsigned char *p = (const unsigned char *)data;
    while (len > 0) {
        int wrote = conn->ssl != NULL
            ? SSL_write(conn->ssl, p, (int)len)
            : (int)send(conn->fd, p, len, 0);
        if (wrote <= 0) {
            return 0;
        }
        p += wrote;
        len -= (size_t)wrote;
    }
    return 1;
}

static int connection_read(SbConnection *conn, char *buf, size_t len)
{
    return conn->ssl != NULL
        ? SSL_read(conn->ssl, buf, (int)len)
        : (int)recv(conn->fd, buf, len, 0);
}

static void extract_body_and_status(char *raw, long *status, char *response, size_t response_size)
{
    char *body;
    *status = 0;
    if (strncmp(raw, "HTTP/", 5) == 0) {
        char *space = strchr(raw, ' ');
        if (space != NULL) {
            *status = strtol(space + 1, NULL, 10);
        }
    }
    body = strstr(raw, "\r\n\r\n");
    if (body != NULL) {
        body += 4;
    } else {
        body = raw;
    }
    if (response != NULL && response_size > 0) {
        sb_safe_copy(response, response_size, body);
    }
}

static int read_response(SbConnection *conn, long *status, char *response, size_t response_size)
{
    char *raw;
    size_t capacity = response_size + 8192;
    size_t used = 0;
    int got;

    raw = (char *)malloc(capacity);
    if (raw == NULL) {
        return 0;
    }
    while (used + 1 < capacity && (got = connection_read(conn, raw + used, capacity - used - 1)) > 0) {
        used += (size_t)got;
    }
    raw[used] = '\0';
    extract_body_and_status(raw, status, response, response_size);
    free(raw);
    return used > 0;
}

static int send_request_headers(
    SbConnection *conn,
    const char *method,
    const SbUrl *url,
    const char *headers,
    unsigned long long content_length
) {
    char request[8192];
    int written = snprintf(
        request,
        sizeof(request),
        "%s %s HTTP/1.0\r\n"
        "Host: %s\r\n"
        "User-Agent: SpiceBush/1.0\r\n"
        "Connection: close\r\n"
        "%s"
        "Content-Length: %llu\r\n"
        "\r\n",
        method,
        url->path,
        url->host,
        headers != NULL ? headers : "",
        content_length
    );
    if (written <= 0 || (size_t)written >= sizeof(request)) {
        return 0;
    }
    return connection_write(conn, request, (size_t)written);
}

int sb_http_request(
    const char *method,
    const char *url_text,
    const char *headers,
    const unsigned char *body,
    size_t body_len,
    long *status,
    char *response,
    size_t response_size
) {
    SbUrl url;
    SbConnection conn;
    int ok = 0;

    if (!parse_url(url_text, &url)) {
        return 0;
    }
    if (!connection_open(&url, &conn)) {
        connection_close(&conn);
        return 0;
    }
    if (!send_request_headers(&conn, method, &url, headers, (unsigned long long)body_len)) {
        goto done;
    }
    if (body_len > 0 && body != NULL && !connection_write(&conn, body, body_len)) {
        goto done;
    }
    ok = read_response(&conn, status, response, response_size);
done:
    connection_close(&conn);
    return ok;
}

int sb_http_upload_file(
    const char *url_text,
    const char *headers,
    const char *path,
    long *status,
    char *response,
    size_t response_size
) {
    SbUrl url;
    SbConnection conn;
    FILE *file = NULL;
    struct stat st;
    unsigned char *buffer = NULL;
    size_t got;
    int ok = 0;

    if (stat(path, &st) != 0 || !parse_url(url_text, &url)) {
        return 0;
    }
    memset(&conn, 0, sizeof(conn));
    buffer = (unsigned char *)malloc(RAW_UPLOAD_BUFFER_BYTES);
    if (buffer == NULL) {
        return 0;
    }
    file = fopen(path, "rb");
    if (file == NULL) {
        free(buffer);
        return 0;
    }
    if (!connection_open(&url, &conn)) {
        connection_close(&conn);
        fclose(file);
        return 0;
    }
    if (!send_request_headers(&conn, "POST", &url, headers, (unsigned long long)st.st_size)) {
        goto done;
    }
    while ((got = fread(buffer, 1, RAW_UPLOAD_BUFFER_BYTES, file)) > 0) {
        if (!connection_write(&conn, buffer, got)) {
            goto done;
        }
    }
    if (ferror(file)) {
        goto done;
    }
    ok = read_response(&conn, status, response, response_size);
done:
    fclose(file);
    free(buffer);
    connection_close(&conn);
    return ok;
}
