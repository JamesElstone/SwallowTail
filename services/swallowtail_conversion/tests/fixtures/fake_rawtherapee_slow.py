#!/usr/bin/env python3
from __future__ import annotations

import sys
import time


def main() -> int:
    time.sleep(30)
    print("slow fake rawtherapee completed", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
