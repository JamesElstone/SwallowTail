# Database Schema

The `db_schema` directory contains the canonical database structure for the eelKit-derived SwallowTail application and the incremental SQL migrations used to keep existing installations up to date.

## Directory Structure

```text
db_schema/
  eelKit.schema.sql   Baseline schema used to hydrate a new empty SwallowTail database
  migrations/         Ordered incremental SQL migrations
  README.md           This guide
```

## Baseline Schema

`eelKit.schema.sql` is the full baseline schema for a fresh SwallowTail database. The file still carries the eelKit name because the project was derived from eelKit. The migration tool uses it when the configured database has no application tables yet.

The baseline includes the `schema_migrations` table and marks the initial migration as applied, so a newly hydrated database does not try to replay the baseline marker migration.

## Migrations

`migrations/` contains one SQL file per schema change. Files are applied in filename order, and each applied filename is recorded in `schema_migrations`.

Current naming style:

```text
YYYY_MM_DD_NNN_short_description.sql
```

Examples:

```text
2026_05_08_001_schema_integrity.sql
2026_05_08_002_force_password_change.sql
2026_05_08_003_user_otp_optional.sql
```

When adding a migration:

- Use the next sequence number for that date.
- Keep each migration focused on one schema change.
- Write SQL that can run once and leave the database in the expected final shape.
- Do not rename an already-applied migration file, because the filename is the migration identity.
- Update `eelKit.schema.sql` when the baseline schema should include the same final structure for new installs.

## Running Migrations

Use the tools in `tools/` from the project root.

Set up configuration, hydrate a new empty database, apply pending migrations, and update the stored external IP:

```sh
tools/bin/setupDb.sh
tools/bin/setupDb.sh --driver=mysql --host=127.0.0.1 --database=swallowtail --user=root
```

Windows Command Prompt:

```bat
tools\bat\setupDb.bat
tools\bat\setupDb.bat --driver=mysql --host=127.0.0.1 --database=swallowtail --user=root
```

Apply pending migrations only, without changing database settings or the stored external IP:

```sh
tools/bin/migrateDb.sh
tools/bin/setupDb.sh --migrate-only
```

Windows Command Prompt:

```bat
tools\bat\migrateDb.bat
tools\bat\setupDb.bat --migrate-only
```

Direct PHP equivalents:

```sh
php tools/php/setupDb.php
php tools/php/setupDb.php --migrate-only
php tools/php/migrateDb.php
```

## Runtime Configuration

The setup tool uses the database settings from `secure/app.php`. If the database connection has not been configured yet, run `setupDb` with database options or let it prompt interactively. To change an existing database connection, run:

```sh
tools/bin/setupDb.sh --configure-db
```

Windows Command Prompt:

```bat
tools\bat\setupDb.bat --configure-db
```
