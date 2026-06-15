#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path


def main() -> int:
    output = None
    for index, arg in enumerate(sys.argv):
        if arg == "-o" and index + 1 < len(sys.argv):
            output = sys.argv[index + 1]
            break
    if output is None:
        print("missing -o", file=sys.stderr)
        return 2
    path = Path(output)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(b"\xff\xd8fake-jpeg\xff\xd9")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
