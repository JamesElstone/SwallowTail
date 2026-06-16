from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Any


DERIVATIVE_TYPES = {"embedded", "original_jpeg", "preview", "thumbnail", "jpeg"}


@dataclass(frozen=True)
class ConversionJob:
    id: int
    photo_id: int
    derivative_type: str
    input_path: str
    pp3_path: str | None
    output_path: str
    output_storage_path: str
    output_storage_location_id: int | None
    profile_version: int
    attempts: int
    output_width: int | None = None
    output_height: int | None = None

    @classmethod
    def from_row(cls, row: dict[str, Any]) -> "ConversionJob":
        return cls(
            id=int(row["id"]),
            photo_id=int(row["photo_id"]),
            derivative_type=str(row["derivative_type"] or ""),
            input_path=str(row["input_path"] or ""),
            pp3_path=str(row["pp3_path"]) if row.get("pp3_path") else None,
            output_path=str(row["output_path"] or ""),
            output_storage_path=str(row["output_storage_path"] or ""),
            output_storage_location_id=int(row["output_storage_location_id"]) if row.get("output_storage_location_id") else None,
            profile_version=max(1, int(row.get("profile_version") or 1)),
            attempts=int(row.get("attempts") or 0),
            output_width=cls._positive_int_or_none(row.get("output_width")),
            output_height=cls._positive_int_or_none(row.get("output_height")),
        )

    @staticmethod
    def _positive_int_or_none(value: Any) -> int | None:
        if value is None or value == "":
            return None
        parsed = int(value)
        return parsed if parsed > 0 else None

    def validate(self) -> None:
        if self.derivative_type not in DERIVATIVE_TYPES:
            raise RuntimeError(f"Unsupported derivative type: {self.derivative_type}")
        input_path = Path(self.input_path)
        if input_path.suffix.lower() != ".cr2":
            raise RuntimeError("Raw conversion worker v1 only supports CR2 inputs.")
        if not input_path.is_file():
            raise RuntimeError(f"Input CR2 file was not found: {self.input_path}")
        if self.pp3_path and not Path(self.pp3_path).is_file():
            raise RuntimeError(f"PP3 profile was not found: {self.pp3_path}")
        output_parent = Path(self.output_path).parent
        if not output_parent.exists():
            output_parent.mkdir(parents=True, exist_ok=True)
        if (self.output_width is None) != (self.output_height is None):
            raise RuntimeError("Both output_width and output_height must be set together.")
