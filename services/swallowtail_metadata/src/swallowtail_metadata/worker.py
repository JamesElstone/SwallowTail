from __future__ import annotations

import logging
import signal
import threading
import time
from pathlib import Path
from typing import Any

from .config import AppConfig
from .db import MetadataDatabase
from .exiftool import ExifToolRunner
from .profile import RawTheapeeProfileScanner, RawTherapeeBaselineRunner, parse_pp3_properties
from .redis_heartbeat import RedisHeartbeat


class MetadataWorker:
    def __init__(self, config: AppConfig):
        self.config = config
        self.log = logging.getLogger("swallowtail_metadata.worker")
        self.db = MetadataDatabase(config.database)
        self.exiftool = ExifToolRunner(config.metadata.exiftool_binary)
        self.profile_runner = RawTherapeeBaselineRunner(config.metadata.rawtherapee_binary)
        self.rawtheapee_scanner = RawTheapeeProfileScanner(config.metadata.rawtheapee_profile_root)
        self.redis = RedisHeartbeat(config.redis)
        self.shutdown_requested = threading.Event()
        self.idle_delay_seconds = config.worker.poll_min_seconds
        self.last_rawtheapee_profile_scan_at = time.time()

    def request_shutdown(self) -> None:
        self.shutdown_requested.set()

    def run_forever(self) -> None:
        self.log.info("Metadata worker started")
        self.scan_rawtheapee_profiles("startup")
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
        if not hasattr(self, "last_rawtheapee_profile_scan_at"):
            self.last_rawtheapee_profile_scan_at = time.time()
        if hasattr(self.redis, "pop_rawtheapee_profile_refresh") and self.redis.pop_rawtheapee_profile_refresh():
            self.scan_rawtheapee_profiles("refresh")
            self._touch_status()
            return True
        if time.time() - self.last_rawtheapee_profile_scan_at >= 86400:
            self.scan_rawtheapee_profiles("daily")
            self._touch_status()
            return True
        urgent_profile_photo = self._urgent_profile_photo()
        if urgent_profile_photo is not None:
            self.process_profile_photo(urgent_profile_photo)
            self._touch_status()
            return True

        photo = self.db.next_photo()
        if photo is not None:
            self.process_photo(photo)
            self._touch_status()
            return True

        profile_photo = self.db.next_profile_photo()
        if profile_photo is None:
            profile_photo = self.db.next_unprofiled_photo()
            if profile_photo is None:
                self.log.info("No metadata or profile records returned; worker idle")
                return False
            self.log.info("Found uploaded photo without profile data; photo=%s", int(profile_photo.get("id") or 0))
        self.process_profile_photo(profile_photo)
        self._touch_status()
        return True

    def scan_rawtheapee_profiles(self, reason: str) -> int:
        profiles = self.rawtheapee_scanner.scan()
        count = self.db.replace_rawtheapee_profiles(profiles)
        self.last_rawtheapee_profile_scan_at = time.time()
        self.log.info(
            "RawTheapee profile scan completed; reason=%s root=%s profiles=%s",
            reason,
            self.config.metadata.rawtheapee_profile_root,
            count,
        )
        return count

    def _urgent_profile_photo(self) -> dict[str, Any] | None:
        notification = self.redis.pop_profile_notification()
        if notification is None:
            return None
        profile_photo = self.db.profile_photo_by_id(notification.photo_id)
        if profile_photo is None:
            self.log.info(
                "Urgent profile notification ignored; photo=%s reason=%s",
                notification.photo_id,
                notification.reason,
            )
            return None
        self.log.info(
            "Urgent profile notification received; photo=%s reason=%s",
            notification.photo_id,
            notification.reason,
        )
        return profile_photo

    def process_photo(self, photo: dict[str, Any]) -> None:
        photo_id = int(photo.get("id") or 0)
        try:
            source_path = self.source_path(photo)
            if not source_path.is_file():
                raise RuntimeError(f"Source CR2 file was not found: {source_path}")
            result = self.exiftool.extract(
                str(source_path),
                self.config.metadata,
                should_interrupt=self.redis.has_profile_notification,
            )
            self.db.upsert_ready(photo_id, result.fields, result.properties)
            self.log.info("Extracted metadata for photo=%s source=%s", photo_id, source_path)
        except InterruptedError as exc:
            self.log.info("Metadata extraction interrupted for urgent profile; photo=%s: %s", photo_id, exc)
        except Exception as exc:
            status = self.db.defer_or_fail(
                photo_id,
                str(exc),
                self.config.worker.max_attempts,
                self.config.worker.retry_delay_seconds,
            )
            self.log.warning("Metadata extraction %s for photo=%s: %s", status, photo_id, exc)

    def process_profile_photo(self, photo: dict[str, Any]) -> None:
        photo_id = int(photo.get("id") or 0)
        started_at = time.perf_counter()
        try:
            source_path = self.source_path(photo)
            if not source_path.is_file():
                raise RuntimeError(f"Source CR2 file was not found: {source_path}")
            source_profile_path = self.image_path(photo, "source_profile")
            self.db.mark_profile_processing(photo_id)
            profile_source = "existing"
            version = self.profile_runner.version()
            if not source_profile_path.is_file() or source_profile_path.stat().st_size <= 0:
                profile_source = "generated"
                result = self.profile_runner.generate(source_path, source_profile_path)
                version = result.version
            properties = parse_pp3_properties(source_profile_path.read_text(encoding="utf-8"))
            store_stats = self.db.replace_profile_data(photo_id, properties, str(source_profile_path), version)
            duration_seconds = time.perf_counter() - started_at
            self.log.info(
                "Stored RawTherapee source profile for photo=%s path=%s source=%s duration_seconds=%.3f profile_rows=%s profile_sections=%s profile_insert_batches=%s profile_largest_value_length=%s profile_max_value_chunks=%s",
                photo_id,
                source_profile_path,
                profile_source,
                duration_seconds,
                int(store_stats.get("profile_rows_written", len(properties)) if store_stats else len(properties)),
                int(store_stats.get("profile_sections", 0) if store_stats else 0),
                int(store_stats.get("profile_insert_batches", 0) if store_stats else 0),
                int(store_stats.get("profile_largest_value_length", 0) if store_stats else 0),
                int(store_stats.get("profile_max_value_chunks", 0) if store_stats else 0),
            )
        except Exception as exc:
            status = self.db.defer_profile(
                photo_id,
                str(exc),
                self.config.worker.max_attempts,
                self.config.worker.retry_delay_seconds,
            )
            self.log.warning("Source profile generation %s for photo=%s: %s", status, photo_id, exc)

    def source_path(self, photo: dict[str, Any]) -> Path:
        return self.image_path(photo, "source")

    def image_path(self, photo: dict[str, Any], image_type: str) -> Path:
        base = str(photo.get("storage_base_location") or "").strip()
        checksum = str(photo.get("original_sha256") or "").strip().lower()
        if base == "" or len(checksum) < 4:
            raise RuntimeError("Photo storage location or checksum is missing.")
        extension = {"source": ".cr2", "source_profile": ".pp3"}.get(image_type, ".jpg")
        suffix = {"source_profile": "source"}.get(image_type, image_type)
        return Path(base) / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_{suffix}{extension}"

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
        check("rawtherapee", self.profile_runner.health_check)
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
