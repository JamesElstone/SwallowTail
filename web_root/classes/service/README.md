# Swallowtail Service Classes

Swallowtail
Copyright (c) 2026 James Elstone
Licensed under the BSD 3-Clause License.
See `LICENSE` for details.

This folder contains both eelKit framework services and Swallowtail application services.

Files with the eelKit framework header are part of eelKit and should not be altered for
Swallowtail feature work. Files with the Swallowtail header belong to this application
and provide the photo workflow that sits on top of eelKit.

## Swallowtail Services

### `SwallowtailStorageService`

Owns filesystem safety for private photo storage.

- Keeps storage outside `web_root`.
- Normalises and validates storage roots and relative paths.
- Generates checksum-based paths for originals and derivatives.
- Reads configured storage locations from `swallowtail_storage_locations`.
- Chooses a writable storage location that is active, not read-only, not marked full, and has enough free space above its reserve.
- Copies or moves RAW files into managed storage.
- Moves stored files between mounted storage locations with SHA-256 verification before and after the copy.

This service should be the only place that turns an internal storage path into an absolute filesystem path.

### `SwallowtailStorageLocationService`

Provides the backend surface that a future UI can use to manage mounted disks.

- Registers storage locations.
- Lists storage locations with current writable status.
- Marks a location as full or read-only.
- Moves a photo original from one storage location to another.
- Updates the photo's `storage_location_id` after a verified move.
- Writes an audit record for storage moves.

The UI should configure storage locations in the database rather than writing path settings directly into code.

### `SwallowtailPhotoIngestService`

Coordinates RAW file ingest.

- Accepts a local uploaded RAW file path.
- Validates `.CR2` and `.CR3` filenames.
- Checks for a plausible Canon RAW signature.
- Computes the SHA-256 checksum.
- Detects duplicate uploads by checksum before storing another copy.
- Stores new originals through `SwallowtailStorageService`.
- Records the photo through `SwallowtailPhotoLibraryService`.
- Queues derivative generation through `SwallowtailConversionQueueService`.

New uploads are unassigned by default, so event viewers cannot see them until an editor assigns them to an event.

### `SwallowtailPhotoLibraryService`

Owns database persistence for the photo library.

- Checks whether the Swallowtail schema is available.
- Looks up photos by ID or checksum.
- Records RAW uploads and duplicate detections.
- Creates events.
- Assigns photos to events.
- Grants event permissions to users.
- Creates and authenticates API upload tokens.
- Records photo audit events.

This service is deliberately database-focused. Filesystem writes should go through `SwallowtailStorageService`.

### `SwallowtailRawUploadApiService`

Implements the backend API workflow for RAW uploads.

- Expects `POST`.
- Authenticates upload clients using a bearer token or `X-Swallowtail-Upload-Token`.
- Accepts multipart uploads using `raw_file` or `file`.
- Can also read a raw request body for simpler hardware clients.
- Supports optional `X-Swallowtail-Checksum-SHA256` verification.
- Passes upload metadata such as device ID, IP address, and user agent into audit logging.
- Returns JSON responses suitable for future ESP32 or Windows uploader clients.

The public entrypoint is `web_root/api/raw-upload.php`.

### `SwallowtailEventAccessService`

Centralises event/photo permission checks.

- Checks whether a user can see an event.
- Checks whether a user can view a photo through an assigned event.
- Checks single JPEG download permission.
- Checks event ZIP download permission.
- Checks all-accessible download permission.
- Checks RAW-original download permission.

The default posture is least privilege: no event permission means no access.

### `SwallowtailConversionQueueService`

Provides a small database-backed queue facade for derivative generation.

- Enqueues RAW derivative jobs.
- Avoids creating duplicate queued or processing jobs for the same photo.
- Lists queued jobs ordered by priority and age.

Actual RAW-to-JPEG conversion workers are out of scope for these PHP services. If a worker is added later, it should live under `python/worker/...`.

## Upload Flow

1. A device or application posts a RAW file to `web_root/api/raw-upload.php`.
2. `SwallowtailRawUploadApiService` authenticates the upload token.
3. `SwallowtailPhotoIngestService` validates the RAW file and computes its checksum.
4. If the checksum already exists, the duplicate is audited and no second original is stored.
5. If the checksum is new, `SwallowtailStorageService` chooses a writable storage location and stores the original.
6. `SwallowtailPhotoLibraryService` records the photo as uploaded and unassigned.
7. `SwallowtailConversionQueueService` queues derivative generation.
8. Editors can later assign the photo to one or more events.
9. `SwallowtailEventAccessService` controls viewing and download decisions.

## Storage Locations

Storage is designed for multiple mounted disks.

The `swallowtail_storage_locations` table records each mount/root with:

- `root_path`
- `location_label`
- `reserve_bytes`
- `sort_order`
- `is_active`
- `is_read_only`
- `is_full`

When storing a new original, the backend chooses the first configured location that can write the required number of bytes. Locations marked full or read-only are skipped. If no database locations exist, the service falls back to the configured default storage root.

Stored photos reference their location using `swallowtail_photos.storage_location_id`. This lets the backend move files from one mounted disk to another and update the database without changing the internal relative path used for the original.

## Related Database Tables

The Swallowtail services expect the migration `2026_05_31_001_swallowtail_photo_services.sql` to have run. It creates:

- `swallowtail_events`
- `swallowtail_storage_locations`
- `swallowtail_api_upload_tokens`
- `swallowtail_photos`
- `swallowtail_event_photos`
- `swallowtail_event_permissions`
- `swallowtail_photo_derivatives`
- `swallowtail_photo_conversion_jobs`
- `swallowtail_photo_audit`

## Tests

Service tests live in `web_root/tests/test_SwallowtailBackendServices.php`.

Run the complete test index from the project root:

```powershell
php web_root\tests\index.php
```

