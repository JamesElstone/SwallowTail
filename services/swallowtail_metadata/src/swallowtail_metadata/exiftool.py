from __future__ import annotations

import json
import subprocess
from dataclasses import dataclass
from datetime import datetime, timezone, timedelta
from pathlib import Path
from typing import Any
from zoneinfo import ZoneInfo


CANON_TIMEZONE_CITY_LABELS = {
    20: "London",
}


@dataclass(frozen=True)
class MetadataResult:
    fields: dict[str, Any]
    raw: dict[str, Any]


class ExifToolRunner:
    def __init__(self, binary: str):
        self.binary = binary

    def health_check(self) -> None:
        result = subprocess.run([self.binary, "-ver"], check=False, capture_output=True, text=True, timeout=10)
        if result.returncode != 0:
            raise RuntimeError((result.stderr or result.stdout or "ExifTool did not run").strip())

    def extract(self, path: str, server_timezone: str) -> MetadataResult:
        if not Path(path).is_file():
            raise RuntimeError(f"Source CR2 file was not found: {path}")
        command = [
            self.binary,
            "-json",
            "-G1",
            "-s",
            "-n",
            "-api",
            "largefilesupport=1",
            path,
        ]
        try:
            result = subprocess.run(command, check=False, capture_output=True, text=True, timeout=60)
        except OSError as exc:
            raise RuntimeError(f"Unable to run ExifTool: {exc}") from exc
        except subprocess.TimeoutExpired as exc:
            raise RuntimeError("ExifTool timed out while reading metadata") from exc
        if result.returncode != 0:
            raise RuntimeError((result.stderr or result.stdout or "ExifTool failed").strip())
        try:
            parsed = json.loads(result.stdout or "[]")
        except json.JSONDecodeError as exc:
            raise RuntimeError("ExifTool returned invalid JSON") from exc
        if not isinstance(parsed, list) or not parsed or not isinstance(parsed[0], dict):
            raise RuntimeError("ExifTool did not return a metadata object")
        raw = parsed[0]
        return MetadataResult(fields=parse_metadata(raw, server_timezone), raw=raw)


def parse_metadata(raw: dict[str, Any], server_timezone: str) -> dict[str, Any]:
    captured_local = _exif_datetime(_tag(raw, "DateTimeOriginal", "CreateDate", "DateTime"))
    offset_minutes = _offset_minutes(_tag(raw, "Canon:TimeZone", "TimeZone"))
    city_code = _int_or_none(_tag(raw, "Canon:TimeZoneCity", "TimeZoneCity"))
    dst_minutes = _int_or_none(_tag(raw, "Canon:DaylightSavings", "DaylightSavings"))
    timezone_source = "unknown"
    captured_utc = None

    if captured_local is not None and offset_minutes is not None:
        timezone_source = "canon_makernote"
        captured_utc = captured_local.replace(
            tzinfo=timezone(timedelta(minutes=offset_minutes))
        ).astimezone(timezone.utc)
    elif captured_local is not None:
        timezone_source = "server_default"
        try:
            captured_utc = captured_local.replace(tzinfo=ZoneInfo(server_timezone)).astimezone(timezone.utc)
            offset = captured_local.replace(tzinfo=ZoneInfo(server_timezone)).utcoffset()
            offset_minutes = int(offset.total_seconds() // 60) if offset is not None else None
        except Exception:
            captured_utc = captured_local.replace(tzinfo=timezone.utc)
            offset_minutes = 0

    return {
        "captured_at_local": _sql_datetime(captured_local),
        "captured_at_utc": _sql_datetime(captured_utc),
        "captured_timezone_offset_minutes": offset_minutes,
        "captured_timezone_source": timezone_source,
        "camera_timezone_city_code": city_code,
        "camera_timezone_city_label": _city_label(city_code, _tag(raw, "Canon:TimeZoneCity", "TimeZoneCity")),
        "camera_daylight_savings_minutes": dst_minutes,
        "server_timezone_name_at_upload": server_timezone,
        "camera_make": _str_or_none(_tag(raw, "EXIF:Make", "Make")),
        "camera_model": _str_or_none(_tag(raw, "EXIF:Model", "Model")),
        "camera_serial": _str_or_none(_tag(raw, "EXIF:BodySerialNumber", "MakerNotes:SerialNumber", "SerialNumber")),
        "lens_model": _str_or_none(_tag(raw, "EXIF:LensModel", "Composite:LensID", "LensModel", "LensID")),
        "lens_serial": _str_or_none(_tag(raw, "EXIF:LensSerialNumber", "LensSerialNumber")),
        "iso": _int_or_none(_tag(raw, "EXIF:ISO", "ISO")),
        "shutter_speed": _str_or_none(_tag(raw, "EXIF:ExposureTime", "ExposureTime")),
        "aperture": _float_or_none(_tag(raw, "EXIF:FNumber", "FNumber", "Aperture")),
        "focal_length_mm": _float_or_none(_tag(raw, "EXIF:FocalLength", "FocalLength")),
        "pixel_width": _int_or_none(_tag(raw, "EXIF:ExifImageWidth", "EXIF:ImageWidth", "ExifImageWidth", "ImageWidth")),
        "pixel_height": _int_or_none(_tag(raw, "EXIF:ExifImageHeight", "EXIF:ImageHeight", "ExifImageHeight", "ImageHeight")),
        "orientation": _int_or_none(_tag(raw, "EXIF:Orientation", "Orientation")),
    }


def _tag(raw: dict[str, Any], *names: str) -> Any:
    for name in names:
        if name in raw:
            return raw[name]
    suffixes = tuple(":" + name for name in names if ":" not in name)
    for key, value in raw.items():
        if key.endswith(suffixes):
            return value
    return None


def _exif_datetime(value: Any) -> datetime | None:
    text = _str_or_none(value)
    if text is None:
        return None
    for fmt in ("%Y:%m:%d %H:%M:%S", "%Y-%m-%d %H:%M:%S"):
        try:
            return datetime.strptime(text[0:19], fmt)
        except ValueError:
            continue
    return None


def _offset_minutes(value: Any) -> int | None:
    if isinstance(value, (int, float)):
        return int(value)
    text = _str_or_none(value)
    if text is None:
        return None
    try:
        return int(float(text))
    except ValueError:
        pass
    if len(text) >= 6 and text[0] in "+-" and text[3] == ":":
        sign = 1 if text[0] == "+" else -1
        try:
            return sign * ((int(text[1:3]) * 60) + int(text[4:6]))
        except ValueError:
            return None
    return None


def _city_label(code: int | None, fallback: Any) -> str | None:
    if code is not None and code in CANON_TIMEZONE_CITY_LABELS:
        return CANON_TIMEZONE_CITY_LABELS[code]
    value = _str_or_none(fallback)
    return None if value is None or value.isdigit() else value


def _sql_datetime(value: datetime | None) -> str | None:
    if value is None:
        return None
    return value.replace(tzinfo=None).strftime("%Y-%m-%d %H:%M:%S")


def _str_or_none(value: Any) -> str | None:
    if value is None:
        return None
    text = str(value).strip()
    return text if text != "" else None


def _int_or_none(value: Any) -> int | None:
    try:
        return int(float(str(value).strip()))
    except (TypeError, ValueError):
        return None


def _float_or_none(value: Any) -> float | None:
    try:
        return float(str(value).strip().split()[0])
    except (TypeError, ValueError, IndexError):
        return None
