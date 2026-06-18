#!/bin/sh
#
# Refresh the local FreeBSD port overlay and rebuild/reinstall it.

set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd) || exit 1
SOURCE_DIR=$ROOT_DIR/FreeBSD
PORT_DIR=${SWALLOWTAIL_FREEBSD_PORT_DIR:-/usr/local/graphics/SwallowTail}

case "$PORT_DIR" in
	""|"/")
		echo "Refusing unsafe port directory: $PORT_DIR" >&2
		exit 1
		;;
esac

if [ ! -d "$SOURCE_DIR" ]; then
	echo "FreeBSD source directory not found: $SOURCE_DIR" >&2
	exit 1
fi

if [ -f "$PORT_DIR/Makefile" ]; then
	echo "==> make distclean in $PORT_DIR"
	( cd "$PORT_DIR" && make distclean )
fi

echo "==> refreshing $PORT_DIR from $SOURCE_DIR"
mkdir -p "$PORT_DIR"
find "$PORT_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
( cd "$SOURCE_DIR" && tar -cf - . ) | ( cd "$PORT_DIR" && tar -xf - )

echo "==> make clean in $PORT_DIR"
( cd "$PORT_DIR" && make clean )

echo "==> make in $PORT_DIR"
( cd "$PORT_DIR" && make )

echo "==> make reinstall in $PORT_DIR"
( cd "$PORT_DIR" && make reinstall )
