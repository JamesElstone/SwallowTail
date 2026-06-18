# SwallowTail FreeBSD Deployment

This guide describes deploying SwallowTail on a FreeBSD host with Apache,
PHP-FPM, unixODBC, MariaDB, Redis, RawTherapee, and the bundled SwallowTail
background services.

Verified platform:

```text
FreeBSD 15.0-RELEASE-p9 GENERIC amd64
```

## Runtime Layout

Use this layout unless the host has a specific reason to differ:

```text
/usr/local/swallowtail          SwallowTail checkout and application files
/usr/local/swallowtail/web_root Apache document root
/usr/local/etc/rc.d             SwallowTail rc.d service scripts
/usr/local/libexec              SwallowTail service worker wrappers
/var/db/swallowtail-raw-conversion
/var/tmp/swallowtail-raw-conversion
/var/run/swallowtail
```

Only `web_root` should be served publicly. Do not expose `secure`, `db_schema`,
`tools`, `service`, `debug/logs`, uploaded photo storage roots, conversion
caches, or generated ZIP directories.

## Install Base Packages

```sh
pkg update
pkg install -y apache24 \
  php84 \
  php84-ctype php84-curl php84-filter php84-gd php84-mbstring \
  php84-pdo php84-pdo_odbc php84-session php84-tokenizer \
  unixODBC git sudo screen portmaster portconfig sqlite3 \
  cmake-core gmake meson pkgconf corepack gettext-tools
```

The SwallowTail port installs the service-side Python, Redis, and RawTherapee
dependencies. If you are preparing the host manually, install these too:

```sh
pkg install -y python311 py311-pymysql py311-pyodbc redis rawtherapee
```

## Install MariaDB ODBC

Confirm the MariaDB client library is present:

```sh
pkg info | grep -i maria
find /usr/local -name 'libmariadb.so*' -print
ldconfig -r | grep -i mariadb
```

Do not install `databases/mariadb-connector-c` if the installed MariaDB client
package already provides `mariadb_config`.

If the FreeBSD ports tree still has an iconv-related build issue in
`databases/mariadb-connector-odbc`, patch the ports tree before building. Then
build and install the driver:

```sh
cd /usr/ports/databases/mariadb-connector-odbc
make clean
make LDFLAGS="-L/usr/local/lib/mysql" CPPFLAGS="-I/usr/local/include/mysql"
make install
```

Verify the driver:

```sh
find /usr/local -name 'libmaodbc.so*' -print
ldd /usr/local/lib/mariadb/libmaodbc.so
```

The expected driver path is:

```text
/usr/local/lib/mariadb/libmaodbc.so
```

## Configure PHP PDO ODBC

The bundled SwallowTail port installs the PHP PDO_ODBC properties file as:

```sh
/usr/local/etc/php/ext-30-pdo_odbc.ini
```

For a manual, non-port installation, copy `FreeBSD/files/ext-30-pdo_odbc.ini`
from the checkout to that path.

It must contain:

```ini
extension=pdo_odbc.so
pdo_odbc.connection_pooling=off
```

Keep ODBC connection pooling disabled. With pooling set to `strict` or
`relaxed`, repeated PDO ODBC connections have segfaulted in `libodbc.so.2` on
FreeBSD/PHP 8.4/unixODBC/MariaDB ODBC.

Verify PHP:

```sh
php -m | grep -Ei 'ctype|curl|filter|gd|json|mbstring|PDO|ODBC|session'
php -r 'print_r(PDO::getAvailableDrivers());'
php -i | grep 'ODBC Connection Pooling'
```

`PDO_ODBC` must be loaded, `PDO::getAvailableDrivers()` must include `odbc`,
and ODBC connection pooling must be disabled.

## Configure unixODBC

Register the MariaDB ODBC driver:

```sh
cat > /tmp/mariadb-odbc-driver.template <<'EOF'
[MariaDB]
Description=MariaDB ODBC Driver
Driver=/usr/local/lib/mariadb/libmaodbc.so
Setup=/usr/local/lib/mariadb/libmaodbc.so
FileUsage=1
EOF

odbcinst -i -d -f /tmp/mariadb-odbc-driver.template
odbcinst -q -d
odbcinst -q -d -n MariaDB
```

Create or edit `/usr/local/etc/odbc.ini`:

```ini
[swallowtail]
Driver=MariaDB
Description=SwallowTail MariaDB
SERVER=127.0.0.1
PORT=3306
DATABASE=swallowtail
USER=swallowtail_app
PASSWORD=replace_with_real_password
CHARSET=utf8mb4
```

Check the unixODBC configuration:

```sh
odbcinst -j
odbcinst -q -s
isql -v swallowtail
```

## Create The Database

Create an empty database with the expected charset and collation:

```sh
printf 'CREATE DATABASE IF NOT EXISTS `swallowtail` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' | \
  isql -v -k 'DRIVER=MariaDB;SERVER=127.0.0.1;PORT=3306;UID=swallowtail_app;PWD=replace_with_real_password;CHARSET=utf8mb4' -b
```

Grant the application account the privileges it needs before running
`setupDb.php`.

## Install The Checkout

Clone or update the checkout:

```sh
git clone https://github.com/JamesElstone/SwallowTail.git /usr/local/swallowtail
cd /usr/local/swallowtail
git pull --ff-only origin main
```

Use ownership that lets the deployment user update the checkout while letting
the web server write only runtime-owned private files:

```sh
chown -R deploy:www /usr/local/swallowtail
chmod 775 /usr/local/swallowtail/secure /usr/local/swallowtail/debug/logs
find /usr/local/swallowtail/secure /usr/local/swallowtail/debug/logs -type f \
  -exec chmod 664 {} +
```

Replace `deploy` with the account used for deployments on the host.

## Install The SwallowTail Port

The bundled FreeBSD port lives in `FreeBSD/` and installs the application files,
worker wrappers, rc.d scripts, service user, and newsyslog configuration.

From the checkout:

```sh
cd /usr/local/swallowtail/FreeBSD
make clean
make install \
  SWALLOWTAIL_SOURCE=/usr/local/swallowtail \
  SWALLOWTAIL_ROOT=/usr/local/swallowtail
```

The port installs these services:

```text
swallowtail_image_engine
swallowtail_storage
```

Enable the services that should run on this host:

```sh
sysrc swallowtail_image_engine_enable=YES
sysrc swallowtail_storage_enable=YES
```

Useful service tunables can be set in `/etc/rc.conf`:

```sh
sysrc swallowtail_image_engine_database_dsn=swallowtail
sysrc swallowtail_image_engine_database_user=swallowtail_worker
sysrc swallowtail_image_engine_database_password=replace_with_real_password
sysrc swallowtail_image_engine_poll_interval_seconds=5
sysrc swallowtail_storage_interval_seconds=300
```

Use `swallowtail_image_engine_*` for RAW conversion worker settings and
`swallowtail_storage_*` for storage service settings.

## Configure PHP-FPM

Enable PHP-FPM:

```sh
sysrc php_fpm_enable=YES
```

The default listener is suitable:

```ini
user = www
group = www
listen = 127.0.0.1:9000
```

Check it with:

```sh
grep -n '^listen' /usr/local/etc/php-fpm.d/www.conf
```

## Configure Apache

Create an Apache include for SwallowTail:

```sh
cat > /usr/local/etc/apache24/Includes/swallowtail.conf <<'EOF'
LoadModule proxy_module libexec/apache24/mod_proxy.so
LoadModule proxy_fcgi_module libexec/apache24/mod_proxy_fcgi.so
LoadModule rewrite_module libexec/apache24/mod_rewrite.so

ServerName swallowtail.example.invalid:80

<VirtualHost *:80>
    ServerName swallowtail.example.invalid
    DocumentRoot "/usr/local/swallowtail/web_root"

    DirectoryIndex index.php index.html

    <Directory "/usr/local/swallowtail/web_root">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch "\.php$">
        SetHandler "proxy:fcgi://127.0.0.1:9000"
    </FilesMatch>

    ErrorLog "/var/log/httpd-swallowtail-error.log"
    CustomLog "/var/log/httpd-swallowtail-access.log" combined
</VirtualHost>
EOF
```

Set the real `ServerName` for the host before starting Apache.

## Configure SwallowTail

From the project root, create `secure/app.php`, configure the database, load
the baseline schema if needed, and apply pending migrations:

```sh
cd /usr/local/swallowtail
php tools/php/setupDb.php \
  --driver=odbc \
  --odbc-name=swallowtail \
  --user=swallowtail_app \
  --password=replace_with_real_password
```

For later schema-only updates:

```sh
php tools/php/setupDb.php --migrate-only
```

When the RAW conversion service is installed, prefer the service migration
command so the worker drains cleanly:

```sh
service swallowtail_image_engine migrate
```

## Start Services

```sh
sysrc apache24_enable=YES
sysrc redis_enable=YES

service redis start
service php_fpm start
service apache24 start
service swallowtail_image_engine start
service swallowtail_storage start
```

After PHP, ODBC, or Apache changes:

```sh
service php_fpm restart
service apache24 restart
```

After SwallowTail code or database migrations:

```sh
service swallowtail_image_engine restart
service swallowtail_storage restart
```

## Production Checks

```sh
php -m | grep -Ei 'PDO|PDO_ODBC|mbstring|session|curl|gd'
php -r 'print_r(PDO::getAvailableDrivers());'
php -r 'echo function_exists("imagecreatetruecolor") ? "gd ok\n" : "gd missing\n";'
php -i | grep 'ODBC Connection Pooling'
service apache24 status
service php_fpm status
service redis status
service swallowtail_image_engine status
service swallowtail_storage status
```

Confirm:

- `PDO_ODBC` is loaded.
- `PDO::getAvailableDrivers()` includes `odbc`.
- `ODBC Connection Pooling => Disabled`.
- `gd ok`.
- Apache serves `/usr/local/swallowtail/web_root` as the document root.
- `secure/app.php` is not web-accessible.
- `developer_options` is set to `false` in `secure/app.php`.
- SQL logging is disabled unless actively diagnosing an issue.
- Photo storage roots and generated ZIP directories are outside `web_root`.
- `swallowtail_image_engine` and `swallowtail_storage` are healthy if enabled.
