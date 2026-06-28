from __future__ import annotations

import threading
from datetime import datetime, timedelta
from typing import Any

from .config import DatabaseConfig


class MetadataDatabase:
    PROFILE_INSERT_BATCH_ROWS = 500
    ODBC_VALUE_CHUNK_CHARS = 100
    PROFILE_SECTION_MAX_CHARS = 64
    PROFILE_KEY_MAX_CHARS = 191

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
        self.database = database
        self.driver = database.driver
        self.pyodbc = None
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
            self.pyodbc = pyodbc
            dsn = database.dsn.strip()
            if dsn == "":
                raise RuntimeError("ODBC database.dsn must be set for the metadata worker.")
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

    def ping(self) -> None:
        self._fetchone("SELECT 1 AS ok")
        self._rollback_read()

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
        self._rollback_read()
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
               AND status.revision = 0
              LEFT JOIN photo_profile_data viewed
                ON viewed.photo_id = p.id
               AND viewed.type = 'swallowtail'
               AND viewed.`key` = 'viewed_at'
               AND viewed.revision = 0
              LEFT JOIN photo_profile_data next_attempt
                ON next_attempt.photo_id = p.id
               AND next_attempt.type = 'swallowtail'
               AND next_attempt.`key` = 'next_attempt_at'
               AND next_attempt.revision = 0
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
        self._rollback_read()
        return row

    def next_unprofiled_photo(self) -> dict[str, Any] | None:
        if not self._table_exists("photo_profile_data"):
            return None
        row = self._fetchone(
            """
            SELECT p.*
              FROM photos p
              LEFT JOIN photo_profile_data status
                ON status.photo_id = p.id
               AND status.type = 'swallowtail'
               AND status.`key` = 'status'
               AND status.revision = 0
             WHERE p.upload_state = 'uploaded'
               AND LOWER(COALESCE(p.original_extension, 'cr2')) = 'cr2'
               AND status.id IS NULL
             ORDER BY p.id
             LIMIT 1
            """
        )
        self._rollback_read()
        return row

    def profile_photo_by_id(self, photo_id: int) -> dict[str, Any] | None:
        if photo_id <= 0 or not self._table_exists("photo_profile_data"):
            return None
        row = self._fetchone(
            """
            SELECT p.*
              FROM photos p
              LEFT JOIN photo_profile_data status
                ON status.photo_id = p.id
               AND status.type = 'swallowtail'
               AND status.`key` = 'status'
               AND status.revision = 0
             WHERE p.id = %s
               AND p.upload_state = 'uploaded'
               AND LOWER(COALESCE(p.original_extension, 'cr2')) = 'cr2'
               AND (status.id IS NULL OR status.value <> 'processed')
             LIMIT 1
            """,
            (photo_id,),
        )
        self._rollback_read()
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

    def replace_profile_data(
        self,
        photo_id: int,
        properties: list[dict[str, Any]],
        source_profile_path: str,
        rawtherapee_version: str,
        rawtherapee_profile: dict[str, Any] | None = None,
    ) -> dict[str, int]:
        self._execute("DELETE FROM photo_profile_data WHERE photo_id = %s AND revision = 0 AND type <> 'swallowtail'", (photo_id,))
        stats = self._insert_profile_properties_by_section(photo_id, properties)
        self._set_profile_value(photo_id, "swallowtail", "source_profile_path", source_profile_path, "string")
        self._set_profile_value(photo_id, "swallowtail", "rawtherapee_version", rawtherapee_version, "string")
        self._set_profile_value(photo_id, "swallowtail", "rawtherapee_profile_id", int((rawtherapee_profile or {}).get("id") or 0), "int")
        self._set_profile_value(photo_id, "swallowtail", "rawtherapee_profile_path", str((rawtherapee_profile or {}).get("profile_path") or ""), "string")
        self._set_profile_value(photo_id, "swallowtail", "rawtherapee_profile_hash", str((rawtherapee_profile or {}).get("profile_hash") or ""), "string")
        self._set_profile_value(photo_id, "swallowtail", "last_error", "", "string")
        self._set_profile_value(photo_id, "swallowtail", "status", "processed", "string")
        self.connection.commit()
        return stats

    def rawtherapee_profile_for_photo(self, photo: dict[str, Any]) -> dict[str, Any] | None:
        profile_id = int(photo.get("rawtherapee_profile_id") or 0)
        if profile_id <= 0 or not self._table_exists("rawtherapee_profile_data"):
            return None
        row = self._fetchone(
            """
            SELECT id, profile_path, relative_path, display_label, profile_hash, profile_bytes, profile_mtime
              FROM rawtherapee_profile_data
             WHERE id = %s
               AND is_available = 1
             LIMIT 1
            """,
            (profile_id,),
        )
        self._rollback_read()
        return row

    def replace_rawtherapee_profiles(self, profiles: list[Any]) -> int:
        if not self._table_exists("rawtherapee_profile_data"):
            return 0

        self._execute("UPDATE rawtherapee_profile_data SET is_available = 0, scanned_at = CURRENT_TIMESTAMP")
        written = 0
        for profile in profiles:
            self._execute(
                """
                INSERT INTO rawtherapee_profile_data (
                    profile_path,
                    relative_path,
                    display_label,
                    profile_hash,
                    profile_bytes,
                    profile_mtime,
                    profile_content,
                    is_available,
                    scanned_at
                ) VALUES (
                    %s, %s, %s, %s, %s, %s, %s, 1, CURRENT_TIMESTAMP
                )
                ON DUPLICATE KEY UPDATE
                    relative_path = VALUES(relative_path),
                    display_label = VALUES(display_label),
                    profile_hash = VALUES(profile_hash),
                    profile_bytes = VALUES(profile_bytes),
                    profile_mtime = VALUES(profile_mtime),
                    profile_content = VALUES(profile_content),
                    is_available = 1,
                    scanned_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                """,
                (
                    str(profile.profile_path),
                    str(profile.relative_path),
                    str(profile.display_label),
                    str(profile.profile_hash),
                    int(profile.profile_bytes),
                    int(profile.profile_mtime),
                    str(profile.profile_content),
                ),
            )
            written += 1
        self.connection.commit()
        return written

    def upsert_image_asset(
        self,
        photo_id: int,
        image_type: str,
        sha256: str,
        bytes_size: int,
        modified_at: int,
        width: int | None,
        height: int | None,
        profile_signature: str,
        conversion_job_id: int | None,
    ) -> None:
        if not self._table_exists("photo_image_assets"):
            return
        conversion_job_id = conversion_job_id if conversion_job_id is not None and conversion_job_id > 0 else None
        profile_signature = profile_signature if len(profile_signature) == 64 else None
        if image_type == "rawtherapee_sample" and profile_signature is None:
            return
        asset_variant_key = profile_signature if image_type == "rawtherapee_sample" and profile_signature is not None else ""
        self._execute(
            """
            INSERT INTO photo_image_assets (
                photo_id,
                image_type,
                sha256,
                bytes,
                modified_at,
                width,
                height,
                profile_signature,
                asset_variant_key,
                conversion_job_id,
                generated_at
            ) VALUES (
                %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, CURRENT_TIMESTAMP
            )
            ON DUPLICATE KEY UPDATE
                sha256 = VALUES(sha256),
                bytes = VALUES(bytes),
                modified_at = VALUES(modified_at),
                width = VALUES(width),
                height = VALUES(height),
                profile_signature = VALUES(profile_signature),
                asset_variant_key = VALUES(asset_variant_key),
                conversion_job_id = VALUES(conversion_job_id),
                generated_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            """,
            (
                photo_id,
                image_type,
                sha256,
                bytes_size,
                modified_at,
                width,
                height,
                profile_signature,
                asset_variant_key,
                conversion_job_id,
            ),
        )
        self.connection.commit()

    def next_unrecorded_image_asset_job(self) -> dict[str, Any] | None:
        if not self._table_exists("photo_image_assets"):
            return None
        row = self._fetchone(
            """
            SELECT job.id AS job_id,
                   job.photo_id,
                   job.image_type,
                   job.output_path,
                   job.profile_signature
              FROM photo_conversion_jobs job
              LEFT JOIN photo_image_assets asset
                ON asset.photo_id = job.photo_id
               AND asset.image_type = job.image_type
               AND (
                   job.image_type <> 'rawtherapee_sample'
                   OR asset.asset_variant_key = job.profile_signature
               )
             WHERE job.status = 'succeeded'
               AND job.image_type IN ('embedded', 'thumbnail', 'original', 'preview', 'final', 'rawtherapee_sample')
               AND (
                   job.image_type <> 'rawtherapee_sample'
                   OR LENGTH(COALESCE(job.profile_signature, '')) = 64
               )
               AND asset.id IS NULL
             ORDER BY
                   CASE job.image_type
                       WHEN 'thumbnail' THEN 0
                       WHEN 'preview' THEN 1
                       WHEN 'final' THEN 2
                       WHEN 'original' THEN 3
                       WHEN 'embedded' THEN 4
                       WHEN 'rawtherapee_sample' THEN 5
                       ELSE 6
                   END,
                   job.completed_at DESC,
                   job.id DESC
             LIMIT 1
            """
        )
        self._rollback_read()
        return row

    def _insert_profile_properties_by_section(self, photo_id: int, properties: list[dict[str, Any]]) -> dict[str, int]:
        sections: dict[str, list[dict[str, Any]]] = {}
        largest_value_length = 0
        for row in properties:
            type_name = str(row.get("type") or "")[:self.PROFILE_SECTION_MAX_CHARS]
            key = str(row.get("key") or "")[:self.PROFILE_KEY_MAX_CHARS]
            value = row.get("value")
            value_type = str(row.get("value_type") or "string")
            value_type = value_type if value_type in {"null", "bool", "int", "float", "string"} else "string"
            stored_value = None if value is None else str(value)
            largest_value_length = max(largest_value_length, len(stored_value or ""))
            sections.setdefault(type_name, []).append({
                "type": type_name,
                "key": key,
                "value": stored_value,
                "value_type": value_type,
            })

        insert_batches = 0
        rows_written = 0
        max_value_chunks = 0
        for rows in sections.values():
            for start in range(0, len(rows), self.PROFILE_INSERT_BATCH_ROWS):
                batch = rows[start:start + self.PROFILE_INSERT_BATCH_ROWS]
                if not batch:
                    continue
                row_placeholders: list[str] = []
                params: list[Any] = []
                for row in batch:
                    value_expression, value_params = self._profile_value_sql(row["value"])
                    max_value_chunks = max(max_value_chunks, len(value_params))
                    row_placeholders.append(f"(%s, 0, %s, %s, {value_expression}, %s)")
                    params.extend([photo_id, row["type"], row["key"], *value_params, row["value_type"]])
                self._execute(
                    f"""
                    INSERT INTO photo_profile_data (
                        photo_id, revision, type, `key`, value, value_type
                    ) VALUES {", ".join(row_placeholders)}
                    """,
                    tuple(params),
                    self._profile_insert_input_sizes(params),
                )
                insert_batches += 1
                rows_written += len(batch)

        return {
            "profile_rows_written": rows_written,
            "profile_sections": len(sections),
            "profile_insert_batches": insert_batches,
            "profile_largest_value_length": largest_value_length,
            "profile_max_value_chunks": max_value_chunks,
        }

    def _profile_value_sql(self, value: Any) -> tuple[str, list[Any]]:
        if value is None or self.pyodbc is None:
            return "%s", [value]
        text = str(value)
        chunks = [
            text[start:start + self.ODBC_VALUE_CHUNK_CHARS]
            for start in range(0, len(text), self.ODBC_VALUE_CHUNK_CHARS)
        ] or [""]
        if len(chunks) == 1:
            return "%s", chunks
        return "CONCAT(" + ", ".join(["%s"] * len(chunks)) + ")", chunks

    def _profile_insert_input_sizes(self, params: list[Any]) -> list[tuple[int, int, int]] | None:
        if self.pyodbc is None:
            return None
        sql_integer = getattr(self.pyodbc, "SQL_INTEGER", 4)
        sql_varchar = getattr(self.pyodbc, "SQL_VARCHAR", 12)
        sizes: list[tuple[int, int, int]] = []
        for param in params:
            if isinstance(param, int):
                sizes.append((sql_integer, 0, 0))
            else:
                sizes.append((sql_varchar, max(1, len(str(param or ""))), 0))
        return sizes

    def defer_profile(self, photo_id: int, message: str, max_attempts: int, retry_delay_seconds: int) -> str:
        attempts_row = self._fetchone(
            "SELECT value FROM photo_profile_data WHERE photo_id = %s AND type = 'swallowtail' AND `key` = 'attempts' AND revision = 0 LIMIT 1",
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
        self._rollback_read()
        return counts

    def _set_profile_value(self, photo_id: int, type_name: str, key: str, value: Any, value_type: str) -> None:
        value_type = value_type if value_type in {"null", "bool", "int", "float", "string"} else "string"
        existing = self._fetchone(
            "SELECT id FROM photo_profile_data WHERE photo_id = %s AND type = %s AND `key` = %s AND revision = 0 LIMIT 1",
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
                photo_id, revision, type, `key`, value, value_type
            ) VALUES (
                %s, 0, %s, %s, %s, %s
            )
            """,
            (photo_id, type_name[:self.PROFILE_SECTION_MAX_CHARS], key[:self.PROFILE_KEY_MAX_CHARS], stored_value, value_type),
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
            self._rollback_read()
            return row is not None
        except Exception:
            self._rollback_read()
            return False

    def _now(self) -> datetime:
        return datetime.now().replace(microsecond=0)

    def _execute(self, sql: str, params: tuple[Any, ...] = (), input_sizes: list[tuple[int, int, int]] | None = None):
        cursor = self.connection.cursor()
        if self.paramstyle == "?":
            sql = sql.replace("%s", "?")
        if input_sizes is not None and hasattr(cursor, "setinputsizes"):
            cursor.setinputsizes(input_sizes)
        try:
            cursor.execute(sql, params)
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
            if not rows:
                return []
            if isinstance(rows[0], dict):
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
