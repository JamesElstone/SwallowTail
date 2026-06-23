from __future__ import annotations

import logging
from logging.handlers import WatchedFileHandler
from pathlib import Path


def configure_logging(verbose: bool = False, log_file: str | None = None, level_name: str = "INFO") -> None:
    level = logging.DEBUG if verbose else getattr(logging, level_name.upper(), logging.INFO)
    handlers: list[logging.Handler] = []

    if log_file:
        path = Path(log_file)
        handlers.append(WatchedFileHandler(path))
    else:
        handlers.append(logging.StreamHandler())

    logging.basicConfig(
        level=level,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
        handlers=handlers,
        force=True,
    )
