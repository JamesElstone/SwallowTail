from __future__ import annotations

import argparse
import json

from .config import load_config
from .logging_setup import configure_logging
from .worker import StorageWorker, install_signal_handlers


def main() -> int:
    parser = argparse.ArgumentParser(description="SwallowTail storage cache worker")
    parser.add_argument("--config", required=True, help="Path to storage.ini")
    parser.add_argument("--once", action="store_true", help="Refresh storage cache and process migrations once")
    parser.add_argument("--status", action="store_true", help="Print storage cache status and exit")
    parser.add_argument("--health", action="store_true", help="Validate storage service dependencies and exit")
    args = parser.parse_args()

    config = load_config(args.config)
    configure_logging(config.log_file, config.log_level)
    worker = StorageWorker(config)

    if args.status:
        print(json.dumps(worker.status(), indent=2))
        return 0

    if args.health:
        return 0 if worker.run_php("status") else 1

    if args.once:
        return 0 if worker.run_once() else 1

    install_signal_handlers(worker)
    worker.run_forever()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
