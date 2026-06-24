from __future__ import annotations

import logging
import os
import shutil
import threading
import time
import uuid
from concurrent.futures import Future, ThreadPoolExecutor, wait, FIRST_COMPLETED
from pathlib import Path

from .config import AppConfig
from .db import ConversionDatabase
from .embedded import EmbeddedJpegExtractor
from .jobs import ConversionJob
from .rawtherapee import RawTherapeeRunner
from .redis_queue import RedisQueue
from .storage import ConversionStorageManager, StorageBlocked


class ConversionWorker:
    STATUS_REFRESH_INTERVAL_SECONDS = 300
    STORAGE_WAKE_WAIT_INTERVAL_SECONDS = 5

    def __init__(self, config: AppConfig):
        self.config = config
        self.log = logging.getLogger("swallowtail_conversion.worker")
        self.db = ConversionDatabase(config.database, config.worker)
        self.redis = RedisQueue(config.redis)
        self.runner = RawTherapeeRunner(config.rawtherapee)
        self.embedded = EmbeddedJpegExtractor()
        self.storage = ConversionStorageManager(config.storage, self.db)
        self.shutdown_requested = threading.Event()
        self.last_storage_blocked_log_at = 0.0
        self.preempt_lock = threading.Lock()
        self.preempt_target: dict[str, int] | None = None

    def request_shutdown(self) -> None:
        if not self.shutdown_requested.is_set():
            self.log.info("Shutdown requested; stopping after any active conversion jobs finish")
            self.shutdown_requested.set()

    def run_forever(self) -> None:
        self.log.info("Raw conversion worker started")

        removed = self.cleanup_stale_temp_dirs()
        if removed:
            self.log.info("Removed %s stale raw conversion temp directories", removed)

        recovered = self.db.requeue_expired_jobs()
        if recovered:
            self.log.info("Requeued %s expired conversion jobs", recovered)

        with ThreadPoolExecutor(max_workers=self.config.rawtherapee.maximum_threads) as executor:
            futures: set[Future] = set()
            while not self.shutdown_requested.is_set() or futures:
                self._touch_status()
                storage_blocked = self._storage_blocked()
                if storage_blocked:
                    self._log_storage_blocked()
                    if not futures:
                        self._wait_with_status(self.config.storage.storage_blocked_poll_interval_seconds)
                        continue
                else:
                    while not self.shutdown_requested.is_set() and len(futures) < self.config.rawtherapee.maximum_threads:
                        job_id = self._next_job_id()
                        if job_id is None:
                            break
                        future = executor.submit(self.process_job_id, job_id)
                        futures.add(future)

                if not futures:
                    self.shutdown_requested.wait(self.config.worker.poll_interval_seconds)
                    continue

                done, futures = wait(futures, timeout=1, return_when=FIRST_COMPLETED)
                for future in done:
                    future.result()

        self.log.info("Raw conversion worker stopped")

    def run_once(self) -> bool:
        self._touch_status()
        self.cleanup_stale_temp_dirs()
        if self._storage_blocked():
            self._log_storage_blocked()
            return False
        job_id = self._next_job_id()
        if job_id is None:
            return False
        self.process_job_id(job_id)
        return True

    def process_job_id(self, job_id: int) -> None:
        job = self.db.claim_job(job_id)
        if job is None:
            return

        self.process_job(job)

    def process_job(self, job: ConversionJob) -> None:
        self.log.info("Processing job=%s photo=%s image_type=%s", job.id, job.photo_id, job.image_type)
        temp_dir = Path(self.config.worker.work_dir) / f"job-{job.id}"
        render_duration: float | None = None
        if temp_dir.exists():
            shutil.rmtree(temp_dir)
        temp_dir.mkdir(parents=True, exist_ok=True)

        try:
            if self._job_is_obsolete(job) or self.db.is_stale_filtered(job):
                self._obsolete_job(job, "Obsolete preview profile")
                return

            storage = getattr(self, "storage", None)
            if storage is not None:
                job = storage.relocate_job_if_needed(job)

            job.validate()
            result = self.embedded.extract(job, str(temp_dir)) if job.image_type == "embedded" else self.runner.render(
                job,
                str(temp_dir),
                should_cancel=lambda: self._should_preempt(job),
            )
            render_duration = result.duration_seconds
            if getattr(result, "cancelled", False):
                if self._job_is_obsolete(job):
                    self._obsolete_job(job, "Obsolete preview profile")
                else:
                    self.db.requeue_preempted_job(job, result.stderr, duration=render_duration)
                return

            if result.exit_code != 0:
                raise RuntimeError(f"conversion failed with exit code {result.exit_code}: {result.stderr}")

            output = Path(result.temp_output_path)
            if not output.is_file() or output.stat().st_size <= 0:
                raise RuntimeError("Conversion did not create a non-empty output file.")

            if self._job_is_obsolete(job) or self.db.is_stale_filtered(job):
                self._obsolete_job(job, "Obsolete preview profile")
                return

            final = Path(job.output_path)
            final.parent.mkdir(parents=True, exist_ok=True)
            shutil.move(str(output), str(final))
            baseline_path = self._preserve_original_baseline_profile(job, result)
            self.db.complete_job(job, str(final), result.command, result.stderr, result.duration_seconds)
            if baseline_path is not None:
                self.log.info("Completed job=%s output=%s baseline_profile=%s", job.id, final, baseline_path)
            else:
                self.log.info("Completed job=%s output=%s", job.id, final)
        except StorageBlocked as exc:
            self._log_storage_blocked()
            if hasattr(self.db, "defer_job_for_storage"):
                self.db.defer_job_for_storage(
                    job,
                    str(exc),
                    self.config.storage.storage_blocked_poll_interval_seconds,
                )
            else:
                self.db.fail_job(job, str(exc), retryable=True, duration=render_duration)
        except Exception as exc:
            self.log.exception("Conversion job %s failed", job.id)
            self.db.fail_job(job, str(exc), retryable=True, duration=render_duration)
        finally:
            shutil.rmtree(temp_dir, ignore_errors=True)

    def _next_job_id(self) -> int | None:
        target = self._consume_preempt_target()
        if target is not None:
            return target
        self.redis.pop()
        target = self._consume_preempt_target()
        if target is not None:
            return target
        return self.db.next_queued_job_id()

    def _should_preempt(self, job: ConversionJob) -> bool:
        if self._job_is_obsolete(job):
            return True

        target = self._current_preempt_target()
        if target is None or int(target["id"]) == job.id:
            return False

        return int(target["priority"]) > int(job.priority)

    def _current_preempt_target(self) -> dict[str, int] | None:
        lock = getattr(self, "preempt_lock", None)
        if lock is None:
            return self._current_preempt_target_unlocked()
        with lock:
            return self._current_preempt_target_unlocked()

    def _current_preempt_target_unlocked(self) -> dict[str, int] | None:
        self._read_preempt_message_unlocked()
        target = getattr(self, "preempt_target", None)
        if not target:
            return None

        verified = self.db.preempt_target(int(target["id"]))
        if verified is None:
            self.preempt_target = None
            return None

        self.preempt_target = verified
        return verified

    def _consume_preempt_target(self) -> int | None:
        lock = getattr(self, "preempt_lock", None)
        if lock is None:
            target = self._current_preempt_target_unlocked()
            self.preempt_target = None
            return int(target["id"]) if target is not None else None
        with lock:
            target = self._current_preempt_target_unlocked()
            self.preempt_target = None
            return int(target["id"]) if target is not None else None

    def _read_preempt_message_unlocked(self) -> None:
        if not hasattr(self.redis, "pop_preempt"):
            return

        message = self.redis.pop_preempt()
        if message is None:
            return

        verified = self.db.preempt_target(message.job_id)
        if verified is None:
            return

        current = getattr(self, "preempt_target", None)
        if (
            current is None
            or int(verified["priority"]) > int(current["priority"])
            or (int(verified["priority"]) == int(current["priority"]) and int(verified["id"]) > int(current["id"]))
        ):
            self.preempt_target = verified

    def _job_is_obsolete(self, job: ConversionJob) -> bool:
        if hasattr(self.db, "is_obsolete_job"):
            return bool(self.db.is_obsolete_job(job))
        return False

    def _obsolete_job(self, job: ConversionJob, message: str) -> None:
        if hasattr(self.db, "obsolete_job"):
            self.db.obsolete_job(job, message)
        else:
            self.db.cancel_job(job, message)

    def _storage_blocked(self) -> bool:
        storage = getattr(self, "storage", None)
        if storage is None:
            return False
        return not storage.has_usable_location()

    def _log_storage_blocked(self) -> None:
        now = time.monotonic()
        last_logged = float(getattr(self, "last_storage_blocked_log_at", 0.0))
        interval = max(60, int(self.config.storage.storage_blocked_poll_interval_seconds))
        if last_logged > 0 and now - last_logged < interval:
            return
        self.last_storage_blocked_log_at = now
        self.log.warning("Conversion paused: no storage location is above the configured free-space threshold")

    def _touch_status(self) -> None:
        if not self.redis.touch_service("swallowtail_conversion"):
            self.log.debug("Unable to refresh Redis heartbeat for conversion worker")

    def _preserve_original_baseline_profile(self, job, result) -> Path | None:
        if job.image_type != "original" or not getattr(result, "temp_profile_path", None):
            return None

        source = Path(result.temp_profile_path)
        if not source.is_file() or source.stat().st_size <= 0:
            return None

        output = Path(job.output_path)
        stem = output.name
        suffix = "_original.jpg"
        if not stem.endswith(suffix):
            return None
        checksum = stem[:-len(suffix)]
        if checksum == "":
            return None

        destination = output.with_name(f"{checksum}_baseline.pp3")
        if destination.is_file() and destination.stat().st_size > 0:
            return destination

        temporary = destination.with_name(f".{destination.name}.moving-{uuid.uuid4().hex}")
        shutil.copy2(source, temporary)
        os.replace(temporary, destination)
        return destination

    def _wait_with_status(self, timeout_seconds: int) -> None:
        deadline = time.monotonic() + max(0, int(timeout_seconds))
        next_status_at = time.monotonic() + self.STATUS_REFRESH_INTERVAL_SECONDS
        while not self.shutdown_requested.is_set():
            now = time.monotonic()
            remaining = deadline - now
            if remaining <= 0:
                return
            wait_seconds = min(next_status_at, deadline) - now
            if hasattr(self.redis, "pop_storage_wake"):
                wait_seconds = min(self.STORAGE_WAKE_WAIT_INTERVAL_SECONDS, wait_seconds)
                wait_started = time.monotonic()
                if self.redis.pop_storage_wake(int(max(1, wait_seconds))):
                    self.log.info("Storage wake received; rechecking storage availability")
                    return
                elapsed = time.monotonic() - wait_started
                if elapsed < min(1.0, wait_seconds):
                    self.shutdown_requested.wait(max(0.0, wait_seconds - elapsed))
            else:
                self.shutdown_requested.wait(wait_seconds)
            now = time.monotonic()
            if not self.shutdown_requested.is_set() and now >= next_status_at and deadline - now > 0:
                self._touch_status()
                next_status_at = now + self.STATUS_REFRESH_INTERVAL_SECONDS

    def cleanup_stale_temp_dirs(self) -> int:
        work_dir = Path(self.config.worker.work_dir)
        if not work_dir.is_dir():
            return 0

        cutoff = time.time() - (self.config.worker.temp_retention_hours * 3600)
        removed = 0
        for path in work_dir.glob("job-*"):
            if not path.is_dir():
                continue
            try:
                if path.stat().st_mtime >= cutoff:
                    continue
                shutil.rmtree(path)
                removed += 1
            except OSError:
                self.log.warning("Unable to remove stale temp directory: %s", path, exc_info=True)
        return removed
