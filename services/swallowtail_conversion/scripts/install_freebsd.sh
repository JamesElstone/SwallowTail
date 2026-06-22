#!/bin/sh
set -eu

PROJECT_ROOT=${1:-/usr/local/swallowtail}
PYTHON=${PYTHON:-/usr/local/bin/python3.11}
PHP=${PHP:-/usr/local/bin/php}
PREFIX=${PREFIX:-/usr/local}
RC_FILE="/usr/local/etc/rc.d/swallowtail_conversion"
STALE_RUNNER_FILE="/usr/local/libexec/swallowtail_conversion_worker"
LOG_DIR="/var/log/swallowtail"
LOG_FILE="${LOG_DIR}/swallowtail_conversion.log"
NEWSYSLOG_FILE="/usr/local/etc/newsyslog.conf.d/swallowtail_conversion.conf"
TEMPLATE_DIR="${PROJECT_ROOT}/FreeBSD/files"
APP_CONFIG_FILE="${PROJECT_ROOT}/secure/app.php"

if [ ! -d "${PROJECT_ROOT}/services/swallowtail_conversion" ]; then
  echo "Conversion service was not found under ${PROJECT_ROOT}" >&2
  exit 1
fi

pkg install -y py311-pymysql py311-pyodbc

if ! pw usershow swallowtail >/dev/null 2>&1; then
  pw useradd swallowtail -d /var/db/swallowtail -s /usr/sbin/nologin -c "SwallowTail service user"
fi

mkdir -p /var/db/swallowtail_conversion /var/tmp/swallowtail_conversion
mkdir -p /var/run/swallowtail "${LOG_DIR}/trace"
chown -R swallowtail:swallowtail /var/db/swallowtail_conversion /var/tmp/swallowtail_conversion /var/run/swallowtail "${LOG_DIR}"
chmod 2775 "${LOG_DIR}" "${LOG_DIR}/trace"
touch "${LOG_FILE}"
chown swallowtail:swallowtail "${LOG_FILE}"
chmod 0640 "${LOG_FILE}"
mkdir -p /usr/local/etc/newsyslog.conf.d

sed \
  -e "s#%%SWALLOWTAIL_ROOT%%#${PROJECT_ROOT}#g" \
  -e "s#%%PYTHON_CMD%%#${PYTHON}#g" \
  -e "s#%%PHP_CMD%%#${PHP}#g" \
  -e "s#%%PREFIX%%#${PREFIX}#g" \
  -e "s#%%SWALLOWTAIL_LOG_DIR%%#${LOG_DIR}#g" \
  "${TEMPLATE_DIR}/swallowtail_conversion.in" \
  > "${RC_FILE}"
chmod 0555 "${RC_FILE}"

rm -f "${STALE_RUNNER_FILE}"

cp "${TEMPLATE_DIR}/swallowtail_conversion.newsyslog.conf" "${NEWSYSLOG_FILE}"
chmod 0644 "${NEWSYSLOG_FILE}"

if [ -f "${APP_CONFIG_FILE}" ]; then
  chown www:swallowtail "${APP_CONFIG_FILE}"
  chmod 0640 "${APP_CONFIG_FILE}"
fi

sysrc swallowtail_conversion_enable=YES
echo "Installed ${RC_FILE}"
echo "Installed ${NEWSYSLOG_FILE}"
echo "Database settings are read from ${PROJECT_ROOT}/secure/app.php"
echo "Override service-specific swallowtail_conversion_* defaults in /etc/rc.conf, then run: service swallowtail_conversion start"
