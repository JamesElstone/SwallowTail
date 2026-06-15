# SwallowTail Raw Conversion Service

This service consumes SwallowTail conversion jobs from MariaDB, wakes quickly via
Redis, and renders Canon `.CR2` files with `rawtherapee-cli`.

PHP owns storage decisions. Each job includes the input path, optional PP3 path,
final output path, derivative type, and storage metadata. The worker renders into
a per-job temporary directory, then moves the completed file to the output path
defined on the job.

## Install On FreeBSD

Install dependencies:

```sh
pkg install -y py311-pymysql
```

Copy and edit configuration:

```sh
mkdir -p /usr/local/etc/swallowtail
cp service/rawConversion/config.example.ini /usr/local/etc/swallowtail/raw-conversion.ini
```

Install the rc.d script from the repository:

```sh
sed "s#__PROJECT_ROOT__#/usr/local/swallowtail#g" \
  service/rawConversion/scripts/swallowtail_raw_conversion.in \
  > /usr/local/etc/rc.d/swallowtail_raw_conversion
chmod 0555 /usr/local/etc/rc.d/swallowtail_raw_conversion
sysrc swallowtail_raw_conversion_enable=YES
service swallowtail_raw_conversion start
```

The service command is:

```sh
python3.11 -m raw_conversion --config /usr/local/etc/swallowtail/raw-conversion.ini
```

For one-shot testing:

```sh
python3.11 -m raw_conversion --config /usr/local/etc/swallowtail/raw-conversion.ini --once
```
