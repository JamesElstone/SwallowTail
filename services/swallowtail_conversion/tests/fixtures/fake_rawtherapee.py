#!/usr/bin/env python3
from __future__ import annotations

import sys
import os
from pathlib import Path


def main() -> int:
    if os.environ.get("SWALLOWTAIL_FAKE_RAWTHERAPEE_FAIL") == "1":
        print("fake rawtherapee failure", file=sys.stderr)
        return 17

    output = None
    output_profile = False
    for index, arg in enumerate(sys.argv):
        if arg in {"-o", "-O"} and index + 1 < len(sys.argv):
            output = sys.argv[index + 1]
            output_profile = arg == "-O"
            break
    if output is None:
        print("missing -o/-O", file=sys.stderr)
        return 2
    path = Path(output)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(b"\xff\xd8fake-jpeg\xff\xd9")
    if output_profile:
        path.with_suffix(path.suffix + ".pp3").write_text("[Version]\nAppVersion=5.12\n", encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
