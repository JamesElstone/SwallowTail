from __future__ import annotations

import hashlib
import json
import os
from typing import Any

from .config import DatabaseConfig, WorkerConfig
from .jobs import ConversionJob


class ConversionDatabase:
    PREEMPT_PRIORITY = 50

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
        self.connection.rollback()

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
        self.connection.rollback()
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
        self.connection.rollback()
        if row is None:
            return None
        return {"id": int(row["id"]), "priority": int(row["priority"] or 0)}

    def claim_job(self, job_id: int) -> ConversionJob | None:
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
            return None

        row = self._fetchone("SELECT * FROM photo_conversion_jobs WHERE id = %s LIMIT 1", (job_id,))
        if row is not None:
            self._refresh_photo_conversion_state(int(row["photo_id"]))
        self.connection.commit()
        return ConversionJob.from_row(row) if row else None

    def is_stale_filtered(self, job: ConversionJob) -> bool:
        if job.image_type != "filtered":
            return False
        row = self._fetchone(
            """
            SELECT 1 AS stale
              FROM photo_conversion_jobs
             WHERE photo_id = %s
               AND image_type = 'filtered'
               AND profile_version > %s
               AND status IN ('queued', 'processing', 'succeeded')
             LIMIT 1
            """,
            (job.photo_id, job.profile_version),
        )
        self.connection.rollback()
        return row is not None

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
        self.connection.rollback()
        return row is not None and str(row.get("status") or "") == "obsolete"

    def storage_location_properties(self) -> list[dict[str, Any]]:
        rows = self._fetchall(
            """
            SELECT storage_base_location, is_excluded, is_zfs, dataset_name
              FROM storage_location_properties
            """
        )
        self.connection.rollback()
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
        self.connection.rollback()
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
        sha256 = self._sha256(output_path)
        size = os.path.getsize(output_path)
        details = {
            "job_id": job.id,
            "image_type": job.image_type,
            "command": command,
            "stderr": stderr,
            "duration_seconds": round(duration, 3),
            "bytes": size,
            "sha256": sha256,
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
            "photo_filtered_refreshed" if job.image_type == "filtered" else "photo_image_generated",
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

    def _fetchall(self, sql: str, params: tuple[Any, ...] = ()) -> list[dict[str, Any]]:
        cursor = self._execute(sql, params)
        rows = cursor.fetchall()
        if rows is None:
            return []
        if rows and isinstance(rows[0], dict):
            return list(rows)
        columns = [column[0] for column in cursor.description]
        return [dict(zip(columns, row)) for row in rows]

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
