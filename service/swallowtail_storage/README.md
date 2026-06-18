# SwallowTail Storage Service

The storage service refreshes the Redis storage snapshot used by PHP uploads and
processes durable storage migration jobs.

It runs `tools/php/storageCache.php refresh` at startup and on its normal
interval, refreshes early when the host mount signature changes, and runs
`tools/php/storageCache.php process-migrations <limit>` after each refresh.

## Install On FreeBSD

```sh
sh service/swallowtail_storage/scripts/install_freebsd.sh /usr/local/swallowtail
service swallowtail_storage start
service swallowtail_storage status
```

The installer writes rc.conf defaults and bakes the checkout path into the
installed rc.d script. Override these with `sysrc` if needed:

```sh
sysrc swallowtail_storage_php=/usr/local/bin/php
sysrc swallowtail_storage_interval_seconds=300
sysrc swallowtail_storage_migration_limit=10
sysrc swallowtail_storage_log=/var/log/swallowtail_storage.log
sysrc swallowtail_storage_log_level=INFO
```

For one-shot checks:

```sh
python3.11 -m swallowtail_storage --health
python3.11 -m swallowtail_storage --status
python3.11 -m swallowtail_storage --once
```

PHP remains the source of truth for storage calculation. The Python service only
handles the rc.d lifecycle, periodic refresh, early refresh on mount changes,
logging, and migration job processing.
