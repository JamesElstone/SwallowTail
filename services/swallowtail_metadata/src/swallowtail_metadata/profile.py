from __future__ import annotations

import subprocess
from dataclasses import dataclass
from pathlib import Path
from typing import Any


@dataclass(frozen=True)
class BaselineResult:
    command: list[str]
    stderr: str
    version: str


class RawTherapeeBaselineRunner:
    def __init__(self, binary: str):
        self.binary = binary

    def health_check(self) -> None:
        result = subprocess.run([self.binary, "--version"], capture_output=True, text=True, timeout=10, check=False)
        if result.returncode != 0:
            raise RuntimeError((result.stderr or result.stdout or "RawTherapee did not run").strip())

    def generate(self, source_path: Path, baseline_path: Path) -> BaselineResult:
        baseline_path.parent.mkdir(parents=True, exist_ok=True)
        scratch = baseline_path.with_name(baseline_path.stem + "_scratch.jpg")
        command = [self.binary, "-Y", "-O", str(scratch), "-j1", "-c", str(source_path)]
        result = subprocess.run(command, capture_output=True, text=True, timeout=120, check=False)
        stderr = (result.stderr or result.stdout or "").strip()
        if result.returncode != 0:
            raise RuntimeError(stderr or "RawTherapee baseline generation failed.")

        candidate = self._find_profile_output(scratch)
        if candidate is None:
            raise RuntimeError("RawTherapee did not write a baseline PP3 profile.")
        candidate.replace(baseline_path)

        for path in [scratch, *scratch.parent.glob(scratch.stem + "*")]:
            if path != baseline_path and path.exists() and path.suffix.lower() in {".jpg", ".jpeg", ".tif", ".tiff", ".png"}:
                try:
                    path.unlink()
                except OSError:
                    pass

        return BaselineResult(command=command, stderr=stderr, version=self.version())

    def version(self) -> str:
        try:
            result = subprocess.run([self.binary, "--version"], capture_output=True, text=True, timeout=10, check=False)
        except Exception:
            return ""
        return (result.stdout or result.stderr or "").strip().splitlines()[0][:191] if result.returncode == 0 else ""

    def _find_profile_output(self, scratch: Path) -> Path | None:
        candidates = [
            scratch.with_suffix(scratch.suffix + ".pp3"),
            scratch.with_suffix(".pp3"),
            scratch.parent / (scratch.name + ".pp3"),
        ]
        candidates.extend(sorted(scratch.parent.glob(scratch.stem + "*.pp3")))
        for candidate in candidates:
            if candidate.is_file():
                return candidate
        return None


def parse_pp3_properties(contents: str) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    section = ""
    for raw_line in contents.splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or line.startswith(";"):
            continue
        if line.startswith("[") and line.endswith("]"):
            section = line[1:-1]
            continue
        if section == "" or "=" not in line:
            continue
        key, value = line.split("=", 1)
        value = value.strip()
        rows.append({
            "type": section[:32],
            "key": key.strip()[:191],
            "value": value,
            "value_type": _value_type(value),
        })
    return rows


def _value_type(value: str) -> str:
    text = value.strip().lower()
    if text == "":
        return "string"
    if text in {"true", "false"}:
        return "bool"
    try:
        int(value)
        return "int"
    except ValueError:
        pass
    try:
        float(value)
        return "float"
    except ValueError:
        return "string"
