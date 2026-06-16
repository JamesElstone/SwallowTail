from __future__ import annotations

from configparser import ConfigParser
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class DatabaseConfig:
    driver: str
    dsn: str
    host: str
    port: int
    database: str
    user: str
    password: str


@dataclass(frozen=True)
class RedisConfig:
    host: str
    port: int
    urgent_queue: str
    normal_queue: str
    timeout_seconds: int


@dataclass(frozen=True)
class RawTherapeeConfig:
    binary: str
    maximum_threads: int
    home: str
    stderr_chars: int


@dataclass(frozen=True)
class WorkerConfig:
    worker_id: str
    poll_interval_seconds: int
    job_timeout_seconds: int
    max_attempts: int
    retry_delay_seconds: int
    work_dir: str
    temp_retention_hours: int


@dataclass(frozen=True)
class LoggingConfig:
    file: str
    level: str


@dataclass(frozen=True)
class AppConfig:
    database: DatabaseConfig
    redis: RedisConfig
    rawtherapee: RawTherapeeConfig
    worker: WorkerConfig
    logging: LoggingConfig


def load_config(path: str) -> AppConfig:
    parser = ConfigParser()
    loaded = parser.read(path)
    if not loaded:
        raise RuntimeError(f"Configuration file could not be read: {path}")

    return AppConfig(
        database=DatabaseConfig(
            driver=parser.get("database", "driver", fallback="odbc").strip().lower(),
            dsn=parser.get("database", "dsn", fallback=""),
            host=parser.get("database", "host", fallback="127.0.0.1"),
            port=parser.getint("database", "port", fallback=3306),
            database=parser.get("database", "database"),
            user=parser.get("database", "user"),
            password=parser.get("database", "password", fallback=""),
        ),
        redis=RedisConfig(
            host=parser.get("redis", "host", fallback="127.0.0.1"),
            port=parser.getint("redis", "port", fallback=6379),
            urgent_queue=parser.get("redis", "urgent_queue", fallback="swallowtail:conversion:urgent"),
            normal_queue=parser.get("redis", "normal_queue", fallback="swallowtail:conversion:normal"),
            timeout_seconds=parser.getint("redis", "timeout_seconds", fallback=5),
        ),
        rawtherapee=RawTherapeeConfig(
            binary=parser.get("rawtherapee", "binary", fallback="/usr/local/bin/rawtherapee-cli"),
            maximum_threads=max(1, parser.getint("rawtherapee", "maximum_threads", fallback=1)),
            home=parser.get("rawtherapee", "home", fallback="/var/db/swallowtail-raw-conversion"),
            stderr_chars=max(200, parser.getint("logging", "stderr_chars", fallback=4000)),
        ),
        worker=WorkerConfig(
            worker_id=parser.get("worker", "id", fallback="swallowtail-converter-1"),
            poll_interval_seconds=max(1, parser.getint("worker", "poll_interval_seconds", fallback=5)),
            job_timeout_seconds=max(60, parser.getint("worker", "job_timeout_seconds", fallback=600)),
            max_attempts=max(1, parser.getint("worker", "max_attempts", fallback=3)),
            retry_delay_seconds=max(1, parser.getint("worker", "retry_delay_seconds", fallback=60)),
            work_dir=parser.get("worker", "work_dir", fallback="/var/tmp/swallowtail-raw-conversion"),
            temp_retention_hours=max(1, parser.getint("worker", "temp_retention_hours", fallback=24)),
        ),
        logging=LoggingConfig(
            file=parser.get("logging", "file", fallback="/var/log/swallowtail_subsystem.log"),
            level=parser.get("logging", "level", fallback="INFO").strip().upper(),
        ),
    )


def ensure_runtime_directories(config: AppConfig) -> None:
    Path(config.worker.work_dir).mkdir(parents=True, exist_ok=True)
    Path(config.rawtherapee.home).mkdir(parents=True, exist_ok=True)
