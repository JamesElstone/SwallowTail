# Tools

The `tools` directory contains command line helpers for setting up, maintaining, and managing the eelKit-derived SwallowTail application.

## Directory Structure

```text
tools/
  bat/        Windows Command Prompt wrappers
  bin/        Unix shell wrappers for Linux, macOS, Git Bash, and similar shells
  php/        PHP implementations used by the wrappers
  README.md   This guide
```

Run the wrapper that matches your shell from the project root. The wrappers resolve the PHP scripts relative to their own location, so they can be run from any current working directory.

## Requirements

- PHP must be available on your `PATH`.
- Database-related tools require a valid application configuration in `secure/app.php`.
- Git-related tools require Git to be available on your `PATH`.

## setupDb

Creates the stored application configuration if needed, asks for database settings only when `db.dsn` is empty, loads the baseline schema for an empty database, applies pending SQL migrations, and then runs `tools/bin/setExternalIP.sh`.

Linux, macOS, or Git Bash:

```sh
tools/bin/setupDb.sh
tools/bin/setupDb.sh --driver=mysql --host=127.0.0.1 --database=swallowtail --user=root
```

Windows Command Prompt:

```bat
tools\bat\setupDb.bat
tools\bat\setupDb.bat --driver=mysql --host=127.0.0.1 --database=swallowtail --user=root
```

Direct PHP:

```sh
php tools/php/setupDb.php
php tools/php/setupDb.php --driver=sqlite --sqlite-path=secure/swallowtail.sqlite
```

Use `--configure-db` to update existing database settings, `--migrate-only` to skip configuration and external IP updates, or `--skip-external-ip` to skip only the final external IP step.

## migrateDb

Compatibility entrypoint for applying pending SQL migrations from `db_schema/migrations`. For normal setup and upgrades, prefer `setupDb` so configuration, migrations, and external IP setup happen in the right order. If the configured database is empty, the migration runner first loads `db_schema/eelKit.schema.sql`.

Linux, macOS, or Git Bash:

```sh
tools/bin/migrateDb.sh
```

Windows Command Prompt:

```bat
tools\bat\migrateDb.bat
```

Direct PHP:

```sh
php tools/php/migrateDb.php
```

## setDbConfig

Compatibility entrypoint for updating `db.dsn`, `db.user`, and `db.pass` in `secure/app.php`. For normal setup, prefer passing the same options to `setupDb`; it only asks for database details when needed.

Interactive:

```sh
tools/bin/setDbConfig.sh
```

Windows Command Prompt:

```bat
tools\bat\setDbConfig.bat
```

Example direct PHP options:

```sh
php tools/php/setDbConfig.php --driver=mysql --host=127.0.0.1 --database=swallowtail --user=root
php tools/php/setDbConfig.php --driver=odbc --odbc-name=swallowtail
php tools/php/setDbConfig.php --driver=sqlite --sqlite-path=secure/swallowtail.sqlite
php tools/php/setDbConfig.php --dsn="mysql:host=127.0.0.1;dbname=swallowtail;charset=utf8mb4" --user=root
```

Use `--help` to show the available options:

```sh
php tools/php/setDbConfig.php --help
```

## setExternalIP

Looks up the current public IP address and stores it as `antifraud.vendor_public_ip` in `secure/app.php`.

Linux, macOS, or Git Bash:

```sh
tools/bin/setExternalIP.sh
```

Windows Command Prompt:

```bat
tools\bat\setExternalIP.bat
```

Direct PHP:

```sh
php tools/php/setExternalIP.php
```

## storageCache

Refreshes or inspects the cached SwallowTail storage snapshot and processes queued
storage migration jobs. The `swallowtail_storage` service uses `discover` for
the PHP-side storage calculation, then writes the Redis snapshot itself. The
helper can also be run directly for diagnostics:

```sh
php tools/php/storageCache.php status
php tools/php/storageCache.php discover
php tools/php/storageCache.php refresh
php tools/php/storageCache.php process-migrations 10
```

`discover` reads the live mount/ZFS state without updating the cache. `refresh`
stores a new snapshot for manual diagnostics; the daemon path writes Redis
directly after `discover`.
`process-migrations` processes up to the requested number of migration job
items, not whole migration jobs.

## run-all-tests

Runs the PHP test index, SpiceBush client build, and Python service tests:

```sh
tools/run-all-tests.sh
```

On Windows with Git Bash, set `PYTHON` if `python3` resolves to the Microsoft
Store alias instead of a usable interpreter:

```powershell
$env:PYTHON='C:\Python314\python.exe'
sh tools/run-all-tests.sh
```

## resetPassword

Connects to the configured database, finds a user by display name or email address, resets the user's password, and optionally resets OTP setup.

Linux, macOS, or Git Bash:

```sh
tools/bin/resetPassword.sh
```

Windows Command Prompt:

```bat
tools\bat\resetPassword.bat
```

Direct PHP:

```sh
php tools/php/reset_password.php
```

## projectGit

Helps create a project repository from eelKit while keeping eelKit available as an upstream remote. It can also import later eelKit changes from that upstream remote.

SwallowTail is already an initialized project repository. In this checkout the
tool is retained for the inherited eelKit workflow and for importing later
framework updates intentionally.

Initialize a project origin:

```sh
tools/bin/projectGit.sh init git@github.com:you/yourProjectRepo.git
```

Windows Command Prompt:

```bat
tools\bat\projectGit.bat init git@github.com:you/yourProjectRepo.git
```

Import upstream eelKit changes:

```sh
tools/bin/projectGit.sh import
tools/bin/projectGit.sh import upstream main --rebase
```

Direct PHP:

```sh
php tools/php/projectGit.php import
```

See `tools/projectGit.md` for the detailed workflow.
