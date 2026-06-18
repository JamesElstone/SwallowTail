from __future__ import annotations

from configparser import ConfigParser
from dataclasses import dataclass


@dataclass(frozen=True)
class StorageConfig:
    php: str
    project_root: str
    interval_seconds: int
    migration_limit: int
    log_file: str
    log_level: str


def load_config(path: str) -> StorageConfig:
    parser = ConfigParser()
    loaded = parser.read(path)
    if not loaded:
        raise RuntimeError(f"Configuration file could not be read: {path}")

    return StorageConfig(
        php=parser.get("runtime", "php", fallback="/usr/local/bin/php"),
        project_root=parser.get("runtime", "project_root", fallback="/usr/local/swallowtail"),
        interval_seconds=max(10, parser.getint("storage", "interval_seconds", fallback=300)),
        migration_limit=max(1, parser.getint("storage", "migration_limit", fallback=10)),
        log_file=parser.get("logging", "file", fallback="/var/log/swallowtail_storage.log"),
        log_level=parser.get("logging", "level", fallback="INFO").strip().upper(),
    )
