from __future__ import annotations

import os
import shutil
import time
import unittest
import uuid
import logging
from pathlib import Path
from unittest.mock import patch

from raw_conversion.config import (
    AppConfig,
    DatabaseConfig,
    LoggingConfig,
    RawTherapeeConfig,
    RedisConfig,
    WorkerConfig,
)
from raw_conversion.health import _check_directory_writable, _check_log_writable
from raw_conversion.jobs import ConversionJob
from raw_conversion.rawtherapee import RawTherapeeRunner
from raw_conversion.worker import ConversionWorker


def job(root: Path, **overrides) -> ConversionJob:
    input_path = root / "test.CR2"
    input_path.write_bytes(b"II*\0CR2")
    values = {
        "id": 1,
        "photo_id": 2,
        "derivative_type": "preview",
        "input_path": str(input_path),
        "pp3_path": None,
        "output_path": str(root / "final.jpg"),
        "output_storage_path": "derivatives/preview/aa/bb/test_preview.jpg",
        "output_storage_location_id": 3,
        "profile_version": 1,
        "attempts": 0,
        "output_width": None,
        "output_height": None,
    }
    values.update(overrides)
    return ConversionJob(**values)


def app_config(root: Path, rawtherapee_binary: str) -> AppConfig:
    return AppConfig(
        database=DatabaseConfig(
            driver="mysql",
            dsn="",
            host="127.0.0.1",
            port=3306,
            database="swallowtail",
            user="",
            password="",
        ),
        redis=RedisConfig(
            host="127.0.0.1",
            port=6379,
            urgent_queue="urgent",
            normal_queue="normal",
            timeout_seconds=1,
        ),
        rawtherapee=RawTherapeeConfig(
            binary=rawtherapee_binary,
            maximum_threads=2,
            home=str(root / "home"),
            stderr_chars=4000,
        ),
        worker=WorkerConfig(
            worker_id="test-worker",
            poll_interval_seconds=1,
            job_timeout_seconds=60,
            max_attempts=3,
            retry_delay_seconds=1,
            work_dir=str(root / "work"),
            temp_retention_hours=24,
        ),
        logging=LoggingConfig(file=str(root / "raw.log"), level="INFO"),
    )


def test_root(prefix: str) -> Path:
    base = Path.cwd() / "tmp-tests"
    base.mkdir(parents=True, exist_ok=True)
    path = base / f"{prefix}{uuid.uuid4().hex}"
    path.mkdir(parents=True)
    return path


class RawTherapeeRunnerTest(unittest.TestCase):
    def setUp(self) -> None:
        self.root = test_root("raw-test-")
        self.fake = Path(__file__).parent / "fixtures" / "fake_rawtherapee.py"

    def tearDown(self) -> None:
        shutil.rmtree(self.root, ignore_errors=True)

    def test_runner_writes_output_with_fake_binary(self) -> None:
        result = RawTherapeeRunner(
            RawTherapeeConfig(binary=str(self.fake), maximum_threads=1, home=str(self.root / "home"), stderr_chars=4000)
        ).render(job(self.root), str(self.root / "work"))

        self.assertEqual(0, result.exit_code)
        self.assertTrue(Path(result.temp_output_path).is_file())
        self.assertIn("-c", result.command)

    def test_thumbnail_dimensions_add_resize_profile_after_user_profile(self) -> None:
        pp3 = self.root / "user.pp3"
        pp3.write_text("[Version]\nAppVersion=5.12\n", encoding="utf-8")
        result = RawTherapeeRunner(
            RawTherapeeConfig(binary=str(self.fake), maximum_threads=1, home=str(self.root / "home"), stderr_chars=4000)
        ).render(
            job(self.root, derivative_type="thumbnail", pp3_path=str(pp3), output_width=512, output_height=512),
            str(self.root / "work"),
        )

        profile_args = [result.command[index + 1] for index, value in enumerate(result.command) if value == "-p"]
        self.assertEqual(str(pp3), profile_args[0])
        self.assertTrue(profile_args[1].endswith("-resize.pp3"))
        self.assertIn("Width=512", Path(profile_args[1]).read_text(encoding="utf-8"))
        self.assertIn("Height=512", Path(profile_args[1]).read_text(encoding="utf-8"))

    def test_rawtherapee_nonzero_exit_is_reported(self) -> None:
        failing = Path(__file__).parent / "fixtures" / "fake_rawtherapee_fail.py"
        result = RawTherapeeRunner(
            RawTherapeeConfig(binary=str(failing), maximum_threads=1, home=str(self.root / "home"), stderr_chars=4000)
        ).render(job(self.root), str(self.root / "work"))

        self.assertEqual(17, result.exit_code)
        self.assertIn("fake rawtherapee failure", result.stderr)

    def test_missing_input_and_pp3_are_validation_failures(self) -> None:
        missing_input = job(self.root, input_path=str(self.root / "missing.CR2"))
        with self.assertRaisesRegex(RuntimeError, "Input CR2 file was not found"):
            missing_input.validate()

        missing_pp3 = job(self.root, pp3_path=str(self.root / "missing.pp3"))
        with self.assertRaisesRegex(RuntimeError, "PP3 profile was not found"):
            missing_pp3.validate()

    def test_unwritable_output_path_is_failed_by_worker(self) -> None:
        class FakeDb:
            def __init__(self) -> None:
                self.failed = False

            def is_stale_preview(self, _job) -> bool:
                return False

            def fail_job(self, _job, _message, retryable=True) -> None:
                self.failed = True

        worker = ConversionWorker.__new__(ConversionWorker)
        worker.config = app_config(self.root, str(self.fake))
        worker.log = logging.getLogger("test")
        worker.log.disabled = True
        worker.db = FakeDb()
        worker.runner = RawTherapeeRunner(worker.config.rawtherapee)
        blocked_parent = self.root / "not-a-directory"
        blocked_parent.write_text("blocked", encoding="utf-8")

        worker.process_job(job(self.root, output_path=str(blocked_parent / "out.jpg")))
        self.assertTrue(worker.db.failed)


class WorkerBehaviourTest(unittest.TestCase):
    def setUp(self) -> None:
        self.root = test_root("raw-worker-")
        self.fake = Path(__file__).parent / "fixtures" / "fake_rawtherapee.py"

    def tearDown(self) -> None:
        shutil.rmtree(self.root, ignore_errors=True)

    def test_redis_unavailable_falls_back_to_database_polling(self) -> None:
        class FakeRedis:
            def pop(self):
                return None

        class FakeDb:
            def next_queued_job_id(self):
                return 42

        worker = ConversionWorker.__new__(ConversionWorker)
        worker.redis = FakeRedis()
        worker.db = FakeDb()
        self.assertEqual(42, worker._next_job_id())

    def test_cleanup_removes_only_stale_job_directories(self) -> None:
        config = app_config(self.root, str(self.fake))
        work_dir = Path(config.worker.work_dir)
        stale = work_dir / "job-1"
        fresh = work_dir / "job-2"
        other = work_dir / "other"
        stale.mkdir(parents=True)
        fresh.mkdir()
        other.mkdir()
        old = time.time() - (48 * 3600)
        os.utime(stale, (old, old))

        worker = ConversionWorker.__new__(ConversionWorker)
        worker.config = config
        worker.log = logging.getLogger("test")
        worker.log.disabled = True

        self.assertEqual(1, worker.cleanup_stale_temp_dirs())
        self.assertFalse(stale.exists())
        self.assertTrue(fresh.exists())
        self.assertTrue(other.exists())

    def test_stale_preview_is_cancelled_before_render(self) -> None:
        class FakeDb:
            def __init__(self) -> None:
                self.cancelled = False

            def is_stale_preview(self, _job) -> bool:
                return True

            def cancel_job(self, _job, _message) -> None:
                self.cancelled = True

        worker = ConversionWorker.__new__(ConversionWorker)
        worker.config = app_config(self.root, str(self.fake))
        worker.log = logging.getLogger("test")
        worker.log.disabled = True
        worker.db = FakeDb()
        worker.runner = RawTherapeeRunner(worker.config.rawtherapee)

        worker.process_job(job(self.root))
        self.assertTrue(worker.db.cancelled)

    def test_health_helpers_report_missing_paths(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "directory not found"):
            _check_directory_writable(str(self.root / "missing"))

        with self.assertRaisesRegex(RuntimeError, "log directory not found"):
            _check_log_writable(str(self.root / "missing" / "raw.log"))


if __name__ == "__main__":
    unittest.main()
