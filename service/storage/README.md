# SwallowTail Storage Service

The storage service refreshes the Redis storage snapshot used by PHP uploads and
processes durable storage migration jobs.

## Install On FreeBSD

```sh
sh service/storage/scripts/install_freebsd.sh /usr/local/swallowtail
ee /usr/local/etc/swallowtail/storage.ini
service swallowtail_storage start
service swallowtail_storage status
```

PHP remains the source of truth for storage calculation. The Python service only
handles the rc.d lifecycle, periodic refresh, early refresh on mount changes,
logging, and migration job processing.
