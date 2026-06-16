#ifndef SPICEBUSH_HTTP_H
#define SPICEBUSH_HTTP_H

#include <stddef.h>

int sb_http_request(
    const char *method,
    const char *url,
    const char *headers,
    const unsigned char *body,
    size_t body_len,
    long *status,
    char *response,
    size_t response_size
);

int sb_http_upload_file(
    const char *url,
    const char *headers,
    const char *path,
    long *status,
    char *response,
    size_t response_size
);

#endif
