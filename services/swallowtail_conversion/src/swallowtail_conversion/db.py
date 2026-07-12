from __future__ import annotations

import json
import threading
from datetime import datetime, timedelta
from typing import Any

from .config import DatabaseConfig, WorkerConfig
from .jobs import ConversionJob


class ConversionDatabase:
    PREEMPT_PRIORITY = 50

    def __init__(self, database: DatabaseConfig, worker: WorkerConfig):
        self.database = database
        self.worker = worker
        self.driver = database.driver
        self.paramstyle = "%s"
        self._local = threading.local()

        if self.driver == "odbc":
            self.paramstyle = "?"

        self.connection = self._connect()

    @property
    def connection(self):
        if not hasattr(self, "_local"):
            self._local = threading.local()
        connection = getattr(self._local, "connection", None)
        if connection is None:
            connection = self._connect()
            self._local.connection = connection
        return connection

    @connection.setter
    def connection(self, connection) -> None:
        if not hasattr(self, "_local"):
            self._local = threading.local()
        self._local.connection = connection

    def _connect(self):
        database = self.database
        if self.driver == "odbc":
            try:
                import pyodbc
            except ImportError as exc:
                raise RuntimeError("pyodbc is required. Install py311-pyodbc on FreeBSD.") from exc

            dsn = database.dsn.strip()
            if dsn == "":
                raise RuntimeError("ODBC database.dsn must be set for the raw conversion worker.")

            parts = [f"DSN={dsn}"]
            if database.user:
                parts.append(f"UID={database.user}")
            if database.password:
                parts.append(f"PWD={database.password}")
            return pyodbc.connect(";".join(parts), autocommit=False)

        try:
            import pymysql
            import pymysql.cursors
        except ImportError as exc:
            raise RuntimeError("PyMySQL is required. Install py311-pymysql on FreeBSD.") from exc

        return pymysql.connect(
            host=database.host,
            port=database.port,
            user=database.user,
            password=database.password,
            database=database.database,
            charset="utf8mb4",
            autocommit=False,
            cursorclass=pymysql.cursors.DictCursor,
        )

    def requeue_expired_jobs(self) -> int:
        rows = self._fetchall(
            """
            SELECT DISTINCT photo_id
              FROM photo_conversion_jobs
             WHERE status = 'processing'
               AND locked_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL %s SECOND)
            """,
            (self.worker.job_timeout_seconds,),
        )
        cursor = self._execute(
            """
            UPDATE photo_conversion_jobs
               SET status = 'queued',
                   locked_at = NULL,
                   locked_by = NULL,
                   last_error = 'Worker lock expired and job was requeued'
             WHERE status = 'processing'
               AND locked_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL %s SECOND)
            """,
            (self.worker.job_timeout_seconds,),
        )
        count = cursor.rowcount
        for row in rows:
            self._refresh_photo_conversion_state(int(row["photo_id"]))
        self.connection.commit()
        return count

    def ping(self) -> None:
        self._fetchone("SELECT 1 AS ok")
        self._rollback_read()

    def next_queued_job_id(self) -> int | None:
        row = self._fetchone(
            f"""
            SELECT id
              FROM photo_conversion_jobs
             WHERE status = 'queued'
               AND available_at <= CURRENT_TIMESTAMP
             ORDER BY
               priority DESC,
               id
             LIMIT 1
            """
        )
        self._rollback_read()
        return int(row["id"]) if row else None

    def preempt_target(self, job_id: int) -> dict[str, int] | None:
        row = self._fetchone(
            """
            SELECT id, priority
              FROM photo_conversion_jobs
             WHERE id = %s
               AND status = 'queued'
               AND priority >= %s
               AND available_at <= CURRENT_TIMESTAMP
             LIMIT 1
            """,
            (job_id, self.PREEMPT_PRIORITY),
        )
        self._rollback_read()
        if row is None:
            return None
        return {"id": int(row["id"]), "priority": int(row["priority"] or 0)}

    def claim_job(self, job_id: int) -> ConversionJob | None:
        candidate = self._fetchone("SELECT photo_id FROM photo_conversion_jobs WHERE id = %s AND status = 'queued' LIMIT 1", (job_id,))
        self._rollback_read()
        if candidate is None:
            return None
        photo_id = int(candidate["photo_id"])
        if not self.acquire_photo_lease(photo_id, job_id):
            return None
        cursor = self._execute(
            """
            UPDATE photo_conversion_jobs
               SET status = 'processing',
                   locked_at = CURRENT_TIMESTAMP,
                   locked_by = %s,
                   started_at = CURRENT_TIMESTAMP,
                   attempts = attempts + 1
             WHERE id = %s
               AND status = 'queued'
               AND available_at <= CURRENT_TIMESTAMP
            """,
            (self.worker.worker_id, job_id),
        )
        if cursor.rowcount != 1:
            self.connection.rollback()
            self.release_photo_lease(photo_id, job_id)
            return None

        row = self._fetchone("SELECT * FROM photo_conversion_jobs WHERE id = %s LIMIT 1", (job_id,))
        if row is not None:
            self._refresh_photo_conversion_state(int(row["photo_id"]))
        self.connection.commit()
        return ConversionJob.from_row(row) if row else None

    def _lease_owner(self, job_id: int) -> str:
        return f"conversion:{self.worker.worker_id}:{job_id}"

    def acquire_photo_lease(self, photo_id: int, job_id: int, ttl_seconds: int = 900) -> bool:
        try:
            self._execute("DELETE FROM photo_operation_leases WHERE photo_id = %s AND expires_at <= CURRENT_TIMESTAMP", (photo_id,))
            self._execute(
                "INSERT INTO photo_operation_leases (photo_id, operation_type, owner_token, expires_at) VALUES (%s, 'conversion', %s, %s)",
                (photo_id, self._lease_owner(job_id), datetime.now() + timedelta(seconds=max(30, ttl_seconds))),
            )
            self.connection.commit()
            return True
        except Exception:
            self.connection.rollback()
            return False

    def heartbeat_photo_lease(self, photo_id: int, job_id: int, ttl_seconds: int = 900) -> None:
        self._execute(
            "UPDATE photo_operation_leases SET heartbeat_at = CURRENT_TIMESTAMP, expires_at = %s WHERE photo_id = %s AND owner_token = %s",
            (datetime.now() + timedelta(seconds=max(30, ttl_seconds)), photo_id, self._lease_owner(job_id)),
        )
        self.connection.commit()

    def release_photo_lease(self, photo_id: int, job_id: int) -> None:
        self._execute("DELETE FROM photo_operation_leases WHERE photo_id = %s AND owner_token = %s", (photo_id, self._lease_owner(job_id)))
        self.connection.commit()

    def is_stale_preview(self, job: ConversionJob) -> bool:
        return False

    def is_obsolete_job(self, job: ConversionJob) -> bool:
        row = self._fetchone(
            """
            SELECT status
              FROM photo_conversion_jobs
             WHERE id = %s
             LIMIT 1
            """,
            (job.id,),
        )
        self._rollback_read()
        return row is not None and str(row.get("status") or "") == "obsolete"

    def storage_location_properties(self) -> list[dict[str, Any]]:
        rows = self._fetchall(
            """
            SELECT storage_base_location, is_excluded, is_zfs, dataset_name
              FROM storage_location_properties
            """
        )
        self._rollback_read()
        return rows

    def photo_storage(self, photo_id: int) -> dict[str, Any] | None:
        row = self._fetchone(
            """
            SELECT id, original_sha256, storage_base_location
              FROM photos
             WHERE id = %s
             LIMIT 1
            """,
            (photo_id,),
        )
        self._rollback_read()
        return row

    def update_photo_storage_location(
        self,
        photo_id: int,
        old_base_location: str,
        new_base_location: str,
        old_data_root: str,
        new_data_root: str,
    ) -> None:
        self._execute(
            """
            UPDATE photos
               SET storage_base_location = %s
             WHERE id = %s
            """,
            (new_base_location, photo_id),
        )
        self._execute(
            """
            UPDATE photo_conversion_jobs
               SET input_path = REPLACE(input_path, %s, %s),
                   output_path = REPLACE(output_path, %s, %s),
                   profile_path = CASE
                       WHEN profile_path IS NULL THEN NULL
                       ELSE REPLACE(profile_path, %s, %s)
                   END
             WHERE photo_id = %s
               AND status IN ('queued', 'processing')
            """,
            (
                old_data_root,
                new_data_root,
                old_data_root,
                new_data_root,
                old_data_root,
                new_data_root,
                photo_id,
            ),
        )
        self._insert_audit(
            photo_id,
            "photo_storage_relocated",
            {
                "old_storage_base_location": old_base_location,
                "new_storage_base_location": new_base_location,
            },
        )
        self.connection.commit()

    def defer_job_for_storage(self, job: ConversionJob, message: str, delay_seconds: int) -> None:
        self._execute(
            """
            UPDATE photo_conversion_jobs
               SET status = 'queued',
                   locked_at = NULL,
                   locked_by = NULL,
                   last_error = %s,
                   attempts = CASE WHEN attempts > 0 THEN attempts - 1 ELSE 0 END,
                   available_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL %s SECOND)
             WHERE id = %s
            """,
            (message[-4000:], max(60, int(delay_seconds)), job.id),
        )
        self._refresh_photo_conversion_state(job.photo_id)
        self.connection.commit()

    def complete_job(self, job: ConversionJob, output_path: str, command: list[str], stderr: str, duration: float) -> None:
        details = {
            "job_id": job.id,
            "image_type": job.image_type,
            "command": command,
            "stderr": stderr,
            "duration_seconds": round(duration, 3),
            "output_path": output_path,
            "profile_signature": job.profile_signature,
        }

        self._execute(
            """
            UPDATE photo_conversion_jobs
               SET status = 'succeeded',
                   completed_at = CURRENT_TIMESTAMP,
                   duration_seconds = %s,
                   locked_at = NULL,
                   locked_by = NULL,
                   last_error = NULL
             WHERE id = %s
            """,
            (round(duration, 3), job.id),
        )
        self._refresh_photo_conversion_state(job.photo_id)
        self._insert_audit(
            job.photo_id,
            "photo_preview_refreshed" if job.image_type == "preview" else "photo_image_generated",
            details,
        )
        self.connection.commit()

    def fail_job(self, job: ConversionJob, message: str, retryable: bool = True, duration: float | None = None) -> None:
        duration_seconds = round(duration, 3) if duration is not None else None
        status = "queued" if retryable and job.attempts < self.worker.max_attempts else "failed"
        if status == "queued":
            self._execute(
                """
                UPDATE photo_conversion_jobs
                   SET status = 'queued',
                       locked_at = NULL,
                       locked_by = NULL,
                       last_error = %s,
                       duration_seconds = %s,
                       available_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL %s SECOND)
                 WHERE id = %s
                """,
                (message[-4000:], duration_seconds, self.worker.retry_delay_seconds, job.id),
            )
        else:
            self._execute(
                """
                UPDATE photo_conversion_jobs
                   SET status = 'failed',
                       completed_at = CURRENT_TIMESTAMP,
                       locked_at = NULL,
                       locked_by = NULL,
                       duration_seconds = %s,
                       last_error = %s
                 WHERE id = %s
                """,
                (duration_seconds, message[-4000:], job.id),
            )
            self._insert_audit(job.photo_id, "photo_conversion_failed", {"job_id": job.id, "error": message[-4000:]})
        self._refresh_photo_conversion_state(job.photo_id)
        self.connection.commit()

    def cancel_job(self, job: ConversionJob, message: str) -> None:
        self._execute(
            """
            UPDATE photo_conversion_jobs
               SET status = 'cancelled',
                   completed_at = CURRENT_TIMESTAMP,
                   locked_at = NULL,
                   locked_by = NULL,
                   last_error = %s
             WHERE id = %s
            """,
            (message[-4000:], job.id),
        )
        self._refresh_photo_conversion_state(job.photo_id)
        self.connection.commit()

    def obsolete_job(self, job: ConversionJob, message: str) -> None:
        self._execute(
            """
            UPDATE photo_conversion_jobs
               SET status = 'obsolete',
                   completed_at = CURRENT_TIMESTAMP,
                   locked_at = NULL,
                   locked_by = NULL,
                   last_error = %s
             WHERE id = %s
            """,
            (message[-4000:], job.id),
        )
        self._refresh_photo_conversion_state(job.photo_id)
        self.connection.commit()

    def requeue_preempted_job(self, job: ConversionJob, message: str, duration: float | None = None) -> None:
        duration_seconds = round(duration, 3) if duration is not None else None
        self._execute(
            """
            UPDATE photo_conversion_jobs
               SET status = 'queued',
                   locked_at = NULL,
                   locked_by = NULL,
                   last_error = %s,
                   duration_seconds = %s,
                   attempts = CASE WHEN attempts > 0 THEN attempts - 1 ELSE 0 END,
                   available_at = CURRENT_TIMESTAMP
             WHERE id = %s
               AND status = 'processing'
            """,
            (message[-4000:], duration_seconds, job.id),
        )
        self._refresh_photo_conversion_state(job.photo_id)
        self.connection.commit()

    def _refresh_photo_conversion_state(self, photo_id: int) -> None:
        row = self._fetchone(
            """
            SELECT
                SUM(CASE WHEN status IN ('queued', 'processing') THEN 1 ELSE 0 END) AS active_jobs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_jobs,
                SUM(CASE WHEN status NOT IN ('cancelled', 'obsolete') THEN 1 ELSE 0 END) AS non_cancelled_jobs,
                SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succeeded_jobs
              FROM photo_conversion_jobs
             WHERE photo_id = %s
            """,
            (photo_id,),
        )
        if row is None:
            return

        state = self.photo_state_from_job_counts(
            int(row["active_jobs"] or 0),
            int(row["failed_jobs"] or 0),
            int(row["non_cancelled_jobs"] or 0),
            int(row["succeeded_jobs"] or 0),
        )
        self._execute("UPDATE photos SET conversion_state = %s WHERE id = %s", (state, photo_id))

    @staticmethod
    def photo_state_from_job_counts(
        active_jobs: int,
        failed_jobs: int,
        non_cancelled_jobs: int,
        succeeded_jobs: int,
    ) -> str:
        if active_jobs > 0:
            return "processing"
        if failed_jobs > 0:
            return "failed"
        if non_cancelled_jobs > 0 and succeeded_jobs >= non_cancelled_jobs:
            return "ready"
        return "pending"

    def _insert_audit(self, photo_id: int, action_type: str, details: dict[str, Any]) -> None:
        self._execute(
            """
            INSERT INTO photo_audit (
                photo_id,
                action_type,
                details_json
            ) VALUES (
                %s, %s, %s
            )
            """,
            (photo_id, action_type, json.dumps(details, ensure_ascii=False)),
        )

    def _execute(self, sql: str, params: tuple[Any, ...] = ()):
        cursor = self.connection.cursor()
        try:
            cursor.execute(self._sql(sql), params)
        except Exception as exc:
            close = getattr(cursor, "close", None)
            if callable(close):
                close()
            if self._is_connection_lost_error(exc):
                self._discard_connection()
            raise
        return cursor

    def _fetchone(self, sql: str, params: tuple[Any, ...] = ()) -> dict[str, Any] | None:
        return self._read_with_reconnect(lambda: self._fetchone_once(sql, params))

    def _fetchone_once(self, sql: str, params: tuple[Any, ...] = ()) -> dict[str, Any] | None:
        cursor = self._execute(sql, params)
        try:
            row = cursor.fetchone()
            if row is None:
                return None
            if isinstance(row, dict):
                return row
            columns = [column[0] for column in cursor.description]
            return dict(zip(columns, row))
        finally:
            close = getattr(cursor, "close", None)
            if callable(close):
                close()

    def _fetchall(self, sql: str, params: tuple[Any, ...] = ()) -> list[dict[str, Any]]:
        return self._read_with_reconnect(lambda: self._fetchall_once(sql, params))

    def _fetchall_once(self, sql: str, params: tuple[Any, ...] = ()) -> list[dict[str, Any]]:
        cursor = self._execute(sql, params)
        try:
            rows = cursor.fetchall()
            if rows is None:
                return []
            if rows and isinstance(rows[0], dict):
                return list(rows)
            columns = [column[0] for column in cursor.description]
            return [dict(zip(columns, row)) for row in rows]
        finally:
            close = getattr(cursor, "close", None)
            if callable(close):
                close()

    def _read_with_reconnect(self, operation):
        try:
            return operation()
        except Exception as exc:
            if not self._is_connection_lost_error(exc):
                raise
            self._discard_connection()
            return operation()

    def _discard_connection(self) -> None:
        if not hasattr(self, "_local"):
            return
        connection = getattr(self._local, "connection", None)
        self._local.connection = None
        close = getattr(connection, "close", None)
        if callable(close):
            try:
                close()
            except Exception:
                pass

    def _rollback_read(self) -> None:
        try:
            self.connection.rollback()
        except Exception as exc:
            if self.driver == "odbc" and self._is_odbc_function_sequence_error(exc):
                return
            if self._is_connection_lost_error(exc):
                self._discard_connection()
            raise

    def _is_connection_lost_error(self, exc: Exception) -> bool:
        args = getattr(exc, "args", ())
        sqlstate = str(args[0]).upper() if args else ""
        if self.driver == "odbc" and sqlstate.startswith("08"):
            return True

        message = " ".join(str(arg) for arg in args).lower()
        return any(
            fragment in message
            for fragment in (
                "server has gone away",
                "lost connection",
                "got packets out of order",
            )
        )

    @staticmethod
    def _is_odbc_function_sequence_error(exc: Exception) -> bool:
        args = getattr(exc, "args", ())
        return bool(args) and str(args[0]).upper() == "HY010"

    def _sql(self, sql: str) -> str:
        if self.paramstyle == "%s":
            return sql
        return sql.replace("%s", "?")
