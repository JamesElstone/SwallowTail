from __future__ import annotations

import json
import subprocess
from configparser import ConfigParser
from dataclasses import dataclass, replace
from pathlib import Path
from typing import Any


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
class StorageConfig:
    full_threshold_percent: float
    store_on_root_partition: bool
    storage_blocked_poll_interval_seconds: int
    project_root: str


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
    storage: StorageConfig
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
            home="/var/db/swallowtail_conversion",
            stderr_chars=4000,
        ),
        worker=WorkerConfig(
            worker_id="swallowtail-converter-1",
            poll_interval_seconds=5,
            job_timeout_seconds=600,
            max_attempts=3,
            retry_delay_seconds=60,
            work_dir="/var/tmp/swallowtail_conversion",
            temp_retention_hours=24,
        ),
        storage=StorageConfig(
            full_threshold_percent=5.0,
            store_on_root_partition=False,
            storage_blocked_poll_interval_seconds=3600,
            project_root="/usr/local/swallowtail",
        ),
        logging=LoggingConfig(
            file="/var/log/swallowtail/swallowtail_conversion.log",
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
        storage=StorageConfig(
            full_threshold_percent=max(0.0, min(100.0, parser.getfloat(
                "storage",
                "full_threshold_percent",
                fallback=defaults.storage.full_threshold_percent,
            ))),
            store_on_root_partition=parser.getboolean(
                "storage",
                "store_on_root_partition",
                fallback=defaults.storage.store_on_root_partition,
            ),
            storage_blocked_poll_interval_seconds=max(60, min(86400, parser.getint(
                "storage",
                "storage_blocked_poll_interval_seconds",
                fallback=defaults.storage.storage_blocked_poll_interval_seconds,
            ))),
            project_root=parser.get("storage", "project_root", fallback=defaults.storage.project_root),
        ),
        logging=LoggingConfig(
            file=parser.get("logging", "file", fallback=defaults.logging.file),
            level=parser.get("logging", "level", fallback=defaults.logging.level).strip().upper(),
        ),
    )


def load_php_app_config(path: str, php_binary: str = "php", base: AppConfig | None = None) -> AppConfig:
    config = base if base is not None else default_config()
    loaded = _read_php_app_config(path, php_binary)
    if not isinstance(loaded, dict):
        raise RuntimeError(f"PHP application config did not return an object: {path}")

    config = _apply_php_database_config(config, loaded.get("db"))
    config = _apply_php_redis_config(config, loaded.get("swallowtail"))
    config = _apply_php_storage_config(config, loaded.get("swallowtail"))

    return config


def _read_php_app_config(path: str, php_binary: str) -> dict[str, Any]:
    php_code = (
        "$config = require $argv[1];"
        "if (!is_array($config)) { fwrite(STDERR, 'app.php did not return an array'); exit(2); }"
        "echo json_encode($config, JSON_UNESCAPED_SLASHES);"
    )

    try:
        result = subprocess.run(
            [php_binary, "-r", php_code, path],
            check=False,
            capture_output=True,
            text=True,
        )
    except FileNotFoundError as exc:
        raise RuntimeError(f"PHP binary was not found while reading secure/app.php: {php_binary}") from exc

    if result.returncode != 0:
        detail = (result.stderr or result.stdout).strip()
        suffix = f": {detail}" if detail else ""
        raise RuntimeError(f"Unable to read PHP application config {path}{suffix}")

    try:
        loaded = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise RuntimeError(f"PHP application config was not valid JSON after loading: {path}") from exc

    if not isinstance(loaded, dict):
        raise RuntimeError(f"PHP application config did not return an object: {path}")

    return loaded


def _apply_php_database_config(config: AppConfig, db_config: Any) -> AppConfig:
    if not isinstance(db_config, dict):
        return config

    dsn = str(db_config.get("dsn") or "").strip()
    if dsn == "":
        return config

    driver, body = _split_pdo_dsn(dsn)
    user = str(db_config.get("user") or "")
    password = str(db_config.get("pass") or "")

    if driver == "odbc":
        return replace(
            config,
            database=replace(
                config.database,
                driver="odbc",
                dsn=_odbc_dsn_from_pdo_body(body),
                user=user,
                password=password,
            ),
        )

    if driver in {"mysql", "mariadb"}:
        options = _parse_pdo_dsn_options(body)
        port = config.database.port
        if "port" in options and options["port"].strip() != "":
            try:
                port = int(options["port"])
            except ValueError as exc:
                raise RuntimeError(f"Invalid database port in secure/app.php: {options['port']}") from exc

        return replace(
            config,
            database=replace(
                config.database,
                driver="mysql",
                dsn="",
                host=options.get("host", config.database.host),
                port=port,
                database=options.get("dbname", options.get("database", config.database.database)),
                user=user,
                password=password,
            ),
        )

    raise RuntimeError(f"Unsupported database DSN driver for conversion worker in secure/app.php: {driver}")


def _apply_php_redis_config(config: AppConfig, swallowtail_config: Any) -> AppConfig:
    if not isinstance(swallowtail_config, dict):
        return config

    redis_config = swallowtail_config.get("redis")
    if not isinstance(redis_config, dict):
        return config

    port = config.redis.port
    if "port" in redis_config:
        try:
            port = int(redis_config["port"])
        except (TypeError, ValueError) as exc:
            raise RuntimeError(f"Invalid Redis port in secure/app.php: {redis_config['port']}") from exc

    return replace(
        config,
        redis=replace(
            config.redis,
            host=str(redis_config.get("host", config.redis.host)),
            port=port,
            urgent_queue=str(redis_config.get("urgent_queue", config.redis.urgent_queue)),
            normal_queue=str(redis_config.get("normal_queue", config.redis.normal_queue)),
        ),
    )


def _apply_php_storage_config(config: AppConfig, swallowtail_config: Any) -> AppConfig:
    if not isinstance(swallowtail_config, dict):
        return config

    storage_config = swallowtail_config.get("storage")
    if not isinstance(storage_config, dict):
        return config

    return replace(
        config,
        storage=replace(
            config.storage,
            full_threshold_percent=_clamped_float(
                storage_config.get("full_threshold_percent"),
                config.storage.full_threshold_percent,
                0.0,
                100.0,
            ),
            store_on_root_partition=_bool_value(
                storage_config.get("store_on_root_partition"),
                config.storage.store_on_root_partition,
            ),
            storage_blocked_poll_interval_seconds=_clamped_int(
                storage_config.get("storage_blocked_poll_interval_seconds"),
                config.storage.storage_blocked_poll_interval_seconds,
                60,
                86400,
            ),
        ),
    )


def _clamped_float(value: Any, default: float, minimum: float, maximum: float) -> float:
    try:
        parsed = float(value)
    except (TypeError, ValueError):
        parsed = default
    return max(minimum, min(maximum, parsed))


def _clamped_int(value: Any, default: int, minimum: int, maximum: int) -> int:
    try:
        parsed = int(value)
    except (TypeError, ValueError):
        parsed = default
    return max(minimum, min(maximum, parsed))


def _bool_value(value: Any, default: bool) -> bool:
    if value is None:
        return default
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return value != 0
    if isinstance(value, str):
        return value.strip().lower() in {"1", "true", "yes", "on"}
    return default


def _split_pdo_dsn(dsn: str) -> tuple[str, str]:
    if ":" not in dsn:
        raise RuntimeError(f"Database DSN in secure/app.php is missing a driver prefix: {dsn}")

    driver, body = dsn.split(":", 1)
    driver = driver.strip().lower()
    if driver == "":
        raise RuntimeError(f"Database DSN in secure/app.php is missing a driver prefix: {dsn}")

    return driver, body.strip()


def _odbc_dsn_from_pdo_body(body: str) -> str:
    options = _parse_pdo_dsn_options(body)
    if "dsn" in options:
        return options["dsn"]

    return body


def _parse_pdo_dsn_options(body: str) -> dict[str, str]:
    options = {}
    for part in body.split(";"):
        if "=" not in part:
            continue

        key, value = part.split("=", 1)
        key = key.strip().lower()
        if key != "":
            options[key] = value.strip()

    return options


def ensure_runtime_directories(config: AppConfig) -> None:
    Path(config.worker.work_dir).mkdir(parents=True, exist_ok=True)
    Path(config.rawtherapee.home).mkdir(parents=True, exist_ok=True)
