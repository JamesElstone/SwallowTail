from __future__ import annotations

import shutil
import sys
import unittest
import uuid
from contextlib import redirect_stdout
from dataclasses import replace
from io import StringIO
from pathlib import Path

from swallowtail_metadata.config import default_config
from swallowtail_metadata.exiftool import parse_metadata
from swallowtail_metadata.worker import MetadataWorker


class FakeDatabase:
    def __init__(self, photos=None):
        self.photos = list(photos or [])
        self.ready = []
        self.deferred = []
        self.count_payload = {"ready": 0, "deferred": 0, "failed": 0}

    def next_photo(self):
        return self.photos.pop(0) if self.photos else None

    def upsert_ready(self, photo_id, fields, raw):
        self.ready.append((photo_id, fields, raw))

    def defer_or_fail(self, photo_id, message, max_attempts, retry_delay_seconds):
        self.deferred.append((photo_id, message, max_attempts, retry_delay_seconds))
        return "deferred"

    def counts(self):
        return self.count_payload

    def ping(self):
        return None


class FakeExifTool:
    def __init__(self):
        self.paths = []

    def extract(self, path, server_timezone):
        self.paths.append((path, server_timezone))
        return type("Result", (), {
            "fields": {"camera_make": "Canon", "captured_timezone_source": "canon_makernote"},
            "raw": {"EXIF:Make": "Canon"},
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


class MetadataParserTest(unittest.TestCase):
    def test_canon_time_info_maps_london_and_utc_capture_time(self) -> None:
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
            "Europe/London",
        )

        self.assertEqual("2024-02-01 20:39:49", fields["captured_at_local"])
        self.assertEqual("2024-02-01 19:39:49", fields["captured_at_utc"])
        self.assertEqual(60, fields["captured_timezone_offset_minutes"])
        self.assertEqual("canon_makernote", fields["captured_timezone_source"])
        self.assertEqual(20, fields["camera_timezone_city_code"])
        self.assertEqual("London", fields["camera_timezone_city_label"])
        self.assertEqual(60, fields["camera_daylight_savings_minutes"])
        self.assertEqual("Canon", fields["camera_make"])
        self.assertEqual("Canon EOS 760D", fields["camera_model"])
        self.assertEqual(1000, fields["iso"])

    def test_server_timezone_is_fallback_when_canon_timezone_is_missing(self) -> None:
        fields = parse_metadata({"EXIF:DateTimeOriginal": "2024:02:01 20:39:49"}, "Europe/London")

        self.assertEqual("server_default", fields["captured_timezone_source"])
        self.assertEqual("2024-02-01 20:39:49", fields["captured_at_utc"])
        self.assertEqual(0, fields["captured_timezone_offset_minutes"])


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
        worker.redis = FakeRedis()
        worker.shutdown_requested = None
        worker.idle_delay_seconds = config.worker.poll_min_seconds
        worker.log = type("Log", (), {"info": lambda *a, **k: None, "warning": lambda *a, **k: None, "debug": lambda *a, **k: None})()
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
        self.assertEqual([(str(source), "Europe/London")], exiftool.paths)
        self.assertEqual(["swallowtail_metadata", "swallowtail_metadata"], worker.redis.touched)

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
