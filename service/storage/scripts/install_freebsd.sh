#!/bin/sh
set -eu

PROJECT_ROOT=${1:-/usr/local/swallowtail}
CONFIG_DIR=${2:-/usr/local/etc/swallowtail}
CONFIG_FILE="${CONFIG_DIR}/storage.ini"
RC_FILE="/usr/local/etc/rc.d/swallowtail_storage"
RUNNER_FILE="/usr/local/libexec/swallowtail_storage_worker"
LOG_FILE="/var/log/swallowtail_storage.log"
NEWSYSLOG_FILE="/usr/local/etc/newsyslog.conf.d/swallowtail_storage.conf"

if [ ! -d "${PROJECT_ROOT}/service/storage" ]; then
  echo "Storage service was not found under ${PROJECT_ROOT}" >&2
  exit 1
fi

pkg install -y py311-pymysql py311-pyodbc redis

if ! pw usershow swallowtail >/dev/null 2>&1; then
  pw useradd swallowtail -d /var/db/swallowtail -s /usr/sbin/nologin -c "SwallowTail service user"
fi

mkdir -p "${CONFIG_DIR}"
if [ ! -f "${CONFIG_FILE}" ]; then
  cp "${PROJECT_ROOT}/service/storage/config.example.ini" "${CONFIG_FILE}"
fi
chown root:swallowtail "${CONFIG_FILE}"
chmod 0640 "${CONFIG_FILE}"

mkdir -p /var/run/swallowtail
chown -R swallowtail:swallowtail /var/run/swallowtail
touch "${LOG_FILE}"
chown swallowtail:swallowtail "${LOG_FILE}"
chmod 0640 "${LOG_FILE}"
mkdir -p /usr/local/etc/newsyslog.conf.d

sed "s#__PROJECT_ROOT__#${PROJECT_ROOT}#g" \
  "${PROJECT_ROOT}/service/storage/scripts/swallowtail_storage.in" \
  > "${RC_FILE}"
chmod 0555 "${RC_FILE}"

sed \
  -e "s#__PROJECT_ROOT__#${PROJECT_ROOT}#g" \
  -e "s#__PYTHON__#/usr/local/bin/python3.11#g" \
  -e "s#__CONFIG__#${CONFIG_FILE}#g" \
  "${PROJECT_ROOT}/service/storage/scripts/swallowtail_storage_worker.in" \
  > "${RUNNER_FILE}"
chmod 0555 "${RUNNER_FILE}"

cp "${PROJECT_ROOT}/service/storage/scripts/swallowtail_storage.newsyslog.conf" "${NEWSYSLOG_FILE}"
chmod 0644 "${NEWSYSLOG_FILE}"

sysrc swallowtail_storage_enable=YES
echo "Installed ${RC_FILE}"
echo "Installed ${RUNNER_FILE}"
echo "Installed ${NEWSYSLOG_FILE}"
echo "Review ${CONFIG_FILE}, then run: service swallowtail_storage start"
