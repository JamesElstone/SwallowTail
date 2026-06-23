from __future__ import annotations

import json
import subprocess
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
    timeout_seconds: int


@dataclass(frozen=True)
class WorkerConfig:
    poll_min_seconds: int
    poll_max_seconds: int
    retry_delay_seconds: int
    max_attempts: int


@dataclass(frozen=True)
class MetadataConfig:
    exiftool_binary: str
    server_timezone: str


@dataclass(frozen=True)
class LoggingConfig:
    file: str
    level: str


@dataclass(frozen=True)
class AppConfig:
    database: DatabaseConfig
    redis: RedisConfig
    worker: WorkerConfig
    metadata: MetadataConfig
    logging: LoggingConfig
    project_root: str


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
        redis=RedisConfig(host="127.0.0.1", port=6379, timeout_seconds=5),
        worker=WorkerConfig(poll_min_seconds=5, poll_max_seconds=60, retry_delay_seconds=60, max_attempts=3),
        metadata=MetadataConfig(exiftool_binary="/usr/local/bin/exiftool", server_timezone="Europe/London"),
        logging=LoggingConfig(file="/var/log/swallowtail/swallowtail_metadata.log", level="INFO"),
        project_root="/usr/local/swallowtail",
    )


def load_php_app_config(path: str, php_binary: str = "php", base: AppConfig | None = None) -> AppConfig:
    config = base if base is not None else default_config()
    loaded = _read_php_app_config(path, php_binary)
    config = _apply_php_database_config(config, loaded.get("db"))
    config = _apply_php_redis_config(config, loaded.get("swallowtail"))
    config = _apply_php_timezone_config(config, loaded.get("swallowtail"))
    return config


def _read_php_app_config(path: str, php_binary: str) -> dict[str, Any]:
    php_code = (
        "$config = require $argv[1];"
        "if (!is_array($config)) { fwrite(STDERR, 'app.php did not return an array'); exit(2); }"
        "echo json_encode($config, JSON_UNESCAPED_SLASHES);"
    )
    try:
        result = subprocess.run([php_binary, "-r", php_code, path], check=False, capture_output=True, text=True)
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
            database=replace(config.database, driver="odbc", dsn=_odbc_dsn_from_pdo_body(body), user=user, password=password),
        )
    if driver in {"mysql", "mariadb"}:
        options = _parse_pdo_dsn_options(body)
        port = config.database.port
        if "port" in options and options["port"].strip() != "":
            port = int(options["port"])
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
    raise RuntimeError(f"Unsupported database DSN driver for metadata worker in secure/app.php: {driver}")


def _apply_php_redis_config(config: AppConfig, swallowtail_config: Any) -> AppConfig:
    if not isinstance(swallowtail_config, dict) or not isinstance(swallowtail_config.get("redis"), dict):
        return config
    redis = swallowtail_config["redis"]
    return replace(
        config,
        redis=replace(
            config.redis,
            host=str(redis.get("host", config.redis.host)),
            port=int(redis.get("port", config.redis.port)),
        ),
    )


def _apply_php_timezone_config(config: AppConfig, swallowtail_config: Any) -> AppConfig:
    if not isinstance(swallowtail_config, dict) or not isinstance(swallowtail_config.get("timezone"), dict):
        return config
    timezone = str(swallowtail_config["timezone"].get("server") or config.metadata.server_timezone).strip()
    return replace(config, metadata=replace(config.metadata, server_timezone=timezone or config.metadata.server_timezone))


def _split_pdo_dsn(dsn: str) -> tuple[str, str]:
    if ":" not in dsn:
        return "odbc", dsn
    driver, body = dsn.split(":", 1)
    return driver.strip().lower(), body.strip()


def _odbc_dsn_from_pdo_body(body: str) -> str:
    body = body.strip()
    if body.lower().startswith("dsn="):
        return body[4:].strip()
    return body


def _parse_pdo_dsn_options(body: str) -> dict[str, str]:
    options: dict[str, str] = {}
    for part in body.split(";"):
        if "=" not in part:
            continue
        key, value = part.split("=", 1)
        options[key.strip().lower()] = value.strip()
    return options


def app_config_path(project_root: str) -> str:
    return str(Path(project_root) / "secure" / "app.php")
