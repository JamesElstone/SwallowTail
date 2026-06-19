#!/bin/sh
#
# Refresh the local FreeBSD port overlay from GitHub and rebuild/reinstall it.

set -eu

if [ "$(id -u)" -ne 0 ]; then
	echo "This script must be run as root." >&2
	exit 1
fi

PORT_DIR=${SWALLOWTAIL_FREEBSD_PORT_DIR:-/usr/ports/graphics/SwallowTail}
PORTS_TREE_DIR=${SWALLOWTAIL_FREEBSD_PORTS_TREE_DIR:-/usr/ports}
BLOB_BASE_URL=${SWALLOWTAIL_FREEBSD_BLOB_BASE_URL:-https://github.com/JamesElstone/SwallowTail/blob/main/FreeBSD}
RAW_BASE_URL=${SWALLOWTAIL_FREEBSD_RAW_BASE_URL:-https://raw.githubusercontent.com/JamesElstone/SwallowTail/main/FreeBSD}
PORT_FILES='
Makefile
distinfo
pkg-descr
files/ext-30-pdo_odbc.ini
files/pkg-install.in
files/pkg-message.in
files/swallowtail-fix-storage-permissions.in
files/swallowtail-sudoers.in
files/swallowtail_conversion.in
files/swallowtail_conversion.newsyslog.conf
files/swallowtail_storage.in
files/swallowtail_storage.newsyslog.conf
files/swallowtail-apache.conf.in
files/swallowtail-php-fpm.conf.in
'
SERVICE_NAMES='swallowtail_conversion swallowtail_storage'

case "$PORT_DIR" in
	""|"/")
		echo "Refusing unsafe port directory: $PORT_DIR" >&2
		exit 1
		;;
esac

TMP_PARENT=${TMPDIR:-/tmp}
DOWNLOAD_DIR=$(mktemp -d "$TMP_PARENT/swallowtail-freebsd-port.XXXXXX") || exit 1

cleanup()
{
	rm -rf "$DOWNLOAD_DIR"
}
trap cleanup EXIT HUP INT TERM

fetch_file()
{
	file_path=$1
	output_path=$DOWNLOAD_DIR/$file_path
	output_dir=$(dirname "$output_path")
	raw_url=$RAW_BASE_URL/$file_path

	mkdir -p "$output_dir"
	echo "==> fetching $BLOB_BASE_URL/$file_path"

	if command -v fetch >/dev/null 2>&1; then
		fetch -q -o "$output_path" "$raw_url"
	elif command -v curl >/dev/null 2>&1; then
		curl -fsSL -o "$output_path" "$raw_url"
	elif command -v wget >/dev/null 2>&1; then
		wget -q -O "$output_path" "$raw_url"
	else
		echo "fetch, curl, or wget is required to download port files" >&2
		exit 1
	fi
}

restart_or_start_service()
{
	service_name=$1

	if service "$service_name" onestatus >/dev/null 2>&1; then
		echo "==> restarting $service_name"
		service "$service_name" onerestart
	else
		echo "==> starting $service_name"
		service "$service_name" onestart || {
			echo "==> start did not complete; restarting $service_name"
			service "$service_name" onerestart
		}
	fi
}

if [ -f "$PORT_DIR/Makefile" ]; then
	echo "==> make distclean in $PORT_DIR"
	( cd "$PORT_DIR" && make distclean )
fi

echo "==> git pull --ff-only in $PORTS_TREE_DIR"
( cd "$PORTS_TREE_DIR" && git pull --ff-only )

for port_file in $PORT_FILES; do
	fetch_file "$port_file"
done

echo "==> refreshing $PORT_DIR from downloaded FreeBSD files"
mkdir -p "$PORT_DIR"
find "$PORT_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
( cd "$DOWNLOAD_DIR" && tar -cf - . ) | ( cd "$PORT_DIR" && tar -xf - )

echo "==> make clean in $PORT_DIR"
( cd "$PORT_DIR" && make clean )

echo "==> make makesum in $PORT_DIR"
( cd "$PORT_DIR" && make makesum )

echo "==> make in $PORT_DIR"
( cd "$PORT_DIR" && make )

echo "==> make reinstall in $PORT_DIR"
( cd "$PORT_DIR" && make reinstall )

for service_name in $SERVICE_NAMES; do
	restart_or_start_service "$service_name"
done

echo "==> installed swallowtail package version"
pkg query %v swallowtail
