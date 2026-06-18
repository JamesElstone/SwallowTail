from __future__ import annotations

import argparse
import os
import signal
from dataclasses import replace

from .config import ensure_runtime_directories, load_config
from .health import run_health_checks
from .logging_setup import configure_logging
from .worker import ConversionWorker


def main() -> int:
    parser = argparse.ArgumentParser(description="SwallowTail RawTherapee conversion worker")
    parser.add_argument("--config", help="Optional legacy INI path")
    parser.add_argument("--database-driver")
    parser.add_argument("--database-dsn")
    parser.add_argument("--database-host")
    parser.add_argument("--database-port", type=int)
    parser.add_argument("--database-name")
    parser.add_argument("--database-user")
    parser.add_argument("--database-password")
    parser.add_argument("--redis-host")
    parser.add_argument("--redis-port", type=int)
    parser.add_argument("--redis-urgent-queue")
    parser.add_argument("--redis-normal-queue")
    parser.add_argument("--redis-timeout-seconds", type=int)
    parser.add_argument("--rawtherapee-binary")
    parser.add_argument("--rawtherapee-maximum-threads", type=int)
    parser.add_argument("--rawtherapee-home")
    parser.add_argument("--rawtherapee-stderr-chars", type=int)
    parser.add_argument("--worker-id")
    parser.add_argument("--poll-interval-seconds", type=int)
    parser.add_argument("--job-timeout-seconds", type=int)
    parser.add_argument("--max-attempts", type=int)
    parser.add_argument("--retry-delay-seconds", type=int)
    parser.add_argument("--work-dir")
    parser.add_argument("--temp-retention-hours", type=int)
    parser.add_argument("--log-file")
    parser.add_argument("--log-level")
    parser.add_argument("--once", action="store_true", help="Process at most one job and exit")
    parser.add_argument("--health", action="store_true", help="Validate service dependencies and exit")
    parser.add_argument("--verbose", action="store_true", help="Enable debug logging")
    args = parser.parse_args()

    config = _apply_overrides(load_config(args.config), args)

    if args.health:
        ok, lines = run_health_checks(config)
        for line in lines:
            print(line)
        return 0 if ok else 1

    configure_logging(args.verbose, config.logging.file, config.logging.level)
    ensure_runtime_directories(config)
    worker = ConversionWorker(config)

    if args.once:
        return 0 if worker.run_once() else 2

    def handle_shutdown(_signum, _frame) -> None:
        worker.request_shutdown()

    signal.signal(signal.SIGTERM, handle_shutdown)
    signal.signal(signal.SIGINT, handle_shutdown)

    worker.run_forever()
    return 0


def _setting(args, attr: str, env_name: str, current):
    value = getattr(args, attr)
    if value is not None:
        return value
    env_value = os.environ.get(env_name)
    if env_value is None:
        return current
    if isinstance(current, int):
        return int(env_value)
    return env_value


def _apply_overrides(config, args):
    prefix = "SWALLOWTAIL_IMAGE_ENGINE_"
    return replace(
        config,
        database=replace(
            config.database,
            driver=_setting(args, "database_driver", prefix + "DATABASE_DRIVER", config.database.driver).strip().lower(),
            dsn=_setting(args, "database_dsn", prefix + "DATABASE_DSN", config.database.dsn),
            host=_setting(args, "database_host", prefix + "DATABASE_HOST", config.database.host),
            port=_setting(args, "database_port", prefix + "DATABASE_PORT", config.database.port),
            database=_setting(args, "database_name", prefix + "DATABASE_NAME", config.database.database),
            user=_setting(args, "database_user", prefix + "DATABASE_USER", config.database.user),
            password=_setting(args, "database_password", prefix + "DATABASE_PASSWORD", config.database.password),
        ),
        redis=replace(
            config.redis,
            host=_setting(args, "redis_host", prefix + "REDIS_HOST", config.redis.host),
            port=_setting(args, "redis_port", prefix + "REDIS_PORT", config.redis.port),
            urgent_queue=_setting(args, "redis_urgent_queue", prefix + "REDIS_URGENT_QUEUE", config.redis.urgent_queue),
            normal_queue=_setting(args, "redis_normal_queue", prefix + "REDIS_NORMAL_QUEUE", config.redis.normal_queue),
            timeout_seconds=max(1, _setting(
                args,
                "redis_timeout_seconds",
                prefix + "REDIS_TIMEOUT_SECONDS",
                config.redis.timeout_seconds,
            )),
        ),
        rawtherapee=replace(
            config.rawtherapee,
            binary=_setting(args, "rawtherapee_binary", prefix + "RAWTHERAPEE_BINARY", config.rawtherapee.binary),
            maximum_threads=max(1, _setting(
                args,
                "rawtherapee_maximum_threads",
                prefix + "RAWTHERAPEE_MAXIMUM_THREADS",
                config.rawtherapee.maximum_threads,
            )),
            home=_setting(args, "rawtherapee_home", prefix + "RAWTHERAPEE_HOME", config.rawtherapee.home),
            stderr_chars=max(200, _setting(
                args,
                "rawtherapee_stderr_chars",
                prefix + "RAWTHERAPEE_STDERR_CHARS",
                config.rawtherapee.stderr_chars,
            )),
        ),
        worker=replace(
            config.worker,
            worker_id=_setting(args, "worker_id", prefix + "WORKER_ID", config.worker.worker_id),
            poll_interval_seconds=max(1, _setting(
                args,
                "poll_interval_seconds",
                prefix + "POLL_INTERVAL_SECONDS",
                config.worker.poll_interval_seconds,
            )),
            job_timeout_seconds=max(60, _setting(
                args,
                "job_timeout_seconds",
                prefix + "JOB_TIMEOUT_SECONDS",
                config.worker.job_timeout_seconds,
            )),
            max_attempts=max(1, _setting(args, "max_attempts", prefix + "MAX_ATTEMPTS", config.worker.max_attempts)),
            retry_delay_seconds=max(1, _setting(
                args,
                "retry_delay_seconds",
                prefix + "RETRY_DELAY_SECONDS",
                config.worker.retry_delay_seconds,
            )),
            work_dir=_setting(args, "work_dir", prefix + "WORK_DIR", config.worker.work_dir),
            temp_retention_hours=max(1, _setting(
                args,
                "temp_retention_hours",
                prefix + "TEMP_RETENTION_HOURS",
                config.worker.temp_retention_hours,
            )),
        ),
        logging=replace(
            config.logging,
            file=_setting(args, "log_file", prefix + "LOG_FILE", config.logging.file),
            level=_setting(args, "log_level", prefix + "LOG_LEVEL", config.logging.level).strip().upper(),
        ),
    )


if __name__ == "__main__":
    raise SystemExit(main())
