from __future__ import annotations

import logging
import shutil
import time
from concurrent.futures import Future, ThreadPoolExecutor, wait, FIRST_COMPLETED
from pathlib import Path

from .config import AppConfig
from .db import ConversionDatabase
from .jobs import ConversionJob
from .rawtherapee import RawTherapeeRunner
from .redis_queue import RedisQueue


class ConversionWorker:
    def __init__(self, config: AppConfig):
        self.config = config
        self.log = logging.getLogger("raw_conversion.worker")
        self.db = ConversionDatabase(config.database, config.worker)
        self.redis = RedisQueue(config.redis)
        self.runner = RawTherapeeRunner(config.rawtherapee)

    def run_forever(self) -> None:
        recovered = self.db.requeue_expired_jobs()
        if recovered:
            self.log.info("Requeued %s expired conversion jobs", recovered)

        with ThreadPoolExecutor(max_workers=self.config.rawtherapee.maximum_threads) as executor:
            futures: set[Future] = set()
            while True:
                while len(futures) < self.config.rawtherapee.maximum_threads:
                    job_id = self._next_job_id()
                    if job_id is None:
                        break
                    future = executor.submit(self.process_job_id, job_id)
                    futures.add(future)

                if not futures:
                    time.sleep(self.config.worker.poll_interval_seconds)
                    continue

                done, futures = wait(futures, timeout=1, return_when=FIRST_COMPLETED)
                for future in done:
                    future.result()

    def run_once(self) -> bool:
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
        self.log.info("Processing job=%s photo=%s derivative=%s", job.id, job.photo_id, job.derivative_type)
        temp_dir = Path(self.config.worker.work_dir) / f"job-{job.id}"
        if temp_dir.exists():
            shutil.rmtree(temp_dir)
        temp_dir.mkdir(parents=True, exist_ok=True)

        try:
            if self.db.is_stale_preview(job):
                self.db.cancel_job(job, "Stale profile version")
                return

            job.validate()
            result = self.runner.render(job, str(temp_dir))
            if result.exit_code != 0:
                raise RuntimeError(f"rawtherapee-cli failed with exit code {result.exit_code}: {result.stderr}")

            output = Path(result.temp_output_path)
            if not output.is_file() or output.stat().st_size <= 0:
                raise RuntimeError("rawtherapee-cli did not create a non-empty output file.")

            final = Path(job.output_path)
            final.parent.mkdir(parents=True, exist_ok=True)
            shutil.move(str(output), str(final))
            self.db.complete_job(job, str(final), result.command, result.stderr, result.duration_seconds)
            self.log.info("Completed job=%s output=%s", job.id, final)
        except Exception as exc:
            self.log.exception("Conversion job %s failed", job.id)
            self.db.fail_job(job, str(exc), retryable=True)
        finally:
            shutil.rmtree(temp_dir, ignore_errors=True)

    def _next_job_id(self) -> int | None:
        message = self.redis.pop()
        if message is not None:
            return message.job_id
        return self.db.next_queued_job_id()
