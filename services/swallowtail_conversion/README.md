# SwallowTail Conversion Service

This service consumes SwallowTail conversion jobs from MariaDB, wakes quickly via
Redis, and renders `.CR2` RAW image files with `rawtherapee-cli`.

PHP owns storage decisions. Each job includes the image type, input path,
optional PP3 profile path, and final output path. The worker renders into a
per-job temporary directory, then moves the completed file to the output path
defined on the job.

## Install On FreeBSD

Run the repo-provided installer from the checked-out project:

```sh
sh services/swallowtail_conversion/scripts/install_freebsd.sh /usr/local/swallowtail
```

The installer creates the rc.d script, worker wrapper, log file, and newsyslog
rotation config. Service defaults are embedded in the rc.d script and can be
overridden in `/etc/rc.conf`:

```sh
sysrc swallowtail_conversion_poll_interval_seconds=5
```

Database settings are read from the same `secure/app.php` file used by the web
app. On FreeBSD, use `www:swallowtail` ownership and `0640` permissions for
that file so PHP-FPM owns it and the conversion service can read it.

## Operations

```sh
service swallowtail_conversion start
service swallowtail_conversion status
service swallowtail_conversion migrate
```

`migrate` is the preferred upgrade path on FreeBSD hosts. It takes a migration
lock, remembers whether the worker was running, stops it so current conversion
work can drain cleanly, runs `php tools/php/setupDb.php --migrate-only` from the
project root, and starts the worker again if it was running before.

The service command is:

```sh
python3.11 -m swallowtail_conversion
```

For one-shot testing:

```sh
python3.11 -m swallowtail_conversion --once
```

For one-shot host verification, upload a test CR2 through the normal API and run
the worker with `--once`. Test-created files use the same deterministic
`swallowtail-data` paths as production data and should be removed by the test
that created them.

## Rendering Notes

RawTherapee processing profiles are layered in command-line order. For thumbnail
jobs, PHP supplies `output_width` and `output_height` on the job and the worker
generates a temporary resize PP3 after any user PP3 so thumbnails are bounded to
the requested dimensions. `embedded`, `original`, and `filtered` jobs remain
full-size unless PHP supplies dimensions for those image types later.
