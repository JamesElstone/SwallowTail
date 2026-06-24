from __future__ import annotations

import shutil
import sys
import unittest
import uuid
from contextlib import redirect_stdout
from dataclasses import replace
from io import StringIO
from pathlib import Path
from unittest.mock import patch

from swallowtail_metadata.config import DaylightSavingConfig, default_config
from swallowtail_metadata.exiftool import extract_properties, parse_metadata
from swallowtail_metadata.profile import RawTherapeeBaselineRunner
from swallowtail_metadata.worker import MetadataWorker


class FakeDatabase:
    def __init__(self, photos=None):
        self.photos = list(photos or [])
        self.ready = []
        self.deferred = []
        self.profile_photos = []
        self.profile_photos_by_id = {}
        self.unprofiled_photos = []
        self.profile_deferred = []
        self.profile_processing = []
        self.profile_ready = []
        self.count_payload = {"ready": 0, "deferred": 0, "failed": 0}

    def next_photo(self):
        return self.photos.pop(0) if self.photos else None

    def next_profile_photo(self):
        return self.profile_photos.pop(0) if self.profile_photos else None

    def profile_photo_by_id(self, photo_id):
        return self.profile_photos_by_id.get(photo_id)

    def next_unprofiled_photo(self):
        return self.unprofiled_photos.pop(0) if self.unprofiled_photos else None

    def upsert_ready(self, photo_id, fields, raw):
        self.ready.append((photo_id, fields, raw))

    def defer_or_fail(self, photo_id, message, max_attempts, retry_delay_seconds):
        self.deferred.append((photo_id, message, max_attempts, retry_delay_seconds))
        return "deferred"

    def mark_profile_processing(self, photo_id):
        self.profile_processing.append(photo_id)

    def replace_profile_data(self, photo_id, rows, baseline_path, rawtherapee_version):
        self.profile_ready.append((photo_id, rows, baseline_path, rawtherapee_version))

    def defer_profile(self, photo_id, message, max_attempts, retry_delay_seconds):
        self.profile_deferred.append((photo_id, message, max_attempts, retry_delay_seconds))
        return "queued"

    def counts(self):
        return self.count_payload

    def ping(self):
        return None


class FakeExifTool:
    def __init__(self, interrupt: bool = False):
        self.paths = []
        self.interrupt = interrupt

    def extract(self, path, metadata, should_interrupt=None):
        self.paths.append((path, metadata.server_timezone))
        if self.interrupt and should_interrupt is not None and should_interrupt():
            raise InterruptedError("Urgent profile notification received while reading metadata")
        return type("Result", (), {
            "fields": {"camera_make": "Canon"},
            "properties": [{"type": "exififd", "key": "Make", "value": "Canon", "value_type": "string"}],
        })()

    def health_check(self):
        return None


class FakeRedis:
    def __init__(self):
        self.touched = []
        self.profile_notifications = []
        self.profile_notification_available = False

    def touch_service(self, service_key):
        self.touched.append(service_key)
        return True

    def pop_profile_notification(self):
        return self.profile_notifications.pop(0) if self.profile_notifications else None

    def has_profile_notification(self):
        return self.profile_notification_available or bool(self.profile_notifications)

    def ping(self):
        return None


class FakeProfileRunner:
    def __init__(self):
        self.generated = []

    def generate(self, source_path, baseline_path):
        self.generated.append((source_path, baseline_path))
        baseline_path.write_text(
            "[Version]\nAppVersion=5.12\n\n[Exposure]\nBlack=63\nBrightness=0\n",
            encoding="utf-8",
        )
        return type("Result", (), {"version": "RawTherapee 5.12"})()

    def health_check(self):
        return None


class FakeLog:
    def __init__(self):
        self.infos = []
        self.warnings = []
        self.debugs = []

    def info(self, message, *args, **_kwargs):
        self.infos.append(message % args if args else message)

    def warning(self, message, *args, **_kwargs):
        self.warnings.append(message % args if args else message)

    def debug(self, message, *args, **_kwargs):
        self.debugs.append(message % args if args else message)


class MetadataParserTest(unittest.TestCase):
    def metadata_config(self, enabled: bool = False) -> object:
        config = default_config()
        return replace(
            config.metadata,
            daylight_saving=DaylightSavingConfig(
                enabled=enabled,
                start="03-31",
                end="10-31",
                offset_minutes=60,
            ),
        )

    def test_winter_london_canon_time_info_does_not_apply_daylight_saving(self) -> None:
        fields = parse_metadata(
            {
                "EXIF:DateTimeOriginal": "2024:02:01 20:39:49",
                "Canon:TimeZone": 60,
                "Canon:TimeZoneCity": 20,
                "Canon:DaylightSavings": 60,
                "EXIF:Make": "Canon",
                "EXIF:Model": "Canon EOS 760D",
                "EXIF:ISO": 1000,
                "EXIF:ExposureTime": "1/30",
                "EXIF:FNumber": 4,
                "EXIF:FocalLength": 17,
                "EXIF:ExifImageWidth": 6000,
                "EXIF:ExifImageHeight": 4000,
            },
            self.metadata_config(enabled=True),
        )

        self.assertEqual("2024-02-01 20:39:49", fields["captured_at_local"])
        self.assertEqual("2024-02-01 20:39:49", fields["captured_at_utc"])
        self.assertEqual(20, fields["camera_timezone_city_code"])
        self.assertEqual("London", fields["camera_timezone_city_label"])
        self.assertEqual(60, fields["camera_daylight_savings_minutes"])
        self.assertEqual("Canon", fields["camera_make"])
        self.assertEqual("Canon EOS 760D", fields["camera_model"])
        self.assertEqual(1000, fields["iso"])

    def test_summer_london_canon_time_info_applies_configured_daylight_saving(self) -> None:
        fields = parse_metadata(
            {
                "EXIF:DateTimeOriginal": "2024:07:01 20:39:49",
                "Canon:TimeZone": 60,
                "Canon:TimeZoneCity": 20,
                "Canon:DaylightSavings": 60,
            },
            self.metadata_config(enabled=True),
        )

        self.assertEqual("2024-07-01 19:39:49", fields["captured_at_utc"])
        self.assertEqual(60, fields["camera_daylight_savings_minutes"])

    def test_disabled_daylight_saving_does_not_apply_configured_adjustment(self) -> None:
        fields = parse_metadata(
            {
                "EXIF:DateTimeOriginal": "2024:07:01 20:39:49",
                "Canon:TimeZone": 60,
                "Canon:DaylightSavings": 60,
            },
            self.metadata_config(enabled=False),
        )

        self.assertEqual("2024-07-01 20:39:49", fields["captured_at_utc"])

    def test_server_timezone_is_fallback_when_canon_timezone_is_missing(self) -> None:
        fields = parse_metadata({"EXIF:DateTimeOriginal": "2024:02:01 20:39:49"}, self.metadata_config())

        self.assertEqual("2024-02-01 20:39:49", fields["captured_at_utc"])

    def test_property_extraction_keeps_selected_scalar_sections_and_skips_binary(self) -> None:
        properties = extract_properties({
            "File:FileType": "CR2",
            "ExifIFD:ISO": 100,
            "Canon:LiveViewShooting": False,
            "Composite:Aperture": 4.0,
            "IFD0:Make": "Canon",
            "Canon:PreviewImage": "(Binary data 10 bytes, use -b option to extract)",
            "Canon:Nested": {"bad": "shape"},
        })

        self.assertEqual(
            [
                {"type": "file", "key": "FileType", "value": "CR2", "value_type": "string"},
                {"type": "exififd", "key": "ISO", "value": "100", "value_type": "int"},
                {"type": "canon", "key": "LiveViewShooting", "value": "0", "value_type": "bool"},
                {"type": "composite", "key": "Aperture", "value": "4.0", "value_type": "float"},
            ],
            properties,
        )


class RawTherapeeBaselineRunnerTest(unittest.TestCase):
    def test_health_check_accepts_rawtherapee_version_output_with_nonzero_exit(self) -> None:
        runner = RawTherapeeBaselineRunner("/usr/local/bin/rawtherapee-cli")

        with patch("swallowtail_metadata.profile.subprocess.run") as run:
            run.return_value = type("Result", (), {
                "returncode": 1,
                "stdout": "",
                "stderr": "RawTherapee, version 5.12, command line.\n",
            })()

            runner.health_check()
            self.assertEqual("RawTherapee, version 5.12, command line.", runner.version())

    def test_generate_uses_contained_runtime_environment_next_to_baseline(self) -> None:
        root = Path(__file__).resolve().parent / ".tmp" / f"rt_{uuid.uuid4().hex[0:8]}"
        source = root / "swallowtail-data" / "ab" / "cd" / ("abcdef" + ("0" * 58) + "_source.cr2")
        baseline = source.with_name("abcdef" + ("0" * 58) + "_baseline.pp3")
        source.parent.mkdir(parents=True, exist_ok=True)
        source.write_bytes(b"II*\0CR2")
        captured_env = {}

        def fake_run(command, **kwargs):
            if "-O" in command:
                captured_env.update(kwargs.get("env") or {})
                scratch = Path(command[command.index("-O") + 1])
                scratch.write_bytes(b"jpg")
                scratch.with_suffix(scratch.suffix + ".pp3").write_text("[Version]\nAppVersion=5.12\n", encoding="utf-8")
                return type("Result", (), {"returncode": 0, "stdout": "", "stderr": ""})()
            return type("Result", (), {
                "returncode": 0,
                "stdout": "RawTherapee, version 5.12, command line.\n",
                "stderr": "",
            })()

        try:
            runner = RawTherapeeBaselineRunner("/usr/local/bin/rawtherapee-cli")
            with patch("swallowtail_metadata.profile.subprocess.run", side_effect=fake_run):
                runner.generate(source, baseline)

            home = Path(captured_env["HOME"])
            self.assertEqual(baseline.parent, home.parent)
            self.assertTrue(home.name.startswith(baseline.stem[0:16] + "_rt_"))
            self.assertTrue(str(captured_env["XDG_CONFIG_HOME"]).startswith(str(home)))
            self.assertTrue(str(captured_env["XDG_CACHE_HOME"]).startswith(str(home)))
            self.assertTrue(baseline.is_file())
            self.assertEqual([], list(baseline.parent.glob(baseline.stem[0:16] + "_rt_*")))
        finally:
            shutil.rmtree(root, ignore_errors=True)


class MetadataWorkerTest(unittest.TestCase):
    def setUp(self) -> None:
        temp_parent = Path(__file__).resolve().parent / ".tmp"
        temp_parent.mkdir(exist_ok=True)
        self.root = temp_parent / f"swallowtail_metadata_worker_{uuid.uuid4().hex}"
        self.root.mkdir()

    def tearDown(self) -> None:
        shutil.rmtree(self.root, ignore_errors=True)
        try:
            self.root.parent.rmdir()
        except OSError:
            pass

    def worker(self, db: FakeDatabase, exiftool: FakeExifTool | None = None, redis: FakeRedis | None = None) -> MetadataWorker:
        config = replace(default_config(), project_root=str(self.root))
        worker = object.__new__(MetadataWorker)
        worker.config = config
        worker.db = db
        worker.exiftool = exiftool or FakeExifTool()
        worker.profile_runner = FakeProfileRunner()
        worker.redis = redis or FakeRedis()
        worker.shutdown_requested = None
        worker.idle_delay_seconds = config.worker.poll_min_seconds
        worker.log = FakeLog()
        return worker

    def test_source_path_is_derived_from_current_photo_storage(self) -> None:
        checksum = "abcdef" + ("0" * 58)
        worker = self.worker(FakeDatabase())

        path = worker.source_path({"storage_base_location": str(self.root), "original_sha256": checksum})

        self.assertEqual(
            self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_source.cr2",
            path,
        )

    def test_run_once_extracts_and_writes_ready_metadata(self) -> None:
        checksum = "abcdef" + ("0" * 58)
        source = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_source.cr2"
        source.parent.mkdir(parents=True)
        source.write_bytes(b"II*\0CR2")
        db = FakeDatabase([{"id": 7, "storage_base_location": str(self.root), "original_sha256": checksum}])
        exiftool = FakeExifTool()
        worker = self.worker(db, exiftool)

        self.assertTrue(worker.run_once())

        self.assertEqual(7, db.ready[0][0])
        self.assertEqual([{"type": "exififd", "key": "Make", "value": "Canon", "value_type": "string"}], db.ready[0][2])
        self.assertEqual([(str(source), "Europe/London")], exiftool.paths)
        self.assertEqual(["swallowtail_metadata", "swallowtail_metadata"], worker.redis.touched)

    def test_metadata_extraction_is_interrupted_when_urgent_profile_arrives(self) -> None:
        checksum = "abcdef" + ("0" * 58)
        source = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_source.cr2"
        source.parent.mkdir(parents=True)
        source.write_bytes(b"II*\0CR2")
        db = FakeDatabase([{"id": 18, "storage_base_location": str(self.root), "original_sha256": checksum}])
        redis = FakeRedis()
        redis.profile_notification_available = True
        worker = self.worker(db, FakeExifTool(interrupt=True), redis)

        self.assertTrue(worker.run_once())

        self.assertEqual([], db.ready)
        self.assertEqual([], db.deferred)
        self.assertIn(
            "Metadata extraction interrupted for urgent profile; photo=18: Urgent profile notification received while reading metadata",
            worker.log.infos,
        )

    def test_run_once_processes_urgent_profile_notification_before_other_work(self) -> None:
        urgent_checksum = "abcdef" + ("0" * 58)
        queued_checksum = "123456" + ("0" * 58)
        for checksum in [urgent_checksum, queued_checksum]:
            source = self.root / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_source.cr2"
            thumbnail = self.root / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_thumbnail.jpg"
            source.parent.mkdir(parents=True, exist_ok=True)
            source.write_bytes(b"II*\0CR2")
            thumbnail.write_bytes(b"jpg")
        db = FakeDatabase([{"id": 14, "storage_base_location": str(self.root), "original_sha256": queued_checksum}])
        db.profile_photos.append({"id": 15, "storage_base_location": str(self.root), "original_sha256": queued_checksum})
        db.unprofiled_photos.append({"id": 16, "storage_base_location": str(self.root), "original_sha256": queued_checksum})
        db.profile_photos_by_id[17] = {"id": 17, "storage_base_location": str(self.root), "original_sha256": urgent_checksum}
        redis = FakeRedis()
        redis.profile_notifications.append(type("Notification", (), {"photo_id": 17, "reason": "picture_viewer"})())
        worker = self.worker(db, redis=redis)

        self.assertTrue(worker.run_once())

        baseline = self.root / "swallowtail-data" / "ab" / "cd" / f"{urgent_checksum}_baseline.pp3"
        self.assertEqual([17], db.profile_processing)
        self.assertEqual([(self.root / "swallowtail-data" / "ab" / "cd" / f"{urgent_checksum}_source.cr2", baseline)], worker.profile_runner.generated)
        self.assertEqual(1, len(db.photos))
        self.assertEqual(1, len(db.profile_photos))
        self.assertEqual(1, len(db.unprofiled_photos))
        self.assertIn("Urgent profile notification received; photo=17 reason=picture_viewer", worker.log.infos)

    def test_run_once_generates_profile_baseline_when_metadata_is_idle(self) -> None:
        checksum = "abcdef" + ("0" * 58)
        source = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_source.cr2"
        thumbnail = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_thumbnail.jpg"
        source.parent.mkdir(parents=True)
        source.write_bytes(b"II*\0CR2")
        thumbnail.write_bytes(b"jpg")
        db = FakeDatabase()
        db.profile_photos.append({"id": 9, "storage_base_location": str(self.root), "original_sha256": checksum})
        worker = self.worker(db)

        self.assertTrue(worker.run_once())

        baseline = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_baseline.pp3"
        self.assertEqual([9], db.profile_processing)
        self.assertEqual([(source, baseline)], worker.profile_runner.generated)
        self.assertEqual(9, db.profile_ready[0][0])
        self.assertEqual(str(baseline), db.profile_ready[0][2])
        self.assertEqual("RawTherapee 5.12", db.profile_ready[0][3])
        self.assertTrue(any(
            message.startswith("Generated RawTherapee baseline profile for photo=9 ")
            and "duration_seconds=" in message
            for message in worker.log.infos
        ))

    def test_run_once_generates_unprofiled_baseline_when_profile_queue_is_idle(self) -> None:
        checksum = "abcdef" + ("0" * 58)
        source = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_source.cr2"
        thumbnail = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_thumbnail.jpg"
        source.parent.mkdir(parents=True)
        source.write_bytes(b"II*\0CR2")
        thumbnail.write_bytes(b"jpg")
        db = FakeDatabase()
        db.unprofiled_photos.append({"id": 11, "storage_base_location": str(self.root), "original_sha256": checksum})
        worker = self.worker(db)

        self.assertTrue(worker.run_once())

        baseline = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_baseline.pp3"
        self.assertEqual([11], db.profile_processing)
        self.assertEqual([(source, baseline)], worker.profile_runner.generated)
        self.assertIn("Found uploaded photo without profile data; photo=11", worker.log.infos)

    def test_run_once_prefers_queued_profile_before_unprofiled_backfill(self) -> None:
        queued_checksum = "abcdef" + ("0" * 58)
        unprofiled_checksum = "123456" + ("0" * 58)
        for checksum in [queued_checksum, unprofiled_checksum]:
            source = self.root / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_source.cr2"
            thumbnail = self.root / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_thumbnail.jpg"
            source.parent.mkdir(parents=True, exist_ok=True)
            source.write_bytes(b"II*\0CR2")
            thumbnail.write_bytes(b"jpg")
        db = FakeDatabase()
        db.profile_photos.append({"id": 12, "storage_base_location": str(self.root), "original_sha256": queued_checksum})
        db.unprofiled_photos.append({"id": 13, "storage_base_location": str(self.root), "original_sha256": unprofiled_checksum})
        worker = self.worker(db)

        self.assertTrue(worker.run_once())

        queued_baseline = self.root / "swallowtail-data" / "ab" / "cd" / f"{queued_checksum}_baseline.pp3"
        self.assertEqual([12], db.profile_processing)
        self.assertEqual([(self.root / "swallowtail-data" / "ab" / "cd" / f"{queued_checksum}_source.cr2", queued_baseline)], worker.profile_runner.generated)
        self.assertEqual(1, len(db.unprofiled_photos))

    def test_run_once_logs_when_no_metadata_or_profile_records_are_ready(self) -> None:
        worker = self.worker(FakeDatabase())

        self.assertFalse(worker.run_once())

        self.assertIn("No metadata or profile records returned; worker idle", worker.log.infos)

    def test_profile_baseline_waits_for_thumbnail(self) -> None:
        checksum = "abcdef" + ("0" * 58)
        source = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_source.cr2"
        source.parent.mkdir(parents=True)
        source.write_bytes(b"II*\0CR2")
        db = FakeDatabase()
        db.profile_photos.append({"id": 10, "storage_base_location": str(self.root), "original_sha256": checksum})
        worker = self.worker(db)

        self.assertTrue(worker.run_once())

        self.assertEqual([], worker.profile_runner.generated)
        self.assertEqual(10, db.profile_deferred[0][0])
        self.assertIn("Thumbnail is not ready", db.profile_deferred[0][1])

    def test_missing_source_file_is_deferred(self) -> None:
        checksum = "abcdef" + ("0" * 58)
        db = FakeDatabase([{"id": 8, "storage_base_location": str(self.root), "original_sha256": checksum}])
        worker = self.worker(db)

        self.assertTrue(worker.run_once())

        self.assertEqual(8, db.deferred[0][0])
        self.assertIn("Source CR2 file was not found", db.deferred[0][1])

    def test_cli_loads(self) -> None:
        from swallowtail_metadata.__main__ import main
        original_argv = sys.argv
        try:
            sys.argv = ["swallowtail_metadata", "--help"]
            with redirect_stdout(StringIO()):
                with self.assertRaises(SystemExit) as raised:
                    main()
            self.assertEqual(0, raised.exception.code)
        finally:
            sys.argv = original_argv


if __name__ == "__main__":
    unittest.main()
