from __future__ import annotations

import logging
import hashlib
import json
import signal
import subprocess
import threading
import time
from pathlib import Path
from typing import Any

from .config import AppConfig
from .db import MetadataDatabase
from .exiftool import ExifToolRunner
from .profile import RawTherapeeProfileScanner, RawTherapeeBaselineRunner, parse_pp3_properties
from .redis_heartbeat import AssetNotification, RedisHeartbeat


class MetadataWorker:
    def __init__(self, config: AppConfig):
        self.config = config
        self.log = logging.getLogger("swallowtail_metadata.worker")
        self.db = MetadataDatabase(config.database)
        self.exiftool = ExifToolRunner(config.metadata.exiftool_binary)
        self.profile_runner = RawTherapeeBaselineRunner(config.metadata.rawtherapee_binary)
        self.rawtherapee_scanner = RawTherapeeProfileScanner(config.metadata.rawtherapee_profile_root)
        self.redis = RedisHeartbeat(config.redis)
        self.shutdown_requested = threading.Event()
        self.idle_delay_seconds = config.worker.poll_min_seconds
        self.last_rawtherapee_profile_scan_at = time.time()
        self.data_integrity_requested = True
        self.profiled_derivative_queue_requested = True

    def request_shutdown(self) -> None:
        self.shutdown_requested.set()

    def run_forever(self) -> None:
        self.log.info("Metadata worker started")
        self.scan_rawtherapee_profiles("startup")
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
        if not hasattr(self, "last_rawtherapee_profile_scan_at"):
            self.last_rawtherapee_profile_scan_at = time.time()
        if hasattr(self.redis, "pop_rawtherapee_profile_refresh") and self.redis.pop_rawtherapee_profile_refresh():
            self.scan_rawtherapee_profiles("refresh")
            self._touch_status()
            return True
        if hasattr(self.redis, "pop_asset_notification"):
            asset_notification = self.redis.pop_asset_notification()
            if asset_notification is not None:
                self.process_asset_notification(asset_notification)
                self._touch_status()
                return True
        if hasattr(self.redis, "pop_data_integrity_notification"):
            data_integrity_notification = self.redis.pop_data_integrity_notification()
            if data_integrity_notification is not None:
                self.data_integrity_requested = True
                self.log.info(
                    "Data integrity maintenance notification received; action=%s reason=%s",
                    data_integrity_notification.action,
                    data_integrity_notification.reason,
                )
        if time.time() - self.last_rawtherapee_profile_scan_at >= 86400:
            self.scan_rawtherapee_profiles("daily")
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
                asset_job = self.db.next_unrecorded_image_asset_job() if hasattr(self.db, "next_unrecorded_image_asset_job") else None
                if asset_job is None:
                    if self.process_profiled_derivative_queue_batch():
                        self._touch_status()
                        return True
                    if getattr(self, "data_integrity_requested", False) and self.process_data_integrity_maintenance():
                        self._touch_status()
                        return True
                    self.log.info("No metadata, profile, or asset records returned; worker idle")
                    return False
                self.process_asset_job(asset_job)
                self._touch_status()
                return True
            self.log.info("Found uploaded photo without profile data; photo=%s", int(profile_photo.get("id") or 0))
        self.process_profile_photo(profile_photo)
        self._touch_status()
        return True

    def scan_rawtherapee_profiles(self, reason: str) -> int:
        profiles = self.rawtherapee_scanner.scan()
        count = self.db.replace_rawtherapee_profiles(profiles)
        self.last_rawtherapee_profile_scan_at = time.time()
        self.log.info(
            "RawTherapee profile scan completed; reason=%s root=%s profiles=%s",
            reason,
            self.config.metadata.rawtherapee_profile_root,
            count,
        )
        return count

    def process_data_integrity_maintenance(self) -> bool:
        script = Path(self.config.project_root) / "tools" / "php" / "dataIntegrityCheck.php"
        if not script.is_file():
            self.data_integrity_requested = False
            self.log.warning("Data integrity maintenance script was not found: %s", script)
            return False

        try:
            result = subprocess.run(
                [
                    self.config.php_binary,
                    str(script),
                    "--process-lazy-loading",
                    "--json",
                    "--limit=150",
                ],
                check=False,
                capture_output=True,
                text=True,
                timeout=270,
            )
        except Exception as exc:
            self.log.warning("Data integrity maintenance failed to start: %s", exc)
            return False

        output = (result.stdout or "").strip()
        if result.returncode != 0:
            detail = (result.stderr or output).strip()
            self.log.warning("Data integrity maintenance failed: %s", detail)
            return False

        try:
            payload = json.loads(output) if output != "" else {}
        except json.JSONDecodeError:
            self.log.warning("Data integrity maintenance returned invalid JSON: %s", output[:400])
            return False

        requested = bool(payload.get("requested", False))
        blocked = bool(payload.get("blocked", False))
        complete = bool(payload.get("complete_pass", False))
        queued = int(payload.get("queued_preview", 0) or 0) + int(payload.get("queued_final", 0) or 0)
        scanned = int(payload.get("scanned", 0) or 0)
        self.data_integrity_requested = requested and not complete
        if blocked:
            return False
        if not requested:
            return False

        self.log.info(
            "Data integrity maintenance batch completed; scanned=%s queued=%s complete=%s",
            scanned,
            queued,
            complete,
        )
        return scanned > 0 or queued > 0 or complete

    def process_profiled_derivative_queue_batch(self) -> bool:
        script = Path(self.config.project_root) / "tools" / "php" / "dataIntegrityCheck.php"
        if not script.is_file():
            self.log.warning("Profiled derivative batch queue script was not found: %s", script)
            return False

        try:
            result = subprocess.run(
                [
                    self.config.php_binary,
                    str(script),
                    "--queue-profiled-derivatives-batch",
                    "--json",
                    "--limit=150",
                ],
                check=False,
                capture_output=True,
                text=True,
                timeout=270,
            )
        except Exception as exc:
            self.log.warning("Profiled derivative batch queueing failed to start: %s", exc)
            return False

        output = (result.stdout or "").strip()
        if result.returncode != 0:
            detail = (result.stderr or output).strip()
            self.log.warning("Profiled derivative batch queueing failed: %s", detail)
            return False

        try:
            payload = json.loads(output) if output != "" else {}
        except json.JSONDecodeError:
            self.log.warning("Profiled derivative batch queueing returned invalid JSON: %s", output[:400])
            return False

        queued = int(payload.get("queued_preview", 0) or 0) + int(payload.get("queued_final", 0) or 0)
        scanned = int(payload.get("scanned", 0) or 0)
        active = int(payload.get("active_jobs", 0) or 0)
        fresh = int(payload.get("already_fresh", 0) or 0)
        skipped = int(payload.get("skipped", 0) or 0)
        complete = bool(payload.get("complete_pass", False))
        self.profiled_derivative_queue_requested = not complete
        self.log.info(
            "Profiled derivative batch queueing completed; scanned=%s queued=%s active=%s fresh=%s skipped=%s complete=%s",
            scanned,
            queued,
            active,
            fresh,
            skipped,
            complete,
        )

        return (scanned > 0 and not complete) or queued > 0

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
            rawtherapee_profile = self.db.rawtherapee_profile_for_photo(photo) if hasattr(self.db, "rawtherapee_profile_for_photo") else None
            rawtherapee_profile_text = str(rawtherapee_profile.get("profile_path") or "") if rawtherapee_profile else ""
            rawtherapee_profile_path = Path(rawtherapee_profile_text) if rawtherapee_profile_text != "" else None
            profile_source = "existing"
            version = self.profile_runner.version()
            if not source_profile_path.is_file() or source_profile_path.stat().st_size <= 0:
                profile_source = "generated"
                result = self.profile_runner.generate(source_path, source_profile_path, rawtherapee_profile_path) if rawtherapee_profile_path is not None else self.profile_runner.generate(source_path, source_profile_path)
                version = result.version
            properties = parse_pp3_properties(source_profile_path.read_text(encoding="utf-8"))
            try:
                store_stats = self.db.replace_profile_data(photo_id, properties, str(source_profile_path), version, rawtherapee_profile)
            except TypeError:
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
            self.queue_profiled_derivatives(photo_id)
            self.profiled_derivative_queue_requested = True
        except Exception as exc:
            status = self.db.defer_profile(
                photo_id,
                str(exc),
                self.config.worker.max_attempts,
                self.config.worker.retry_delay_seconds,
            )
            self.log.warning("Source profile generation %s for photo=%s: %s", status, photo_id, exc)

    def queue_profiled_derivatives(self, photo_id: int) -> bool:
        if photo_id <= 0:
            return False

        script = Path(self.config.project_root) / "tools" / "php" / "dataIntegrityCheck.php"
        if not script.is_file():
            self.log.warning("Profiled derivative queue script was not found: %s", script)
            return False

        try:
            result = subprocess.run(
                [
                    self.config.php_binary,
                    str(script),
                    "--queue-profiled-derivatives",
                    f"--photo-id={photo_id}",
                    "--json",
                ],
                check=False,
                capture_output=True,
                text=True,
                timeout=120,
            )
        except Exception as exc:
            self.log.warning("Profiled derivative queueing failed to start for photo=%s: %s", photo_id, exc)
            return False

        output = (result.stdout or "").strip()
        if result.returncode != 0:
            detail = (result.stderr or output).strip()
            self.log.warning("Profiled derivative queueing failed for photo=%s: %s", photo_id, detail)
            return False

        try:
            payload = json.loads(output) if output != "" else {}
        except json.JSONDecodeError:
            self.log.warning("Profiled derivative queueing returned invalid JSON for photo=%s: %s", photo_id, output[:400])
            return False

        queued = int(payload.get("queued_preview", 0) or 0) + int(payload.get("queued_final", 0) or 0)
        active = int(payload.get("active_jobs", 0) or 0)
        fresh = int(payload.get("already_fresh", 0) or 0)
        skipped = int(payload.get("skipped", 0) or 0)
        self.log.info(
            "Profiled derivative queueing completed for photo=%s queued=%s active=%s fresh=%s skipped=%s",
            photo_id,
            queued,
            active,
            fresh,
            skipped,
        )

        return bool(payload.get("success", False))

    def process_asset_notification(self, notification: AssetNotification) -> None:
        self.log.info(
            "Received image asset notification photo=%s image_type=%s job=%s reason=%s path=%s",
            notification.photo_id,
            notification.image_type,
            notification.job_id,
            notification.reason,
            notification.output_path,
        )
        self.process_asset_job({
            "job_id": notification.job_id,
            "photo_id": notification.photo_id,
            "image_type": notification.image_type,
            "output_path": notification.output_path,
            "profile_signature": notification.profile_signature,
        })

    def process_asset_job(self, job: dict[str, Any]) -> None:
        photo_id = int(job.get("photo_id") or 0)
        job_id = int(job.get("job_id") or 0)
        image_type = str(job.get("image_type") or "").strip().lower()
        output_path_text = str(job.get("output_path") or "").strip()
        output_path = Path(output_path_text)
        profile_signature = str(job.get("profile_signature") or "").strip().lower()
        try:
            if photo_id <= 0 or image_type == "" or output_path_text == "":
                raise RuntimeError("Asset notification did not include a valid photo, image type, and output path.")
            if not output_path.is_file():
                raise RuntimeError(f"Asset output file was not found: {output_path}")
            stat = output_path.stat()
            if stat.st_size <= 0:
                raise RuntimeError(f"Asset output file is empty: {output_path}")
            width, height = self._jpeg_dimensions(output_path)
            self.db.upsert_image_asset(
                photo_id,
                image_type,
                self._sha256(output_path),
                int(stat.st_size),
                int(stat.st_mtime),
                width,
                height,
                profile_signature,
                job_id,
            )
            self.log.info("Recorded image asset photo=%s image_type=%s job=%s path=%s", photo_id, image_type, job_id, output_path)
        except Exception as exc:
            self.log.warning("Image asset recording failed for photo=%s image_type=%s job=%s: %s", photo_id, image_type, job_id, exc)

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

    def _sha256(self, path: Path) -> str:
        digest = hashlib.sha256()
        with path.open("rb") as handle:
            for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                digest.update(chunk)
        return digest.hexdigest()

    def _jpeg_dimensions(self, path: Path) -> tuple[int | None, int | None]:
        try:
            with path.open("rb") as handle:
                if handle.read(2) != b"\xff\xd8":
                    return None, None
                while True:
                    marker_start = handle.read(1)
                    if marker_start == b"":
                        return None, None
                    if marker_start != b"\xff":
                        continue
                    marker = handle.read(1)
                    while marker == b"\xff":
                        marker = handle.read(1)
                    if marker in {b"\xc0", b"\xc1", b"\xc2", b"\xc3", b"\xc5", b"\xc6", b"\xc7", b"\xc9", b"\xca", b"\xcb", b"\xcd", b"\xce", b"\xcf"}:
                        length = int.from_bytes(handle.read(2), "big")
                        if length < 7:
                            return None, None
                        handle.read(1)
                        height = int.from_bytes(handle.read(2), "big")
                        width = int.from_bytes(handle.read(2), "big")
                        return (width if width > 0 else None, height if height > 0 else None)
                    if marker in {b"\xd8", b"\xd9"}:
                        continue
                    length_bytes = handle.read(2)
                    if len(length_bytes) != 2:
                        return None, None
                    length = int.from_bytes(length_bytes, "big")
                    if length < 2:
                        return None, None
                    handle.seek(length - 2, 1)
        except OSError:
            return None, None

    def status(self) -> dict[str, Any]:
        return {
            "success": True,
            "service": {
                "state": "running",
                "project_root": self.config.project_root,
                "poll_min_seconds": self.config.worker.poll_min_seconds,
                "poll_max_seconds": self.config.worker.poll_max_seconds,
                "server_timezone": self.config.metadata.server_timezone,
                "data_integrity_requested": self.data_integrity_requested,
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
