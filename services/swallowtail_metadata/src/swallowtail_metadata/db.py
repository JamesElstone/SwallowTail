from __future__ import annotations

import json
from datetime import datetime, timedelta
from typing import Any

from .config import DatabaseConfig


class MetadataDatabase:
    FIELD_NAMES = [
        "captured_at_local",
        "captured_at_utc",
        "captured_timezone_offset_minutes",
        "captured_timezone_source",
        "camera_timezone_city_code",
        "camera_timezone_city_label",
        "camera_daylight_savings_minutes",
        "server_timezone_name_at_upload",
        "camera_make",
        "camera_model",
        "camera_serial",
        "lens_model",
        "lens_serial",
        "iso",
        "shutter_speed",
        "aperture",
        "focal_length_mm",
        "pixel_width",
        "pixel_height",
        "orientation",
    ]

    def __init__(self, database: DatabaseConfig):
        self.driver = database.driver
        self.paramstyle = "%s"
        if self.driver == "odbc":
            try:
                import pyodbc
            except ImportError as exc:
                raise RuntimeError("pyodbc is required. Install py311-pyodbc on FreeBSD.") from exc
            dsn = database.dsn.strip()
            if dsn == "":
                raise RuntimeError("ODBC database.dsn must be set for the metadata worker.")
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

    def ping(self) -> None:
        self._fetchone("SELECT 1 AS ok")
        self.connection.rollback()

    def next_photo(self) -> dict[str, Any] | None:
        row = self._fetchone(
            """
            SELECT p.*, pm.status AS metadata_status, pm.attempts AS metadata_attempts, pm.next_attempt_at
              FROM photos p
              LEFT JOIN photo_metadata pm ON pm.photo_id = p.id
             WHERE p.upload_state = 'uploaded'
               AND (
                    pm.photo_id IS NULL
                    OR (pm.status = 'deferred' AND pm.next_attempt_at <= CURRENT_TIMESTAMP)
               )
             ORDER BY
               CASE WHEN pm.photo_id IS NULL THEN 0 ELSE 1 END,
               COALESCE(pm.next_attempt_at, CURRENT_TIMESTAMP),
               p.id
             LIMIT 1
            """
        )
        self.connection.rollback()
        return row

    def upsert_ready(self, photo_id: int, fields: dict[str, Any], raw: dict[str, Any]) -> None:
        values = {name: fields.get(name) for name in self.FIELD_NAMES}
        columns = ["photo_id", "status", *self.FIELD_NAMES, "metadata_json", "attempts", "next_attempt_at", "last_error", "extracted_at"]
        params = [
            photo_id,
            "ready",
            *(values[name] for name in self.FIELD_NAMES),
            json.dumps(raw, separators=(",", ":"), ensure_ascii=False),
            0,
            None,
            None,
            self._now(),
        ]
        updates = ", ".join(f"{column} = VALUES({column})" for column in columns[1:])
        sql = f"""
            INSERT INTO photo_metadata ({", ".join(columns)})
            VALUES ({", ".join(["%s"] * len(columns))})
            ON DUPLICATE KEY UPDATE {updates}, updated_at = CURRENT_TIMESTAMP
        """
        self._execute(sql, tuple(params))
        self.connection.commit()

    def defer_or_fail(self, photo_id: int, message: str, max_attempts: int, retry_delay_seconds: int) -> str:
        existing = self._fetchone("SELECT attempts FROM photo_metadata WHERE photo_id = %s LIMIT 1", (photo_id,))
        attempts = int(existing.get("attempts") or 0) + 1 if existing else 1
        status = "failed" if attempts >= max_attempts else "deferred"
        next_attempt_at = None if status == "failed" else self._now() + timedelta(seconds=retry_delay_seconds)
        sql = """
            INSERT INTO photo_metadata (
                photo_id, status, attempts, next_attempt_at, last_error
            ) VALUES (
                %s, %s, %s, %s, %s
            )
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                attempts = VALUES(attempts),
                next_attempt_at = VALUES(next_attempt_at),
                last_error = VALUES(last_error),
                updated_at = CURRENT_TIMESTAMP
        """
        self._execute(sql, (photo_id, status, attempts, next_attempt_at, message[-4000:]))
        self.connection.commit()
        return status

    def counts(self) -> dict[str, int]:
        rows = self._fetchall("SELECT status, COUNT(*) AS count FROM photo_metadata GROUP BY status")
        counts = {"ready": 0, "deferred": 0, "failed": 0}
        for row in rows:
            counts[str(row.get("status") or "")] = int(row.get("count") or 0)
        self.connection.rollback()
        return counts

    def _now(self) -> datetime:
        return datetime.now().replace(microsecond=0)

    def _execute(self, sql: str, params: tuple[Any, ...] = ()):
        cursor = self.connection.cursor()
        if self.paramstyle == "?":
            sql = sql.replace("%s", "?")
        cursor.execute(sql, params)
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
        if not rows:
            return []
        if isinstance(rows[0], dict):
            return list(rows)
        columns = [column[0] for column in cursor.description]
        return [dict(zip(columns, row)) for row in rows]
