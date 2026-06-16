#!/bin/sh
set -eu

PROJECT_ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
exec php "${PROJECT_ROOT}/tools/php/rawConversionSmokeTest.php" "$@"
