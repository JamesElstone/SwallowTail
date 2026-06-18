from __future__ import annotations

import shutil
import sys
import unittest
import uuid
from pathlib import Path

from storage_service.config import StorageConfig
from storage_service.worker import StorageWorker


class StorageWorkerTest(unittest.TestCase):
    def setUp(self) -> None:
        temp_parent = Path(__file__).resolve().parent / ".tmp"
        temp_parent.mkdir(exist_ok=True)
        self.root = temp_parent / f"swallowtail-storage-worker-{uuid.uuid4().hex}"
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
                    "    print(json.dumps({'success': True, 'cache': {'snapshot': {'mount_signature': 'abc'}}}))",
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


if __name__ == "__main__":
    unittest.main()
