#!/bin/sh
set -eu

PROJECT_ROOT=${1:-/usr/local/swallowtail}
CONFIG_DIR=${2:-/usr/local/etc/swallowtail}
CONFIG_FILE="${CONFIG_DIR}/raw-conversion.ini"
RC_FILE="/usr/local/etc/rc.d/swallowtail_raw_conversion"

if [ ! -d "${PROJECT_ROOT}/service/rawConversion" ]; then
  echo "Raw conversion service was not found under ${PROJECT_ROOT}" >&2
  exit 1
fi

pkg install -y py311-pymysql py311-pyodbc

mkdir -p "${CONFIG_DIR}"
if [ ! -f "${CONFIG_FILE}" ]; then
  cp "${PROJECT_ROOT}/service/rawConversion/config.example.ini" "${CONFIG_FILE}"
fi
chown root:swallowtail "${CONFIG_FILE}"
chmod 0640 "${CONFIG_FILE}"

if ! pw usershow swallowtail >/dev/null 2>&1; then
  pw useradd swallowtail -d /var/db/swallowtail -s /usr/sbin/nologin -c "SwallowTail service user"
fi

mkdir -p /var/db/swallowtail-raw-conversion /var/tmp/swallowtail-raw-conversion
mkdir -p /var/run/swallowtail
chown -R swallowtail:swallowtail /var/db/swallowtail-raw-conversion /var/tmp/swallowtail-raw-conversion /var/run/swallowtail

sed "s#__PROJECT_ROOT__#${PROJECT_ROOT}#g" \
  "${PROJECT_ROOT}/service/rawConversion/scripts/swallowtail_raw_conversion.in" \
  > "${RC_FILE}"
chmod 0555 "${RC_FILE}"

sysrc swallowtail_raw_conversion_enable=YES
echo "Installed ${RC_FILE}"
echo "Review ${CONFIG_FILE}, then run: service swallowtail_raw_conversion start"
