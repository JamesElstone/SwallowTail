# SwallowTail Service Classes

SwallowTail
Copyright (c) 2026 James Elstone
Licensed under the BSD 3-Clause License.
See `LICENSE` for details.

This folder contains both eelKit framework services and SwallowTail application services.

Files with the eelKit framework header are part of eelKit and should not be altered for
SwallowTail feature work. Files with the SwallowTail header belong to this application
and provide the photo workflow that sits on top of eelKit.

## SwallowTail Services

### `SwallowtailStorageService`

Owns filesystem safety for private photo storage.

- Keeps storage outside `web_root`.
- Discovers already-mounted filesystems on each storage-location request.
- Excludes the root partition unless `swallowtail.storage.store_on_root_partition` is enabled.
- Appends `swallowtail-data` below each eligible base location.
- Builds deterministic checksum paths for `source`, `original`, `embedded`, `thumbnail`, `filtered`, and `profile`.
- Chooses a writable location that is not excluded and remains above the configured free-space threshold.
- Copies or moves RAW source files into managed storage.

This service should be the only place that turns a storage base location, checksum, and image type into an absolute filesystem path.

### `SwallowtailStorageLocationService`

Provides the backend surface that the storage UI uses to adjust discovered storage locations.

- Lists dynamically discovered mounted locations with current writable status.
- Records whether a discovered base location is excluded in `storage_location_properties`.

The service does not mount filesystems or create synthetic mount roots. System administrators own mounts; SwallowTail discovers and filters them.

### `SwallowtailPhotoIngestService`

Coordinates RAW file ingest.

- Accepts a local uploaded RAW file path.
- Validates `.CR2` filenames.
- Checks for a plausible CR2 RAW signature.
- Computes the SHA-256 checksum.
- Detects duplicate uploads by checksum before storing another copy.
- Stores new originals through `SwallowtailStorageService`.
- Records the photo through `SwallowtailPhotoLibraryService`.
- Queues image generation through `SwallowtailConversionQueueService`.

New uploads are unassigned by default, so event viewers cannot see them until an editor assigns them to an event.

### `SwallowtailPhotoLibraryService`

Owns database persistence for the photo library.

- Checks whether the SwallowTail schema is available.
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
- Authenticates upload clients using `Authorization: Bearer <upload-token>`.
- Restricts upload tokens to their configured CIDR ranges.
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

Provides a small database-backed queue facade for image generation.

- Enqueues RAW image jobs for embedded, original, thumbnail, and filtered outputs.
- Avoids creating duplicate queued or processing jobs for the same photo.
- Lists queued jobs ordered by priority and age.

The RAW-to-JPEG worker lives under `service/swallowtail_conversion/src/swallowtail_conversion`.

## Upload Flow

1. A device or application posts a RAW file to `web_root/api/raw-upload.php`.
2. `SwallowtailRawUploadApiService` authenticates the upload token.
3. `SwallowtailPhotoIngestService` validates the RAW file and computes its checksum.
4. If the checksum already exists, the duplicate is audited and no second original is stored.
5. If the checksum is new, `SwallowtailStorageService` chooses a writable storage location and stores the original.
6. `SwallowtailPhotoLibraryService` records the photo as uploaded and unassigned.
7. `SwallowtailConversionQueueService` queues image generation.
8. Editors can later assign the photo to one or more events.
9. `SwallowtailEventAccessService` controls viewing and download decisions.

## Storage Locations

Storage is designed for multiple already-mounted disks.

The backend discovers mounted filesystems from system mount/df data each time storage candidates are requested. It does not auto mount or manipulate filesystems.

The root partition is excluded by default. The storage settings card controls:

- `swallowtail.storage.store_on_root_partition`
- `swallowtail.storage.round_robin_locations`
- `swallowtail.storage.full_threshold_percent`

Each eligible base location stores files under `swallowtail-data`. Stored photos reference their chosen base mount using `photos.storage_base_location`, for example `/storage/1`. Full paths are derived from that base, the photo checksum, and the image type:

```text
{storage_base_location}/swallowtail-data/{checksum[0:2]}/{checksum[2:4]}/{checksum}_{image_type}.{ext}
```

`source` uses `.cr2`, `profile` uses `.pp3`, and generated images use `.jpg`. `storage_location_properties` stores per-base metadata such as whether a location is excluded from future writes.

## Related Database Tables

The SwallowTail services expect the migration `2026_05_31_001_swallowtail_photo_services.sql` to have run. It creates:

- `events`
- `storage_location_properties`
- `api_upload_tokens`
- `api_upload_token_cidrs`
- `photos`
- `event_photos`
- `event_permissions`
- `photo_conversion_jobs`
- `photo_audit`

## Tests

Service tests live in `web_root/tests/test_SwallowtailBackendServices.php`.

Run the complete test index from the project root:

```powershell
php web_root\tests\index.php
```
