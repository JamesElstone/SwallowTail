from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Any


IMAGE_TYPES = {"embedded", "original", "preview", "final"}


@dataclass(frozen=True)
class ConversionJob:
    id: int
    photo_id: int
    image_type: str
    input_path: str
    profile_path: str | None
    output_path: str
    profile_version: int
    attempts: int
    priority: int = 0
    output_width: int | None = None
    output_height: int | None = None

    @classmethod
    def from_row(cls, row: dict[str, Any]) -> "ConversionJob":
        return cls(
            id=int(row["id"]),
            photo_id=int(row["photo_id"]),
            image_type=str(row["image_type"] or ""),
            input_path=str(row["input_path"] or ""),
            profile_path=str(row["profile_path"]) if row.get("profile_path") else None,
            output_path=str(row["output_path"] or ""),
            profile_version=max(1, int(row.get("profile_version") or 1)),
            attempts=int(row.get("attempts") or 0),
            priority=int(row.get("priority") or 0),
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
        if self.image_type not in IMAGE_TYPES:
            raise RuntimeError(f"Unsupported image type: {self.image_type}")
        input_path = Path(self.input_path)
        if input_path.suffix.lower() != ".cr2":
            raise RuntimeError("Raw conversion worker v1 only supports CR2 inputs.")
        if not input_path.is_file():
            raise RuntimeError(f"Input CR2 file was not found: {self.input_path}")
        if self.profile_path and not Path(self.profile_path).is_file():
            raise RuntimeError(f"PP3 profile was not found: {self.profile_path}")
        output_parent = Path(self.output_path).parent
        if not output_parent.exists():
            output_parent.mkdir(parents=True, exist_ok=True)
        if (self.output_width is None) != (self.output_height is None):
            raise RuntimeError("Both output_width and output_height must be set together.")
