from __future__ import annotations

import os
from pathlib import Path

from .config import AppConfig
from .db import ConversionDatabase
from .rawtherapee import RawTherapeeRunner
from .redis_queue import RedisQueue


def run_health_checks(config: AppConfig) -> tuple[bool, list[str]]:
    results: list[str] = []
    healthy = True

    def check(label: str, callback) -> None:
        nonlocal healthy
        try:
            callback()
            results.append(f"OK {label}")
        except Exception as exc:
            healthy = False
            results.append(f"FAIL {label}: {exc}")

    check("database", lambda: ConversionDatabase(config.database, config.worker).ping())
    check("redis", lambda: RedisQueue(config.redis).ping())
    check("rawtherapee", lambda: _check_executable(RawTherapeeRunner(config.rawtherapee).binary_path()))
    check("worker work_dir", lambda: _check_directory_writable(config.worker.work_dir))
    check("rawtherapee home", lambda: _check_directory_writable(config.rawtherapee.home))
    check("log file", lambda: _check_log_writable(config.logging.file))

    return healthy, results


def _check_executable(path: str) -> None:
    if not path or not os.path.isfile(path):
        raise RuntimeError(f"not found: {path}")
    if not os.access(path, os.X_OK):
        raise RuntimeError(f"not executable: {path}")


def _check_directory_writable(path: str) -> None:
    directory = Path(path)
    if not directory.is_dir():
        raise RuntimeError(f"directory not found: {path}")
    if not os.access(directory, os.W_OK | os.X_OK):
        raise RuntimeError(f"directory not writable: {path}")


def _check_log_writable(path: str) -> None:
    log_path = Path(path)
    if log_path.exists():
        if not log_path.is_file():
            raise RuntimeError(f"not a file: {path}")
        if not os.access(log_path, os.W_OK):
            raise RuntimeError(f"log file not writable: {path}")
        return

    parent = log_path.parent
    if not parent.is_dir():
        raise RuntimeError(f"log directory not found: {parent}")
    if not os.access(parent, os.W_OK | os.X_OK):
        raise RuntimeError(f"log directory not writable: {parent}")
