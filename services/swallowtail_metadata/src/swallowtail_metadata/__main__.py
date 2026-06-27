from __future__ import annotations

import argparse
import json
import os
from dataclasses import replace

from .config import app_config_path, default_config, load_php_app_config
from .logging_setup import configure_logging
from .worker import MetadataWorker, install_signal_handlers


def _env(name: str, default: str) -> str:
    return os.environ.get("SWALLOWTAIL_METADATA_" + name, default)


def _env_int(name: str, default: int) -> int:
    value = os.environ.get("SWALLOWTAIL_METADATA_" + name)
    return int(value) if value is not None else default


def main() -> int:
    defaults = default_config()
    parser = argparse.ArgumentParser(description="SwallowTail CR2 metadata extraction worker")
    parser.add_argument("--project-root", default=_env("PROJECT_ROOT", defaults.project_root))
    parser.add_argument("--php-binary", default=_env("PHP_BINARY", "php"))
    parser.add_argument("--app-config", default=_env("APP_CONFIG", ""))
    parser.add_argument("--exiftool-binary", default=_env("EXIFTOOL_BINARY", defaults.metadata.exiftool_binary))
    parser.add_argument("--rawtherapee-binary", default=_env("RAWTHERAPEE_BINARY", defaults.metadata.rawtherapee_binary))
    parser.add_argument("--poll-min-seconds", type=int, default=_env_int("POLL_MIN_SECONDS", defaults.worker.poll_min_seconds))
    parser.add_argument("--poll-max-seconds", type=int, default=_env_int("POLL_MAX_SECONDS", defaults.worker.poll_max_seconds))
    parser.add_argument("--retry-delay-seconds", type=int, default=_env_int("RETRY_DELAY_SECONDS", defaults.worker.retry_delay_seconds))
    parser.add_argument("--max-attempts", type=int, default=_env_int("MAX_ATTEMPTS", defaults.worker.max_attempts))
    parser.add_argument("--redis-profile-queue", default=_env("REDIS_PROFILE_QUEUE", ""))
    parser.add_argument("--redis-asset-queue", default=_env("REDIS_ASSET_QUEUE", ""))
    parser.add_argument("--log-file", default=_env("LOG_FILE", defaults.logging.file))
    parser.add_argument("--log-level", default=_env("LOG_LEVEL", defaults.logging.level))
    parser.add_argument("--once", action="store_true")
    parser.add_argument("--status", action="store_true")
    parser.add_argument("--health", action="store_true")
    args = parser.parse_args()

    base = replace(defaults, project_root=args.project_root)
    app_config = args.app_config.strip() or app_config_path(args.project_root)
    config = load_php_app_config(app_config, args.php_binary, base) if os.path.isfile(app_config) else base
    config = replace(
        config,
        project_root=args.project_root,
        php_binary=args.php_binary,
        metadata=replace(config.metadata, exiftool_binary=args.exiftool_binary, rawtherapee_binary=args.rawtherapee_binary),
        redis=replace(
            config.redis,
            profile_queue=args.redis_profile_queue.strip() or config.redis.profile_queue,
            asset_queue=args.redis_asset_queue.strip() or config.redis.asset_queue,
        ),
        worker=replace(
            config.worker,
            poll_min_seconds=max(1, args.poll_min_seconds),
            poll_max_seconds=max(max(1, args.poll_min_seconds), args.poll_max_seconds),
            retry_delay_seconds=max(1, args.retry_delay_seconds),
            max_attempts=max(1, args.max_attempts),
        ),
        logging=replace(config.logging, file=args.log_file, level=args.log_level.strip().upper()),
    )
    configure_logging(config.logging.file, config.logging.level)
    worker = MetadataWorker(config)

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
