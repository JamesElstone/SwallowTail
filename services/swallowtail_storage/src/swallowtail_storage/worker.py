from __future__ import annotations

import hashlib
import json
import logging
import signal
import subprocess
import threading
import time
from pathlib import Path

from .config import StorageConfig


class StorageWorker:
    def __init__(self, config: StorageConfig):
        self.config = config
        self.log = logging.getLogger("swallowtail_storage.worker")
        self.shutdown_requested = threading.Event()
        self.last_mount_signature = ""

    def request_shutdown(self) -> None:
        self.shutdown_requested.set()

    def run_forever(self) -> None:
        self.log.info("Storage worker started")
        self.refresh("startup")
        while not self.shutdown_requested.wait(self.config.interval_seconds):
            reason = "timer"
            signature = self.mount_signature()
            if signature != "" and signature != self.last_mount_signature:
                reason = "mount-change"
            self.refresh(reason)
        self.log.info("Storage worker stopped")

    def run_once(self) -> bool:
        return self.refresh("once")

    def refresh(self, reason: str) -> bool:
        ok = self.run_php("refresh")
        if ok:
            status = self.php_json("status")
            self.last_mount_signature = str(
                (((status.get("cache") or {}).get("snapshot") or {}).get("mount_signature")) or self.mount_signature()
            )
        self.run_php("process-migrations", str(self.config.migration_limit))
        self.log.info("Storage refresh completed reason=%s ok=%s", reason, ok)
        return ok

    def status(self) -> dict:
        payload = self.php_json("status")
        payload["service"] = {
            "state": "running",
            "project_root": self.config.project_root,
            "interval_seconds": self.config.interval_seconds,
            "last_mount_signature": self.last_mount_signature,
        }
        return payload

    def health_checks(self) -> tuple[bool, list[str]]:
        results: list[str] = []
        healthy = True
        status_payload: dict | None = None

        def check(label: str, callback) -> None:
            nonlocal healthy
            try:
                callback()
                results.append(f"OK {label}")
            except Exception as exc:
                healthy = False
                results.append(f"FAIL {label}: {exc}")

        def storage_status() -> dict:
            nonlocal status_payload
            if status_payload is None:
                status_payload = self.php_json("status")
                if not status_payload.get("success"):
                    errors = status_payload.get("errors")
                    if isinstance(errors, list) and errors:
                        raise RuntimeError("; ".join(str(error) for error in errors))
                    raise RuntimeError("storage status command failed")
            return status_payload

        def redis_status() -> None:
            cache = storage_status().get("cache")
            if not isinstance(cache, dict) or not cache.get("redis_available"):
                raise RuntimeError("Redis ping failed")

        check("storage status", storage_status)
        check("redis", redis_status)
        return healthy, results

    def mount_signature(self) -> str:
        commands = [["/bin/df", "-Pk"], ["/sbin/mount", "-p"]]
        parts: list[str] = []
        for command in commands:
            try:
                result = subprocess.run(command, check=False, capture_output=True, text=True, timeout=10)
                if result.stdout:
                    parts.append(result.stdout)
            except OSError:
                continue
            except subprocess.TimeoutExpired:
                continue
        return hashlib.sha256("\n".join(parts).encode("utf-8")).hexdigest() if parts else ""

    def run_php(self, *args: str) -> bool:
        payload = self.php_json(*args)
        return bool(payload.get("success"))

    def php_json(self, *args: str) -> dict:
        script = Path(self.config.project_root) / "tools" / "php" / "storageCache.php"
        command = [self.config.php, str(script), *args]
        try:
            result = subprocess.run(command, check=False, capture_output=True, text=True, timeout=300)
        except (OSError, subprocess.TimeoutExpired) as exc:
            self.log.error("PHP storage command failed: %s", exc)
            return {"success": False, "errors": [str(exc)]}

        if result.returncode != 0:
            self.log.error("PHP storage command exited %s: %s", result.returncode, result.stderr.strip())
        try:
            return json.loads(result.stdout or "{}")
        except json.JSONDecodeError:
            self.log.error("PHP storage command returned invalid JSON: %s", result.stdout[:400])
            return {"success": False, "errors": ["Invalid JSON from PHP storage command."]}


def install_signal_handlers(worker: StorageWorker) -> None:
    def handle_shutdown(_signum, _frame) -> None:
        worker.request_shutdown()

    signal.signal(signal.SIGTERM, handle_shutdown)
    signal.signal(signal.SIGINT, handle_shutdown)
