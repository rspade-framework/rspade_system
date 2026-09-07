# db_reset

`php artisan rsx:database_and_storage_reset` - the guarded, total reset of an
application's DATA: every table dropped, every file under the three configured roots
deleted, then `migrate`, leaving the operator on the fresh-install state.

## Domain

The use case is iterating on a bulk import. An import that has to be run, inspected,
corrected and re-run needs a zero state to run against, and the only route to one was
rebuilding the container - which rebuilds a schema and a codebase that were never wrong.

Not `rsx:clean`, which resets the **`system/` tree**. This resets the **data**. The names
are deliberately dissimilar so an operator reaching for one cannot land on the other.

The command extends `Maint_Migrate`, so it uses migrate's own snapshot machinery and its
own snapshot predicate: where the datadir can be snapshotted it is snapshotted before the
drop, and a failed wipe or migrate rolls the DATABASE back. Deleted FILES are never
covered - there is no filesystem snapshot - which is why the refusal text says the only
route back is a backup taken beforehand.

## Why the tests are shaped the way they are

The command destroys a database and a file store on purpose, and it enters the REAL
maintenance window (php-fpm, realtime and redis go down). Neither is a thing a test may
do, so **no test in this directory ever runs the reset**. This is the doctrine
`../db_cache/README.md` records for `rsx:db:rebuild_provision_cache_snapshot`, and it applies here verbatim.

What carries the safety instead is that the two decisions are PURE FUNCTIONS, driven by
`handle()`, so the rule under test is the rule that runs:

| Property | Function | Test |
|---|---|---|
| Which flags are required, and when a seal adds one | `Database_And_Storage_Reset_Command::refusal_reason()` | `php/Db_Reset_Refusal_Test` |
| What the operator must have read before they can agree | `Database_And_Storage_Reset_Command::refusal_text()` | `php/Db_Reset_Refusal_Test` |
| The blast radius reported afterwards | `Database_And_Storage_Reset_Command::announcement()` | `php/Db_Reset_Refusal_Test` |
| Whether this run is snapshot-protected, and every reason it is not | `Maint_Migrate::snapshot_protection_engaged()` / `snapshot_skipped_reasons()`, inherited | `php/Db_Reset_Snapshot_Decision_Test` |
| Contents go, directory and mode stay, counts are returned | `Rsx_Data_Wipe::clear_directory_contents()` | `php/Db_Reset_Directory_Wipe_Test` |

`cli/Db_Reset_Refusal_Cli_Test` then drives the REAL command in subprocesses to prove the
wiring: the refusal is reached first, exits 1, lands on stdout with stderr empty, raises no
maintenance window, and leaves the table count unchanged. **Every one of those invocations
omits `--yes`**, and each spawn additionally points the child at the TEST database via
`DB_DATABASE`, so a regression in the gate could not reach the developer's database.

**Residual risk, stated plainly**: a regressed gate would still resolve the real storage
roots in that child, because the file subsystem's test isolation is an in-process config
key with no environment spelling. The gate is the first statement of `handle()` and is
proved for all six flag/seal combinations, which is what keeps that hypothetical closed.

The SEALED refusal is proved as pure logic rather than by faking a seal in a subprocess: a
seal cannot be faked across a process boundary (`Rsx_Prod_Seal::_testing_set_sealed()` is
in-process state), and manufacturing one to watch a wipe refuse would be a worse test than
proving the rule that decides it.

`Rsx_Data_Wipe`'s directory tests operate on a sandbox under the system temp directory,
never under `storage/`: the function under test deletes everything it is pointed at.

## Source files under test

- `app/RSpade/Commands/Database/Database_And_Storage_Reset_Command.php`
- `app/RSpade/Core/Database/Rsx_Data_Wipe.php` (shared with `rsx:db:rebuild_provision_cache_snapshot`)
- `app/RSpade/Commands/Migrate/Maint_Migrate.php` (the inherited snapshot predicate and API)

## Man pages that define the behaviour

- `php artisan rsx:man migrations` - RESETTING THE DATA
- `php artisan rsx:man maintenance_mode` - who raises the window
