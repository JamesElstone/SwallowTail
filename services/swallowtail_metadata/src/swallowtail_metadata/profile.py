from __future__ import annotations

import os
import hashlib
import shutil
import subprocess
import uuid
from dataclasses import dataclass
from pathlib import Path
from typing import Any

PP3_SECTION_MAX_CHARS = 64
PP3_KEY_MAX_CHARS = 191


@dataclass(frozen=True)
class BaselineResult:
    command: list[str]
    stderr: str
    version: str


@dataclass(frozen=True)
class RawTheapeeProfileRow:
    profile_path: str
    relative_path: str
    display_label: str
    profile_hash: str
    profile_bytes: int
    profile_mtime: int
    profile_content: str


class RawTheapeeProfileScanner:
    def __init__(self, root: str):
        self.root = Path(root)

    def scan(self) -> list[RawTheapeeProfileRow]:
        if not self.root.is_dir():
            return []

        rows: list[RawTheapeeProfileRow] = []
        for path in sorted(self.root.rglob("*.pp3"), key=lambda item: str(item).lower()):
            if not path.is_file():
                continue
            try:
                content = path.read_text(encoding="utf-8")
            except UnicodeDecodeError:
                content = path.read_text(encoding="utf-8", errors="replace")
            relative = path.relative_to(self.root).as_posix()
            stat = path.stat()
            rows.append(
                RawTheapeeProfileRow(
                    profile_path=str(path),
                    relative_path=relative,
                    display_label=self.display_label(relative),
                    profile_hash=hashlib.sha256(content.encode("utf-8")).hexdigest(),
                    profile_bytes=int(stat.st_size),
                    profile_mtime=int(stat.st_mtime),
                    profile_content=content,
                )
            )
        return rows

    @staticmethod
    def display_label(relative_path: str) -> str:
        parts = Path(relative_path).with_suffix("").parts
        labels = []
        for part in parts:
            words = [word for word in part.replace("_", "-").split("-") if word]
            labels.append("-".join(word[:1].upper() + word[1:].lower() for word in words) if words else part)
        return " :: ".join(labels)


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
        command = [self.binary, "-q", "-Y", "-O", str(scratch), "-j1", "-c", str(source_path)]
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
            "type": section[:PP3_SECTION_MAX_CHARS],
            "key": key.strip()[:PP3_KEY_MAX_CHARS],
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
