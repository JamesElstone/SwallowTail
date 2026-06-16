from __future__ import annotations

import argparse

from .config import ensure_runtime_directories, load_config
from .health import run_health_checks
from .logging_setup import configure_logging
from .worker import ConversionWorker


def main() -> int:
    parser = argparse.ArgumentParser(description="SwallowTail RawTherapee conversion worker")
    parser.add_argument("--config", required=True, help="Path to raw-conversion.ini")
    parser.add_argument("--once", action="store_true", help="Process at most one job and exit")
    parser.add_argument("--health", action="store_true", help="Validate service dependencies and exit")
    parser.add_argument("--verbose", action="store_true", help="Enable debug logging")
    args = parser.parse_args()

    config = load_config(args.config)

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

    worker.run_forever()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
