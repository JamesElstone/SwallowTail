#!/bin/sh
set -eu

PROJECT_ROOT=${1:-/usr/local/swallowtail}
PYTHON=${PYTHON:-/usr/local/bin/python3.11}
PHP=${PHP:-/usr/local/bin/php}
RC_FILE="/usr/local/etc/rc.d/swallowtail_storage"
STALE_RUNNER_FILE="/usr/local/libexec/swallowtail_storage_worker"
LOG_DIR="/var/log/swallowtail"
LOG_FILE="${LOG_DIR}/swallowtail_storage.log"
NEWSYSLOG_FILE="/usr/local/etc/newsyslog.conf.d/swallowtail_storage.conf"
TEMPLATE_DIR="${PROJECT_ROOT}/FreeBSD/files"

if [ ! -d "${PROJECT_ROOT}/services/swallowtail_storage" ]; then
  echo "Storage service was not found under ${PROJECT_ROOT}" >&2
  exit 1
fi

pkg install -y python311 redis

if ! pw usershow swallowtail >/dev/null 2>&1; then
  pw useradd swallowtail -d /var/db/swallowtail -s /usr/sbin/nologin -c "SwallowTail service user"
fi

mkdir -p /var/run/swallowtail "${LOG_DIR}/trace"
chown -R swallowtail:swallowtail /var/run/swallowtail "${LOG_DIR}"
chmod 2775 "${LOG_DIR}" "${LOG_DIR}/trace"
touch "${LOG_FILE}"
chown swallowtail:swallowtail "${LOG_FILE}"
chmod 0640 "${LOG_FILE}"
mkdir -p /usr/local/etc/newsyslog.conf.d

sed \
  -e "s#%%SWALLOWTAIL_ROOT%%#${PROJECT_ROOT}#g" \
  -e "s#%%PYTHON_CMD%%#${PYTHON}#g" \
  -e "s#%%PHP_CMD%%#${PHP}#g" \
  -e "s#%%SWALLOWTAIL_LOG_DIR%%#${LOG_DIR}#g" \
  "${TEMPLATE_DIR}/swallowtail_storage.in" \
  > "${RC_FILE}"
chmod 0555 "${RC_FILE}"

rm -f "${STALE_RUNNER_FILE}"

cp "${TEMPLATE_DIR}/swallowtail_storage.newsyslog.conf" "${NEWSYSLOG_FILE}"
chmod 0644 "${NEWSYSLOG_FILE}"

sysrc swallowtail_storage_enable=YES
echo "Installed ${RC_FILE}"
echo "Installed ${NEWSYSLOG_FILE}"
echo "Optional rc.conf tunables: swallowtail_storage_project_root, swallowtail_storage_python, swallowtail_storage_php, swallowtail_storage_interval_seconds, swallowtail_storage_mount_poll_seconds, swallowtail_storage_migration_item_limit, swallowtail_storage_log, swallowtail_storage_log_level"
echo "Run: service swallowtail_storage start"
