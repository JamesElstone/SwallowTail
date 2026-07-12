from __future__ import annotations

import hashlib
import json
import logging
import signal
import subprocess
import threading
import time
from datetime import datetime, timezone
from pathlib import Path

from .config import StorageConfig
from .redis_client import RedisClient, RedisConfig


class StorageWorker:
    def __init__(self, config: StorageConfig, redis_client: RedisClient | None = None):
        self.config = config
        self.log = logging.getLogger("swallowtail_storage.worker")
        self.shutdown_requested = threading.Event()
        self.last_mount_signature = ""
        self.last_writable_mount_signature: str | None = None
        self.redis = redis_client if redis_client is not None else RedisClient(RedisConfig(
            host=config.redis_host,
            port=config.redis_port,
            timeout_seconds=config.redis_timeout_seconds,
            snapshot_key=config.redis_snapshot_key,
            snapshot_ttl_seconds=config.redis_snapshot_ttl_seconds,
            storage_wake_queue=config.redis_storage_wake_queue,
        ))

    def request_shutdown(self) -> None:
        self.shutdown_requested.set()

    def run_forever(self) -> None:
        self.log.info("Storage worker started")
        self.refresh("startup")
        next_refresh_at = time.monotonic() + self.config.interval_seconds

        while not self.shutdown_requested.is_set():
            now = time.monotonic()
            wait_seconds = min(self.config.mount_poll_seconds, max(0.0, next_refresh_at - now))
            if self.shutdown_requested.wait(wait_seconds):
                break

            now = time.monotonic()
            if now >= next_refresh_at:
                self.refresh("timer")
                next_refresh_at = time.monotonic() + self.config.interval_seconds
                continue

            signature = self.mount_signature()
            if signature != "" and signature != self.last_mount_signature:
                self.refresh("mount-change")
                next_refresh_at = time.monotonic() + self.config.interval_seconds
        self.log.info("Storage worker stopped")

    def run_once(self) -> bool:
        return self.refresh("once")

    def refresh(self, reason: str) -> bool:
        self.touch_status()
        payload = self.php_json("discover")
        discovered = bool(payload.get("success")) and isinstance(payload.get("snapshot"), dict)
        snapshot = payload.get("snapshot") if discovered else {}
        mount_points = self.snapshot_mount_points(payload.get("snapshot"))
        writable_mount_points = self.snapshot_writable_mount_points(payload.get("snapshot"))
        cache_written = False
        cache_error = ""
        if discovered:
            try:
                cache_written = self.redis.store_snapshot(snapshot)
            except OSError as exc:
                cache_error = str(exc)
            if not cache_written and cache_error == "":
                cache_error = "Redis SET failed"

        ok = discovered and cache_written
        if ok:
            self.last_mount_signature = self.mount_signature()
        self.process_migrations_while_conversion_idle()
        self.touch_status()
        self.log.info(
            "Storage refresh completed reason=%s ok=%s mount_points=%s writable_mount_points=%s",
            reason,
            ok,
            json.dumps(mount_points, separators=(",", ":")),
            json.dumps(writable_mount_points, separators=(",", ":")),
        )
        if discovered and not cache_written:
            self.log.warning("Storage cache Redis write failed error=%s", cache_error or "unknown")
        if discovered and cache_written:
            self.maybe_notify_storage_wake(snapshot, writable_mount_points)
        if not discovered:
            self.log_refresh_failure(reason, payload)
        return ok

    def process_migrations_while_conversion_idle(self) -> None:
        total_processed = 0
        batches = 0
        stop_reason = "no-work"
        while not self.shutdown_requested.is_set() and batches < self.config.migration_idle_batch_limit:
            payload = self.php_json("process-migrations", str(self.config.migration_item_limit))
            if not bool(payload.get("success")):
                stop_reason = "command-failed"
                break

            batches += 1
            processed = max(0, int(payload.get("processed_items", payload.get("processed", 0)) or 0))
            conversion_active = max(0, int(payload.get("conversion_active_jobs", 0) or 0))
            remaining = max(0, int(payload.get("migration_items_remaining", 0) or 0))
            failed = max(0, int(payload.get("migration_failed_items", 0) or 0))
            total_processed += processed

            if conversion_active > 0:
                stop_reason = "conversion-active"
                break
            if failed > 0:
                stop_reason = "migration-failed"
                break
            if remaining <= 0:
                stop_reason = "complete"
                break
            if processed < self.config.migration_item_limit:
                stop_reason = "short-batch"
                break
        else:
            stop_reason = "batch-limit" if not self.shutdown_requested.is_set() else "shutdown"

        if total_processed > 0 or batches > 1:
            self.log.info(
                "Storage migrations processed batches=%s items=%s stop_reason=%s",
                batches,
                total_processed,
                stop_reason,
            )

    def maybe_notify_storage_wake(self, snapshot: dict, writable_mount_points: list[str]) -> None:
        previous_signature = self.last_writable_mount_signature
        current_signature = self.writable_mount_signature(writable_mount_points)

        if previous_signature is None:
            if current_signature == "":
                self.last_writable_mount_signature = current_signature
                self.log_storage_wake(self.notify_storage_wake(snapshot))
                return

            storage_wake = self.notify_storage_wake(snapshot)
            self.log_storage_wake(storage_wake)
            if bool(storage_wake.get("sent")) or not bool(storage_wake.get("attempted")):
                self.last_writable_mount_signature = current_signature
            return

        should_wake = current_signature != "" and current_signature != previous_signature
        if not should_wake:
            self.last_writable_mount_signature = current_signature
            return

        storage_wake = self.notify_storage_wake(snapshot)
        self.log_storage_wake(storage_wake)
        if bool(storage_wake.get("sent")) or not bool(storage_wake.get("attempted")):
            self.last_writable_mount_signature = current_signature

    def notify_storage_wake(self, snapshot: dict) -> dict:
        queue = self.config.redis_storage_wake_queue.strip()
        if not self.snapshot_has_writable_location(snapshot):
            return {
                "attempted": False,
                "sent": False,
                "queue": queue,
                "error": "No writable storage location is available.",
            }
        if queue == "":
            return {
                "attempted": False,
                "sent": False,
                "queue": "",
                "error": "Storage wake queue is not configured.",
            }

        try:
            sent = self.redis.list_push_json(queue, {
                "reason": "storage_refresh",
                "mount_signature": str(snapshot.get("mount_signature") or ""),
                "generated_at": int(snapshot.get("generated_at") or time.time()),
            }, 1)
        except OSError as exc:
            return {
                "attempted": True,
                "sent": False,
                "queue": queue,
                "error": str(exc),
            }

        return {
            "attempted": True,
            "sent": sent,
            "queue": queue,
            "error": None if sent else "Redis LPUSH failed; Redis may be unavailable.",
        }

    def log_storage_wake(self, storage_wake) -> None:
        if not isinstance(storage_wake, dict):
            self.log.warning("Storage wake message status missing from refresh response")
            return

        queue = str(storage_wake.get("queue") or "")
        error = str(storage_wake.get("error") or "")
        if bool(storage_wake.get("sent")):
            self.log.info("Storage wake message sent queue=%s", queue)
            return

        if bool(storage_wake.get("attempted")):
            self.log.warning("Storage wake message failed queue=%s error=%s", queue, error or "unknown")
            return

        self.log.info("Storage wake message not sent reason=%s", error or "not attempted")

    def log_refresh_failure(self, reason: str, payload: dict) -> None:
        errors = payload.get("errors")
        if isinstance(errors, list) and errors:
            detail = "; ".join(str(error) for error in errors)
        else:
            detail = "unknown"
        self.log.warning("Storage refresh failed reason=%s error=%s", reason, detail)

    def status(self) -> dict:
        payload = self.php_json("status")
        redis_available = False
        try:
            redis_available = self.redis.ping()
        except OSError:
            redis_available = False
        cache = payload.get("cache")
        if not isinstance(cache, dict):
            cache = {}
            payload["cache"] = cache
        cache["redis_available"] = redis_available
        payload["service"] = {
            "state": "running",
            "project_root": self.config.project_root,
            "interval_seconds": self.config.interval_seconds,
            "mount_poll_seconds": self.config.mount_poll_seconds,
            "migration_item_limit": self.config.migration_item_limit,
            "migration_idle_batch_limit": self.config.migration_idle_batch_limit,
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

        def storage_discovery() -> dict:
            nonlocal status_payload
            if status_payload is None:
                status_payload = self.php_json("discover")
                if not status_payload.get("success"):
                    errors = status_payload.get("errors")
                    if isinstance(errors, list) and errors:
                        raise RuntimeError("; ".join(str(error) for error in errors))
                    raise RuntimeError("storage discovery command failed")
            return status_payload

        def redis_status() -> None:
            if not self.redis.ping():
                raise RuntimeError("Redis ping failed")

        check("storage discovery", storage_discovery)
        check("redis", redis_status)
        return healthy, results

    def mount_signature(self) -> str:
        commands = [["/bin/df", "-Pk"], ["/sbin/mount", "-p"]]
        parts: list[str] = []
        for command in commands:
            try:
                result = subprocess.run(command, check=False, capture_output=True, text=True, timeout=10)
                if result.stdout:
                    parts.extend(self.mount_signature_parts(command, result.stdout))
            except OSError:
                continue
            except subprocess.TimeoutExpired:
                continue
        return hashlib.sha256("\n".join(sorted(parts)).encode("utf-8")).hexdigest() if parts else ""

    def mount_signature_parts(self, command: list[str], output: str) -> list[str]:
        if command == ["/bin/df", "-Pk"]:
            return self.df_mount_signature_parts(output)

        return [
            "mount:" + line.strip()
            for line in output.splitlines()
            if line.strip() != ""
        ]

    def df_mount_signature_parts(self, output: str) -> list[str]:
        parts: list[str] = []
        for index, line in enumerate(output.splitlines()):
            if index == 0:
                continue
            columns = line.split()
            if len(columns) < 6:
                continue
            parts.append(f"df:{columns[0]}\t{columns[-1]}")
        return parts

    def snapshot_mount_points(self, snapshot) -> list[str]:
        if not isinstance(snapshot, dict):
            return []
        locations = snapshot.get("locations")
        if not isinstance(locations, list):
            return []

        mount_points: list[str] = []
        for location in locations:
            if not isinstance(location, dict):
                continue
            mount_point = str(location.get("storage_base_location") or "").strip()
            if mount_point != "":
                mount_points.append(mount_point)
        return mount_points

    def snapshot_writable_mount_points(self, snapshot) -> list[str]:
        if not isinstance(snapshot, dict):
            return []
        locations = snapshot.get("locations")
        if not isinstance(locations, list):
            return []

        mount_points: list[str] = []
        for location in locations:
            if not isinstance(location, dict) or not bool(location.get("can_write")):
                continue
            mount_point = str(location.get("storage_base_location") or "").strip()
            if mount_point != "":
                mount_points.append(mount_point)
        return mount_points

    def writable_mount_signature(self, mount_points: list[str]) -> str:
        mount_points = sorted(mount_point for mount_point in mount_points if mount_point != "")
        if mount_points == []:
            return ""
        return hashlib.sha256("\n".join(mount_points).encode("utf-8")).hexdigest()

    def snapshot_has_writable_location(self, snapshot: dict) -> bool:
        locations = snapshot.get("locations")
        if not isinstance(locations, list):
            return False
        return any(isinstance(location, dict) and bool(location.get("can_write")) for location in locations)

    def run_php(self, *args: str) -> bool:
        payload = self.php_json(*args)
        return bool(payload.get("success"))

    def touch_status(self) -> bool:
        touched_at = int(time.time())
        try:
            ok = self.redis.set_json(
                "swallowtail:service:swallowtail_storage:last_touched",
                {
                    "service": "swallowtail_storage",
                    "touched_at": touched_at,
                    "touched_at_iso": datetime.fromtimestamp(touched_at, timezone.utc).isoformat(),
                },
                720,
            )
        except OSError:
            ok = False
        if not ok:
            self.log.warning("Unable to refresh Redis heartbeat for storage worker")
        return ok

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
