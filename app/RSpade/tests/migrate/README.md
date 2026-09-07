# migrate

The `php artisan migrate` pipeline: mode-aware snapshot protection, migration
validation, and schema normalization.

## Source under test

- `app/RSpade/Commands/Migrate/Maint_Migrate.php` - the unified `migrate` command.
  Where snapshot protection is available it snapshots the physical MySQL datadir,
  runs migrations one-by-one with normalization between them, runs the
  schema-quality check, then commits (or, on ANY failure, restores the datadir via
  `rollback_snapshot()` + `cleanup_migration_mode()`).
- `app/RSpade/Commands/Migrate/Migrate_Normalize_Schema_Command.php` -
  `migrate:normalize_schema`, invoked pre-migration, between each migration, and
  post-migration to add framework-required columns and correct drift.

## Behavior of record

- **The snapshot is taken ONLY where it can actually be performed**:
  `snapshot_protection_available()` requires development mode AND the RSpade
  DEVELOPMENT container (`/.rspade_container_dev`; the PRODUCTION container carries
  `/.rspade_container` but ships mysql-client only, so there is no mysqld to stop and
  no datadir to copy) AND a local database host. `--framework-only` and
  `--_no-snapshot` suppress it per-run. Everywhere else `migrate` runs bare and prints
  every reason. The danger is the ROLLBACK, not the snapshot: restoring a
  `/var/lib/mysql` that is not the live database would report a successful rollback
  while the real database stayed broken, and a false rollback is worse than none.
  `migrate:restore` applies the identical predicate.
- **There is exactly one real recovery: the physical MySQL-datadir snapshot
  restore** owned by `Maint_Migrate::rollback_snapshot()`. Framework migrations
  have their `down()` methods stripped (`MigrationValidator`), so a logical
  `migrate:rollback` reverts nothing. `migrate:normalize_schema` therefore performs
  NO logical rollback: on failure it reports the error, disables query echo, and
  re-throws (fail loud).
- **All three normalize call sites route failure to the snapshot restore.** The
  mid-loop call throws inside `execute_migrations()`'s try/catch, which returns a
  non-zero code; the pre- and post-migration calls each wrap the invocation in a
  try/catch that converts a throw (or non-zero exit) into the same non-zero return.
  The caller (`run_with_snapshot()`) sees a non-zero `execute_migrations()` result
  and runs `rollback_snapshot()` + `cleanup_migration_mode()`.

## Testable surface

- **php** (implemented): the normalize command's catch path performs no logical
  rollback and emits no false success message; it re-throws.
- **php** (implemented): a table rename executed by a migration is followed into
  `_type_refs.table_name` in the same run. `_type_refs` stores class_name AND table_name,
  and a migration cannot maintain it itself (MIGRATION-MODEL-01 forbids naming the
  registry), so the pipeline does: every executed statement passes through the one
  `DB::macro('statement')` seam, which hands it to `Type_Ref_Table_Rename::observe()`, and
  `execute_migrations()` calls `apply_type_ref_table_renames()` after the post-migration
  normalize. `Type_Ref_Table_Rename_Test` pins the parser (every accepted spelling, plus
  the three `RENAME COLUMN`/`INDEX`/`KEY` statements that are NOT table renames), the
  apply (including an ordered chain landing on the final name), and the wiring by source
  structure.
- **cli / integration** (deferred): end-to-end proof that a normalize failure during
  `php artisan migrate` triggers the physical datadir snapshot restore and leaves no
  orphaned `.migrating` flag or backup dir. Requires a real MySQL datadir + Docker
  supervisor control; a destructive infra-harness follow-up (see test_catalog.md).
