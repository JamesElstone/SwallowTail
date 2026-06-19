PORTNAME=	swallowtail
DISTVERSION=	g20260618
PORTREVISION=	24
CATEGORIES=	graphics www

MAINTAINER=	james@elstone.net
COMMENT=	SwallowTail photo application and background services
WWW=		https://github.com/JamesElstone/SwallowTail

LICENSE=	BSD3CLAUSE

RUN_DEPENDS=	${PYTHON_PKGNAMEPREFIX}pymysql>=1.1:databases/py-pymysql@${PY_FLAVOR} \
		${PYTHON_PKGNAMEPREFIX}pyodbc>=5.0:databases/py-pyodbc@${PY_FLAVOR} \
		${LOCALBASE}/sbin/httpd:www/apache24 \
		${LOCALBASE}/lib/mariadb/libmaodbc.so:databases/mariadb-connector-odbc \
		rawtherapee-cli:graphics/rawtherapee \
		redis-server:databases/redis

USES=		php:cli python:3.11 ssl
USE_GITHUB=	yes
GH_ACCOUNT=	JamesElstone
GH_PROJECT=	SwallowTail
# Local development only: track the moving main branch until this port is
# submitted upstream. Pin GH_TAGNAME to a release tag and regenerate distinfo
# before submitting through bugs.freebsd.org.
GH_TAGNAME=	main
NO_CHECKSUM=	yes
USE_PHP=	ctype curl filter gd mbstring pdo pdo_odbc session tokenizer
USE_RC_SUBR=	swallowtail_storage \
		swallowtail_conversion

CONFLICTS_INSTALL=	swallowtail-raw-conversion

OPTIONS_DEFINE=	APACHECONF SUDOERS
OPTIONS_DEFAULT=	SUDOERS
APACHECONF_DESC=	Install active Apache include as swallowtail.conf
SUDOERS_DESC=	Install sudoers rule for storage permission repair

SUDOERS_RUN_DEPENDS=	sudo:security/sudo

SWALLOWTAIL_ROOT?=	${PREFIX}/swallowtail
SWALLOWTAIL_SOURCE?=	${WRKSRC}
SWALLOWTAIL_SERVER_NAME?=	swallowtail.example.invalid
SWALLOWTAIL_HTTP_PORT?=	80
SWALLOWTAIL_PHP_FPM_LISTEN?=	127.0.0.1:9001
SWALLOWTAIL_UPLOAD_MAX_FILESIZE?=	128M
SWALLOWTAIL_POST_MAX_SIZE?=	128M

PROJECT_DIRS=	db_schema \
		services \
		tools \
		web_root
PROJECT_FILES=	API.md \
		DEPLOYMENT.md \
		LICENSE \
		README.md \
		eelKit_CHANGES.md

SUB_FILES=	pkg-install \
		pkg-message \
		swallowtail-apache.conf \
		swallowtail-fix-storage-permissions \
		swallowtail-php-fpm.conf
SUB_LIST=	PHP_CMD=${PREFIX}/bin/php \
		PYTHON_CMD=${PYTHON_CMD} \
		SWALLOWTAIL_ROOT=${SWALLOWTAIL_ROOT} \
		SWALLOWTAIL_SERVER_NAME=${SWALLOWTAIL_SERVER_NAME} \
		SWALLOWTAIL_HTTP_PORT=${SWALLOWTAIL_HTTP_PORT} \
		SWALLOWTAIL_PHP_FPM_LISTEN=${SWALLOWTAIL_PHP_FPM_LISTEN} \
		SWALLOWTAIL_UPLOAD_MAX_FILESIZE=${SWALLOWTAIL_UPLOAD_MAX_FILESIZE} \
		SWALLOWTAIL_POST_MAX_SIZE=${SWALLOWTAIL_POST_MAX_SIZE}

.include <bsd.port.options.mk>

PLIST_FILES=	etc/newsyslog.conf.d/swallowtail_storage.conf \
		etc/newsyslog.conf.d/swallowtail_conversion.conf \
		etc/php/ext-30-pdo_odbc.ini \
		etc/php-fpm.d/swallowtail.conf \
		etc/apache24/Includes/swallowtail.conf.sample \
		bin/spicebush \
		sbin/swallowtail-fix-storage-permissions

.if ${PORT_OPTIONS:MAPACHECONF}
PLIST_FILES+=	etc/apache24/Includes/swallowtail.conf
.endif

.if ${PORT_OPTIONS:MSUDOERS}
SUB_FILES+=	swallowtail-sudoers
PLIST_FILES+=	etc/sudoers.d/swallowtail
.endif

do-build:
	${MAKE_CMD} -C ${WRKSRC}/client/spicebush -f Makefile.freebsd \
		CC="${CC}" \
		CFLAGS="${CFLAGS} -Wall -Wextra -std=c99 -I${OPENSSLINC}" \
		LDFLAGS="${LDFLAGS} -L${OPENSSLLIB}" \
		WORKDIR="${WRKDIR}/spicebush"

do-install:
	${MKDIR} ${STAGEDIR}${PREFIX}/bin
	${MKDIR} ${STAGEDIR}${PREFIX}/sbin
	${MKDIR} ${STAGEDIR}${PREFIX}/etc/newsyslog.conf.d
	${MKDIR} ${STAGEDIR}${PREFIX}/etc/apache24/Includes
	${MKDIR} ${STAGEDIR}${PREFIX}/etc/php-fpm.d
	${MKDIR} ${STAGEDIR}${SWALLOWTAIL_ROOT}
	${MKDIR} ${STAGEDIR}${SWALLOWTAIL_ROOT}/secure
	for dir in ${PROJECT_DIRS}; do \
		if [ -d "${SWALLOWTAIL_SOURCE}/$${dir}" ]; then \
			cd ${SWALLOWTAIL_SOURCE} && ${COPYTREE_SHARE} "$${dir}" \
				${STAGEDIR}${SWALLOWTAIL_ROOT}; \
		fi; \
	done
	if [ -f "${STAGEDIR}${SWALLOWTAIL_ROOT}/tools/reinstall-freebsd-port.sh" ]; then \
		${CHMOD} 0555 ${STAGEDIR}${SWALLOWTAIL_ROOT}/tools/reinstall-freebsd-port.sh; \
	fi
	if [ -d "${STAGEDIR}${SWALLOWTAIL_ROOT}/tools/bin" ]; then \
		${FIND} ${STAGEDIR}${SWALLOWTAIL_ROOT}/tools/bin -type f \
			-exec ${CHMOD} 0555 {} \; ; \
	fi
	if [ -d "${STAGEDIR}${SWALLOWTAIL_ROOT}/web_root" ]; then \
		${FIND} ${STAGEDIR}${SWALLOWTAIL_ROOT}/web_root -type f \
			-name '*.md' -delete; \
	fi
	${INSTALL_DATA} "${SWALLOWTAIL_SOURCE}/secure/README.md" \
		${STAGEDIR}${SWALLOWTAIL_ROOT}/secure/README.md
	for project_file in ${PROJECT_FILES}; do \
		if [ -f "${SWALLOWTAIL_SOURCE}/$${project_file}" ]; then \
			${INSTALL_DATA} "${SWALLOWTAIL_SOURCE}/$${project_file}" \
				${STAGEDIR}${SWALLOWTAIL_ROOT}/"$${project_file}"; \
		fi; \
	done
	${INSTALL_DATA} ${FILESDIR}/swallowtail_storage.newsyslog.conf \
		${STAGEDIR}${PREFIX}/etc/newsyslog.conf.d/swallowtail_storage.conf
	${INSTALL_DATA} ${FILESDIR}/swallowtail_conversion.newsyslog.conf \
		${STAGEDIR}${PREFIX}/etc/newsyslog.conf.d/swallowtail_conversion.conf
	${MKDIR} ${STAGEDIR}${PREFIX}/etc/php
	${INSTALL_DATA} ${FILESDIR}/ext-30-pdo_odbc.ini \
		${STAGEDIR}${PREFIX}/etc/php/ext-30-pdo_odbc.ini
	${INSTALL_DATA} ${WRKDIR}/swallowtail-apache.conf \
		${STAGEDIR}${PREFIX}/etc/apache24/Includes/swallowtail.conf.sample
	${INSTALL_DATA} ${WRKDIR}/swallowtail-php-fpm.conf \
		${STAGEDIR}${PREFIX}/etc/php-fpm.d/swallowtail.conf
	${INSTALL_PROGRAM} ${WRKDIR}/spicebush/spicebush \
		${STAGEDIR}${PREFIX}/bin/spicebush
	${INSTALL_SCRIPT} -m 0555 ${WRKDIR}/swallowtail-fix-storage-permissions \
		${STAGEDIR}${PREFIX}/sbin/swallowtail-fix-storage-permissions
	${MKDIR} ${STAGEDIR}${SWALLOWTAIL_ROOT}/tmp
	${MKDIR} ${STAGEDIR}${SWALLOWTAIL_ROOT}/web_root/tests/tmp
.if ${PORT_OPTIONS:MAPACHECONF}
	${INSTALL_DATA} ${WRKDIR}/swallowtail-apache.conf \
		${STAGEDIR}${PREFIX}/etc/apache24/Includes/swallowtail.conf
.endif
.if ${PORT_OPTIONS:MSUDOERS}
	${MKDIR} ${STAGEDIR}${PREFIX}/etc/sudoers.d
	${INSTALL} -m 0440 ${WRKDIR}/swallowtail-sudoers \
		${STAGEDIR}${PREFIX}/etc/sudoers.d/swallowtail
.endif

post-install:
	cd ${STAGEDIR}${PREFIX} && ${FIND} swallowtail -type f \
		! -path 'swallowtail/tools/reinstall-freebsd-port.sh' \
		! -path 'swallowtail/tools/bin/*' \
		! -path 'swallowtail/web_root/tests/fixtures/config/app.php' | \
		${SORT} >> ${TMPPLIST}
	${ECHO_CMD} "@mode 0555" >> ${TMPPLIST}
	${ECHO_CMD} "swallowtail/tools/reinstall-freebsd-port.sh" >> ${TMPPLIST}
	if [ -d "${STAGEDIR}${PREFIX}/swallowtail/tools/bin" ]; then \
		cd ${STAGEDIR}${PREFIX} && ${FIND} swallowtail/tools/bin -type f | \
			${SORT} >> ${TMPPLIST}; \
	fi
	${ECHO_CMD} "@mode" >> ${TMPPLIST}
	${ECHO_CMD} "@owner www" >> ${TMPPLIST}
	${ECHO_CMD} "@group www" >> ${TMPPLIST}
	${ECHO_CMD} "@mode 0640" >> ${TMPPLIST}
	${ECHO_CMD} "swallowtail/web_root/tests/fixtures/config/app.php" >> ${TMPPLIST}
	${ECHO_CMD} "@mode" >> ${TMPPLIST}
	${ECHO_CMD} "@group" >> ${TMPPLIST}
	${ECHO_CMD} "@owner" >> ${TMPPLIST}
	${ECHO_CMD} "@dir(www,swallowtail,2750) swallowtail/secure" >> ${TMPPLIST}
	${ECHO_CMD} "@dir(www,www,0770) swallowtail/tmp" >> ${TMPPLIST}
	${ECHO_CMD} "@dir(www,www,0770) swallowtail/web_root/tests/tmp" >> ${TMPPLIST}
	cd ${STAGEDIR}${PREFIX} && ${FIND} swallowtail -type d | ${SORT} -r | \
		${SED} -e '/^swallowtail\/secure$$/d' \
		-e '/^swallowtail\/tmp$$/d' \
		-e '/^swallowtail\/web_root\/tests\/tmp$$/d' \
		-e 's,^,@dir ,' >> ${TMPPLIST}

.include <bsd.port.mk>
