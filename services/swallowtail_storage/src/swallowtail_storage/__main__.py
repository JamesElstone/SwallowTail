from __future__ import annotations

import argparse
import json
import os

from .config import StorageConfig
from .logging_setup import configure_logging
from .worker import StorageWorker, install_signal_handlers


def _env(name: str, default: str) -> str:
    return os.environ.get("SWALLOWTAIL_STORAGE_" + name, default)


def _env_int(name: str, default: int) -> int:
    value = os.environ.get("SWALLOWTAIL_STORAGE_" + name)
    return int(value) if value is not None else default


def main() -> int:
    parser = argparse.ArgumentParser(description="SwallowTail storage cache worker")
    parser.add_argument("--project-root", default=_env("PROJECT_ROOT", "/usr/local/swallowtail"), help="SwallowTail checkout root")
    parser.add_argument("--php", default=_env("PHP", "/usr/local/bin/php"), help="PHP executable")
    parser.add_argument("--interval-seconds", type=int, default=_env_int("INTERVAL_SECONDS", 300), help="Storage refresh interval")
    parser.add_argument("--mount-poll-seconds", type=int, default=_env_int("MOUNT_POLL_SECONDS", 30), help="Mount change check interval")
    parser.add_argument("--migration-item-limit", type=int, default=_env_int("MIGRATION_ITEM_LIMIT", 10), help="Migration job items to process per refresh")
    parser.add_argument("--redis-host", default=_env("REDIS_HOST", "127.0.0.1"), help="Redis host")
    parser.add_argument("--redis-port", type=int, default=_env_int("REDIS_PORT", 6379), help="Redis port")
    parser.add_argument("--redis-timeout-seconds", type=int, default=_env_int("REDIS_TIMEOUT_SECONDS", 5), help="Redis socket timeout")
    parser.add_argument("--redis-snapshot-key", default=_env("REDIS_SNAPSHOT_KEY", "swallowtail:storage:snapshot"), help="Redis storage snapshot key")
    parser.add_argument("--redis-snapshot-ttl-seconds", type=int, default=_env_int("REDIS_SNAPSHOT_TTL_SECONDS", 360), help="Redis storage snapshot TTL")
    parser.add_argument("--redis-storage-wake-queue", default=_env("REDIS_STORAGE_WAKE_QUEUE", "swallowtail:conversion:storage_wake"), help="Redis conversion storage wake queue")
    parser.add_argument("--log-file", default=_env("LOG_FILE", "/var/log/swallowtail/swallowtail_storage.log"), help="Log file path")
    parser.add_argument("--log-level", default=_env("LOG_LEVEL", "INFO"), help="Log level")
    parser.add_argument("--once", action="store_true", help="Refresh storage cache and process migrations once")
    parser.add_argument("--status", action="store_true", help="Print storage cache status and exit")
    parser.add_argument("--health", action="store_true", help="Validate storage service dependencies and exit")
    args = parser.parse_args()

    config = StorageConfig(
        php=args.php,
        project_root=args.project_root,
        interval_seconds=max(10, args.interval_seconds),
        mount_poll_seconds=max(5, args.mount_poll_seconds),
        migration_item_limit=max(1, args.migration_item_limit),
        redis_host=args.redis_host,
        redis_port=max(1, args.redis_port),
        redis_timeout_seconds=max(1, args.redis_timeout_seconds),
        redis_snapshot_key=args.redis_snapshot_key,
        redis_snapshot_ttl_seconds=max(1, args.redis_snapshot_ttl_seconds),
        redis_storage_wake_queue=args.redis_storage_wake_queue,
        log_file=args.log_file,
        log_level=args.log_level.strip().upper(),
    )
    configure_logging(config.log_file, config.log_level)
    worker = StorageWorker(config)

    if args.status:
        print(json.dumps(worker.status(), indent=2))
        return 0

    if args.health:
        ok, lines = worker.health_checks()
        for line in lines:
            print(line)
        return 0 if ok else 1

    if args.once:
        return 0 if worker.run_once() else 1

    install_signal_handlers(worker)
    worker.run_forever()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
