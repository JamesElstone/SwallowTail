#!/usr/bin/env python3
from __future__ import annotations

import sys


def main() -> int:
    print("fake rawtherapee failure", file=sys.stderr)
    return 17


if __name__ == "__main__":
    raise SystemExit(main())
