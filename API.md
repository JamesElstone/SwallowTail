# SwallowTail API

This document describes the device-facing SwallowTail HTTP API. It is intended
for upload bridges such as an ESP32 WiFi bridge that downloads a CR2 RAW image file
from a camera and uploads it to SwallowTail.

Only `web_root` should be served publicly by the web server. The API paths below
are relative to that public web root.

## Authentication

Device API calls use a bearer upload token:

```http
Authorization: Bearer <upload-token>
```

For older SpiceBush builds, SwallowTail also accepts `X-SwallowTail-Upload-Token`
or `X-Swallowtail-Upload-Token`. `Authorization: Bearer` is preferred for new
clients.

Create and manage upload tokens in the web UI:

1. Sign in as a user with access to the `upload_tokens` settings card.
2. Open `Settings`.
3. Use `Upload Tokens` to create a token.
4. Enter one or more allowed CIDR ranges for the bridge network.
5. Copy the generated token immediately. It is shown only once.

Tokens must be:

- active
- allowed to upload CR2 files
- unexpired, if an expiry is set
- called from an IP address matching at least one configured CIDR

CIDRs support IPv4 and IPv6, for example:

```text
192.168.8.0/24
203.0.113.10/32
2001:db8::/32
```

## Ping

Checks that the API is reachable and that the bearer upload token is still
valid for the caller's network.

Endpoint:

```http
GET /api/remote-ping.php
Authorization: Bearer <upload-token>
```

Successful response:

```json
{
  "success": true,
  "pong": true
}
```

Invalid, expired, disabled, or out-of-CIDR tokens return `success: false` with
an `errors` array.

## Preflight Quick Checksum

Checks whether SwallowTail already knows about a likely matching CR2 before the
caller uploads the full file.

Endpoint:

```http
GET /api/upload-checksum.php?algorithm=sha256&hash=<sha256>&size_bytes=<bytes>
Authorization: Bearer <upload-token>
```

`hash` is required. `size_bytes` is optional but recommended because matching
the SHA-256 checksum and byte size avoids a stale or partial client-side match.

The fixed quick checksum algorithm is `sha256`, returned as 64 lowercase
hexadecimal characters. Older `fnv1a64` quick-check requests are rejected.

Example with `curl`:

```sh
curl "https://swallowtail.example.test/api/upload-checksum.php?algorithm=sha256&hash=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef&size_bytes=31457280" \
  -H "Authorization: Bearer stup_example"
```

Existing file response:

```json
{
  "success": true,
  "exists": true,
  "algorithm": "sha256",
  "hash": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
  "size_bytes": 31457280,
  "matched_on": "hash_size",
  "photo_id": 123
}
```

Missing file response:

```json
{
  "success": true,
  "exists": false,
  "algorithm": "sha256",
  "hash": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
  "size_bytes": 31457280,
  "matched_on": "hash_size",
  "photo_id": null
}
```

The optional `algorithm=sha256` query parameter may be supplied. Missing
`algorithm` defaults to `sha256`; other algorithm names are rejected.

## Upload CR2

Uploads a `.CR2` RAW image file, stores the source file outside `web_root`, records the
photo, and queues conversion jobs for:

- `embedded`
- `original`
- `thumbnail`

Endpoint:

```http
POST /api/upload-raw.php
```

### Raw Body Upload

This is the simplest shape for hardware clients.

```http
POST /api/upload-raw.php
Authorization: Bearer <upload-token>
Content-Type: application/octet-stream
X-Swallowtail-Filename: IMG_0001.CR2
X-Swallowtail-Device-ID: esp32-bridge-001
X-Swallowtail-Checksum-SHA256: <required lowercase sha256 for raw body uploads>

<CR2 bytes>
```

Example with `curl`:

```sh
curl -X POST "https://swallowtail.example.test/api/upload-raw.php" \
  -H "Authorization: Bearer stup_example" \
  -H "Content-Type: application/octet-stream" \
  -H "X-Swallowtail-Filename: IMG_0001.CR2" \
  -H "X-Swallowtail-Device-ID: esp32-bridge-001" \
  -H "X-Swallowtail-Checksum-SHA256: 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef" \
  --data-binary "@IMG_0001.CR2"
```

### Multipart Upload

Use this from desktop tools, test scripts, or HTTP clients that already support
multipart form data.

```http
POST /api/upload-raw.php
Authorization: Bearer <upload-token>
Content-Type: multipart/form-data

raw_file=@IMG_0001.CR2
```

Example with `curl`:

```sh
curl -X POST "https://swallowtail.example.test/api/upload-raw.php" \
  -H "Authorization: Bearer stup_example" \
  -H "X-Swallowtail-Device-ID: esp32-bridge-001" \
  -F "raw_file=@IMG_0001.CR2"
```

The file field may be named `raw_file` or `file`. `raw_file` is preferred.

### Successful Upload Response

New uploads return HTTP `201`.

```json
{
  "success": true,
  "status": "uploaded",
  "duplicate": false,
  "photo_id": 123,
  "sha256": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
  "storage_base_location": "/storage/1",
  "conversion_job_id": 456,
  "conversion_jobs": {
    "embedded": {"job_id": 456, "status": "queued"},
    "original": {"job_id": 457, "status": "queued"},
    "thumbnail": {"job_id": 458, "status": "queued"}
  },
  "warnings": []
}
```

Duplicate uploads return HTTP `200`. No second original is stored.

```json
{
  "success": true,
  "status": "duplicate",
  "duplicate": true,
  "photo_id": 123,
  "sha256": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
  "warnings": []
}
```

## Conversion Status

Returns conversion job state and filesystem-derived image readiness for a photo.

Endpoint:

```http
GET /api/upload-status.php?photo_id=<photo_id>
Authorization: Bearer <upload-token>
```

Example with `curl`:

```sh
curl "https://swallowtail.example.test/api/upload-status.php?photo_id=123" \
  -H "Authorization: Bearer stup_example"
```

Successful response:

```json
{
  "success": true,
  "photo_id": 123,
  "conversion_state": "pending",
  "jobs": {
    "embedded": {"job_id": 456, "status": "queued"},
    "original": {"job_id": 457, "status": "processing"},
    "thumbnail": {"job_id": 458, "status": "succeeded"},
    "preview": {"job_id": null, "status": "not_queued"},
    "final": {"job_id": null, "status": "not_queued"},
    "profile": {"job_id": null, "status": "not_queued"}
  },
  "images": {
    "embedded": {"ready": false},
    "original": {"ready": false},
    "thumbnail": {
      "ready": true,
      "bytes": 12345,
      "modified_at": "2026-06-18T12:00:00+00:00",
      "sha256": "..."
    },
    "preview": {"ready": false},
    "final": {"ready": false},
    "profile": {"ready": false}
  }
}
```

Job statuses can be:

- `not_queued`
- `queued`
- `processing`
- `succeeded`
- `failed`
- `cancelled`

Photo conversion states can be:

- `pending`
- `processing`
- `ready`
- `failed`
- `not_required`

## Error Responses

Errors are JSON and include `success: false`.

Wrong method:

```json
{
  "success": false,
  "errors": ["RAW upload API expects POST."]
}
```

Missing, invalid, expired, disabled, or CIDR-blocked bearer token:

```json
{
  "success": false,
  "errors": ["Bearer upload token was missing, invalid, expired, disabled, or not allowed from this network."]
}
```

Unsupported file type:

```json
{
  "success": false,
  "errors": ["Only .CR2 RAW image files are supported."]
}
```

Checksum mismatch:

```json
{
  "success": false,
  "errors": ["Uploaded RAW checksum did not match the expected SHA-256 value."]
}
```

Invalid quick checksum request:

```json
{
  "success": false,
  "errors": ["Unsupported quick checksum algorithm. Use sha256."]
}
```

Common HTTP status codes:

- `200` duplicate upload or successful status lookup
- `201` new upload accepted and conversion jobs queued
- `400` invalid request, upload error, unsupported file, or checksum mismatch
- `401` bearer token failed authentication or CIDR check
- `404` requested photo was not found
- `405` wrong HTTP method
- `413` RAW upload exceeded the configured size limit
- `429` repeated failed upload-token or registration attempts are temporarily blocked
- `503` database migrations are missing

## ESP32 Bridge Notes

For a small hardware bridge, prefer raw body upload:

1. Download the `.CR2` file from the camera.
2. Compute the SHA-256 checksum and record the file size.
3. Call `GET /api/upload-checksum.php?algorithm=sha256&hash=<sha256>&size_bytes=<bytes>`.
4. If the response has `exists: true`, skip the upload and keep the returned
   `photo_id` if needed.
5. If the response has `exists: false`, keep the SHA-256 for the upload header.
6. Open `POST /api/upload-raw.php`.
7. Send `Authorization`, `X-Swallowtail-Filename`, and
   `X-Swallowtail-Checksum-SHA256`.
8. Stream the CR2 bytes as the request body.
9. Store the returned `photo_id`.
10. Poll `GET /api/upload-status.php?photo_id=<photo_id>` until the needed
   derivatives are ready.

Make sure the bridge's source IP, as seen by PHP, falls inside one of the
token's configured CIDR ranges. If SwallowTail is behind a reverse proxy, the
web server/PHP deployment must expose the real client address consistently.

## SpiceBush Registration

SpiceBush desktop clients can request an upload token without scraping the web UI.
The account must have access to the `upload_tokens` settings card.

Endpoint:

```http
POST /api/upload-register.php
Content-Type: application/json
```

Request:

```json
{
  "username": "user@example.test",
  "password": "account-password",
  "otp_code": "123456",
  "device_id": "spicebush-computer-name",
  "token_label": "SpiceBush computer-name",
  "cidrs": "203.0.113.15/32"
}
```

If the account has OTP enabled, `otp_code` must be the user's current six digit
code. If the account does not use OTP, send an empty string or omit the field.

If `cidrs` is omitted, SwallowTail creates the token for the caller's detected
IP address as `/32` for IPv4 or `/128` for IPv6.

The returned API URL is built from the configured External Base Web URL, or from
trusted reverse-proxy forwarded host and scheme headers. If neither source is
available, registration fails closed rather than guessing a public API URL.

Successful response:

```json
{
  "success": true,
  "token": "stup_example",
  "token_id": 123,
  "api_url": "https://swallowtail.example.test/api",
  "ping_url": "https://swallowtail.example.test/api/remote-ping.php",
  "raw_upload_url": "https://swallowtail.example.test/api/upload-raw.php",
  "quick_checksum_url": "https://swallowtail.example.test/api/upload-checksum.php",
  "quick_checksum_algorithm": "sha256",
  "cidrs": ["203.0.113.15/32"]
}
```

The token is shown only in this response. SpiceBush stores it in
`%APPDATA%\SpiceBush\spicebush.ini` and then uses it as the bearer token for the
upload-checksum and upload-raw calls.
