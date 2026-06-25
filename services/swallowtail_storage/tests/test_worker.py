from __future__ import annotations

import shutil
import sys
import unittest
import uuid
from contextlib import redirect_stdout
from io import StringIO
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from swallowtail_storage.config import StorageConfig
from swallowtail_storage.worker import StorageWorker


class FakeRedis:
    def __init__(self) -> None:
        self.snapshots: list[dict] = []
        self.messages: list[tuple[str, dict, int]] = []
        self.heartbeats: list[tuple[str, dict, int]] = []
        self.available = True
        self.store_ok = True
        self.push_ok = True

    def store_snapshot(self, snapshot: dict) -> bool:
        self.snapshots.append(snapshot)
        return self.store_ok

    def list_push_json(self, key: str, payload: dict, max_length: int = 0) -> bool:
        self.messages.append((key, payload, max_length))
        return self.push_ok

    def set_json(self, key: str, payload: dict, ttl_seconds: int) -> bool:
        self.heartbeats.append((key, payload, ttl_seconds))
        return self.available

    def ping(self) -> bool:
        return self.available


class StorageWorkerTest(unittest.TestCase):
    def setUp(self) -> None:
        temp_parent = Path(__file__).resolve().parent / ".tmp"
        temp_parent.mkdir(exist_ok=True)
        self.root = temp_parent / f"swallowtail_storage_worker_{uuid.uuid4().hex}"
        self.root.mkdir()
        script_dir = self.root / "tools" / "php"
        script_dir.mkdir(parents=True)
        self.log_file = self.root / "storage.log"
        (script_dir / "storageCache.php").write_text(
            "\n".join(
                [
                    "import json, sys",
                    "cmd = sys.argv[1] if len(sys.argv) > 1 else 'status'",
                    "if cmd == 'discover':",
                    "    print(json.dumps({'success': True, 'snapshot': {'mount_signature': 'abc', 'locations': [{'storage_base_location': '/storage/a', 'can_write': True}, {'storage_base_location': '/storage/b', 'can_write': False}]}}))",
                    "elif cmd == 'refresh':",
                    "    print(json.dumps({'success': True, 'snapshot': {'mount_signature': 'abc', 'locations': [{'storage_base_location': '/storage/a', 'can_write': True}, {'storage_base_location': '/storage/b', 'can_write': False}]}}))",
                    "elif cmd == 'process-migrations':",
                    "    print(json.dumps({'success': True, 'processed': 1}))",
                    "elif cmd == 'touch-service':",
                    "    print(json.dumps({'success': True, 'service': sys.argv[2] if len(sys.argv) > 2 else ''}))",
                    "elif cmd == 'status':",
                    "    redis_available = '--redis-down' not in sys.argv",
                    "    print(json.dumps({'success': True, 'cache': {'redis_available': redis_available, 'snapshot': {'mount_signature': 'abc'}}}))",
                    "else:",
                    "    print(json.dumps({'success': False}))",
                    "    raise SystemExit(1)",
                ]
            ),
            encoding="utf-8",
        )

    def tearDown(self) -> None:
        shutil.rmtree(self.root, ignore_errors=True)
        try:
            self.root.parent.rmdir()
        except OSError:
            pass

    def config(self) -> StorageConfig:
        return StorageConfig(
            php=sys.executable,
            project_root=str(self.root),
            interval_seconds=300,
            mount_poll_seconds=30,
            migration_item_limit=5,
            redis_host="127.0.0.1",
            redis_port=6379,
            redis_timeout_seconds=5,
            redis_snapshot_key="swallowtail:storage:snapshot",
            redis_snapshot_ttl_seconds=360,
            redis_storage_wake_queue="storage-wake",
            log_file=str(self.log_file),
            log_level="INFO",
        )

    def worker(self, redis: FakeRedis | None = None) -> StorageWorker:
        return StorageWorker(self.config(), redis if redis is not None else FakeRedis())

    def test_run_once_refreshes_and_processes_migrations(self) -> None:
        redis = FakeRedis()
        worker = self.worker(redis)
        worker.mount_signature = lambda: "mount-raw"

        with self.assertLogs("swallowtail_storage.worker", level="INFO") as logs:
            self.assertTrue(worker.run_once())
        self.assertEqual("mount-raw", worker.last_mount_signature)
        self.assertEqual(1, len(redis.snapshots))
        self.assertTrue(any('mount_points=["/storage/a","/storage/b"]' in line for line in logs.output))
        self.assertTrue(any('writable_mount_points=["/storage/a"]' in line for line in logs.output))
        self.assertTrue(any("Storage wake message sent queue=storage-wake" in line for line in logs.output))

    def test_startup_logs_storage_wake_sent_when_storage_is_writable(self) -> None:
        redis = FakeRedis()
        worker = self.worker(redis)
        worker.mount_signature = lambda: "mount-raw"

        with self.assertLogs("swallowtail_storage.worker", level="INFO") as logs:
            self.assertTrue(worker.refresh("startup"))

        self.assertEqual(1, len(redis.messages))
        self.assertEqual("storage-wake", redis.messages[0][0])
        self.assertTrue(any("Storage wake message sent queue=storage-wake" in line for line in logs.output))

    def test_startup_logs_storage_wake_not_sent_when_storage_is_unwritable(self) -> None:
        redis = FakeRedis()
        worker = self.worker(redis)
        worker.mount_signature = lambda: "mount-raw"

        def php_json(*args: str) -> dict:
            if args[0] == "discover":
                return {
                    "success": True,
                    "snapshot": {
                        "mount_signature": "abc",
                        "locations": [
                            {"storage_base_location": "/storage/a", "can_write": False},
                        ],
                    },
                }
            if args[0] == "process-migrations":
                return {"success": True, "processed": 1}
            return {"success": True}

        worker.php_json = php_json

        with self.assertLogs("swallowtail_storage.worker", level="INFO") as logs:
            self.assertTrue(worker.refresh("startup"))

        self.assertEqual(0, len(redis.messages))
        self.assertTrue(any('writable_mount_points=[]' in line for line in logs.output))
        self.assertTrue(any("Storage wake message not sent reason=No writable storage location is available." in line for line in logs.output))

    def test_timer_logs_storage_wake_when_writable_storage_appears(self) -> None:
        redis = FakeRedis()
        worker = self.worker(redis)
        worker.mount_signature = lambda: "mount-raw"
        snapshots = iter([
            {
                "success": True,
                "snapshot": {
                    "mount_signature": "abc",
                    "locations": [
                        {"storage_base_location": "/storage/a", "can_write": False},
                    ],
                },
            },
            {
                "success": True,
                "snapshot": {
                    "mount_signature": "abc",
                    "locations": [
                        {"storage_base_location": "/storage/a", "can_write": True},
                    ],
                },
            },
        ])

        def php_json(*args: str) -> dict:
            if args[0] == "discover":
                return next(snapshots)
            if args[0] == "process-migrations":
                return {"success": True, "processed": 1}
            return {"success": True}

        worker.php_json = php_json

        with self.assertLogs("swallowtail_storage.worker", level="INFO") as logs:
            self.assertTrue(worker.refresh("startup"))
            self.assertTrue(worker.refresh("timer"))

        self.assertEqual(1, len(redis.messages))
        self.assertEqual("storage-wake", redis.messages[0][0])
        self.assertTrue(any('writable_mount_points=[]' in line for line in logs.output))
        self.assertTrue(any('writable_mount_points=["/storage/a"]' in line for line in logs.output))
        self.assertTrue(any("Storage wake message sent queue=storage-wake" in line for line in logs.output))

    def test_mount_change_logs_storage_wake_sent(self) -> None:
        redis = FakeRedis()
        worker = self.worker(redis)
        worker.mount_signature = lambda: "mount-raw"
        worker.last_writable_mount_signature = ""

        with self.assertLogs("swallowtail_storage.worker", level="INFO") as logs:
            self.assertTrue(worker.refresh("mount-change"))

        self.assertEqual(1, len(redis.messages))
        self.assertEqual("storage-wake", redis.messages[0][0])
        self.assertTrue(any("Storage wake message sent queue=storage-wake" in line for line in logs.output))

    def test_mount_change_logs_storage_wake_failure(self) -> None:
        redis = FakeRedis()
        redis.push_ok = False
        worker = self.worker(redis)
        worker.mount_signature = lambda: "mount-raw"
        worker.last_writable_mount_signature = ""

        with self.assertLogs("swallowtail_storage.worker", level="WARNING") as logs:
            self.assertTrue(worker.refresh("mount-change"))

        self.assertTrue(any("Storage wake message failed queue=storage-wake" in line for line in logs.output))

    def test_refresh_logs_storage_cache_write_failure(self) -> None:
        redis = FakeRedis()
        redis.store_ok = False
        worker = self.worker(redis)
        worker.mount_signature = lambda: "mount-raw"

        with self.assertLogs("swallowtail_storage.worker", level="WARNING") as logs:
            self.assertFalse(worker.run_once())

        self.assertTrue(any("Storage cache Redis write failed" in line for line in logs.output))

    def test_status_includes_service_state(self) -> None:
        worker = self.worker()

        status = worker.status()

        self.assertTrue(status["success"])
        self.assertEqual("running", status["service"]["state"])
        self.assertEqual(str(self.root), status["service"]["project_root"])
        self.assertEqual(30, status["service"]["mount_poll_seconds"])

    def test_run_forever_checks_mounts_between_full_refreshes(self) -> None:
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

            def wait(self, seconds: float) -> bool:
                self.waits.append(seconds)
                self.clock.now += seconds
                return len(self.waits) >= 3

        clock = FakeClock()
        shutdown = FakeShutdown(clock)
        worker = self.worker()
        worker.shutdown_requested = shutdown
        reasons: list[str] = []
        signatures = iter(["sig1", "sig2"])

        def refresh(reason: str) -> bool:
            reasons.append(reason)
            if reason == "startup":
                worker.last_mount_signature = "sig1"
            if reason == "mount-change":
                worker.last_mount_signature = "sig2"
            return True

        worker.refresh = refresh
        worker.mount_signature = lambda: next(signatures)

        with patch("swallowtail_storage.worker.time.monotonic", clock.monotonic):
            worker.run_forever()

        self.assertEqual(["startup", "mount-change"], reasons)
        self.assertEqual([30, 30, 30], shutdown.waits)

    def test_mount_signature_ignores_df_free_space_changes(self) -> None:
        worker = self.worker()
        df_outputs = iter([
            "Filesystem 1024-blocks Used Available Capacity Mounted on\n"
            "/dev/ufs/storage3 8122124 100 8122024 0% /storage/3\n",
            "Filesystem 1024-blocks Used Available Capacity Mounted on\n"
            "/dev/ufs/storage3 8122124 200 8121924 0% /storage/3\n",
        ])

        def run(command, **_kwargs):
            if command == ["/bin/df", "-Pk"]:
                return SimpleNamespace(stdout=next(df_outputs))
            if command == ["/sbin/mount", "-p"]:
                return SimpleNamespace(stdout="/dev/ufs/storage3 /storage/3 ufs rw 0 0\n")
            return SimpleNamespace(stdout="")

        with patch("swallowtail_storage.worker.subprocess.run", run):
            first = worker.mount_signature()
            second = worker.mount_signature()

        self.assertEqual(first, second)

    def test_mount_signature_changes_when_mount_point_changes(self) -> None:
        worker = self.worker()
        df_outputs = iter([
            "Filesystem 1024-blocks Used Available Capacity Mounted on\n"
            "/dev/ufs/storage2 8122124 100 8122024 0% /storage/2\n",
            "Filesystem 1024-blocks Used Available Capacity Mounted on\n"
            "/dev/ufs/storage2 8122124 100 8122024 0% /storage/2\n"
            "/dev/ufs/storage3 8122124 100 8122024 0% /storage/3\n",
        ])
        mount_outputs = iter([
            "/dev/ufs/storage2 /storage/2 ufs rw 0 0\n",
            "/dev/ufs/storage2 /storage/2 ufs rw 0 0\n"
            "/dev/ufs/storage3 /storage/3 ufs rw 0 0\n",
        ])

        def run(command, **_kwargs):
            if command == ["/bin/df", "-Pk"]:
                return SimpleNamespace(stdout=next(df_outputs))
            if command == ["/sbin/mount", "-p"]:
                return SimpleNamespace(stdout=next(mount_outputs))
            return SimpleNamespace(stdout="")

        with patch("swallowtail_storage.worker.subprocess.run", run):
            first = worker.mount_signature()
            second = worker.mount_signature()

        self.assertNotEqual(first, second)

    def test_health_checks_validate_storage_status_and_redis(self) -> None:
        worker = self.worker()

        ok, lines = worker.health_checks()

        self.assertTrue(ok)
        self.assertEqual(["OK storage discovery", "OK redis"], lines)

    def test_health_checks_fail_when_redis_is_unavailable(self) -> None:
        config = self.config()
        redis = FakeRedis()
        redis.available = False
        worker = StorageWorker(config, redis)

        ok, lines = worker.health_checks()

        self.assertFalse(ok)
        self.assertEqual("OK storage discovery", lines[0])
        self.assertEqual("FAIL redis: Redis ping failed", lines[1])

    def test_cli_accepts_rc_conf_style_arguments(self) -> None:
        from swallowtail_storage.__main__ import main
        original_argv = sys.argv
        try:
            sys.argv = [
                "swallowtail_storage",
                "--project-root",
                str(self.root),
                "--php",
                sys.executable,
                "--interval-seconds",
                "300",
                "--migration-item-limit",
                "5",
                "--log-file",
                str(self.log_file),
                "--status",
            ]

            with patch("swallowtail_storage.worker.RedisClient", return_value=FakeRedis()):
                with redirect_stdout(StringIO()):
                    self.assertEqual(0, main())
        finally:
            sys.argv = original_argv

    def test_cli_health_prints_internal_checks(self) -> None:
        from swallowtail_storage.__main__ import main
        original_argv = sys.argv
        try:
            sys.argv = [
                "swallowtail_storage",
                "--project-root",
                str(self.root),
                "--php",
                sys.executable,
                "--log-file",
                str(self.log_file),
                "--health",
            ]

            output = StringIO()
            with patch("swallowtail_storage.worker.RedisClient", return_value=FakeRedis()):
                with redirect_stdout(output):
                    self.assertEqual(0, main())
            self.assertEqual("OK storage discovery\nOK redis\n", output.getvalue())
        finally:
            sys.argv = original_argv


if __name__ == "__main__":
    unittest.main()
