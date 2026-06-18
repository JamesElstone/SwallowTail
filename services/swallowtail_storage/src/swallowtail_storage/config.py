from __future__ import annotations

from dataclasses import dataclass


@dataclass(frozen=True)
class StorageConfig:
    php: str
    project_root: str
    interval_seconds: int
    migration_limit: int
    log_file: str
    log_level: str
