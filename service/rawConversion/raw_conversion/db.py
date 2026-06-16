from __future__ import annotations

import hashlib
import json
import os
from typing import Any

from .config import DatabaseConfig, WorkerConfig
from .jobs import ConversionJob


class ConversionDatabase:
    def __init__(self, database: DatabaseConfig, worker: WorkerConfig):
        self.worker = worker
        self.driver = database.driver
        self.paramstyle = "%s"

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
            self.connection = pyodbc.connect(";".join(parts), autocommit=False)
            self.paramstyle = "?"
            return

        try:
            import pymysql
            import pymysql.cursors
        except ImportError as exc:
            raise RuntimeError("PyMySQL is required. Install py311-pymysql on FreeBSD.") from exc

        self.connection = pymysql.connect(
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
        cursor = self._execute(
            """
            UPDATE swallowtail_photo_conversion_jobs
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
        self.connection.commit()
        return count

    def ping(self) -> None:
        self._fetchone("SELECT 1 AS ok")
        self.connection.rollback()

    def next_queued_job_id(self) -> int | None:
        row = self._fetchone(
            """
            SELECT id
              FROM swallowtail_photo_conversion_jobs
             WHERE status = 'queued'
               AND available_at <= CURRENT_TIMESTAMP
             ORDER BY
               CASE derivative_type
                 WHEN 'preview' THEN 1
                 WHEN 'thumbnail' THEN 2
                 WHEN 'jpeg' THEN 3
                 WHEN 'original_jpeg' THEN 4
                 ELSE 5
               END,
               CASE priority
                 WHEN 'high' THEN 1
                 WHEN 'normal' THEN 2
                 ELSE 3
               END,
               id
             LIMIT 1
            """
        )
        return int(row["id"]) if row else None

    def claim_job(self, job_id: int) -> ConversionJob | None:
        cursor = self._execute(
            """
            UPDATE swallowtail_photo_conversion_jobs
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
            return None

        row = self._fetchone("SELECT * FROM swallowtail_photo_conversion_jobs WHERE id = %s LIMIT 1", (job_id,))
        self.connection.commit()
        return ConversionJob.from_row(row) if row else None

    def is_stale_preview(self, job: ConversionJob) -> bool:
        if job.derivative_type != "preview":
            return False
        row = self._fetchone(
            """
            SELECT 1 AS stale
              FROM swallowtail_photo_conversion_jobs
             WHERE photo_id = %s
               AND derivative_type = 'preview'
               AND profile_version > %s
               AND status IN ('queued', 'processing', 'succeeded')
             LIMIT 1
            """,
            (job.photo_id, job.profile_version),
        )
        return row is not None

    def complete_job(self, job: ConversionJob, output_path: str, command: list[str], stderr: str, duration: float) -> None:
        sha256 = self._sha256(output_path)
        size = os.path.getsize(output_path)
        details = {
            "job_id": job.id,
            "derivative_type": job.derivative_type,
            "command": command,
            "stderr": stderr,
            "duration_seconds": round(duration, 3),
            "bytes": size,
        }

        self._execute(
            """
            INSERT INTO swallowtail_photo_derivatives (
                photo_id,
                derivative_type,
                storage_path,
                storage_location_id,
                bytes,
                sha256
            ) VALUES (
                %s, %s, %s, %s, %s, %s
            )
            ON DUPLICATE KEY UPDATE
                storage_path = VALUES(storage_path),
                storage_location_id = VALUES(storage_location_id),
                bytes = VALUES(bytes),
                sha256 = VALUES(sha256),
                generated_at = CURRENT_TIMESTAMP
            """,
            (
                job.photo_id,
                job.derivative_type,
                job.output_storage_path,
                job.output_storage_location_id,
                size,
                sha256,
            ),
        )
        self._execute(
            """
            UPDATE swallowtail_photo_conversion_jobs
               SET status = 'succeeded',
                   completed_at = CURRENT_TIMESTAMP,
                   locked_at = NULL,
                   locked_by = NULL,
                   last_error = NULL
             WHERE id = %s
            """,
            (job.id,),
        )
        self._execute("UPDATE swallowtail_photos SET conversion_state = 'ready' WHERE id = %s", (job.photo_id,))
        self._insert_audit(
            job.photo_id,
            "photo_preview_refreshed" if job.derivative_type == "preview" else "photo_derivative_generated",
            details,
        )
        self.connection.commit()

    def fail_job(self, job: ConversionJob, message: str, retryable: bool = True) -> None:
        status = "queued" if retryable and job.attempts < self.worker.max_attempts else "failed"
        if status == "queued":
            self._execute(
                """
                UPDATE swallowtail_photo_conversion_jobs
                   SET status = 'queued',
                       locked_at = NULL,
                       locked_by = NULL,
                       last_error = %s,
                       available_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL %s SECOND)
                 WHERE id = %s
                """,
                (message[-4000:], self.worker.retry_delay_seconds, job.id),
            )
        else:
            self._execute(
                """
                UPDATE swallowtail_photo_conversion_jobs
                   SET status = 'failed',
                       completed_at = CURRENT_TIMESTAMP,
                       locked_at = NULL,
                       locked_by = NULL,
                       last_error = %s
                 WHERE id = %s
                """,
                (message[-4000:], job.id),
            )
            self._execute("UPDATE swallowtail_photos SET conversion_state = 'failed' WHERE id = %s", (job.photo_id,))
            self._insert_audit(job.photo_id, "photo_conversion_failed", {"job_id": job.id, "error": message[-4000:]})
        self.connection.commit()

    def cancel_job(self, job: ConversionJob, message: str) -> None:
        self._execute(
            """
            UPDATE swallowtail_photo_conversion_jobs
               SET status = 'cancelled',
                   completed_at = CURRENT_TIMESTAMP,
                   locked_at = NULL,
                   locked_by = NULL,
                   last_error = %s
             WHERE id = %s
            """,
            (message[-4000:], job.id),
        )
        self.connection.commit()

    def _insert_audit(self, photo_id: int, action_type: str, details: dict[str, Any]) -> None:
        self._execute(
            """
            INSERT INTO swallowtail_photo_audit (
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
        cursor.execute(self._sql(sql), params)
        return cursor

    def _fetchone(self, sql: str, params: tuple[Any, ...] = ()) -> dict[str, Any] | None:
        cursor = self._execute(sql, params)
        row = cursor.fetchone()
        if row is None:
            return None
        if isinstance(row, dict):
            return row
        columns = [column[0] for column in cursor.description]
        return dict(zip(columns, row))

    def _sql(self, sql: str) -> str:
        if self.paramstyle == "%s":
            return sql
        return sql.replace("%s", "?")

    def _sha256(self, path: str) -> str:
        digest = hashlib.sha256()
        with open(path, "rb") as handle:
            for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                digest.update(chunk)
        return digest.hexdigest()
