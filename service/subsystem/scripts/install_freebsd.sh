#!/bin/sh
set -eu

PROJECT_ROOT=${1:-/usr/local/swallowtail}
RC_FILE="/usr/local/etc/rc.d/swallowtail_subsystem"
RUNNER_FILE="/usr/local/libexec/swallowtail_subsystem_worker"
LOG_FILE="/var/log/swallowtail_subsystem.log"
NEWSYSLOG_FILE="/usr/local/etc/newsyslog.conf.d/swallowtail_subsystem.conf"

if [ ! -d "${PROJECT_ROOT}/service/subsystem" ]; then
  echo "Subsystem service was not found under ${PROJECT_ROOT}" >&2
  exit 1
fi

pkg install -y py311-pymysql py311-pyodbc

if ! pw usershow swallowtail >/dev/null 2>&1; then
  pw useradd swallowtail -d /var/db/swallowtail -s /usr/sbin/nologin -c "SwallowTail service user"
fi

mkdir -p /var/db/swallowtail-raw-conversion /var/tmp/swallowtail-raw-conversion
mkdir -p /var/run/swallowtail
chown -R swallowtail:swallowtail /var/db/swallowtail-raw-conversion /var/tmp/swallowtail-raw-conversion /var/run/swallowtail
touch "${LOG_FILE}"
chown swallowtail:swallowtail "${LOG_FILE}"
chmod 0640 "${LOG_FILE}"
mkdir -p /usr/local/etc/newsyslog.conf.d

sed "s#__PROJECT_ROOT__#${PROJECT_ROOT}#g" \
  "${PROJECT_ROOT}/service/subsystem/scripts/swallowtail_subsystem.in" \
  > "${RC_FILE}"
chmod 0555 "${RC_FILE}"

sed \
  -e "s#__PROJECT_ROOT__#${PROJECT_ROOT}#g" \
  -e "s#__PYTHON__#/usr/local/bin/python3.11#g" \
  "${PROJECT_ROOT}/service/subsystem/scripts/swallowtail_subsystem_worker.in" \
  > "${RUNNER_FILE}"
chmod 0555 "${RUNNER_FILE}"

cp "${PROJECT_ROOT}/service/subsystem/scripts/swallowtail_subsystem.newsyslog.conf" "${NEWSYSLOG_FILE}"
chmod 0644 "${NEWSYSLOG_FILE}"

sysrc swallowtail_subsystem_enable=YES
echo "Installed ${RC_FILE}"
echo "Installed ${RUNNER_FILE}"
echo "Installed ${NEWSYSLOG_FILE}"
echo "Override swallowtail_subsystem_* defaults in /etc/rc.conf, then run: service swallowtail_subsystem start"
