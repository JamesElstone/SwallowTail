# SwallowTail Storage Service

The storage service discovers live storage locations, writes the Redis storage
snapshot used by PHP uploads and the storage UI, and processes durable storage
migration jobs.

It runs `tools/php/storageCache.php discover` at startup and on its normal
interval, writes the returned snapshot directly to Redis, checks the host mount
signature every 30 seconds so it can refresh early when mounted storage changes,
sends a conversion wake message when a refresh finds writable storage for the
first time or when the writable storage set changes, and runs
`tools/php/storageCache.php process-migrations <item-limit>` after each refresh.

## Install On FreeBSD

```sh
sh services/swallowtail_storage/scripts/install_freebsd.sh /usr/local/swallowtail
service swallowtail_storage start
service swallowtail_storage status
```

`status` reports the daemon pid and runs Python `--health`, which validates PHP
storage discovery and confirms Redis is reachable directly from the daemon.

The installer writes rc.conf defaults and bakes the checkout path into the
installed rc.d script. Override these with `sysrc` if needed:

```sh
sysrc swallowtail_storage_php=/usr/local/bin/php
sysrc swallowtail_storage_interval_seconds=300
sysrc swallowtail_storage_mount_poll_seconds=30
sysrc swallowtail_storage_migration_item_limit=10
sysrc swallowtail_storage_redis_host=127.0.0.1
sysrc swallowtail_storage_redis_port=6379
sysrc swallowtail_storage_redis_storage_wake_queue=swallowtail:conversion:storage_wake
sysrc swallowtail_storage_log=/var/log/swallowtail/swallowtail_storage.log
sysrc swallowtail_storage_log_level=INFO
sysrc swallowtail_storage_restart_delay_seconds=5
```

The rc.d wrapper runs under FreeBSD `daemon(8)` supervision and restarts the
worker after a crash using `swallowtail_storage_restart_delay_seconds`.

For one-shot checks:

```sh
python3.11 -m swallowtail_storage --health
python3.11 -m swallowtail_storage --status
python3.11 -m swallowtail_storage --once
```

PHP remains the source of truth for storage calculation and can still fall back
to live discovery if Redis is unavailable or the cache is stale/missing. The
Python service owns the Redis snapshot write, heartbeat, mount-change wake,
rc.d lifecycle, periodic refresh, early refresh on mount changes, logging, and
migration job processing.
