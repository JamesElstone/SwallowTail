PORTNAME=	swallowtail
DISTVERSION=	0.1.0
CATEGORIES=	graphics www

MAINTAINER=	james@elstone.net
COMMENT=	SwallowTail photo application and background services
WWW=		https://github.com/JamesElstone/Swallowtail

LICENSE=	BSD3CLAUSE

CONFLICTS_INSTALL=	swallowtail-raw-conversion

RUN_DEPENDS=	${PYTHON_PKGNAMEPREFIX}pymysql>=1.1:databases/py-pymysql@${PY_FLAVOR} \
		${PYTHON_PKGNAMEPREFIX}pyodbc>=5.0:databases/py-pyodbc@${PY_FLAVOR} \
		rawtherapee-cli:graphics/rawtherapee \
		redis-server:databases/redis

USES=		php:cli python:3.11
USE_RC_SUBR=	swallowtail_storage \
		swallowtail_image_engine

NO_ARCH=	yes
NO_BUILD=	yes
DISTFILES=

USERS=		swallowtail
GROUPS=		swallowtail

SWALLOWTAIL_ROOT?=	${PREFIX}/swallowtail
SWALLOWTAIL_SOURCE?=	${.CURDIR}/..

PROJECT_DIRS=	db_schema \
		secure \
		service \
		tools \
		web_root
PROJECT_FILES=	API.md \
		DEPLOYMENT.md \
		LICENSE \
		README.md \
		eelKit_CHANGES.md

.if !exists(${SWALLOWTAIL_SOURCE}/web_root)
IGNORE=	requires SWALLOWTAIL_SOURCE to point at a SwallowTail checkout
.endif

SUB_FILES=	pkg-message \
		swallowtail_storage_worker \
		swallowtail_image_engine_worker
SUB_LIST=	PHP_CMD=${PREFIX}/bin/php \
		PYTHON_CMD=${PYTHON_CMD} \
		SWALLOWTAIL_ROOT=${SWALLOWTAIL_ROOT}

PLIST_FILES=	etc/newsyslog.conf.d/swallowtail_storage.conf \
		etc/newsyslog.conf.d/swallowtail_image_engine.conf \
		etc/php/ext-30-pdo_odbc.ini \
		libexec/swallowtail_storage_worker \
		libexec/swallowtail_image_engine_worker

do-install:
	${MKDIR} ${STAGEDIR}${PREFIX}/etc/newsyslog.conf.d
	${MKDIR} ${STAGEDIR}${PREFIX}/libexec
	${MKDIR} ${STAGEDIR}${SWALLOWTAIL_ROOT}
	for dir in ${PROJECT_DIRS}; do \
		if [ -d "${SWALLOWTAIL_SOURCE}/$${dir}" ]; then \
			cd ${SWALLOWTAIL_SOURCE} && ${COPYTREE_SHARE} "$${dir}" \
				${STAGEDIR}${SWALLOWTAIL_ROOT}; \
		fi; \
	done
	for file in ${PROJECT_FILES}; do \
		if [ -f "${SWALLOWTAIL_SOURCE}/$${file}" ]; then \
			${INSTALL_DATA} "${SWALLOWTAIL_SOURCE}/$${file}" \
				${STAGEDIR}${SWALLOWTAIL_ROOT}/"$${file}"; \
		fi; \
	done
	${INSTALL_DATA} ${FILESDIR}/swallowtail_storage.newsyslog.conf \
		${STAGEDIR}${PREFIX}/etc/newsyslog.conf.d/swallowtail_storage.conf
	${INSTALL_DATA} ${FILESDIR}/swallowtail_image_engine.newsyslog.conf \
		${STAGEDIR}${PREFIX}/etc/newsyslog.conf.d/swallowtail_image_engine.conf
	${MKDIR} ${STAGEDIR}${PREFIX}/etc/php
	${INSTALL_DATA} ${FILESDIR}/ext-30-pdo_odbc.ini \
		${STAGEDIR}${PREFIX}/etc/php/ext-30-pdo_odbc.ini
	${INSTALL_SCRIPT} ${WRKDIR}/swallowtail_storage_worker \
		${STAGEDIR}${PREFIX}/libexec/swallowtail_storage_worker
	${INSTALL_SCRIPT} ${WRKDIR}/swallowtail_image_engine_worker \
		${STAGEDIR}${PREFIX}/libexec/swallowtail_image_engine_worker

post-install:
	cd ${STAGEDIR}${PREFIX} && ${FIND} swallowtail -type f | ${SORT} >> ${TMPPLIST}
	cd ${STAGEDIR}${PREFIX} && ${FIND} swallowtail -type d | ${SORT} -r | \
		${SED} -e 's,^,@dir ,' >> ${TMPPLIST}

.include <bsd.port.mk>
