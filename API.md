# SwallowTail API

This document describes the device-facing SwallowTail HTTP API. It is intended
for upload bridges such as an ESP32 WiFi bridge that downloads a Canon CR2 file
from a camera and uploads it to SwallowTail.

Only `web_root` should be served publicly by the web server. The API paths below
are relative to that public web root.

## Authentication

Device API calls use a bearer upload token:

```http
Authorization: Bearer <upload-token>
```

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

## Preflight Quick Checksum

Checks whether SwallowTail already knows about a likely matching CR2 before the
caller uploads the full file.

Endpoint:

```http
GET /api/quick-checksum.php?hash=<fnv1a64>&size_bytes=<bytes>
Authorization: Bearer <upload-token>
```

`hash` is required. `size_bytes` is optional but recommended because matching
the lightweight hash and byte size reduces the chance of a false positive.

The fixed quick checksum algorithm is `fnv1a64`, returned as 16 lowercase
hexadecimal characters. It is FNV-1a 64-bit:

1. Start with offset basis `0xcbf29ce484222325`.
2. For each input byte, XOR the byte into the hash.
3. Multiply by prime `0x100000001b3`.
4. Keep the low 64 bits after each multiply.
5. Format the final 64-bit value as 16 lowercase hex characters.

This is intentionally not a cryptographic checksum. SwallowTail still computes
and stores SHA-256 during upload. The quick checksum is for cheap preflight
deduplication on small hardware clients.

Example with `curl`:

```sh
curl "https://swallowtail.example.test/api/quick-checksum.php?hash=8f7e1c2d3a4b5960&size_bytes=31457280" \
  -H "Authorization: Bearer stup_example"
```

Existing file response:

```json
{
  "success": true,
  "exists": true,
  "algorithm": "fnv1a64",
  "hash": "8f7e1c2d3a4b5960",
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
  "algorithm": "fnv1a64",
  "hash": "8f7e1c2d3a4b5960",
  "size_bytes": 31457280,
  "matched_on": "hash_size",
  "photo_id": null
}
```

The optional `algorithm=fnv1a64` query parameter may be supplied, but other
algorithm names are rejected.

## Upload CR2

Uploads a Canon `.CR2` file, stores the original outside `web_root`, records the
photo, and queues derivative conversion jobs for:

- `original_jpeg`
- `preview`
- `thumbnail`
- `jpeg`

Endpoint:

```http
POST /api/raw-upload.php
```

### Raw Body Upload

This is the simplest shape for hardware clients.

```http
POST /api/raw-upload.php
Authorization: Bearer <upload-token>
Content-Type: application/octet-stream
X-Swallowtail-Filename: IMG_0001.CR2
X-Swallowtail-Device-ID: esp32-bridge-001
X-Swallowtail-Checksum-SHA256: <optional lowercase sha256>

<CR2 bytes>
```

Example with `curl`:

```sh
curl -X POST "https://swallowtail.example.test/api/raw-upload.php" \
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
POST /api/raw-upload.php
Authorization: Bearer <upload-token>
Content-Type: multipart/form-data

raw_file=@IMG_0001.CR2
```

Example with `curl`:

```sh
curl -X POST "https://swallowtail.example.test/api/raw-upload.php" \
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
  "quick_hash": "8f7e1c2d3a4b5960",
  "storage_path": "originals/01/23/0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef.cr2",
  "conversion_job_id": 456,
  "conversion_jobs": {
    "original_jpeg": {"job_id": 456, "status": "queued"},
    "preview": {"job_id": 457, "status": "queued"},
    "thumbnail": {"job_id": 458, "status": "queued"},
    "jpeg": {"job_id": 459, "status": "queued"}
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
  "quick_hash": "8f7e1c2d3a4b5960",
  "warnings": []
}
```

## Conversion Status

Returns conversion job state and derivative readiness for a photo.

Endpoint:

```http
GET /api/conversion-status.php?photo_id=<photo_id>
Authorization: Bearer <upload-token>
```

Example with `curl`:

```sh
curl "https://swallowtail.example.test/api/conversion-status.php?photo_id=123" \
  -H "Authorization: Bearer stup_example"
```

Successful response:

```json
{
  "success": true,
  "photo_id": 123,
  "conversion_state": "pending",
  "jobs": {
    "original_jpeg": {"job_id": 456, "status": "queued"},
    "preview": {"job_id": 457, "status": "processing"},
    "thumbnail": {"job_id": 458, "status": "succeeded"},
    "jpeg": {"job_id": 459, "status": "queued"}
  },
  "derivatives": {
    "original_jpeg": {"ready": false},
    "preview": {"ready": false},
    "thumbnail": {
      "ready": true,
      "storage_path": "derivatives/thumbnail/01/23/0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef_thumbnail.jpg",
      "bytes": 12345,
      "generated_at": "2026-06-16 12:00:00"
    },
    "jpeg": {"ready": false}
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
  "errors": ["Only Canon .CR2 RAW files are supported."]
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
  "errors": ["Unsupported quick checksum algorithm. Use fnv1a64."]
}
```

Common HTTP status codes:

- `200` duplicate upload or successful status lookup
- `201` new upload accepted and conversion jobs queued
- `400` invalid request, upload error, unsupported file, or checksum mismatch
- `401` bearer token failed authentication or CIDR check
- `404` requested photo was not found
- `405` wrong HTTP method
- `503` database migrations are missing

## ESP32 Bridge Notes

For a small hardware bridge, prefer raw body upload:

1. Download the `.CR2` file from the camera.
2. Compute the FNV-1a 64-bit quick checksum and record the file size.
3. Call `GET /api/quick-checksum.php?hash=<fnv1a64>&size_bytes=<bytes>`.
4. If the response has `exists: true`, skip the upload and keep the returned
   `photo_id` if needed.
5. If the response has `exists: false`, compute SHA-256 while streaming if
   practical.
6. Open `POST /api/raw-upload.php`.
7. Send `Authorization`, `X-Swallowtail-Filename`, and optionally
   `X-Swallowtail-Checksum-SHA256`.
8. Stream the CR2 bytes as the request body.
9. Store the returned `photo_id`.
10. Poll `GET /api/conversion-status.php?photo_id=<photo_id>` until the needed
   derivatives are ready.

Make sure the bridge's source IP, as seen by PHP, falls inside one of the
token's configured CIDR ranges. If SwallowTail is behind a reverse proxy, the
web server/PHP deployment must expose the real client address consistently.

## SpiceBush Registration

SpiceBush desktop clients can request an upload token without scraping the web UI.
The account must have access to the `upload_tokens` settings card.

Endpoint:

```http
POST /api/spicebush-register.php
Content-Type: application/json
```

Request:

```json
{
  "username": "user@example.test",
  "password": "account-password",
  "device_id": "spicebush-computer-name",
  "token_label": "SpiceBush computer-name",
  "cidrs": "203.0.113.15/32"
}
```

If `cidrs` is omitted, SwallowTail creates the token for the caller's detected
IP address as `/32` for IPv4 or `/128` for IPv6.

Successful response:

```json
{
  "success": true,
  "token": "stup_example",
  "token_id": 123,
  "api_url": "https://swallowtail.example.test/api",
  "raw_upload_url": "https://swallowtail.example.test/api/raw-upload.php",
  "quick_checksum_url": "https://swallowtail.example.test/api/quick-checksum.php",
  "quick_checksum_algorithm": "fnv1a64",
  "cidrs": ["203.0.113.15/32"]
}
```

The token is shown only in this response. SpiceBush stores it in
`%APPDATA%\SpiceBush\spicebush.ini` and then uses it as the bearer token for the
quick-checksum and raw-upload calls.
