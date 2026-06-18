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


def default_config() -> AppConfig:
    return AppConfig(
        database=DatabaseConfig(
            driver="odbc",
            dsn="swallowtail",
            host="127.0.0.1",
            port=3306,
            database="swallowtail",
            user="swallowtail_worker",
            password="",
        ),
        redis=RedisConfig(
            host="127.0.0.1",
            port=6379,
            urgent_queue="swallowtail:conversion:urgent",
            normal_queue="swallowtail:conversion:normal",
            timeout_seconds=5,
        ),
        rawtherapee=RawTherapeeConfig(
            binary="/usr/local/bin/rawtherapee-cli",
            maximum_threads=1,
            home="/var/db/swallowtail-raw-conversion",
            stderr_chars=4000,
        ),
        worker=WorkerConfig(
            worker_id="swallowtail-converter-1",
            poll_interval_seconds=5,
            job_timeout_seconds=600,
            max_attempts=3,
            retry_delay_seconds=60,
            work_dir="/var/tmp/swallowtail-raw-conversion",
            temp_retention_hours=24,
        ),
        logging=LoggingConfig(
            file="/var/log/swallowtail_image_engine.log",
            level="INFO",
        ),
    )


def load_config(path: str | None = None) -> AppConfig:
    defaults = default_config()
    if path is None or path == "":
        return defaults

    parser = ConfigParser()
    loaded = parser.read(path)
    if not loaded:
        raise RuntimeError(f"Configuration file could not be read: {path}")

    return AppConfig(
        database=DatabaseConfig(
            driver=parser.get("database", "driver", fallback=defaults.database.driver).strip().lower(),
            dsn=parser.get("database", "dsn", fallback=defaults.database.dsn),
            host=parser.get("database", "host", fallback=defaults.database.host),
            port=parser.getint("database", "port", fallback=defaults.database.port),
            database=parser.get("database", "database", fallback=defaults.database.database),
            user=parser.get("database", "user", fallback=defaults.database.user),
            password=parser.get("database", "password", fallback=defaults.database.password),
        ),
        redis=RedisConfig(
            host=parser.get("redis", "host", fallback=defaults.redis.host),
            port=parser.getint("redis", "port", fallback=defaults.redis.port),
            urgent_queue=parser.get("redis", "urgent_queue", fallback=defaults.redis.urgent_queue),
            normal_queue=parser.get("redis", "normal_queue", fallback=defaults.redis.normal_queue),
            timeout_seconds=parser.getint("redis", "timeout_seconds", fallback=defaults.redis.timeout_seconds),
        ),
        rawtherapee=RawTherapeeConfig(
            binary=parser.get("rawtherapee", "binary", fallback=defaults.rawtherapee.binary),
            maximum_threads=max(1, parser.getint(
                "rawtherapee",
                "maximum_threads",
                fallback=defaults.rawtherapee.maximum_threads,
            )),
            home=parser.get("rawtherapee", "home", fallback=defaults.rawtherapee.home),
            stderr_chars=max(200, parser.getint("logging", "stderr_chars", fallback=defaults.rawtherapee.stderr_chars)),
        ),
        worker=WorkerConfig(
            worker_id=parser.get("worker", "id", fallback=defaults.worker.worker_id),
            poll_interval_seconds=max(1, parser.getint(
                "worker",
                "poll_interval_seconds",
                fallback=defaults.worker.poll_interval_seconds,
            )),
            job_timeout_seconds=max(60, parser.getint(
                "worker",
                "job_timeout_seconds",
                fallback=defaults.worker.job_timeout_seconds,
            )),
            max_attempts=max(1, parser.getint("worker", "max_attempts", fallback=defaults.worker.max_attempts)),
            retry_delay_seconds=max(1, parser.getint(
                "worker",
                "retry_delay_seconds",
                fallback=defaults.worker.retry_delay_seconds,
            )),
            work_dir=parser.get("worker", "work_dir", fallback=defaults.worker.work_dir),
            temp_retention_hours=max(1, parser.getint(
                "worker",
                "temp_retention_hours",
                fallback=defaults.worker.temp_retention_hours,
            )),
        ),
        logging=LoggingConfig(
            file=parser.get("logging", "file", fallback=defaults.logging.file),
            level=parser.get("logging", "level", fallback=defaults.logging.level).strip().upper(),
        ),
    )


def ensure_runtime_directories(config: AppConfig) -> None:
    Path(config.worker.work_dir).mkdir(parents=True, exist_ok=True)
    Path(config.rawtherapee.home).mkdir(parents=True, exist_ok=True)
