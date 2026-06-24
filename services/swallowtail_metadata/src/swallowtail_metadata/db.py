from __future__ import annotations

from datetime import datetime, timedelta
from typing import Any

from .config import DatabaseConfig


class MetadataDatabase:
    FIELD_NAMES = [
        "captured_at_local",
        "camera_timezone_city_code",
        "camera_timezone_city_label",
        "camera_daylight_savings_minutes",
        "captured_at_utc",
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

    def next_profile_photo(self) -> dict[str, Any] | None:
        if not self._table_exists("photo_profile_data"):
            return None
        row = self._fetchone(
            """
            SELECT p.*
              FROM photos p
              JOIN photo_profile_data status
                ON status.photo_id = p.id
               AND status.type = 'swallowtail'
               AND status.`key` = 'status'
              LEFT JOIN photo_profile_data viewed
                ON viewed.photo_id = p.id
               AND viewed.type = 'swallowtail'
               AND viewed.`key` = 'viewed_at'
              LEFT JOIN photo_profile_data next_attempt
                ON next_attempt.photo_id = p.id
               AND next_attempt.type = 'swallowtail'
               AND next_attempt.`key` = 'next_attempt_at'
             WHERE p.upload_state = 'uploaded'
               AND status.value = 'queued'
               AND (
                    next_attempt.value IS NULL
                    OR next_attempt.value = ''
                    OR next_attempt.value <= CURRENT_TIMESTAMP
               )
             ORDER BY COALESCE(viewed.value, '0') DESC, status.updated_at, p.id
             LIMIT 1
            """
        )
        self.connection.rollback()
        return row

    def upsert_ready(self, photo_id: int, fields: dict[str, Any], properties: list[dict[str, Any]]) -> None:
        values = {name: fields.get(name) for name in self.FIELD_NAMES}
        columns = ["photo_id", "status", "attempts", "next_attempt_at", "last_error", *self.FIELD_NAMES, "extracted_at"]
        params = [
            photo_id,
            "ready",
            0,
            None,
            None,
            *(values[name] for name in self.FIELD_NAMES),
            self._now(),
        ]
        updates = ", ".join(f"{column} = VALUES({column})" for column in columns[1:])
        sql = f"""
            INSERT INTO photo_metadata ({", ".join(columns)})
            VALUES ({", ".join(["%s"] * len(columns))})
            ON DUPLICATE KEY UPDATE {updates}, updated_at = CURRENT_TIMESTAMP
        """
        self._execute(sql, tuple(params))
        self._replace_properties(photo_id, properties)
        self.connection.commit()

    def _replace_properties(self, photo_id: int, properties: list[dict[str, Any]]) -> None:
        self._execute("DELETE FROM photo_metadata_property WHERE photo_id = %s", (photo_id,))
        if not properties:
            return
        sql = """
            INSERT INTO photo_metadata_property (
                photo_id, type, `key`, value, value_type
            ) VALUES (
                %s, %s, %s, %s, %s
            )
        """
        for property_row in properties:
            self._execute(sql, (
                photo_id,
                str(property_row.get("type") or "")[:32],
                str(property_row.get("key") or "")[:191],
                property_row.get("value"),
                str(property_row.get("value_type") or "string"),
            ))

    def mark_profile_processing(self, photo_id: int) -> None:
        self._set_profile_value(photo_id, "swallowtail", "status", "processing", "string")
        self._set_profile_value(photo_id, "swallowtail", "last_error", "", "string")
        self.connection.commit()

    def replace_profile_data(self, photo_id: int, properties: list[dict[str, Any]], baseline_path: str, rawtherapee_version: str) -> None:
        self._execute("DELETE FROM photo_profile_data WHERE photo_id = %s AND type <> 'swallowtail'", (photo_id,))
        for row in properties:
            self._set_profile_value(
                photo_id,
                str(row.get("type") or "")[:32],
                str(row.get("key") or "")[:191],
                row.get("value"),
                str(row.get("value_type") or "string"),
            )
        self._set_profile_value(photo_id, "swallowtail", "baseline_profile_path", baseline_path, "string")
        self._set_profile_value(photo_id, "swallowtail", "rawtherapee_version", rawtherapee_version, "string")
        self._set_profile_value(photo_id, "swallowtail", "last_error", "", "string")
        self._set_profile_value(photo_id, "swallowtail", "status", "processed", "string")
        self.connection.commit()

    def defer_profile(self, photo_id: int, message: str, max_attempts: int, retry_delay_seconds: int) -> str:
        attempts_row = self._fetchone(
            "SELECT value FROM photo_profile_data WHERE photo_id = %s AND type = 'swallowtail' AND `key` = 'attempts' LIMIT 1",
            (photo_id,),
        )
        attempts = int(attempts_row.get("value") or 0) + 1 if attempts_row else 1
        status = "failed" if attempts >= max_attempts else "queued"
        next_attempt_at = "" if status == "failed" else (self._now() + timedelta(seconds=max(1, retry_delay_seconds))).strftime("%Y-%m-%d %H:%M:%S")
        self._set_profile_value(photo_id, "swallowtail", "attempts", str(attempts), "int")
        self._set_profile_value(photo_id, "swallowtail", "next_attempt_at", next_attempt_at, "string")
        self._set_profile_value(photo_id, "swallowtail", "last_error", message[-4000:], "string")
        self._set_profile_value(photo_id, "swallowtail", "status", status, "string")
        self.connection.commit()
        return status

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

    def _set_profile_value(self, photo_id: int, type_name: str, key: str, value: Any, value_type: str) -> None:
        value_type = value_type if value_type in {"null", "bool", "int", "float", "string"} else "string"
        existing = self._fetchone(
            "SELECT id FROM photo_profile_data WHERE photo_id = %s AND type = %s AND `key` = %s LIMIT 1",
            (photo_id, type_name, key),
        )
        stored_value = None if value is None else str(value)
        if existing:
            self._execute(
                """
                UPDATE photo_profile_data
                   SET value = %s,
                       value_type = %s,
                       updated_at = CURRENT_TIMESTAMP
                 WHERE id = %s
                """,
                (stored_value, value_type, int(existing["id"])),
            )
            return
        self._execute(
            """
            INSERT INTO photo_profile_data (
                photo_id, type, `key`, value, value_type
            ) VALUES (
                %s, %s, %s, %s, %s
            )
            """,
            (photo_id, type_name[:32], key[:191], stored_value, value_type),
        )

    def _table_exists(self, table_name: str) -> bool:
        try:
            if self.driver == "odbc":
                row = self._fetchone(
                    "SELECT 1 AS ok FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = %s LIMIT 1",
                    (table_name,),
                )
            else:
                row = self._fetchone(
                    "SELECT 1 AS ok FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s LIMIT 1",
                    (table_name,),
                )
            self.connection.rollback()
            return row is not None
        except Exception:
            self.connection.rollback()
            return False

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
