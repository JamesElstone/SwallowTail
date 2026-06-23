from __future__ import annotations

import json
import subprocess
from dataclasses import dataclass
from datetime import datetime, timezone, timedelta
from pathlib import Path
from typing import Any
from zoneinfo import ZoneInfo

from .config import MetadataConfig


CANON_TIMEZONE_CITY_LABELS = {
    20: "London",
}
PROPERTY_GROUPS = {
    "File": "file",
    "ExifIFD": "exififd",
    "Canon": "canon",
    "Composite": "composite",
}
BINARY_TAG_NAMES = {
    "DustRemovalData",
    "PreviewImage",
    "ThumbnailImage",
}


@dataclass(frozen=True)
class MetadataResult:
    fields: dict[str, Any]
    properties: list[dict[str, Any]]


class ExifToolRunner:
    def __init__(self, binary: str):
        self.binary = binary

    def health_check(self) -> None:
        result = subprocess.run([self.binary, "-ver"], check=False, capture_output=True, text=True, timeout=10)
        if result.returncode != 0:
            raise RuntimeError((result.stderr or result.stdout or "ExifTool did not run").strip())

    def extract(self, path: str, metadata: MetadataConfig) -> MetadataResult:
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
        return MetadataResult(fields=parse_metadata(raw, metadata), properties=extract_properties(raw))


def parse_metadata(raw: dict[str, Any], metadata: MetadataConfig) -> dict[str, Any]:
    captured_local = _exif_datetime(_tag(raw, "DateTimeOriginal", "CreateDate", "DateTime"))
    offset_minutes = _offset_minutes(_tag(raw, "Canon:TimeZone", "TimeZone"))
    city_code = _int_or_none(_tag(raw, "Canon:TimeZoneCity", "TimeZoneCity"))
    dst_minutes = _daylight_savings_minutes(_tag(raw, "Canon:DaylightSavings", "DaylightSavings"))
    captured_utc = None

    if captured_local is not None and offset_minutes is not None:
        offset_minutes -= dst_minutes or 0
        if _configured_daylight_saving_applies(captured_local, metadata):
            offset_minutes += metadata.daylight_saving.offset_minutes
        captured_utc = captured_local.replace(
            tzinfo=timezone(timedelta(minutes=offset_minutes))
        ).astimezone(timezone.utc)
    elif captured_local is not None:
        try:
            captured_utc = captured_local.replace(tzinfo=ZoneInfo(metadata.server_timezone)).astimezone(timezone.utc)
        except Exception:
            captured_utc = captured_local.replace(tzinfo=timezone.utc)

    return {
        "captured_at_local": _sql_datetime(captured_local),
        "camera_timezone_city_code": city_code,
        "camera_timezone_city_label": _city_label(city_code, _tag(raw, "Canon:TimeZoneCity", "TimeZoneCity")),
        "camera_daylight_savings_minutes": dst_minutes,
        "captured_at_utc": _sql_datetime(captured_utc),
        "camera_make": _str_or_none(_tag(raw, "EXIF:Make", "Make")),
        "camera_model": _str_or_none(_tag(raw, "EXIF:Model", "Model")),
        "camera_serial": _str_or_none(_tag(raw, "EXIF:BodySerialNumber", "MakerNotes:SerialNumber", "SerialNumber")),
        "lens_model": _str_or_none(_tag(raw, "EXIF:LensModel", "ExifIFD:LensModel", "Canon:LensModel", "LensModel")),
        "lens_serial": _str_or_none(_tag(raw, "EXIF:LensSerialNumber", "LensSerialNumber")),
        "iso": _int_or_none(_tag(raw, "EXIF:ISO", "ISO")),
        "shutter_speed": _str_or_none(_tag(raw, "EXIF:ExposureTime", "ExposureTime")),
        "aperture": _float_or_none(_tag(raw, "EXIF:FNumber", "FNumber", "Aperture")),
        "focal_length_mm": _float_or_none(_tag(raw, "EXIF:FocalLength", "FocalLength")),
        "pixel_width": _int_or_none(_tag(raw, "EXIF:ExifImageWidth", "EXIF:ImageWidth", "ExifImageWidth", "ImageWidth")),
        "pixel_height": _int_or_none(_tag(raw, "EXIF:ExifImageHeight", "EXIF:ImageHeight", "ExifImageHeight", "ImageHeight")),
        "orientation": _int_or_none(_tag(raw, "EXIF:Orientation", "Orientation")),
    }


def extract_properties(raw: dict[str, Any]) -> list[dict[str, Any]]:
    properties: list[dict[str, Any]] = []
    for raw_key, value in raw.items():
        if ":" not in raw_key:
            continue
        group, tag = raw_key.split(":", 1)
        property_type = PROPERTY_GROUPS.get(group)
        if property_type is None or tag in BINARY_TAG_NAMES or _is_binary_placeholder(value):
            continue
        value_type = _property_value_type(value)
        if value_type is None:
            continue
        properties.append({
            "type": property_type,
            "key": tag,
            "value": _property_value_text(value, value_type),
            "value_type": value_type,
        })
    return properties


def _configured_daylight_saving_applies(captured_local: datetime, metadata: MetadataConfig) -> bool:
    daylight_saving = metadata.daylight_saving
    if not daylight_saving.enabled:
        return False
    current = _month_day_number(captured_local.strftime("%m-%d"))
    start = _month_day_number(daylight_saving.start)
    end = _month_day_number(daylight_saving.end)
    if current is None or start is None or end is None:
        return False
    if start <= end:
        return start <= current <= end
    return current >= start or current <= end


def _month_day_number(value: str) -> int | None:
    if len(value) != 5 or value[2] != "-":
        return None
    try:
        return (int(value[0:2]) * 100) + int(value[3:5])
    except ValueError:
        return None


def _property_value_type(value: Any) -> str | None:
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "bool"
    if isinstance(value, int):
        return "int"
    if isinstance(value, float):
        return "float"
    if isinstance(value, str):
        return "string"
    return None


def _property_value_text(value: Any, value_type: str) -> str | None:
    if value_type == "null":
        return None
    if value_type == "bool":
        return "1" if value else "0"
    return str(value)


def _is_binary_placeholder(value: Any) -> bool:
    text = _str_or_none(value)
    return text is not None and text.startswith("(Binary data ") and " use -b option " in text


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


def _daylight_savings_minutes(value: Any) -> int | None:
    minutes = _int_or_none(value)
    if minutes is None:
        return None
    if minutes in (-1, 1):
        return minutes * 60
    return minutes


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
