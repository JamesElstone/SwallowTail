from __future__ import annotations

import hashlib
import shutil
import sys
import threading
import unittest
import uuid
from contextlib import redirect_stdout
from dataclasses import replace
from io import StringIO
from pathlib import Path
from unittest.mock import patch

from swallowtail_metadata.config import DaylightSavingConfig, default_config
from swallowtail_metadata.db import MetadataDatabase
from swallowtail_metadata.exiftool import extract_properties, parse_metadata
from swallowtail_metadata.profile import RawTheapeeProfileScanner, RawTherapeeBaselineRunner, parse_pp3_properties
from swallowtail_metadata.redis_heartbeat import AssetNotification
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
        self.asset_jobs = []
        self.image_assets = []
        self.count_payload = {"ready": 0, "deferred": 0, "failed": 0}

    def next_photo(self):
        return self.photos.pop(0) if self.photos else None

    def next_profile_photo(self):
        return self.profile_photos.pop(0) if self.profile_photos else None

    def profile_photo_by_id(self, photo_id):
        return self.profile_photos_by_id.get(photo_id)

    def next_unprofiled_photo(self):
        return self.unprofiled_photos.pop(0) if self.unprofiled_photos else None

    def next_unrecorded_image_asset_job(self):
        return self.asset_jobs.pop(0) if self.asset_jobs else None

    def upsert_ready(self, photo_id, fields, raw):
        self.ready.append((photo_id, fields, raw))

    def defer_or_fail(self, photo_id, message, max_attempts, retry_delay_seconds):
        self.deferred.append((photo_id, message, max_attempts, retry_delay_seconds))
        return "deferred"

    def mark_profile_processing(self, photo_id):
        self.profile_processing.append(photo_id)

    def replace_profile_data(self, photo_id, rows, baseline_path, rawtherapee_version):
        self.profile_ready.append((photo_id, rows, baseline_path, rawtherapee_version))
        sections = {row["type"] for row in rows}
        largest_value_length = max((len(str(row.get("value") or "")) for row in rows), default=0)
        return {
            "profile_rows_written": len(rows),
            "profile_sections": len(sections),
            "profile_insert_batches": len(sections),
            "profile_largest_value_length": largest_value_length,
            "profile_max_value_chunks": max((max(1, (len(str(row.get("value") or "")) + 99) // 100) for row in rows), default=0),
        }

    def defer_profile(self, photo_id, message, max_attempts, retry_delay_seconds):
        self.profile_deferred.append((photo_id, message, max_attempts, retry_delay_seconds))
        return "queued"

    def upsert_image_asset(
        self,
        photo_id,
        image_type,
        sha256,
        bytes_size,
        modified_at,
        width,
        height,
        profile_signature,
        conversion_job_id,
    ):
        self.image_assets.append({
            "photo_id": photo_id,
            "image_type": image_type,
            "sha256": sha256,
            "bytes": bytes_size,
            "modified_at": modified_at,
            "width": width,
            "height": height,
            "profile_signature": profile_signature,
            "conversion_job_id": conversion_job_id,
        })

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
        self.asset_notifications = []
        self.profile_notification_available = False

    def touch_service(self, service_key):
        self.touched.append(service_key)
        return True

    def pop_profile_notification(self):
        return self.profile_notifications.pop(0) if self.profile_notifications else None

    def pop_asset_notification(self):
        return self.asset_notifications.pop(0) if self.asset_notifications else None

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

    def version(self):
        return "RawTherapee 5.12"


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


def display_jpeg(width: int, height: int, payload: bytes = b"asset") -> bytes:
    return (
        b"\xff\xd8"
        + b"\xff\xc0\x00\x11\x08"
        + height.to_bytes(2, "big")
        + width.to_bytes(2, "big")
        + b"\x03\x01\x11\x00\x02\x11\x00\x03\x11\x00"
        + payload
        + b"\xff\xd9"
    )


class FakeCursor:
    def __init__(self, connection):
        self.connection = connection
        self.description = []

    def setinputsizes(self, input_sizes):
        self.connection.input_sizes.append(input_sizes)

    def execute(self, sql, params=()):
        self.connection.executed.append((sql, params))
        return self

    def fetchone(self):
        return None

    def fetchall(self):
        return []


class FakeConnection:
    def __init__(self):
        self.executed = []
        self.input_sizes = []
        self.commits = 0
        self.rollbacks = 0

    def cursor(self):
        return FakeCursor(self)

    def commit(self):
        self.commits += 1

    def rollback(self):
        self.rollbacks += 1


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

    def test_pp3_parser_keeps_real_baseline_properties_as_rows(self) -> None:
        pp3_path = Path(__file__).resolve().parents[3] / "examples" / "TEST.CR2.pp3"

        properties = parse_pp3_properties(pp3_path.read_text(encoding="utf-8"))

        exposure_curve = next(
            row for row in properties
            if row["type"] == "Exposure" and row["key"] == "Curve"
        )
        self.assertEqual("string", exposure_curve["value_type"])
        self.assertTrue(str(exposure_curve["value"]).startswith("4;0;0;0.050000000000000003;"))
        self.assertIn({"type": "Version", "key": "AppVersion", "value": "5.9", "value_type": "float"}, properties)
        self.assertGreater(len(properties), 100)

    def test_pp3_parser_preserves_long_rawtherapee_section_names(self) -> None:
        properties = parse_pp3_properties(
            "[Common Properties for Transformations]\n"
            "Method=log\n"
        )

        self.assertEqual("Common Properties for Transformations", properties[0]["type"])

    def test_rawtheapee_profile_scanner_recurses_and_labels_profiles(self) -> None:
        root = Path(__file__).resolve().parent / ".tmp" / f"rawtheapee_profiles_{uuid.uuid4().hex}"
        profile = root / "Non-raw" / "Brighten.pp3"
        profile.parent.mkdir(parents=True, exist_ok=True)
        profile.write_text("[Exposure]\nBrightness=12\n", encoding="utf-8")
        try:
            rows = RawTheapeeProfileScanner(str(root)).scan()

            self.assertEqual(1, len(rows))
            self.assertEqual(str(profile), rows[0].profile_path)
            self.assertEqual("Non-raw/Brighten.pp3", rows[0].relative_path)
            self.assertEqual("Non-Raw :: Brighten", rows[0].display_label)
            self.assertEqual("[Exposure]\nBrightness=12\n", rows[0].profile_content)
        finally:
            shutil.rmtree(root, ignore_errors=True)


class MetadataDatabaseProfileDataTest(unittest.TestCase):
    class FakePyodbc:
        SQL_INTEGER = 4
        SQL_VARCHAR = 12

    def database(self) -> tuple[MetadataDatabase, FakeConnection]:
        connection = FakeConnection()
        database = object.__new__(MetadataDatabase)
        database.driver = "pymysql"
        database.pyodbc = None
        database.paramstyle = "%s"
        database.connection = connection
        return database, connection

    def odbc_database(self) -> tuple[MetadataDatabase, FakeConnection]:
        database, connection = self.database()
        database.driver = "odbc"
        database.pyodbc = self.FakePyodbc
        database.paramstyle = "?"
        return database, connection

    def section_insert_statements(self, connection: FakeConnection) -> list[tuple[str, tuple]]:
        return [
            (sql, params) for sql, params in connection.executed
            if "INSERT INTO photo_profile_data" in sql
            and len(params) >= 5
            and params[1] != "swallowtail"
        ]

    def test_replace_profile_data_inserts_one_multi_row_batch_per_section(self) -> None:
        database, connection = self.database()
        properties = parse_pp3_properties(
            "[Version]\nAppVersion=5.9\nVersion=349\n\n"
            "[Exposure]\nBlack=63\nCurve=4;0;0;0.050000000000000003;0.035148935901110998;\n"
            "[Common Properties for Transformations]\nMethod=log\n"
        )

        stats = database.replace_profile_data(42, properties, "/photos/abc_source.pp3", "RawTherapee 5.12")

        section_inserts = self.section_insert_statements(connection)
        self.assertEqual(3, len(section_inserts))
        self.assertIn("revision", section_inserts[0][0])
        self.assertEqual(10, len(section_inserts[0][1]))
        self.assertEqual([42, "Version", "AppVersion", "5.9", "float"], list(section_inserts[0][1][0:5]))
        self.assertEqual([42, "Version", "Version", "349", "int"], list(section_inserts[0][1][5:10]))
        self.assertEqual("Exposure", section_inserts[1][1][1])
        self.assertIn("Curve", section_inserts[1][1])
        self.assertEqual("Common Properties for Transformations", section_inserts[2][1][1])
        self.assertEqual(5, stats["profile_rows_written"])
        self.assertEqual(3, stats["profile_sections"])
        self.assertEqual(3, stats["profile_insert_batches"])
        self.assertGreater(stats["profile_largest_value_length"], 40)
        self.assertEqual(1, connection.commits)
        self.assertTrue(connection.executed[0][0].strip().startswith("DELETE FROM photo_profile_data"))
        self.assertIn("revision = 0", connection.executed[0][0])

    def test_replace_profile_data_splits_large_sections_by_batch_limit(self) -> None:
        database, connection = self.database()
        database.PROFILE_INSERT_BATCH_ROWS = 2
        properties = parse_pp3_properties(
            "[Exposure]\nBlack=63\nBrightness=0\nContrast=26\n"
        )

        stats = database.replace_profile_data(7, properties, "/photos/abc_source.pp3", "RawTherapee 5.12")

        section_inserts = self.section_insert_statements(connection)
        self.assertEqual(2, len(section_inserts))
        self.assertEqual(10, len(section_inserts[0][1]))
        self.assertEqual(5, len(section_inserts[1][1]))
        self.assertEqual(3, stats["profile_rows_written"])
        self.assertEqual(1, stats["profile_sections"])
        self.assertEqual(2, stats["profile_insert_batches"])

    def test_odbc_profile_insert_binds_values_as_varchar(self) -> None:
        database, connection = self.odbc_database()
        curve = "x" * 250
        properties = parse_pp3_properties(
            f"[Exposure]\nBlack=63\nCurve={curve}\n"
        )

        stats = database.replace_profile_data(42, properties, "/photos/abc_source.pp3", "RawTherapee 5.12")

        section_inserts = self.section_insert_statements(connection)
        self.assertEqual(1, len(section_inserts))
        self.assertIn("CONCAT(?, ?, ?)", section_inserts[0][0])
        self.assertEqual([2, 100, 100, 50], [
            len(str(param)) for param in section_inserts[0][1]
            if isinstance(param, str) and set(param) == {"x"} or param == "63"
        ])
        section_inputs = [
            sizes for sizes in connection.input_sizes
            if len(sizes) == 12
        ]
        self.assertEqual(1, len(section_inputs))
        self.assertEqual((self.FakePyodbc.SQL_VARCHAR, 2, 0), section_inputs[0][3])
        self.assertEqual((self.FakePyodbc.SQL_VARCHAR, 100, 0), section_inputs[0][8])
        self.assertEqual((self.FakePyodbc.SQL_VARCHAR, 100, 0), section_inputs[0][9])
        self.assertEqual((self.FakePyodbc.SQL_VARCHAR, 50, 0), section_inputs[0][10])
        self.assertEqual(3, stats["profile_max_value_chunks"])

    def test_read_fetch_reconnects_once_after_odbc_connection_loss(self) -> None:
        class FakeOdbcError(Exception):
            pass

        class FakeCursor:
            description = [("status",), ("count",)]

            def __init__(self, connection) -> None:
                self.connection = connection
                self.closed = False

            def execute(self, _sql, _params) -> None:
                if self.connection.fail_execute:
                    raise FakeOdbcError("08S01", "Server has gone away")

            def fetchall(self):
                return [("ready", self.connection.count)]

            def close(self) -> None:
                self.closed = True

        class FakeConnection:
            def __init__(self, count: int, fail_execute: bool = False) -> None:
                self.count = count
                self.fail_execute = fail_execute
                self.closed = False

            def cursor(self):
                return FakeCursor(self)

            def rollback(self) -> None:
                return None

            def close(self) -> None:
                self.closed = True

        failed_connection = FakeConnection(0, fail_execute=True)
        recovered_connection = FakeConnection(3)

        database = object.__new__(MetadataDatabase)
        database.driver = "odbc"
        database.pyodbc = self.FakePyodbc
        database.paramstyle = "?"
        database.connection = failed_connection
        database._connect = lambda: recovered_connection

        self.assertEqual(3, database.counts()["ready"])
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
        database = object.__new__(MetadataDatabase)
        database.driver = "odbc"
        database.pyodbc = self.FakePyodbc
        database.paramstyle = "?"
        database.connection = connection

        with self.assertRaises(FakeOdbcError):
            database._execute("UPDATE photo_metadata SET status = %s", ("failed",))

        self.assertEqual(1, connection.execute_count)
        self.assertTrue(connection.closed)

    def test_database_uses_thread_local_connections(self) -> None:
        class FakeConnection:
            def __init__(self, name: str) -> None:
                self.name = name

        database = object.__new__(MetadataDatabase)
        database.driver = "odbc"
        database.pyodbc = self.FakePyodbc
        database.paramstyle = "?"
        created: list[FakeConnection] = []

        def connect() -> FakeConnection:
            connection = FakeConnection(str(len(created)))
            created.append(connection)
            return connection

        database._connect = connect

        main_connection = database.connection
        worker_connections: list[FakeConnection] = []
        thread = threading.Thread(target=lambda: worker_connections.append(database.connection))
        thread.start()
        thread.join()

        self.assertIs(main_connection, database.connection)
        self.assertIsNot(main_connection, worker_connections[0])
        self.assertEqual(2, len(created))

    def test_unrecorded_asset_backfill_prioritises_gallery_assets(self) -> None:
        database, connection = self.database()
        database._table_exists = lambda _table_name: True

        database.next_unrecorded_image_asset_job()

        sql = next(
            sql for sql, _params in connection.executed
            if "FROM photo_conversion_jobs job" in sql
        )
        self.assertIn("WHEN 'thumbnail' THEN 0", sql)
        self.assertIn("WHEN 'preview' THEN 1", sql)
        self.assertIn("WHEN 'final' THEN 2", sql)
        self.assertIn("WHEN 'original' THEN 3", sql)
        self.assertIn("WHEN 'embedded' THEN 4", sql)
        self.assertIn("WHEN 'rawtheapee_sample' THEN 5", sql)
        self.assertLess(sql.index("WHEN 'thumbnail' THEN 0"), sql.index("job.completed_at DESC"))

    def test_unrecorded_asset_backfill_matches_rawtheapee_signature_variants(self) -> None:
        database, connection = self.database()
        database._table_exists = lambda _table_name: True

        database.next_unrecorded_image_asset_job()

        sql = next(
            sql for sql, _params in connection.executed
            if "FROM photo_conversion_jobs job" in sql
        )
        self.assertIn("asset.asset_variant_key = job.profile_signature", sql)
        self.assertIn("job.image_type <> 'rawtheapee_sample'", sql)

    def test_upsert_image_asset_uses_rawtheapee_profile_signature_as_variant_key(self) -> None:
        database, connection = self.database()
        database._table_exists = lambda _table_name: True

        database.upsert_image_asset(
            42,
            "rawtheapee_sample",
            "a" * 64,
            123,
            456,
            10,
            20,
            "b" * 64,
            77,
        )
        database.upsert_image_asset(
            42,
            "preview",
            "c" * 64,
            123,
            456,
            10,
            20,
            "d" * 64,
            78,
        )

        raw_params = connection.executed[0][1]
        preview_params = connection.executed[1][1]
        self.assertIn("asset_variant_key", connection.executed[0][0])
        self.assertEqual("b" * 64, raw_params[-2])
        self.assertEqual("", preview_params[-2])

    def test_upsert_image_asset_skips_unsigned_rawtheapee_sample(self) -> None:
        database, connection = self.database()
        database._table_exists = lambda _table_name: True

        database.upsert_image_asset(
            42,
            "rawtheapee_sample",
            "a" * 64,
            123,
            456,
            10,
            20,
            "",
            77,
        )

        self.assertEqual([], connection.executed)


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

    def test_generate_uses_contained_runtime_environment_next_to_source_profile(self) -> None:
        root = Path(__file__).resolve().parent / ".tmp" / f"rt_{uuid.uuid4().hex[0:8]}"
        source = root / "swallowtail-data" / "ab" / "cd" / ("abcdef" + ("0" * 58) + "_source.cr2")
        baseline = source.with_name("abcdef" + ("0" * 58) + "_source.pp3")
        source.parent.mkdir(parents=True, exist_ok=True)
        source.write_bytes(b"II*\0CR2")
        captured_env = {}
        captured_command = {}

        def fake_run(command, **kwargs):
            if "-O" in command:
                captured_command["command"] = command
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

            self.assertIn("-q", captured_command["command"])
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
            source.parent.mkdir(parents=True, exist_ok=True)
            source.write_bytes(b"II*\0CR2")
        db = FakeDatabase([{"id": 14, "storage_base_location": str(self.root), "original_sha256": queued_checksum}])
        db.profile_photos.append({"id": 15, "storage_base_location": str(self.root), "original_sha256": queued_checksum})
        db.unprofiled_photos.append({"id": 16, "storage_base_location": str(self.root), "original_sha256": queued_checksum})
        db.profile_photos_by_id[17] = {"id": 17, "storage_base_location": str(self.root), "original_sha256": urgent_checksum}
        redis = FakeRedis()
        redis.profile_notifications.append(type("Notification", (), {"photo_id": 17, "reason": "picture_viewer"})())
        worker = self.worker(db, redis=redis)

        self.assertTrue(worker.run_once())

        baseline = self.root / "swallowtail-data" / "ab" / "cd" / f"{urgent_checksum}_source.pp3"
        self.assertEqual([17], db.profile_processing)
        self.assertEqual([(self.root / "swallowtail-data" / "ab" / "cd" / f"{urgent_checksum}_source.cr2", baseline)], worker.profile_runner.generated)
        self.assertEqual(1, len(db.photos))
        self.assertEqual(1, len(db.profile_photos))
        self.assertEqual(1, len(db.unprofiled_photos))
        self.assertIn("Urgent profile notification received; photo=17 reason=picture_viewer", worker.log.infos)

    def test_run_once_processes_asset_notification_before_profile_backfill(self) -> None:
        output = self.root / "final.jpg"
        output.write_bytes(display_jpeg(320, 240, b"final-asset"))
        db = FakeDatabase()
        db.profile_photos.append({"id": 19, "storage_base_location": str(self.root), "original_sha256": "abcdef" + ("0" * 58)})
        redis = FakeRedis()
        redis.asset_notifications.append(AssetNotification(
            job_id=91,
            photo_id=42,
            image_type="final",
            output_path=str(output),
            profile_signature="f" * 64,
            reason="conversion_completed",
        ))
        worker = self.worker(db, redis=redis)

        self.assertTrue(worker.run_once())

        self.assertEqual([], db.profile_processing)
        self.assertEqual(1, len(db.image_assets))
        asset = db.image_assets[0]
        self.assertEqual(42, asset["photo_id"])
        self.assertEqual("final", asset["image_type"])
        self.assertEqual(hashlib.sha256(output.read_bytes()).hexdigest(), asset["sha256"])
        self.assertEqual(output.stat().st_size, asset["bytes"])
        self.assertEqual(int(output.stat().st_mtime), asset["modified_at"])
        self.assertEqual(320, asset["width"])
        self.assertEqual(240, asset["height"])
        self.assertEqual("f" * 64, asset["profile_signature"])
        self.assertEqual(91, asset["conversion_job_id"])
        self.assertIn(
            f"Received image asset notification photo=42 image_type=final job=91 reason=conversion_completed path={output}",
            worker.log.infos,
        )

    def test_run_once_backfills_unrecorded_asset_when_idle(self) -> None:
        output = self.root / "preview.jpg"
        output.write_bytes(display_jpeg(160, 90, b"preview-asset"))
        db = FakeDatabase()
        db.asset_jobs.append({
            "job_id": 92,
            "photo_id": 43,
            "image_type": "preview",
            "output_path": str(output),
            "profile_signature": "e" * 64,
        })
        worker = self.worker(db)

        self.assertTrue(worker.run_once())

        self.assertEqual(1, len(db.image_assets))
        self.assertEqual("preview", db.image_assets[0]["image_type"])
        self.assertEqual("e" * 64, db.image_assets[0]["profile_signature"])

    def test_run_once_generates_source_profile_when_metadata_is_idle(self) -> None:
        checksum = "abcdef" + ("0" * 58)
        source = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_source.cr2"
        source.parent.mkdir(parents=True)
        source.write_bytes(b"II*\0CR2")
        db = FakeDatabase()
        db.profile_photos.append({"id": 9, "storage_base_location": str(self.root), "original_sha256": checksum})
        worker = self.worker(db)

        self.assertTrue(worker.run_once())

        baseline = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_source.pp3"
        self.assertEqual([9], db.profile_processing)
        self.assertEqual([(source, baseline)], worker.profile_runner.generated)
        self.assertEqual(9, db.profile_ready[0][0])
        self.assertEqual(str(baseline), db.profile_ready[0][2])
        self.assertEqual("RawTherapee 5.12", db.profile_ready[0][3])
        self.assertTrue(any(
            message.startswith("Stored RawTherapee source profile for photo=9 ")
            and "source=generated" in message
            and "duration_seconds=" in message
            for message in worker.log.infos
        ))

    def test_run_once_queues_profiled_derivatives_after_source_profile_is_stored(self) -> None:
        checksum = "abcdef" + ("0" * 58)
        source = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_source.cr2"
        source.parent.mkdir(parents=True)
        source.write_bytes(b"II*\0CR2")
        script = self.root / "tools" / "php" / "dataIntegrityCheck.php"
        script.parent.mkdir(parents=True)
        script.write_text("<?php\n", encoding="utf-8")
        db = FakeDatabase()
        db.profile_photos.append({"id": 21, "storage_base_location": str(self.root), "original_sha256": checksum})
        worker = self.worker(db)

        with patch("swallowtail_metadata.worker.subprocess.run") as run:
            run.return_value = type("Result", (), {
                "returncode": 0,
                "stdout": '{"success":true,"queued_preview":1,"queued_final":1,"active_jobs":0,"already_fresh":0,"skipped":0}',
                "stderr": "",
            })()

            self.assertTrue(worker.run_once())

        run.assert_called_once_with(
            [
                worker.config.php_binary,
                str(script),
                "--queue-profiled-derivatives",
                "--photo-id=21",
                "--json",
            ],
            check=False,
            capture_output=True,
            text=True,
            timeout=120,
        )
        self.assertEqual(21, db.profile_ready[0][0])
        self.assertIn(
            "Profiled derivative queueing completed for photo=21 queued=2 active=0 fresh=0 skipped=0",
            worker.log.infos,
        )

    def test_run_once_queues_profiled_derivative_batch_when_idle(self) -> None:
        script = self.root / "tools" / "php" / "dataIntegrityCheck.php"
        script.parent.mkdir(parents=True)
        script.write_text("<?php\n", encoding="utf-8")
        worker = self.worker(FakeDatabase())

        with patch("swallowtail_metadata.worker.subprocess.run") as run:
            run.return_value = type("Result", (), {
                "returncode": 0,
                "stdout": '{"success":true,"scanned":150,"queued_preview":2,"queued_final":2,"active_jobs":0,"already_fresh":0,"skipped":0,"complete_pass":false}',
                "stderr": "",
            })()

            self.assertTrue(worker.run_once())

        run.assert_called_once_with(
            [
                worker.config.php_binary,
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
        self.assertIn(
            "Profiled derivative batch queueing completed; scanned=150 queued=4 active=0 fresh=0 skipped=0 complete=False",
            worker.log.infos,
        )

    def test_run_once_uses_existing_source_profile_without_regenerating(self) -> None:
        checksum = "abcdef" + ("0" * 58)
        source = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_source.cr2"
        baseline = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_source.pp3"
        source.parent.mkdir(parents=True)
        source.write_bytes(b"II*\0CR2")
        baseline.write_text("[Version]\nAppVersion=5.12\n\n[Exposure]\nBlack=63\n", encoding="utf-8")
        db = FakeDatabase()
        db.profile_photos.append({"id": 19, "storage_base_location": str(self.root), "original_sha256": checksum})
        worker = self.worker(db)

        self.assertTrue(worker.run_once())

        self.assertEqual([], worker.profile_runner.generated)
        self.assertEqual(19, db.profile_ready[0][0])
        self.assertEqual(str(baseline), db.profile_ready[0][2])
        self.assertEqual("RawTherapee 5.12", db.profile_ready[0][3])
        self.assertEqual(
            {"type": "Exposure", "key": "Black", "value": "63", "value_type": "int"},
            db.profile_ready[0][1][1],
        )
        self.assertTrue(any(
            message.startswith("Stored RawTherapee source profile for photo=19 ")
            and "source=existing" in message
            for message in worker.log.infos
        ))

    def test_run_once_generates_unprofiled_source_profile_when_profile_queue_is_idle(self) -> None:
        checksum = "abcdef" + ("0" * 58)
        source = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_source.cr2"
        source.parent.mkdir(parents=True)
        source.write_bytes(b"II*\0CR2")
        db = FakeDatabase()
        db.unprofiled_photos.append({"id": 11, "storage_base_location": str(self.root), "original_sha256": checksum})
        worker = self.worker(db)

        self.assertTrue(worker.run_once())

        baseline = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_source.pp3"
        self.assertEqual([11], db.profile_processing)
        self.assertEqual([(source, baseline)], worker.profile_runner.generated)
        self.assertIn("Found uploaded photo without profile data; photo=11", worker.log.infos)

    def test_run_once_prefers_queued_profile_before_unprofiled_backfill(self) -> None:
        queued_checksum = "abcdef" + ("0" * 58)
        unprofiled_checksum = "123456" + ("0" * 58)
        for checksum in [queued_checksum, unprofiled_checksum]:
            source = self.root / "swallowtail-data" / checksum[0:2] / checksum[2:4] / f"{checksum}_source.cr2"
            source.parent.mkdir(parents=True, exist_ok=True)
            source.write_bytes(b"II*\0CR2")
        db = FakeDatabase()
        db.profile_photos.append({"id": 12, "storage_base_location": str(self.root), "original_sha256": queued_checksum})
        db.unprofiled_photos.append({"id": 13, "storage_base_location": str(self.root), "original_sha256": unprofiled_checksum})
        worker = self.worker(db)

        self.assertTrue(worker.run_once())

        queued_baseline = self.root / "swallowtail-data" / "ab" / "cd" / f"{queued_checksum}_source.pp3"
        self.assertEqual([12], db.profile_processing)
        self.assertEqual([(self.root / "swallowtail-data" / "ab" / "cd" / f"{queued_checksum}_source.cr2", queued_baseline)], worker.profile_runner.generated)
        self.assertEqual(1, len(db.unprofiled_photos))

    def test_run_once_logs_when_no_metadata_or_profile_records_are_ready(self) -> None:
        worker = self.worker(FakeDatabase())

        self.assertFalse(worker.run_once())

        self.assertIn("No metadata, profile, or asset records returned; worker idle", worker.log.infos)

    def test_source_profile_generation_does_not_wait_for_preview(self) -> None:
        checksum = "abcdef" + ("0" * 58)
        source = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_source.cr2"
        source.parent.mkdir(parents=True)
        source.write_bytes(b"II*\0CR2")
        db = FakeDatabase()
        db.profile_photos.append({"id": 10, "storage_base_location": str(self.root), "original_sha256": checksum})
        worker = self.worker(db)

        self.assertTrue(worker.run_once())

        source_profile = self.root / "swallowtail-data" / "ab" / "cd" / f"{checksum}_source.pp3"
        self.assertEqual([(source, source_profile)], worker.profile_runner.generated)
        self.assertEqual([], db.profile_deferred)

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
