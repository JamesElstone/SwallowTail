#!/bin/sh
#
# Run the SwallowTail verification suite with concise output by default.
# Pass --debug to print command output as each step runs.

set -u

DEBUG=0
SHOW_HELP=0

for arg in "$@"; do
	case "$arg" in
		--debug)
			DEBUG=1
			;;
		-h|--help)
			SHOW_HELP=1
			;;
		*)
			echo "Unknown argument: $arg" >&2
			SHOW_HELP=1
			;;
	esac
done

if [ "$SHOW_HELP" -eq 1 ]; then
	cat <<'EOF'
Usage: tools/run-all-tests.sh [--debug]

Runs:
  - PHP test suite
  - SpiceBush client build
  - swallowtail_conversion CLI load check and unit tests
  - swallowtail_metadata CLI load check and unit tests
  - swallowtail_storage CLI load check and unit tests

Environment overrides:
  PHP=/path/to/php
  PYTHON=/path/to/python
  CLIENT_BUILD=auto|windows|freebsd|skip
EOF
	exit 0
fi

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd) || exit 1
cd "$ROOT_DIR" || exit 1

PHP_BIN=${PHP:-php}
CLIENT_BUILD=${CLIENT_BUILD:-auto}

if [ -n "${PYTHON:-}" ]; then
	PYTHON_BIN=$PYTHON
elif command -v python3 >/dev/null 2>&1; then
	PYTHON_BIN=python3
elif command -v python >/dev/null 2>&1; then
	PYTHON_BIN=python
else
	PYTHON_BIN=
fi

TMP_PARENT=${TMPDIR:-${TMP:-/tmp}}
LOG_DIR=$(mktemp -d "$TMP_PARENT/swallowtail-tests.XXXXXX" 2>/dev/null || mktemp -d)
LOG_COUNTER=0
FAILURES=0

cleanup()
{
	rm -rf "$LOG_DIR"
}
trap cleanup EXIT HUP INT TERM

next_log()
{
	LOG_COUNTER=$((LOG_COUNTER + 1))
	printf '%s/%02d.log' "$LOG_DIR" "$LOG_COUNTER"
}

print_failure_output()
{
	log_file=$1

	if [ ! -s "$log_file" ]; then
		return
	fi

	echo "---- last output ----" >&2
	tail -n 80 "$log_file" >&2
	echo "---------------------" >&2
}

run_step()
{
	description=$1
	shift

	if [ "$DEBUG" -eq 1 ]; then
		echo "==> $description"
		"$@"
		status=$?
	else
		log_file=$(next_log)
		"$@" >"$log_file" 2>&1
		status=$?
	fi

	if [ "$status" -eq 0 ]; then
		echo "ok - $description"
	else
		echo "not ok - $description" >&2
		FAILURES=$((FAILURES + 1))
		if [ "$DEBUG" -eq 0 ]; then
			print_failure_output "$log_file"
		fi
	fi
}

run_python_service()
{
	service_path=$1
	module_name=$2
	label=$3

	run_step "$label CLI loads" env PYTHONDONTWRITEBYTECODE=1 "PYTHONPATH=$service_path/src" "$PYTHON_BIN" -m "$module_name" --help
	run_step "$label tests" env PYTHONDONTWRITEBYTECODE=1 "PYTHONPATH=$service_path/src" "$PYTHON_BIN" -m unittest discover -s "$service_path/tests"
}

run_client_build()
{
	case "$CLIENT_BUILD" in
		skip)
			echo "ok - SpiceBush client build skipped"
			return
			;;
		windows)
			run_step "SpiceBush client build" cmd.exe /c client\\spicebush\\build.cmd
			return
			;;
		freebsd)
			run_step "SpiceBush client build" sh -c 'cd client/spicebush && make -f Makefile.freebsd clean all'
			return
			;;
		auto)
			;;
		*)
			echo "not ok - unknown CLIENT_BUILD value: $CLIENT_BUILD" >&2
			FAILURES=$((FAILURES + 1))
			return
			;;
	esac

	if command -v cmd.exe >/dev/null 2>&1; then
		run_step "SpiceBush client build" cmd.exe /c client\\spicebush\\build.cmd
	elif [ "$(uname -s 2>/dev/null)" = "FreeBSD" ]; then
		run_step "SpiceBush client build" sh -c 'cd client/spicebush && make -f Makefile.freebsd clean all'
	else
		echo "not ok - SpiceBush client build; set CLIENT_BUILD=windows, freebsd, or skip" >&2
		FAILURES=$((FAILURES + 1))
	fi
}

echo "SwallowTail checks"

run_step "PHP tests" "$PHP_BIN" web_root/tests/index.php
run_client_build

if [ -z "$PYTHON_BIN" ]; then
	echo "not ok - Python executable not found; set PYTHON=/path/to/python" >&2
	FAILURES=$((FAILURES + 1))
else
	run_python_service services/swallowtail_conversion swallowtail_conversion "swallowtail_conversion"
	run_python_service services/swallowtail_metadata swallowtail_metadata "swallowtail_metadata"
	run_python_service services/swallowtail_storage swallowtail_storage "swallowtail_storage"
fi

if [ "$FAILURES" -eq 0 ]; then
	echo "All checks passed."
	exit 0
fi

echo "$FAILURES check(s) failed." >&2
exit 1
