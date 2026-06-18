from __future__ import annotations

import shutil
import sys
import unittest
import uuid
from contextlib import redirect_stdout
from io import StringIO
from pathlib import Path

from swallowtail_storage.config import StorageConfig
from swallowtail_storage.worker import StorageWorker


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
                    "if cmd == 'refresh':",
                    "    print(json.dumps({'success': True, 'snapshot': {'mount_signature': 'abc'}}))",
                    "elif cmd == 'process-migrations':",
                    "    print(json.dumps({'success': True, 'processed': 1}))",
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
            migration_limit=5,
            log_file=str(self.log_file),
            log_level="INFO",
        )

    def test_run_once_refreshes_and_processes_migrations(self) -> None:
        worker = StorageWorker(self.config())

        self.assertTrue(worker.run_once())
        self.assertEqual("abc", worker.last_mount_signature)

    def test_status_includes_service_state(self) -> None:
        worker = StorageWorker(self.config())

        status = worker.status()

        self.assertTrue(status["success"])
        self.assertEqual("running", status["service"]["state"])
        self.assertEqual(str(self.root), status["service"]["project_root"])

    def test_health_checks_validate_storage_status_and_redis(self) -> None:
        worker = StorageWorker(self.config())

        ok, lines = worker.health_checks()

        self.assertTrue(ok)
        self.assertEqual(["OK storage status", "OK redis"], lines)

    def test_health_checks_fail_when_redis_is_unavailable(self) -> None:
        config = self.config()
        worker = StorageWorker(
            StorageConfig(
                php=config.php,
                project_root=config.project_root,
                interval_seconds=config.interval_seconds,
                migration_limit=config.migration_limit,
                log_file=config.log_file,
                log_level=config.log_level,
            )
        )
        original_php_json = worker.php_json
        worker.php_json = lambda *args: {
            **original_php_json(*args),
            "cache": {"redis_available": False},
        }

        ok, lines = worker.health_checks()

        self.assertFalse(ok)
        self.assertEqual("OK storage status", lines[0])
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
                "--migration-limit",
                "5",
                "--log-file",
                str(self.log_file),
                "--status",
            ]

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
            with redirect_stdout(output):
                self.assertEqual(0, main())
            self.assertEqual("OK storage status\nOK redis\n", output.getvalue())
        finally:
            sys.argv = original_argv


if __name__ == "__main__":
    unittest.main()
