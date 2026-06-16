PORTNAME=	swallowtail-raw-conversion
DISTVERSION=	0.1.0
CATEGORIES=	graphics python

MAINTAINER=	james@elstone.net
COMMENT=	SwallowTail RAW photo conversion worker service
WWW=		https://github.com/JamesElstone/Swallowtail

LICENSE=	BSD3CLAUSE

RUN_DEPENDS=	${PYTHON_PKGNAMEPREFIX}pymysql>=1.1:databases/py-pymysql@${PY_FLAVOR} \
		${PYTHON_PKGNAMEPREFIX}pyodbc>=5.0:databases/py-pyodbc@${PY_FLAVOR} \
		rawtherapee-cli:graphics/rawtherapee \
		redis-server:databases/redis

USES=		php:cli python:3.11
USE_RC_SUBR=	swallowtail_subsystem

NO_ARCH=	yes
NO_BUILD=	yes
DISTFILES=

USERS=		swallowtail
GROUPS=		swallowtail

SWALLOWTAIL_ROOT?=	${PREFIX}/swallowtail

SUB_FILES=	pkg-message \
		swallowtail_subsystem_worker
SUB_LIST=	PHP_CMD=${PREFIX}/bin/php \
		PYTHON_CMD=${PYTHON_CMD} \
		SWALLOWTAIL_ROOT=${SWALLOWTAIL_ROOT}

PLIST_FILES=	"@sample(root,swallowtail,0640) etc/swallowtail/raw-conversion.ini.sample" \
		etc/newsyslog.conf.d/swallowtail_subsystem.conf \
		libexec/swallowtail_subsystem_worker

do-install:
	${MKDIR} ${STAGEDIR}${PREFIX}/etc/newsyslog.conf.d
	${MKDIR} ${STAGEDIR}${PREFIX}/etc/swallowtail
	${MKDIR} ${STAGEDIR}${PREFIX}/libexec
	${INSTALL_DATA} ${FILESDIR}/raw-conversion.ini.sample \
		${STAGEDIR}${PREFIX}/etc/swallowtail/raw-conversion.ini.sample
	${INSTALL_DATA} ${FILESDIR}/swallowtail_subsystem.newsyslog.conf \
		${STAGEDIR}${PREFIX}/etc/newsyslog.conf.d/swallowtail_subsystem.conf
	${INSTALL_SCRIPT} ${WRKDIR}/swallowtail_subsystem_worker \
		${STAGEDIR}${PREFIX}/libexec/swallowtail_subsystem_worker

.include <bsd.port.mk>
