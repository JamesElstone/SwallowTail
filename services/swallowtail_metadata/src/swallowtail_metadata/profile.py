from __future__ import annotations

import os
import shutil
import subprocess
import uuid
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
        version = self._version_output(result)
        if result.returncode != 0 and "rawtherapee" not in version.lower():
            raise RuntimeError(version or "RawTherapee did not run")

    def generate(self, source_path: Path, baseline_path: Path) -> BaselineResult:
        baseline_path.parent.mkdir(parents=True, exist_ok=True)
        scratch = baseline_path.with_name(baseline_path.stem + "_scratch.jpg")
        command = [self.binary, "-Y", "-O", str(scratch), "-j1", "-c", str(source_path)]
        runtime_path = self._create_runtime_path(baseline_path)
        try:
            env = self._runtime_environment(runtime_path)
            result = subprocess.run(command, capture_output=True, text=True, timeout=120, check=False, env=env)
        finally:
            shutil.rmtree(runtime_path, ignore_errors=True)
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

    def _create_runtime_path(self, baseline_path: Path) -> Path:
        prefix = baseline_path.stem[0:16] + "_rt_"
        for _attempt in range(10):
            runtime_path = baseline_path.with_name(prefix + uuid.uuid4().hex[0:8])
            try:
                runtime_path.mkdir()
                return runtime_path
            except FileExistsError:
                continue
        raise RuntimeError("Unable to create a unique RawTherapee runtime directory.")

    def _runtime_environment(self, runtime_path: Path) -> dict[str, str]:
        config_home = runtime_path / "config"
        cache_home = runtime_path / "cache"
        config_home.mkdir(parents=True, exist_ok=True)
        cache_home.mkdir(parents=True, exist_ok=True)
        env = os.environ.copy()
        env.update({
            "HOME": str(runtime_path),
            "XDG_CONFIG_HOME": str(config_home),
            "XDG_CACHE_HOME": str(cache_home),
        })
        return env

    def version(self) -> str:
        try:
            result = subprocess.run([self.binary, "--version"], capture_output=True, text=True, timeout=10, check=False)
        except Exception:
            return ""
        version = self._version_output(result)
        return version[:191] if "rawtherapee" in version.lower() else ""

    def _version_output(self, result: subprocess.CompletedProcess[str]) -> str:
        return (result.stdout or result.stderr or "").strip().splitlines()[0] if (result.stdout or result.stderr or "").strip() else ""

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
