from __future__ import annotations

import logging
import shutil
import threading
import time
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

    def request_shutdown(self) -> None:
        if not self.shutdown_requested.is_set():
            self.log.info("Shutdown requested; finishing active conversion jobs before exit")
            self.shutdown_requested.set()

    def run_forever(self) -> None:
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
                        self.shutdown_requested.wait(self.config.storage.storage_blocked_poll_interval_seconds)
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
            if self.db.is_stale_filtered(job):
                self.db.cancel_job(job, "Stale profile version")
                return

            storage = getattr(self, "storage", None)
            if storage is not None:
                job = storage.relocate_job_if_needed(job)

            job.validate()
            result = self.embedded.extract(job, str(temp_dir)) if job.image_type == "embedded" else self.runner.render(job, str(temp_dir))
            render_duration = result.duration_seconds
            if result.exit_code != 0:
                raise RuntimeError(f"conversion failed with exit code {result.exit_code}: {result.stderr}")

            output = Path(result.temp_output_path)
            if not output.is_file() or output.stat().st_size <= 0:
                raise RuntimeError("Conversion did not create a non-empty output file.")

            final = Path(job.output_path)
            final.parent.mkdir(parents=True, exist_ok=True)
            shutil.move(str(output), str(final))
            self.db.complete_job(job, str(final), result.command, result.stderr, result.duration_seconds)
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
        self.redis.pop()
        return self.db.next_queued_job_id()

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
