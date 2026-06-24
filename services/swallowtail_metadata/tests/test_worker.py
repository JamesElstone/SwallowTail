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
        self.profile_deferred = []
        self.profile_processing = []
        self.profile_ready = []
        self.count_payload = {"ready": 0, "deferred": 0, "failed": 0}

    def next_photo(self):
        return self.photos.pop(0) if self.photos else None

    def next_profile_photo(self):
        return self.profile_photos.pop(0) if self.profile_photos else None

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
    def __init__(self):
        self.paths = []

    def extract(self, path, metadata):
        self.paths.append((path, metadata.server_timezone))
        return type("Result", (), {
            "fields": {"camera_make": "Canon"},
            "properties": [{"type": "exififd", "key": "Make", "value": "Canon", "value_type": "string"}],
        })()

    def health_check(self):
        return None


class FakeRedis:
    def __init__(self):
        self.touched = []

    def touch_service(self, service_key):
        self.touched.append(service_key)
        return True

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

    def worker(self, db: FakeDatabase, exiftool: FakeExifTool | None = None) -> MetadataWorker:
        config = replace(default_config(), project_root=str(self.root))
        worker = object.__new__(MetadataWorker)
        worker.config = config
        worker.db = db
        worker.exiftool = exiftool or FakeExifTool()
        worker.profile_runner = FakeProfileRunner()
        worker.redis = FakeRedis()
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
