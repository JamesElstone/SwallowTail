from __future__ import annotations

import os
import shutil
import time
import unittest
import uuid
import logging
import json
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from swallowtail_conversion.config import (
    AppConfig,
    DatabaseConfig,
    LoggingConfig,
    RawTherapeeConfig,
    RedisConfig,
    WorkerConfig,
    default_config,
    load_php_app_config,
)
from swallowtail_conversion.db import ConversionDatabase
from swallowtail_conversion.embedded import EmbeddedJpegExtractor
from swallowtail_conversion.health import _check_directory_writable, _check_log_writable
from swallowtail_conversion.jobs import ConversionJob
from swallowtail_conversion.rawtherapee import RawTherapeeRunner
from swallowtail_conversion.worker import ConversionWorker


def job(root: Path, **overrides) -> ConversionJob:
    input_path = root / "test.CR2"
    input_path.write_bytes(b"II*\0CR2")
    values = {
        "id": 1,
        "photo_id": 2,
        "image_type": "filtered",
        "input_path": str(input_path),
        "profile_path": None,
        "output_path": str(root / "final.jpg"),
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
    base = Path(__file__).parent / ".tmp"
    base.mkdir(parents=True, exist_ok=True)
    path = base / f"{prefix}{uuid.uuid4().hex}"
    path.mkdir(parents=True)
    return path


def remove_test_root(path: Path) -> None:
    shutil.rmtree(path, ignore_errors=True)
    try:
        path.parent.rmdir()
    except OSError:
        pass


def display_jpeg(width: int, height: int, payload: bytes) -> bytes:
    return (
        b"\xff\xd8"
        + b"\xff\xc0\x00\x11\x08"
        + height.to_bytes(2, "big")
        + width.to_bytes(2, "big")
        + b"\x03\x01\x11\x00\x02\x11\x00\x03\x11\x00"
        + payload
        + b"\xff\xd9"
    )


def lossless_jpeg_like(width: int, height: int, payload: bytes) -> bytes:
    return (
        b"\xff\xd8"
        + b"\xff\xc3\x00\x11\x08"
        + height.to_bytes(2, "big")
        + width.to_bytes(2, "big")
        + b"\x03\x01\x11\x00\x02\x11\x00\x03\x11\x00"
        + payload
        + b"\xff\xd9"
    )


class ConfigLoadingTest(unittest.TestCase):
    def test_php_app_config_loads_odbc_database_details(self) -> None:
        payload = {
            "db": {
                "dsn": "odbc:swallowtail",
                "user": "swallowtail_app",
                "pass": "secret",
            },
        }

        with patch("swallowtail_conversion.config.subprocess.run") as run:
            run.return_value = SimpleNamespace(returncode=0, stdout=json.dumps(payload), stderr="")

            config = load_php_app_config("/usr/local/swallowtail/secure/app.php", "/usr/local/bin/php", default_config())

        self.assertEqual("odbc", config.database.driver)
        self.assertEqual("swallowtail", config.database.dsn)
        self.assertEqual("swallowtail_app", config.database.user)
        self.assertEqual("secret", config.database.password)
        self.assertEqual("/usr/local/bin/php", run.call_args.args[0][0])
        self.assertEqual("/usr/local/swallowtail/secure/app.php", run.call_args.args[0][-1])

    def test_php_app_config_loads_mysql_database_details(self) -> None:
        payload = {
            "db": {
                "dsn": "mysql:host=db.internal;port=3307;dbname=swallowtail;charset=utf8mb4",
                "user": "swallowtail_app",
                "pass": "secret",
            },
        }

        with patch("swallowtail_conversion.config.subprocess.run") as run:
            run.return_value = SimpleNamespace(returncode=0, stdout=json.dumps(payload), stderr="")

            config = load_php_app_config("secure/app.php", "php", default_config())

        self.assertEqual("mysql", config.database.driver)
        self.assertEqual("", config.database.dsn)
        self.assertEqual("db.internal", config.database.host)
        self.assertEqual(3307, config.database.port)
        self.assertEqual("swallowtail", config.database.database)
        self.assertEqual("swallowtail_app", config.database.user)
        self.assertEqual("secret", config.database.password)

    def test_php_app_config_rejects_unsupported_database_driver(self) -> None:
        payload = {"db": {"dsn": "sqlite:/tmp/swallowtail.sqlite"}}

        with patch("swallowtail_conversion.config.subprocess.run") as run:
            run.return_value = SimpleNamespace(returncode=0, stdout=json.dumps(payload), stderr="")

            with self.assertRaisesRegex(RuntimeError, "Unsupported database DSN driver"):
                load_php_app_config("secure/app.php", "php", default_config())


class RawTherapeeRunnerTest(unittest.TestCase):
    def setUp(self) -> None:
        self.root = test_root("raw-test-")
        self.fake = Path(__file__).parent / "fixtures" / "fake_rawtherapee.py"

    def tearDown(self) -> None:
        remove_test_root(self.root)

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
            job(self.root, image_type="thumbnail", profile_path=str(pp3), output_width=512, output_height=512),
            str(self.root / "work"),
        )

        profile_args = [result.command[index + 1] for index, value in enumerate(result.command) if value == "-p"]
        self.assertEqual(str(pp3), profile_args[0])
        self.assertTrue(profile_args[1].endswith("-resize.pp3"))
        self.assertIn("Width=512", Path(profile_args[1]).read_text(encoding="utf-8"))
        self.assertIn("Height=512", Path(profile_args[1]).read_text(encoding="utf-8"))

    def test_filtered_dimensions_add_resize_profile_after_user_profile(self) -> None:
        pp3 = self.root / "filtered-v2.pp3"
        pp3.write_text("[Exposure]\nBrightness=12\n", encoding="utf-8")
        result = RawTherapeeRunner(
            RawTherapeeConfig(binary=str(self.fake), maximum_threads=1, home=str(self.root / "home"), stderr_chars=4000)
        ).render(
            job(self.root, profile_path=str(pp3), profile_version=2, output_width=1600, output_height=1600),
            str(self.root / "work"),
        )

        profile_args = [result.command[index + 1] for index, value in enumerate(result.command) if value == "-p"]
        self.assertEqual(str(pp3), profile_args[0])
        self.assertTrue(profile_args[1].endswith("-resize.pp3"))
        resize_profile = Path(profile_args[1]).read_text(encoding="utf-8")
        self.assertIn("AppliesTo=Cropped area", resize_profile)
        self.assertIn("Width=1600", resize_profile)
        self.assertIn("Height=1600", resize_profile)

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

        missing_pp3 = job(self.root, profile_path=str(self.root / "missing.pp3"))
        with self.assertRaisesRegex(RuntimeError, "PP3 profile was not found"):
            missing_pp3.validate()

    def test_unwritable_output_path_is_failed_by_worker(self) -> None:
        class FakeDb:
            def __init__(self) -> None:
                self.failed = False

            def is_stale_filtered(self, _job) -> bool:
                return False

            def fail_job(self, _job, _message, retryable=True, duration=None) -> None:
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

    def test_embedded_extractor_writes_largest_jpeg_stream(self) -> None:
        source = self.root / "embedded.CR2"
        small = display_jpeg(160, 120, b"small")
        large = display_jpeg(4000, 6000, b"large" * 20)
        raw_like = lossless_jpeg_like(4056, 3048, b"raw" * 1000)
        source.write_bytes(b"CR2DATA" + small + b"between" + raw_like + b"after" + large)
        result = EmbeddedJpegExtractor().extract(
            job(self.root, image_type="embedded", input_path=str(source)),
            str(self.root / "work"),
        )

        self.assertEqual(0, result.exit_code)
        self.assertEqual(large, Path(result.temp_output_path).read_bytes())
        self.assertLess(result.duration_seconds, 1)

    def test_embedded_extractor_reports_missing_jpeg(self) -> None:
        source = self.root / "no-embedded.CR2"
        source.write_bytes(b"CR2DATA without a jpeg")
        result = EmbeddedJpegExtractor().extract(
            job(self.root, image_type="embedded", input_path=str(source)),
            str(self.root / "work"),
        )

        self.assertEqual(1, result.exit_code)
        self.assertIn("No displayable embedded JPEG", result.stderr)


class WorkerBehaviourTest(unittest.TestCase):
    def setUp(self) -> None:
        self.root = test_root("raw-worker-")
        self.fake = Path(__file__).parent / "fixtures" / "fake_rawtherapee.py"

    def tearDown(self) -> None:
        remove_test_root(self.root)

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

    def test_redis_original_message_wakes_database_priority_selection(self) -> None:
        class FakeRedis:
            def __init__(self) -> None:
                self.popped = False

            def pop(self):
                self.popped = True
                return SimpleNamespace(job_id=99)

        class FakeDb:
            def __init__(self) -> None:
                self.selected = False

            def next_queued_job_id(self):
                self.selected = True
                return 7

        redis = FakeRedis()
        db = FakeDb()
        worker = ConversionWorker.__new__(ConversionWorker)
        worker.redis = redis
        worker.db = db

        self.assertEqual(7, worker._next_job_id())
        self.assertTrue(redis.popped)
        self.assertTrue(db.selected)

    def test_redis_thumbnail_message_still_uses_database_priority_selection(self) -> None:
        class FakeRedis:
            def pop(self):
                return SimpleNamespace(job_id=51)

        class FakeDb:
            def next_queued_job_id(self):
                return 12

        worker = ConversionWorker.__new__(ConversionWorker)
        worker.redis = FakeRedis()
        worker.db = FakeDb()

        self.assertEqual(12, worker._next_job_id())

    def test_run_once_records_worker_heartbeat(self) -> None:
        class FakeRedis:
            def __init__(self) -> None:
                self.touched: list[str] = []

            def touch_service(self, service_key: str) -> bool:
                self.touched.append(service_key)
                return True

            def pop(self):
                return None

        class FakeDb:
            def next_queued_job_id(self):
                return None

        redis = FakeRedis()
        worker = ConversionWorker.__new__(ConversionWorker)
        worker.config = app_config(self.root, str(self.fake))
        worker.log = logging.getLogger("test")
        worker.log.disabled = True
        worker.redis = redis
        worker.db = FakeDb()

        self.assertFalse(worker.run_once())
        self.assertEqual(["swallowtail_conversion"], redis.touched)

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

    def test_stale_filtered_is_cancelled_before_render(self) -> None:
        class FakeDb:
            def __init__(self) -> None:
                self.cancelled = False

            def is_stale_filtered(self, _job) -> bool:
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


class ConversionDatabaseOrderingTest(unittest.TestCase):
    def test_database_priority_order_is_embedded_filtered_thumbnail_original(self) -> None:
        self.assertEqual(
            ["embedded", "filtered", "thumbnail", "original"],
            list(ConversionDatabase.IMAGE_TYPE_ORDER.keys()),
        )

    def test_database_priority_order_sql_matches_image_type_policy(self) -> None:
        sql = ConversionDatabase._image_type_order_sql()

        embedded = sql.index("WHEN 'embedded' THEN 1")
        filtered = sql.index("WHEN 'filtered' THEN 2")
        thumbnail = sql.index("WHEN 'thumbnail' THEN 3")
        original = sql.index("WHEN 'original' THEN 4")

        self.assertLess(embedded, filtered)
        self.assertLess(filtered, thumbnail)
        self.assertLess(thumbnail, original)


if __name__ == "__main__":
    unittest.main()


