from __future__ import annotations

import logging
import signal
import threading
from pathlib import Path
from typing import Any

from .config import AppConfig
from .db import MetadataDatabase
from .exiftool import ExifToolRunner
from .redis_heartbeat import RedisHeartbeat


class MetadataWorker:
    def __init__(self, config: AppConfig):
        self.config = config
        self.log = logging.getLogger("swallowtail_metadata.worker")
        self.db = MetadataDatabase(config.database)
        self.exiftool = ExifToolRunner(config.metadata.exiftool_binary)
        self.redis = RedisHeartbeat(config.redis)
        self.shutdown_requested = threading.Event()
        self.idle_delay_seconds = config.worker.poll_min_seconds

    def request_shutdown(self) -> None:
        self.shutdown_requested.set()

    def run_forever(self) -> None:
        self.log.info("Metadata worker started")
        while not self.shutdown_requested.is_set():
            processed = self.run_once()
            if processed:
                self.idle_delay_seconds = self.config.worker.poll_min_seconds
                continue
            delay = self.idle_delay_seconds
            self.idle_delay_seconds = min(self.config.worker.poll_max_seconds, max(delay + 1, delay * 2))
            self.shutdown_requested.wait(delay)
        self.log.info("Metadata worker stopped")

    def run_once(self) -> bool:
        self._touch_status()
        photo = self.db.next_photo()
        if photo is None:
            return False
        self.process_photo(photo)
        self._touch_status()
        return True

    def process_photo(self, photo: dict[str, Any]) -> None:
        photo_id = int(photo.get("id") or 0)
        try:
            source_path = self.source_path(photo)
            if not source_path.is_file():
                raise RuntimeError(f"Source CR2 file was not found: {source_path}")
            result = self.exiftool.extract(str(source_path), self.config.metadata.server_timezone)
            self.db.upsert_ready(photo_id, result.fields, result.raw)
            self.log.info("Extracted metadata for photo=%s source=%s", photo_id, source_path)
        except Exception as exc:
            status = self.db.defer_or_fail(
                photo_id,
                str(exc),
                self.config.worker.max_attempts,
                self.config.worker.retry_delay_seconds,
            )
            self.log.warning("Metadata extraction %s for photo=%s: %s", status, photo_id, exc)

    def source_path(self, photo: dict[str, Any]) -> Path:
        base = str(photo.get("storage_base_location") or "").strip()
        checksum = str(photo.get("original_sha256") or "").strip().lower()
        if base == "" or len(checksum) < 4:
            raise RuntimeError("Photo storage location or checksum is missing.")
        return Path(base) / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_source.cr2"

    def status(self) -> dict[str, Any]:
        return {
            "success": True,
            "service": {
                "state": "running",
                "project_root": self.config.project_root,
                "poll_min_seconds": self.config.worker.poll_min_seconds,
                "poll_max_seconds": self.config.worker.poll_max_seconds,
                "server_timezone": self.config.metadata.server_timezone,
            },
            "metadata": self.db.counts(),
        }

    def health_checks(self) -> tuple[bool, list[str]]:
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

        check("database", self.db.ping)
        check("exiftool", self.exiftool.health_check)
        check("redis", self.redis.ping)
        return healthy, results

    def _touch_status(self) -> bool:
        ok = self.redis.touch_service("swallowtail_metadata")
        if not ok:
            self.log.debug("Unable to refresh Redis heartbeat for metadata worker")
        return ok


def install_signal_handlers(worker: MetadataWorker) -> None:
    def handle_shutdown(_signum, _frame) -> None:
        worker.request_shutdown()

    signal.signal(signal.SIGTERM, handle_shutdown)
    signal.signal(signal.SIGINT, handle_shutdown)
