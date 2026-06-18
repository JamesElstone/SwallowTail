# SwallowTail Storage Service

The storage service refreshes the Redis storage snapshot used by PHP uploads and
processes durable storage migration jobs.

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
```

PHP remains the source of truth for storage calculation. The Python service only
handles the rc.d lifecycle, periodic refresh, early refresh on mount changes,
logging, and migration job processing.
