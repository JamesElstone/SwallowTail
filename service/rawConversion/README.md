# SwallowTail Raw Conversion Service

This service consumes SwallowTail conversion jobs from MariaDB, wakes quickly via
Redis, and renders Canon `.CR2` files with `rawtherapee-cli`.

PHP owns storage decisions. Each job includes the input path, optional PP3 path,
final output path, derivative type, and storage metadata. The worker renders into
a per-job temporary directory, then moves the completed file to the output path
defined on the job.

## Install On FreeBSD

Run the repo-provided installer from the checked-out project:

```sh
sh service/rawConversion/scripts/install_freebsd.sh /usr/local/swallowtail
```

Review the generated configuration:

```sh
ee /usr/local/etc/swallowtail/raw-conversion.ini
```

The installer creates the rc.d script, worker wrapper, log file, and newsyslog
rotation config.

## Operations

```sh
python3.11 -m raw_conversion --config /usr/local/etc/swallowtail/raw-conversion.ini --health
service swallowtail_raw_conversion start
service swallowtail_raw_conversion status
```

The service command is:

```sh
python3.11 -m raw_conversion --config /usr/local/etc/swallowtail/raw-conversion.ini
```

For one-shot testing:

```sh
python3.11 -m raw_conversion --config /usr/local/etc/swallowtail/raw-conversion.ini --once
```

For host smoke testing:

```sh
sh tools/bin/rawConversionSmokeTest.sh --input=/home/james.elstone/TEST.CR2
```

The smoke test uses `/storage/1/swallowtail-raw-smoke` by default, waits for all
four derivative jobs to succeed, verifies non-empty output files, and cleans up
the rows/files it created unless `--keep-artifacts` is supplied.

## Rendering Notes

RawTherapee processing profiles are layered in command-line order. For thumbnail
jobs, PHP supplies `output_width` and `output_height` on the job and the worker
generates a temporary resize PP3 after any user PP3 so thumbnails are bounded to
the requested dimensions. Preview, full JPEG, and original JPEG jobs remain
full-size unless PHP supplies dimensions for those derivatives later.
