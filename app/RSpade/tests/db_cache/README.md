# db_cache

The **shipped schema cache**: the pair of build artifacts that let a fresh install
arrive at the current schema without replaying every migration ever written.

## Domain

Two halves, one contract.

**The build** - `php artisan rsx:db:rebuild_provision_cache_snapshot`
(`app/RSpade/Commands/Database/Db_Rebuild_Provision_Cache_Snapshot_Command.php`). Development mode only. It
backs the live database up (a gzipped `mysqldump`) and MOVES the blob store aside
(a sibling rename to `<blob_root>_tmp`), drops and recreates the database, migrates it
from zero with `--_no-initial-user` and `--_no-snapshot`, writes
`rsx/resource/db/schema_cache.sql.gz` + `uploads_cache.tar.gz` + `README.md`, wipes
again, restores the live data, and **deletes the backup last**.

**The restore** - `Maint_Migrate::maybe_restore_schema_cache()`. A database with NO
TABLES AT ALL plus a present cache restores it before migrating; the ordinary run then
applies whatever is newer. Any other state is exactly today's behaviour.

## Why the tests are shaped the way they are

The build command destroys a database on purpose, and it operates on the DEFAULT
connection - which during a suite run is this box's development database. It also
enters the REAL maintenance window (php-fpm, realtime and redis go down). Neither is a
thing a test may do, so the command is covered by the two properties that actually carry
the safety, expressed as pure functions and driven by the real code paths:

| Property | Function | Test |
|---|---|---|
| What an interrupt undoes, and in what order | `Db_Rebuild_Provision_Cache_Snapshot_Command::__recovery_plan()` | `php/Db_Cache_Recovery_Plan_Test` |
| Never overwrite an existing live backup | `Db_Rebuild_Provision_Cache_Snapshot_Command::backup_decision()` | `php/Db_Cache_Backup_Overwrite_Test` |

The one part of the command that IS driven for real is its refusal: a subprocess with
`RSX_MODE=debug` exits 1 before `handle()` resolves a single path, so the assertion that
nothing moved is made against the live box
(`cli/Db_Cache_Restore_Cli_Test::test_the_build_refuses_outside_development_and_touches_nothing`).

The restore half IS exercised end to end, against the TEST database, with the cache
artifacts written into a sandbox named by the internal `--_cache-dir` flag - so no test
ever writes into the shipped `rsx/resource/db`.

The maintenance-disable refusal that protects an interrupted build lives with the
maintenance concern: `maintenance/cli/Maintenance_Db_Cache_Guard_Cli_Test`.

## Source files under test

- `app/RSpade/Commands/Database/Db_Rebuild_Provision_Cache_Snapshot_Command.php`
- `app/RSpade/Commands/Migrate/Maint_Migrate.php` (`maybe_restore_schema_cache`, `--_no-snapshot`)
- `app/RSpade/Commands/Rsx/Rsx_Test_Command.php` (`schema_cache_fingerprint`)
- `bin/maintenance-mode.sh` (`db_cache_in_progress`)

## Man pages that define the behaviour

- `php artisan rsx:man migrations` - THE SCHEMA CACHE
- `php artisan rsx:man maintenance_mode` - the disable refusals
