# SwallowTail

SwallowTail is a self-hosted open source application for sharing CR2 RAW image files as controlled JPEG galleries.

The application is built from the eelKit PHP framework. eelKit provides the authenticated admin shell, users, roles, MFA, session protection, CSRF protection, audit history, database helpers, and page/card rendering model. SwallowTail adds the photo workflow on top: upload RAW files, convert them to JPEGs, group them into events, and let specific users view or download only the photos they have permission to access.

## Purpose

SwallowTail is for private event photography workflows where the original files and generated images should live outside the public web root, while authorised users get a safe web interface for viewing and downloading.

The central object is an event. An event is a named group of photos, and user access is granted through membership of that event. When a RAW file is uploaded, it is not part of any event by default. It must be assigned before event viewers can see it.

Users with permission can currently:

- Browse an event gallery with tiled photo thumbnails.
- Open a single-photo viewer page.
- View private generated JPEG assets through application routes after access checks.

The schema and permission services already model single-JPEG, event ZIP,
all-accessible ZIP, and RAW-original download rights. The remaining ZIP and
RAW-original download UI/API flows are still in progress.

Admins and editors can manage uploads, event assignment, permissions, duplicate detection, conversion state, and storage.

## Hosting And Development

Production hosting target:

- FreeBSD 15.0
- Apache 2.4
- PHP 8.4
- MariaDB 10.11.14
- UnixODBC database access

SwallowTail is intended to run on a FreeBSD virtual machine with storage added over time. Uploaded RAW sources, generated JPEGs, PP3 profiles, generated ZIP files, and cache files should be stored outside `web_root` and served only through application routes that check the current user's permissions.

## Photo Workflow

The intended flow is:

1. A CR2 RAW image file is uploaded by an authorised user.
2. SwallowTail computes a checksum for duplicate detection.
3. The source file is stored outside `web_root`.
4. The photo starts as unassigned, so normal event viewers cannot see it.
5. A conversion process creates image files such as embedded, original, thumbnail, and filtered JPEGs.
6. An admin or editor assigns the photo to one or more events.
7. Event permissions decide which users can view and download the photo.
8. Private generated image files are streamed by the application after checking access.

Duplicate uploads should be detected by checksum rather than filename. Matching checksums can be blocked or flagged as duplicates, while matching filenames with different checksums should be treated as a warning for admin review.

## Event Permissions

SwallowTail needs object-level permissions in addition to eelKit's role/card permissions.

Expected event permission checks include:

- Whether a user can see an event.
- Whether a user can view a photo in that event.
- Whether a user can download a single JPEG.
- Whether a user can generate or download an event ZIP.
- Whether a user can download all photos they have access to.
- Whether a user can access original RAW files, if that is ever enabled.

The default should be least privilege: uploaded photos are unassigned, users see no events unless granted access, and RAW-original download should be admin-controlled and disabled by default unless explicitly required.

## Main Features

Current core features:

- CR2 RAW image upload through the web UI and device API.
- SpiceBush desktop/CLI registration and upload-token based API access.
- FNV-1a quick checksum preflight and SHA-256 duplicate detection during ingest.
- Off-web-root storage for RAW sources, generated JPEGs, thumbnails, filtered previews, and PP3 profiles.
- Dynamic storage discovery with root-partition exclusion, free-space thresholds, optional checksum round-robin selection, ZFS dataset selection, cached storage snapshots, and queued storage migrations.
- RAW-to-JPEG conversion jobs for embedded, original, thumbnail, and filtered preview outputs.
- FreeBSD rc.d services for conversion work and storage cache/migration work.
- Event, photo assignment, and event-permission service tables.
- Tiled gallery, single-photo viewer, picture editor preview flow, recent uploads, storage summary, and storage settings UI.
- Upload token management with per-token CIDR allow lists.
- Audit logging for uploads, duplicate detections, conversions, token use, permission changes, and storage migrations.

Still in progress:

- Full event-management UI for creating events, assigning photos, and granting viewer permissions.
- ZIP generation and download flows for selected photos, whole events, and all accessible photos.
- RAW-original download controls exposed through the UI.
- Storage verification and orphan cleanup tools.
- EXIF handling options, including stripping GPS data from generated JPEGs.
- Event invitations.
- Viewer favourites or selections.
- Collections or albums inside events.
- CLI tools for bulk import, reprocessing, checksum verification, and manual storage migration control.

## Security Model

eelKit already provides the application security foundation:

- First-user bootstrap flow.
- Password hashing with Argon2id and a server-side pepper.
- Optional time-based one-time password MFA.
- Session regeneration and device-bound session checks.
- CSRF and AJAX nonce protection.
- Role-based access to cards and pages.
- User login history and account audit tables.
- Local configuration and generated keys outside the public web root.

SwallowTail must add photo-specific security:

- Store files using internal generated paths, not user-supplied filenames.
- Normalise paths and allow access only inside configured storage roots.
- Never expose storage roots directly through Apache.
- Check event/photo permissions before streaming any file.
- Validate uploads by extension and file type where practical.
- Enforce upload size limits and storage quotas.
- Audit who uploaded, assigned, viewed, and downloaded photos.
- Avoid loading large RAW or ZIP downloads fully into PHP memory.

Only `web_root` should be served publicly. Directories such as `secure`, `db_schema`, `tools`, `debug/logs`, photo storage roots, conversion caches, and generated ZIP directories must remain private on the server.

## Current Repository State

This repository currently contains the eelKit-based application shell plus the active SwallowTail photo workflow:

- Account setup, login, MFA, sessions, roles, and audit history.
- Page/card rendering for the admin interface.
- AJAX form and card refresh behaviour.
- Table rendering and export support.
- Application activity and log views.
- Database setup and migration tooling.
- Web and API RAW upload paths.
- Storage discovery, storage settings, and storage migration services.
- Conversion queue services and FreeBSD background workers.
- Gallery, viewer, and picture-editor pages.
- Windows and FreeBSD SpiceBush clients under `client/spicebush`.

Some implementation files still carry eelKit framework names because SwallowTail is starting from eelKit rather than from a blank application.

## Project Layout

- `web_root/index.php` - main web entrypoint.
- `web_root/classes` - framework, service, store, repository, database, and controller classes.
- `web_root/content/pages` - page definitions for the application.
- `web_root/content/cards` - card definitions rendered inside pages.
- `web_root/content/actions` - shared card action handlers.
- `web_root/css` and `web_root/js` - application styling and browser behaviour.
- `web_root/api` - device API, private photo asset serving, and preview endpoints.
- `client/spicebush` - plain-C Windows tray and FreeBSD CLI uploader client.
- `services` - Python background services for conversion and storage cache/migration work.
- `FreeBSD` - local FreeBSD port files, rc.d service templates, and Apache/PHP integration files.
- `secure` - private configuration, generated keys, and bootstrap files.
- `db_schema` - baseline schema and incremental migrations.
- `tools` - command line helpers for setup, migrations, password reset, and maintenance.
- `debug/logs` - local log output.

## License

SwallowTail is licensed under the BSD 3-Clause License. See `LICENSE` for details.

The laws of England and Wales apply to this project.
