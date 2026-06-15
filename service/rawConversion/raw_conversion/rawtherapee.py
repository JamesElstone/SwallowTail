from __future__ import annotations

import os
import shutil
import subprocess
import sys
import time
from dataclasses import dataclass
from pathlib import Path

from .config import RawTherapeeConfig
from .jobs import ConversionJob


@dataclass(frozen=True)
class RenderResult:
    temp_output_path: str
    command: list[str]
    exit_code: int
    stderr: str
    duration_seconds: float


class RawTherapeeRunner:
    def __init__(self, config: RawTherapeeConfig):
        self.config = config

    def render(self, job: ConversionJob, temp_dir: str) -> RenderResult:
        binary = shutil.which(self.config.binary) or self.config.binary
        temp_output = str(Path(temp_dir) / f"job-{job.id}-{job.derivative_type}.jpg")
        command = [binary]
        if binary.endswith(".py"):
            command = [sys.executable, binary]

        command.extend(["-Y", "-o", temp_output, "-j85"])

        if job.pp3_path:
            command.extend(["-p", job.pp3_path])

        command.extend(["-c", job.input_path])

        env = os.environ.copy()
        env["HOME"] = self.config.home

        started = time.monotonic()
        completed = subprocess.run(command, capture_output=True, text=True, env=env, check=False)
        duration = time.monotonic() - started

        stderr = (completed.stderr or completed.stdout or "").strip()
        return RenderResult(
            temp_output_path=temp_output,
            command=command,
            exit_code=completed.returncode,
            stderr=stderr[-4000:],
            duration_seconds=duration,
        )
