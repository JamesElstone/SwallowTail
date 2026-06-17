from __future__ import annotations

import time
from dataclasses import dataclass
from pathlib import Path

from .jobs import ConversionJob


@dataclass(frozen=True)
class EmbeddedJpegResult:
    temp_output_path: str
    command: list[str]
    exit_code: int
    stderr: str
    duration_seconds: float


class EmbeddedJpegExtractor:
    def extract(self, job: ConversionJob, temp_dir: str) -> EmbeddedJpegResult:
        started = time.monotonic()
        temp_path = Path(temp_dir)
        temp_path.mkdir(parents=True, exist_ok=True)
        output = temp_path / f"job-{job.id}-{job.image_type}.jpg"

        try:
            jpeg = self._largest_embedded_jpeg(Path(job.input_path))
            output.write_bytes(jpeg)
            exit_code = 0
            stderr = ""
        except Exception as exc:
            exit_code = 1
            stderr = str(exc)

        return EmbeddedJpegResult(
            temp_output_path=str(output),
            command=["embedded-jpeg-extract", job.input_path, str(output)],
            exit_code=exit_code,
            stderr=stderr,
            duration_seconds=time.monotonic() - started,
        )

    def _largest_embedded_jpeg(self, path: Path) -> bytes:
        data = path.read_bytes()
        best = b""
        start = data.find(b"\xff\xd8")
        while start != -1:
            end = data.find(b"\xff\xd9", start + 2)
            if end == -1:
                break
            candidate = data[start : end + 2]
            if self._display_jpeg_dimensions(candidate) is not None and len(candidate) > len(best):
                best = candidate
            start = data.find(b"\xff\xd8", start + 2)

        if len(best) < 4:
            raise RuntimeError(f"No displayable embedded JPEG stream was found in {path}")

        return best

    def _display_jpeg_dimensions(self, data: bytes) -> tuple[int, int] | None:
        index = 2
        while index < len(data) - 9:
            if data[index] != 0xFF:
                index += 1
                continue
            while index < len(data) and data[index] == 0xFF:
                index += 1
            if index >= len(data):
                return None

            marker = data[index]
            index += 1
            if marker == 0xD9:
                return None
            if marker == 0xD8 or 0xD0 <= marker <= 0xD7:
                continue
            if index + 2 > len(data):
                return None

            length = int.from_bytes(data[index : index + 2], "big")
            if length < 2 or index + length > len(data):
                return None

            if marker in {0xC0, 0xC2} and length >= 7:
                height = int.from_bytes(data[index + 3 : index + 5], "big")
                width = int.from_bytes(data[index + 5 : index + 7], "big")
                return (width, height) if width > 0 and height > 0 else None

            index += length

        return None
