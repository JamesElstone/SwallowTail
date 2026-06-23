from __future__ import annotations

from dataclasses import dataclass


@dataclass(frozen=True)
class StorageConfig:
    php: str
    project_root: str
    interval_seconds: int
    mount_poll_seconds: int
    migration_item_limit: int
    redis_host: str
    redis_port: int
    redis_timeout_seconds: int
    redis_snapshot_key: str
    redis_snapshot_ttl_seconds: int
    redis_storage_wake_queue: str
    log_file: str
    log_level: str
