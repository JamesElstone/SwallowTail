from __future__ import annotations

import os
import shutil
import time
import unittest
import uuid
import logging
import json
import threading
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from swallowtail_conversion.config import (
    AppConfig,
    DatabaseConfig,
    LoggingConfig,
    RawTherapeeConfig,
    RedisConfig,
    StorageConfig,
    WorkerConfig,
    default_config,
    load_php_app_config,
)
from swallowtail_conversion.db import ConversionDatabase
from swallowtail_conversion.embedded import EmbeddedJpegExtractor
from swallowtail_conversion.health import _check_directory_writable, _check_log_writable
from swallowtail_conversion.jobs import ConversionJob
from swallowtail_conversion.rawtherapee import RawTherapeeRunner
from swallowtail_conversion.redis_queue import RedisQueue
from swallowtail_conversion.storage import ConversionStorageManager, StorageBlocked
from swallowtail_conversion.worker import ConversionWorker


def job(root: Path, **overrides) -> ConversionJob:
    input_path = root / "test.CR2"
    input_path.write_bytes(b"II*\0CR2")
    values = {
        "id": 1,
        "photo_id": 2,
        "image_type": "preview",
        "input_path": str(input_path),
        "profile_path": None,
        "output_path": str(root / "final.jpg"),
        "profile_signature": "",
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
            preempt_queue="preempt",
            storage_wake_queue="storage-wake",
            metadata_asset_queue="metadata-asset",
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
        storage=StorageConfig(
            full_threshold_percent=5.0,
            store_on_root_partition=False,
            storage_blocked_poll_interval_seconds=3600,
            project_root=str(root),
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

    def test_php_app_config_loads_storage_settings(self) -> None:
        payload = {
            "swallowtail": {
                "storage": {
                    "full_threshold_percent": 7.5,
                    "store_on_root_partition": True,
                    "storage_blocked_poll_interval_seconds": 1800,
                },
            },
        }

        with patch("swallowtail_conversion.config.subprocess.run") as run:
            run.return_value = SimpleNamespace(returncode=0, stdout=json.dumps(payload), stderr="")

            config = load_php_app_config("secure/app.php", "php", default_config())

        self.assertEqual(7.5, config.storage.full_threshold_percent)
        self.assertTrue(config.storage.store_on_root_partition)
        self.assertEqual(1800, config.storage.storage_blocked_poll_interval_seconds)

    def test_php_app_config_loads_redis_preempt_queue(self) -> None:
        payload = {
            "swallowtail": {
                "redis": {
                    "host": "redis.internal",
                    "port": 6380,
                    "urgent_queue": "urgent-custom",
                    "normal_queue": "normal-custom",
                    "preempt_queue": "preempt-custom",
                    "storage_wake_queue": "storage-wake-custom",
                },
            },
        }

        with patch("swallowtail_conversion.config.subprocess.run") as run:
            run.return_value = SimpleNamespace(returncode=0, stdout=json.dumps(payload), stderr="")

            config = load_php_app_config("secure/app.php", "php", default_config())

        self.assertEqual("redis.internal", config.redis.host)
        self.assertEqual(6380, config.redis.port)
        self.assertEqual("urgent-custom", config.redis.urgent_queue)
        self.assertEqual("normal-custom", config.redis.normal_queue)
        self.assertEqual("preempt-custom", config.redis.preempt_queue)
        self.assertEqual("storage-wake-custom", config.redis.storage_wake_queue)

    def test_php_app_config_defaults_storage_settings_when_missing(self) -> None:
        with patch("swallowtail_conversion.config.subprocess.run") as run:
            run.return_value = SimpleNamespace(returncode=0, stdout=json.dumps({}), stderr="")

            config = load_php_app_config("secure/app.php", "php", default_config())

        self.assertEqual(5.0, config.storage.full_threshold_percent)
        self.assertFalse(config.storage.store_on_root_partition)
        self.assertEqual(3600, config.storage.storage_blocked_poll_interval_seconds)

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
        self.assertIsNone(result.temp_profile_path)
        self.assertIn("-o", result.command)
        self.assertNotIn("-O", result.command)
        self.assertIn("-c", result.command)
        self.assertIn("-q", result.command)
        self.assertIn("-Y", result.command)
        self.assertIn("-f", result.command)
        self.assertIn("-j100", result.command)
        self.assertIn("-js2", result.command)
        self.assertNotIn("-j85", result.command)

    def test_original_render_uses_copy_profile_output_option(self) -> None:
        result = RawTherapeeRunner(
            RawTherapeeConfig(binary=str(self.fake), maximum_threads=1, home=str(self.root / "home"), stderr_chars=4000)
        ).render(job(self.root, image_type="original"), str(self.root / "work"))

        self.assertEqual(0, result.exit_code)
        self.assertIn("-O", result.command)
        self.assertNotIn("-o", result.command)
        self.assertIsNotNone(result.temp_profile_path)
        self.assertTrue(Path(str(result.temp_profile_path)).is_file())

    def test_preview_dimensions_add_resize_profile_after_user_profile(self) -> None:
        pp3 = self.root / "user.pp3"
        pp3.write_text("[Version]\nAppVersion=5.12\n", encoding="utf-8")
        result = RawTherapeeRunner(
            RawTherapeeConfig(binary=str(self.fake), maximum_threads=1, home=str(self.root / "home"), stderr_chars=4000)
        ).render(
            job(self.root, image_type="preview", profile_path=str(pp3), output_width=512, output_height=512),
            str(self.root / "work"),
        )

        profile_args = [result.command[index + 1] for index, value in enumerate(result.command) if value == "-p"]
        self.assertEqual(str(pp3), profile_args[0])
        self.assertTrue(profile_args[1].endswith("-resize.pp3"))
        self.assertIn("Width=512", Path(profile_args[1]).read_text(encoding="utf-8"))
        self.assertIn("Height=512", Path(profile_args[1]).read_text(encoding="utf-8"))

    def test_final_dimensions_add_resize_profile_after_user_profile(self) -> None:
        pp3 = self.root / "final-v2.pp3"
        pp3.write_text("[Exposure]\nBrightness=12\n", encoding="utf-8")
        result = RawTherapeeRunner(
            RawTherapeeConfig(binary=str(self.fake), maximum_threads=1, home=str(self.root / "home"), stderr_chars=4000)
        ).render(
            job(self.root, image_type="final", profile_path=str(pp3), output_width=1600, output_height=1600),
            str(self.root / "work"),
        )

        profile_args = [result.command[index + 1] for index, value in enumerate(result.command) if value == "-p"]
        self.assertEqual(str(pp3), profile_args[0])
        self.assertTrue(profile_args[1].endswith("-resize.pp3"))
        resize_profile = Path(profile_args[1]).read_text(encoding="utf-8")
        self.assertIn("AppliesTo=Cropped area", resize_profile)
        self.assertIn("Width=1600", resize_profile)
        self.assertIn("Height=1600", resize_profile)

    def test_thumbnail_render_uses_supplied_profile(self) -> None:
        pp3 = self.root / "thumbnail.pp3"
        pp3.write_text("[Resize]\nShortEdge=180\n", encoding="utf-8")
        result = RawTherapeeRunner(
            RawTherapeeConfig(binary=str(self.fake), maximum_threads=1, home=str(self.root / "home"), stderr_chars=4000)
        ).render(job(self.root, image_type="thumbnail", profile_path=str(pp3)), str(self.root / "work"))

        self.assertEqual(0, result.exit_code)
        self.assertIn("-o", result.command)
        self.assertNotIn("-O", result.command)
        profile_args = [result.command[index + 1] for index, value in enumerate(result.command) if value == "-p"]
        self.assertEqual([str(pp3)], profile_args)

    def test_rawtherapee_sample_is_supported_as_isolated_output(self) -> None:
        pp3 = self.root / "sample.pp3"
        output = self.root / "sample.jpg"
        pp3.write_text("[Exposure]\nBrightness=12\n", encoding="utf-8")
        sample = job(self.root, image_type="rawtherapee_sample", profile_path=str(pp3), output_path=str(output), priority=65)

        sample.validate()
        result = RawTherapeeRunner(
            RawTherapeeConfig(binary=str(self.fake), maximum_threads=1, home=str(self.root / "home"), stderr_chars=4000)
        ).render(sample, str(self.root / "work"))

        self.assertEqual(0, result.exit_code)
        self.assertIn("-o", result.command)
        self.assertTrue(Path(result.temp_output_path).name.endswith("rawtherapee_sample.jpg"))

    def test_rawtherapee_nonzero_exit_is_reported(self) -> None:
        failing = Path(__file__).parent / "fixtures" / "fake_rawtherapee_fail.py"
        result = RawTherapeeRunner(
            RawTherapeeConfig(binary=str(failing), maximum_threads=1, home=str(self.root / "home"), stderr_chars=4000)
        ).render(job(self.root), str(self.root / "work"))

        self.assertEqual(17, result.exit_code)
        self.assertIn("fake rawtherapee failure", result.stderr)

    def test_rawtherapee_process_can_be_cancelled_while_running(self) -> None:
        slow = Path(__file__).parent / "fixtures" / "fake_rawtherapee_slow.py"
        started = time.monotonic()
        result = RawTherapeeRunner(
            RawTherapeeConfig(binary=str(slow), maximum_threads=1, home=str(self.root / "home"), stderr_chars=4000)
        ).render(
            job(self.root),
            str(self.root / "work"),
            should_cancel=lambda: time.monotonic() - started > 0.4,
        )

        self.assertTrue(result.cancelled)
        self.assertLess(result.duration_seconds, 10)
        self.assertIn("preempted", result.stderr)

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

            def is_stale_preview(self, _job) -> bool:
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

    def test_redis_preview_message_still_uses_database_priority_selection(self) -> None:
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

    def test_redis_job_pop_listens_for_storage_wake_queue(self) -> None:
        config = app_config(self.root, str(self.fake)).redis

        class CapturingRedisQueue(RedisQueue):
            def __init__(self):
                super().__init__(config)
                self.queues: list[str] = []
                self.timeout_seconds = 0

            def _blocking_pop(self, queues: list[str], timeout_seconds: int):
                self.queues = queues
                self.timeout_seconds = timeout_seconds
                return None

        redis = CapturingRedisQueue()

        self.assertIsNone(redis.pop())
        self.assertEqual(["urgent", "normal", "storage-wake"], redis.queues)
        self.assertEqual(1, redis.timeout_seconds)

    def test_storage_wake_message_during_idle_is_logged_and_database_is_checked(self) -> None:
        class FakeRedis:
            def pop(self):
                return SimpleNamespace(queue="storage-wake", job_id=0, reason="storage_refresh")

        class FakeDb:
            def __init__(self) -> None:
                self.selected = False

            def next_queued_job_id(self):
                self.selected = True
                return 15

        db = FakeDb()
        worker = ConversionWorker.__new__(ConversionWorker)
        worker.config = app_config(self.root, str(self.fake))
        worker.redis = FakeRedis()
        worker.db = db
        worker.preempt_lock = threading.Lock()
        worker.preempt_target = None
        worker.log = logging.getLogger("swallowtail_conversion.worker")

        with self.assertLogs("swallowtail_conversion.worker", level="INFO") as logs:
            self.assertEqual(15, worker._next_job_id())

        self.assertTrue(db.selected)
        self.assertTrue(any("Storage wake received; rechecking storage availability" in line for line in logs.output))

    def test_preempt_message_is_verified_and_consumed_before_database_polling(self) -> None:
        class FakeRedis:
            def __init__(self) -> None:
                self.preempt_popped = False
                self.normal_popped = False

            def pop_preempt(self):
                self.preempt_popped = True
                return SimpleNamespace(job_id=99, priority=51)

            def pop(self):
                self.normal_popped = True
                return None

        class FakeDb:
            def __init__(self) -> None:
                self.selected = False

            def preempt_target(self, job_id: int):
                return {"id": job_id, "priority": 51}

            def next_queued_job_id(self):
                self.selected = True
                return 7

        redis = FakeRedis()
        db = FakeDb()
        worker = ConversionWorker.__new__(ConversionWorker)
        worker.redis = redis
        worker.db = db
        worker.preempt_lock = threading.Lock()
        worker.preempt_target = None

        self.assertEqual(99, worker._next_job_id())
        self.assertTrue(redis.preempt_popped)
        self.assertFalse(redis.normal_popped)
        self.assertFalse(db.selected)

    def test_preempt_message_interrupts_lower_priority_job_only_after_database_verification(self) -> None:
        class FakeRedis:
            def __init__(self) -> None:
                self.sent = False

            def pop_preempt(self):
                if self.sent:
                    return None
                self.sent = True
                return SimpleNamespace(job_id=99, priority=51)

        class FakeDb:
            def __init__(self) -> None:
                self.verified = False

            def is_obsolete_job(self, _job) -> bool:
                return False

            def preempt_target(self, job_id: int):
                self.verified = True
                return {"id": job_id, "priority": 51}

        db = FakeDb()
        worker = ConversionWorker.__new__(ConversionWorker)
        worker.redis = FakeRedis()
        worker.db = db
        worker.preempt_lock = threading.Lock()
        worker.preempt_target = None

        self.assertTrue(worker._should_preempt(job(self.root, id=1, priority=20)))
        self.assertTrue(db.verified)
        self.assertFalse(worker._should_preempt(job(self.root, id=2, priority=51)))

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

    def test_run_once_does_not_claim_jobs_when_storage_is_blocked(self) -> None:
        class FakeRedis:
            def __init__(self) -> None:
                self.touched: list[str] = []

            def touch_service(self, service_key: str) -> bool:
                self.touched.append(service_key)
                return True

            def pop(self):
                raise AssertionError("blocked storage should prevent queue polling")

        class FakeDb:
            def __init__(self) -> None:
                self.selected = False

            def next_queued_job_id(self):
                self.selected = True
                return 42

        class FakeStorage:
            def has_usable_location(self) -> bool:
                return False

        db = FakeDb()
        worker = ConversionWorker.__new__(ConversionWorker)
        worker.config = app_config(self.root, str(self.fake))
        worker.log = logging.getLogger("test")
        worker.log.disabled = True
        worker.redis = FakeRedis()
        worker.db = db
        worker.storage = FakeStorage()
        worker.last_storage_blocked_log_at = 0.0

        self.assertFalse(worker.run_once())
        self.assertFalse(db.selected)
        self.assertEqual(["swallowtail_conversion"], worker.redis.touched)

    def test_storage_blocked_wait_refreshes_heartbeat_inside_freshness_window(self) -> None:
        class FakeClock:
            def __init__(self) -> None:
                self.now = 0.0

            def monotonic(self) -> float:
                return self.now

        class FakeShutdown:
            def __init__(self, clock: FakeClock) -> None:
                self.clock = clock
                self.waits: list[float] = []

            def is_set(self) -> bool:
                return False

            def wait(self, seconds: float) -> None:
                self.waits.append(seconds)
                self.clock.now += seconds

        class FakeRedis:
            def __init__(self) -> None:
                self.touched: list[str] = []

            def touch_service(self, service_key: str) -> bool:
                self.touched.append(service_key)
                return True

        clock = FakeClock()
        shutdown = FakeShutdown(clock)
        worker = ConversionWorker.__new__(ConversionWorker)
        worker.redis = FakeRedis()
        worker.shutdown_requested = shutdown
        worker.log = logging.getLogger("test")
        worker.log.disabled = True

        self.assertLess(ConversionWorker.STATUS_REFRESH_INTERVAL_SECONDS, 360)
        with patch("swallowtail_conversion.worker.time.monotonic", clock.monotonic):
            worker._wait_with_status(700)

        self.assertEqual([300, 300, 100], shutdown.waits)
        self.assertEqual(["swallowtail_conversion", "swallowtail_conversion"], worker.redis.touched)

    def test_storage_wake_message_interrupts_blocked_storage_wait(self) -> None:
        class FakeShutdown:
            def is_set(self) -> bool:
                return False

            def wait(self, _seconds: float) -> None:
                raise AssertionError("storage wake should interrupt before event wait fallback")

        class FakeRedis:
            def __init__(self) -> None:
                self.waits: list[int] = []
                self.touched: list[str] = []

            def pop_storage_wake(self, timeout_seconds: int) -> bool:
                self.waits.append(timeout_seconds)
                return True

            def touch_service(self, service_key: str) -> bool:
                self.touched.append(service_key)
                return True

        worker = ConversionWorker.__new__(ConversionWorker)
        worker.redis = FakeRedis()
        worker.shutdown_requested = FakeShutdown()
        worker.log = logging.getLogger("test")
        worker.log.disabled = True

        worker._wait_with_status(3600)

        self.assertEqual([ConversionWorker.STORAGE_WAKE_WAIT_INTERVAL_SECONDS], worker.redis.waits)
        self.assertEqual([], worker.redis.touched)

    def test_storage_blocked_job_is_deferred_without_normal_failure(self) -> None:
        class FakeDb:
            def __init__(self) -> None:
                self.deferred = False
                self.failed = False

            def is_stale_preview(self, _job) -> bool:
                return False

            def defer_job_for_storage(self, _job, message: str, delay_seconds: int) -> None:
                self.deferred = True
                self.message = message
                self.delay_seconds = delay_seconds

            def fail_job(self, _job, _message, retryable=True, duration=None) -> None:
                self.failed = True

        class FakeStorage:
            def relocate_job_if_needed(self, _job):
                raise StorageBlocked("No storage location is above the configured free-space threshold.")

        worker = ConversionWorker.__new__(ConversionWorker)
        worker.config = app_config(self.root, str(self.fake))
        worker.log = logging.getLogger("test")
        worker.log.disabled = True
        worker.db = FakeDb()
        worker.storage = FakeStorage()
        worker.last_storage_blocked_log_at = 0.0

        worker.process_job(job(self.root))
        self.assertTrue(worker.db.deferred)
        self.assertFalse(worker.db.failed)
        self.assertEqual(3600, worker.db.delay_seconds)
        self.assertIn("No storage location", worker.db.message)

    def test_completed_job_log_includes_duration(self) -> None:
        class FakeDb:
            def __init__(self) -> None:
                self.duration = None

            def is_stale_preview(self, _job) -> bool:
                return False

            def complete_job(self, _job, _output_path: str, _command, _stderr, duration: float) -> None:
                self.duration = duration

            def fail_job(self, _job, _message, retryable=True, duration=None) -> None:
                raise AssertionError("completed job should not fail")

        class FakeRedis:
            def __init__(self) -> None:
                self.payload = None

            def push_asset_notification(self, payload: dict) -> bool:
                self.payload = payload
                return True

        class FakeRunner:
            def render(self, job, temp_dir: str, should_cancel=None):
                output = Path(temp_dir) / "rendered.jpg"
                output.write_bytes(b"\xff\xd8\xff\xd9")
                return SimpleNamespace(
                    temp_output_path=str(output),
                    temp_profile_path=None,
                    command=["fake-render"],
                    exit_code=0,
                    stderr="",
                    duration_seconds=12.34567,
                    cancelled=False,
                )

        db = FakeDb()
        worker = ConversionWorker.__new__(ConversionWorker)
        worker.config = app_config(self.root, str(self.fake))
        worker.log = logging.getLogger("swallowtail_conversion.worker")
        worker.db = db
        worker.runner = FakeRunner()
        worker.redis = FakeRedis()

        with self.assertLogs("swallowtail_conversion.worker", level="INFO") as logs:
            worker.process_job(job(self.root, profile_signature="a" * 64))

        self.assertEqual(12.34567, db.duration)
        self.assertIsNotNone(worker.redis.payload)
        self.assertEqual(1, worker.redis.payload["job_id"])
        self.assertEqual(2, worker.redis.payload["photo_id"])
        self.assertEqual("preview", worker.redis.payload["image_type"])
        self.assertEqual(str(self.root / "final.jpg"), worker.redis.payload["output_path"])
        self.assertEqual("a" * 64, worker.redis.payload["profile_signature"])
        self.assertEqual("conversion_completed", worker.redis.payload["reason"])
        self.assertTrue(any("Queued metadata asset notification job=1 photo=2 image_type=preview" in line for line in logs.output))
        self.assertTrue(any("Completed job=1" in line and "duration_seconds=12.346" in line for line in logs.output))

    def test_original_job_preserves_rawtherapee_pp3_as_source_profile(self) -> None:
        class FakeDb:
            def __init__(self) -> None:
                self.completed = False

            def is_stale_preview(self, _job) -> bool:
                return False

            def complete_job(self, _job, output_path: str, _command, _stderr, _duration) -> None:
                self.completed = True
                self.output_path = output_path

            def fail_job(self, _job, _message, retryable=True, duration=None) -> None:
                raise AssertionError("original job should not fail")

        checksum = "abcdef" + ("0" * 58)
        final = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_original.jpg"
        worker = ConversionWorker.__new__(ConversionWorker)
        worker.config = app_config(self.root, str(self.fake))
        worker.log = logging.getLogger("test")
        worker.log.disabled = True
        worker.db = FakeDb()
        worker.runner = RawTherapeeRunner(worker.config.rawtherapee)
        worker.redis = SimpleNamespace(pop_preempt=lambda: None)

        worker.process_job(job(self.root, image_type="original", output_path=str(final)))

        source_profile = final.with_name(f"{checksum}_source.pp3")
        self.assertTrue(worker.db.completed)
        self.assertEqual(str(final), worker.db.output_path)
        self.assertTrue(final.is_file())
        self.assertEqual("[Version]\nAppVersion=5.12\n", source_profile.read_text(encoding="utf-8"))

    def test_profiled_original_job_replaces_existing_source_profile(self) -> None:
        class FakeDb:
            def is_stale_preview(self, _job) -> bool:
                return False

            def complete_job(self, _job, output_path: str, _command, _stderr, _duration) -> None:
                self.output_path = output_path

            def fail_job(self, _job, _message, retryable=True, duration=None) -> None:
                raise AssertionError("profiled original job should not fail")

        checksum = "abcdef" + ("0" * 58)
        final = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_original.jpg"
        final.parent.mkdir(parents=True, exist_ok=True)
        source_profile = final.with_name(f"{checksum}_source.pp3")
        source_profile.write_text("[Version]\nAppVersion=old\n", encoding="utf-8")
        profile = self.root / "baseline.pp3"
        profile.write_text("[Version]\nAppVersion=5.12\n", encoding="utf-8")
        worker = ConversionWorker.__new__(ConversionWorker)
        worker.config = app_config(self.root, str(self.fake))
        worker.log = logging.getLogger("test")
        worker.log.disabled = True
        worker.db = FakeDb()
        worker.runner = RawTherapeeRunner(worker.config.rawtherapee)
        worker.redis = SimpleNamespace(pop_preempt=lambda: None)

        worker.process_job(job(self.root, image_type="original", output_path=str(final), profile_path=str(profile)))

        self.assertEqual(str(final), worker.db.output_path)
        self.assertTrue(final.is_file())
        self.assertEqual("[Version]\nAppVersion=5.12\n", source_profile.read_text(encoding="utf-8"))

    def test_running_lower_priority_rawtherapee_job_is_requeued_when_preempted(self) -> None:
        class FakeRedis:
            def __init__(self) -> None:
                self.sent = False

            def pop_preempt(self):
                if self.sent:
                    return None
                self.sent = True
                return SimpleNamespace(job_id=99, priority=51)

        class FakeDb:
            def __init__(self) -> None:
                self.requeued = False
                self.failed = False

            def is_obsolete_job(self, _job) -> bool:
                return False

            def is_stale_preview(self, _job) -> bool:
                return False

            def preempt_target(self, job_id: int):
                return {"id": job_id, "priority": 51}

            def requeue_preempted_job(self, _job, message: str, duration=None) -> None:
                self.requeued = True
                self.message = message
                self.duration = duration

            def fail_job(self, _job, _message, retryable=True, duration=None) -> None:
                self.failed = True

        slow = Path(__file__).parent / "fixtures" / "fake_rawtherapee_slow.py"
        worker = ConversionWorker.__new__(ConversionWorker)
        worker.config = app_config(self.root, str(slow))
        worker.log = logging.getLogger("test")
        worker.log.disabled = True
        worker.redis = FakeRedis()
        worker.db = FakeDb()
        worker.runner = RawTherapeeRunner(worker.config.rawtherapee)
        worker.preempt_lock = threading.Lock()
        worker.preempt_target = None

        worker.process_job(job(self.root, priority=20))
        self.assertTrue(worker.db.requeued)
        self.assertFalse(worker.db.failed)
        self.assertIn("preempted", worker.db.message)
        self.assertIsNotNone(worker.db.duration)

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

    def test_obsolete_job_is_not_retried_as_failure(self) -> None:
        class FakeDb:
            def __init__(self) -> None:
                self.obsoleted = False
                self.failed = False

            def is_obsolete_job(self, _job) -> bool:
                return True

            def is_stale_preview(self, _job) -> bool:
                return False

            def obsolete_job(self, _job, _message) -> None:
                self.obsoleted = True

            def fail_job(self, _job, _message, retryable=True, duration=None) -> None:
                self.failed = True

        worker = ConversionWorker.__new__(ConversionWorker)
        worker.config = app_config(self.root, str(self.fake))
        worker.log = logging.getLogger("test")
        worker.log.disabled = True
        worker.db = FakeDb()
        worker.runner = RawTherapeeRunner(worker.config.rawtherapee)

        worker.process_job(job(self.root))
        self.assertTrue(worker.db.obsoleted)
        self.assertFalse(worker.db.failed)

    def test_health_helpers_report_missing_paths(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "directory not found"):
            _check_directory_writable(str(self.root / "missing"))

        with self.assertRaisesRegex(RuntimeError, "log directory not found"):
            _check_log_writable(str(self.root / "missing" / "raw.log"))


class StorageManagerTest(unittest.TestCase):
    def setUp(self) -> None:
        self.root = test_root("storage-manager-")

    def tearDown(self) -> None:
        remove_test_root(self.root)

    def test_relocates_checksum_family_to_usable_storage_location(self) -> None:
        checksum = "a" * 64
        old_base = self.root / "storage-old"
        new_base = self.root / "storage-new"
        new_base.mkdir(parents=True)
        source = old_base / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_source.cr2"
        source_profile = old_base / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_source.pp3"
        thumbnail = old_base / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_thumbnail.jpg"
        thumbnail_profile = old_base / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_thumbnail.pp3"
        source.parent.mkdir(parents=True)
        source.write_bytes(b"II*\0CR2 relocation test")
        source_profile.write_text("[Version]\nAppVersion=5.12\n", encoding="utf-8")
        thumbnail.write_bytes(b"\xff\xd8\xff\xd9")
        thumbnail_profile.write_text("[Resize]\nShortEdge=180\n", encoding="utf-8")

        class FakeDb:
            def __init__(self) -> None:
                self.updated = None

            def storage_location_properties(self):
                return []

            def photo_storage(self, _photo_id: int):
                return {
                    "original_sha256": checksum,
                    "storage_base_location": str(old_base),
                }

            def update_photo_storage_location(self, *args) -> None:
                self.updated = args

        def disk_usage(path: str):
            normalised = os.path.abspath(path).rstrip(os.sep) + os.sep
            if normalised == os.path.abspath(str(old_base)).rstrip(os.sep) + os.sep:
                return SimpleNamespace(total=1000, used=950, free=50)
            if normalised == os.path.abspath(str(new_base)).rstrip(os.sep) + os.sep:
                return SimpleNamespace(total=1000, used=800, free=200)
            raise OSError(path)

        db = FakeDb()
        manager = ConversionStorageManager(
            StorageConfig(
                full_threshold_percent=10.0,
                store_on_root_partition=False,
                storage_blocked_poll_interval_seconds=3600,
                project_root=str(self.root),
            ),
            db,
            disk_usage=disk_usage,
            mount_reader=lambda: [str(old_base), str(new_base)],
            zfs_reader=lambda: {},
        )

        old_input = str(source)
        old_output = str(old_base / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_preview.jpg")
        with self.assertLogs("swallowtail_conversion.storage", level="INFO") as logs:
            relocated = manager.relocate_job_if_needed(job(self.root, input_path=old_input, output_path=old_output))
        new_source = new_base / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_source.cr2"
        new_source_profile = new_base / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_source.pp3"
        new_thumbnail = new_base / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_thumbnail.jpg"
        new_thumbnail_profile = new_base / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_thumbnail.pp3"

        self.assertTrue(new_source.is_file())
        self.assertTrue(new_source_profile.is_file())
        self.assertTrue(new_thumbnail.is_file())
        self.assertTrue(new_thumbnail_profile.is_file())
        self.assertFalse(source.exists())
        self.assertFalse(source_profile.exists())
        self.assertFalse(thumbnail.exists())
        self.assertFalse(thumbnail_profile.exists())
        self.assertIn(str(new_base), relocated.input_path)
        self.assertIn(str(new_base), relocated.output_path)
        self.assertIsNotNone(db.updated)
        self.assertEqual(str(new_base.resolve()) + os.sep, db.updated[2])
        self.assertTrue(any("Relocated storage before conversion job=1 photo=2" in line for line in logs.output))
        self.assertTrue(any(f"checksum={checksum}" in line for line in logs.output))
        self.assertTrue(any("reason=old_storage_below_free_space_threshold" in line for line in logs.output))
        self.assertTrue(any("old_free_bytes=50" in line for line in logs.output))
        self.assertTrue(any("old_threshold_bytes=100" in line for line in logs.output))
        self.assertTrue(any(f"new_base={str(new_base.resolve()) + os.sep}" in line for line in logs.output))
        self.assertTrue(any(f"{checksum}_source.cr2" in line and f"{checksum}_source.pp3" in line for line in logs.output))

    def test_relocation_skips_unwritable_storage_destination(self) -> None:
        checksum = "b" * 64
        old_base = self.root / "storage-old"
        new_base = self.root / "storage-new"
        old_base.mkdir(parents=True)
        new_base.mkdir(parents=True)

        class FakeDb:
            updated = None

            def storage_location_properties(self):
                return []

            def photo_storage(self, _photo_id: int):
                return {
                    "original_sha256": checksum,
                    "storage_base_location": str(old_base),
                }

            def update_photo_storage_location(self, *args) -> None:
                self.updated = args

        def disk_usage(path: str):
            normalised = os.path.abspath(path).rstrip(os.sep) + os.sep
            if normalised == os.path.abspath(str(old_base)).rstrip(os.sep) + os.sep:
                return SimpleNamespace(total=1000, used=950, free=50)
            if normalised == os.path.abspath(str(new_base)).rstrip(os.sep) + os.sep:
                return SimpleNamespace(total=1000, used=800, free=200)
            raise OSError(path)

        db = FakeDb()
        manager = ConversionStorageManager(
            StorageConfig(
                full_threshold_percent=10.0,
                store_on_root_partition=False,
                storage_blocked_poll_interval_seconds=3600,
                project_root=str(self.root),
            ),
            db,
            disk_usage=disk_usage,
            mount_reader=lambda: [str(old_base), str(new_base)],
            zfs_reader=lambda: {},
        )
        manager._location_accepts_writes = lambda base: os.path.abspath(base).rstrip(os.sep) != os.path.abspath(str(new_base)).rstrip(os.sep)

        with self.assertRaises(StorageBlocked):
            manager.relocate_job_if_needed(job(self.root))
        self.assertIsNone(db.updated)

    def test_relocation_permission_error_is_storage_blocked(self) -> None:
        checksum = "c" * 64
        old_base = self.root / "storage-old"
        new_base = self.root / "storage-new"
        source = old_base / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_source.cr2"
        source.parent.mkdir(parents=True)
        source.write_bytes(b"II*\0CR2 relocation test")
        new_base.mkdir(parents=True)

        class FakeDb:
            updated = None

            def storage_location_properties(self):
                return []

            def photo_storage(self, _photo_id: int):
                return {
                    "original_sha256": checksum,
                    "storage_base_location": str(old_base),
                }

            def update_photo_storage_location(self, *args) -> None:
                self.updated = args

        def disk_usage(path: str):
            normalised = os.path.abspath(path).rstrip(os.sep) + os.sep
            if normalised == os.path.abspath(str(old_base)).rstrip(os.sep) + os.sep:
                return SimpleNamespace(total=1000, used=950, free=50)
            if normalised == os.path.abspath(str(new_base)).rstrip(os.sep) + os.sep:
                return SimpleNamespace(total=1000, used=800, free=200)
            raise OSError(path)

        db = FakeDb()
        manager = ConversionStorageManager(
            StorageConfig(
                full_threshold_percent=10.0,
                store_on_root_partition=False,
                storage_blocked_poll_interval_seconds=3600,
                project_root=str(self.root),
            ),
            db,
            disk_usage=disk_usage,
            mount_reader=lambda: [str(old_base), str(new_base)],
            zfs_reader=lambda: {},
        )
        manager._copy_verified = lambda _source, _destination: (_ for _ in ()).throw(PermissionError("permission denied"))

        with self.assertRaisesRegex(StorageBlocked, "not writable"):
            manager.relocate_job_if_needed(job(self.root))
        self.assertIsNone(db.updated)

    def test_has_usable_location_uses_threshold(self) -> None:
        base = self.root / "storage"

        manager = ConversionStorageManager(
            StorageConfig(
                full_threshold_percent=10.0,
                store_on_root_partition=False,
                storage_blocked_poll_interval_seconds=3600,
                project_root=str(self.root),
            ),
            SimpleNamespace(storage_location_properties=lambda: []),
            disk_usage=lambda _path: SimpleNamespace(total=1000, used=901, free=99),
            mount_reader=lambda: [str(base)],
            zfs_reader=lambda: {},
        )

        self.assertFalse(manager.has_usable_location())


class ConversionDatabaseOrderingTest(unittest.TestCase):
    def test_photo_state_follows_aggregate_job_status(self) -> None:
        state = ConversionDatabase.photo_state_from_job_counts

        self.assertEqual("processing", state(active_jobs=1, failed_jobs=0, non_cancelled_jobs=3, succeeded_jobs=2))
        self.assertEqual("processing", state(active_jobs=1, failed_jobs=1, non_cancelled_jobs=3, succeeded_jobs=1))
        self.assertEqual("failed", state(active_jobs=0, failed_jobs=1, non_cancelled_jobs=3, succeeded_jobs=2))
        self.assertEqual("ready", state(active_jobs=0, failed_jobs=0, non_cancelled_jobs=3, succeeded_jobs=3))
        self.assertEqual("ready", state(active_jobs=0, failed_jobs=0, non_cancelled_jobs=2, succeeded_jobs=2))
        self.assertEqual("pending", state(active_jobs=0, failed_jobs=0, non_cancelled_jobs=0, succeeded_jobs=0))

    def test_database_priority_order_sql_uses_numeric_priority_descending(self) -> None:
        queries: list[str] = []
        db = ConversionDatabase.__new__(ConversionDatabase)
        db._fetchone = lambda sql, params=(): queries.append(sql) or {"id": 7}
        db._rollback_read = lambda: None

        self.assertEqual(7, db.next_queued_job_id())
        self.assertIn("priority DESC", queries[0])
        self.assertNotIn("CASE image_type", queries[0])

    def test_preempt_priority_threshold_is_high_priority_only(self) -> None:
        self.assertEqual(50, ConversionDatabase.PREEMPT_PRIORITY)

    def test_odbc_storage_properties_read_ignores_hy010_rollback_cleanup(self) -> None:
        class FakeOdbcError(Exception):
            pass

        class FakeCursor:
            description = [("storage_base_location",), ("is_excluded",), ("is_zfs",), ("dataset_name",)]

            def __init__(self) -> None:
                self.closed = False

            def execute(self, _sql, _params) -> None:
                return None

            def fetchall(self):
                return [("/storage/1", 0, 0, None)]

            def close(self) -> None:
                self.closed = True

        class FakeConnection:
            def __init__(self) -> None:
                self.cursor_instance = FakeCursor()
                self.rollback_called = False

            def cursor(self):
                return self.cursor_instance

            def rollback(self) -> None:
                self.rollback_called = True
                raise FakeOdbcError("HY010", "Function sequence error")

        connection = FakeConnection()
        db = ConversionDatabase.__new__(ConversionDatabase)
        db.driver = "odbc"
        db.paramstyle = "?"
        db.connection = connection

        rows = db.storage_location_properties()

        self.assertEqual("/storage/1", rows[0]["storage_base_location"])
        self.assertTrue(connection.cursor_instance.closed)
        self.assertTrue(connection.rollback_called)

    def test_read_fetch_reconnects_once_after_odbc_connection_loss(self) -> None:
        class FakeOdbcError(Exception):
            pass

        class FakeCursor:
            description = [("storage_base_location",), ("is_excluded",), ("is_zfs",), ("dataset_name",)]

            def __init__(self, connection) -> None:
                self.connection = connection
                self.closed = False

            def execute(self, _sql, _params) -> None:
                if self.connection.fail_execute:
                    raise FakeOdbcError("08S01", "Server has gone away")

            def fetchall(self):
                return [(self.connection.name, 0, 0, None)]

            def close(self) -> None:
                self.closed = True

        class FakeConnection:
            def __init__(self, name: str, fail_execute: bool = False) -> None:
                self.name = name
                self.fail_execute = fail_execute
                self.closed = False

            def cursor(self):
                return FakeCursor(self)

            def rollback(self) -> None:
                return None

            def close(self) -> None:
                self.closed = True

        failed_connection = FakeConnection("failed", fail_execute=True)
        recovered_connection = FakeConnection("recovered")

        db = ConversionDatabase.__new__(ConversionDatabase)
        db.driver = "odbc"
        db.paramstyle = "?"
        db.connection = failed_connection
        db._connect = lambda: recovered_connection

        rows = db.storage_location_properties()

        self.assertEqual("recovered", rows[0]["storage_base_location"])
        self.assertTrue(failed_connection.closed)

    def test_write_failure_discards_odbc_connection_without_retrying_write(self) -> None:
        class FakeOdbcError(Exception):
            pass

        class FakeCursor:
            def __init__(self, connection) -> None:
                self.connection = connection
                self.closed = False

            def execute(self, _sql, _params) -> None:
                self.connection.execute_count += 1
                raise FakeOdbcError("08S01", "Got packets out of order")

            def close(self) -> None:
                self.closed = True

        class FakeConnection:
            def __init__(self) -> None:
                self.execute_count = 0
                self.closed = False

            def cursor(self):
                return FakeCursor(self)

            def close(self) -> None:
                self.closed = True

        connection = FakeConnection()
        db = ConversionDatabase.__new__(ConversionDatabase)
        db.driver = "odbc"
        db.paramstyle = "?"
        db.connection = connection

        with self.assertRaises(FakeOdbcError):
            db._execute("UPDATE photo_conversion_jobs SET status = %s", ("failed",))

        self.assertEqual(1, connection.execute_count)
        self.assertTrue(connection.closed)

    def test_database_uses_thread_local_connections(self) -> None:
        class FakeConnection:
            def __init__(self, name: str) -> None:
                self.name = name

        db = ConversionDatabase.__new__(ConversionDatabase)
        db.driver = "odbc"
        db.paramstyle = "?"
        created: list[FakeConnection] = []

        def connect() -> FakeConnection:
            connection = FakeConnection(str(len(created)))
            created.append(connection)
            return connection

        db._connect = connect

        main_connection = db.connection
        worker_connections: list[FakeConnection] = []
        thread = threading.Thread(target=lambda: worker_connections.append(db.connection))
        thread.start()
        thread.join()

        self.assertIs(main_connection, db.connection)
        self.assertIsNot(main_connection, worker_connections[0])
        self.assertEqual(2, len(created))


if __name__ == "__main__":
    unittest.main()
