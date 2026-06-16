# SwallowTail

SwallowTail is a self-hosted open source application for sharing CR2 RAW image files as controlled JPEG galleries.

The application is built from the eelKit PHP framework. eelKit provides the authenticated admin shell, users, roles, MFA, session protection, CSRF protection, audit history, database helpers, and page/card rendering model. SwallowTail adds the photo workflow on top: upload RAW files, convert them to JPEGs, group them into events, and let specific users view or download only the photos they have permission to access.

## Purpose

SwallowTail is for private event photography workflows where the original files and generated images should live outside the public web root, while authorised users get a safe web interface for viewing and downloading.

The central object is an event. An event is a named group of photos, and user access is granted through membership of that event. When a RAW file is uploaded, it is not part of any event by default. It must be assigned before event viewers can see it.

Users with permission can:

- Browse an event gallery with tiled photo thumbnails.
- Open a single-photo viewer page.
- Download an individual JPEG.
- Download selected photos as a ZIP file.
- Download all photos in an event, if their event permission allows it.
- Download all photos they have access to across events, if that permission is enabled.

Admins and editors can manage uploads, event assignment, permissions, duplicate detection, conversion state, and storage.

## Hosting And Development

Production hosting target:

- FreeBSD 15.0
- Apache 2.4
- PHP 8.4
- MariaDB 10.11.14
- UnixODBC database access

SwallowTail is intended to run on a FreeBSD virtual machine with storage added over time. Uploaded originals, converted JPEGs, thumbnails, previews, generated ZIP files, and cache files should be stored outside `web_root` and served only through application routes that check the current user's permissions.

## Photo Workflow

The intended flow is:

1. A CR2 RAW image file is uploaded by an authorised user.
2. SwallowTail computes a checksum for duplicate detection.
3. The original file is stored outside `web_root`.
4. The photo starts as unassigned, so normal event viewers cannot see it.
5. A conversion process creates JPEG derivatives such as preview, thumbnail, and downloadable JPEG files.
6. An admin or editor assigns the photo to one or more events.
7. Event permissions decide which users can view and download the photo.
8. Downloads are streamed by the application after checking access.

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

Planned core features:

- CR2 RAW image file upload support.
- Checksum-based duplicate detection.
- Off-web-root storage for originals, JPEG derivatives, thumbnails, previews, and generated ZIPs.
- RAW-to-JPEG conversion pipeline.
- Event creation and event photo assignment.
- User-to-event permissions.
- Tiled event gallery view.
- Single-photo viewer page.
- Single-photo JPEG download.
- Selected-photo ZIP download.
- Whole-event ZIP download.
- "All accessible photos" ZIP download.
- Audit logging for uploads, conversions, permission changes, and downloads.
- Admin dashboards for unassigned photos, conversion failures, storage status, and activity.

Useful later features:

- Background conversion and ZIP generation queues.
- Configurable storage roots with active, inactive, and read-only states.
- Storage verification and orphan cleanup tools.
- EXIF handling options, including stripping GPS data from generated JPEGs.
- Event invitations.
- Viewer favourites or selections.
- Collections or albums inside events.
- CLI tools for bulk import, reprocessing, checksum verification, and storage migration.

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

This repository currently contains the eelKit-based application shell that SwallowTail will build on:

- Account setup, login, MFA, sessions, roles, and audit history.
- Page/card rendering for the admin interface.
- AJAX form and card refresh behaviour.
- Table rendering and export support.
- Application activity and log views.
- Database setup and migration tooling.
- Styling and JavaScript foundations for upload-oriented interfaces.

Some implementation files still carry eelKit framework names because SwallowTail is starting from eelKit rather than from a blank application.

## Project Layout

- `web_root/index.php` - main web entrypoint.
- `web_root/classes` - framework, service, store, repository, database, and controller classes.
- `web_root/content/pages` - page definitions for the application.
- `web_root/content/cards` - card definitions rendered inside pages.
- `web_root/content/actions` - shared card action handlers.
- `web_root/css` and `web_root/js` - application styling and browser behaviour.
- `secure` - private configuration, generated keys, and bootstrap files.
- `db_schema` - baseline schema and incremental migrations.
- `tools` - command line helpers for setup, migrations, password reset, and maintenance.
- `debug/logs` - local log output.

## License

SwallowTail is licensed under the BSD 3-Clause License. See `LICENSE` for details.

The laws of England and Wales apply to this project.
