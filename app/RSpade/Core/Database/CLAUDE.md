# Database System Documentation

## Migration Policy

- **Forward-only migrations** - No rollbacks, no down() methods
- **Automatic snapshot protection** in development mode (handled by `php artisan migrate`)
- **All migrations must be created via artisan** - Manual creation is blocked by whitelist
  - Use `php artisan make:migration:safe` to create whitelisted migrations
- **Sequential execution only** - No --path option allowed
- **Fail on errors** - No fallback to old schemas, migrations must succeed or fail loudly

## Database Rules

- **No mass assignment**: All fields assigned explicitly
- **No eager loading**: ALL eager loading methods throw exceptions - use explicit queries for relationships
  - *Note: Premature optimization is the root of all evil. When you have thousands of concurrent users in production and N+1 queries become a real bottleneck, you'll be motivated enough to remove these restrictions yourself.*
- **Forward-only migrations**: No down() methods

## Readers Over System Tables

A `_`-prefixed table is private, so an application reads it only through a reader the
framework publishes. WRITING ONE: there are three kinds and each has one shape -
`rsx:man database_schema_architecture`, READERS OVER SYSTEM TABLES, is the standard.

- **A display page** is `list_page(array $query)` answering `{rows, total, offset, limit}`,
  built on `Rsx_Page_Query::parse()` / `result()` (this directory). The reader declares its
  scope keys, its sortable NAMES (mapped to columns - never a caller's column), its maximum
  and default limit, and which columns `filter` searches. Everything invalid throws.
  First adopter: `Login_History::list_page()`.
- **A walk** is `find_page(array $filter, ?string $cursor)` - keyset, no total
  (`Task_Run_Model::find_page()`).
- **A bounded set** returns everything, and its docblock says what bounds it.

An aggregate is a function that answers the number in SQL
(`File_Storage_Model::usage_report()`), never rows added up in PHP.

## Side connections (framework internal)

`Rsx_Side_Connection::run(fn (Connection $db) => ...)` runs a callback on a second connection to
the default connection's database, so what it writes COMMITS whatever the caller's transaction
does. Framework-internal: documented here only, never in a man page or skill. Its one caller
today is the type registry (`TypeRefs/CLAUDE.md`, "Registration commits on its own").

- **Use it for shared, append-only reference data** a rollback of the caller's work must not
  undo. Its opposite is DEFERRING a side effect until the caller commits
  (`Lifecycle/Model_Lifecycle_Emissions`, `Realtime_Emissions`) - pick by whether the effect
  must happen even if the caller's work is thrown away.
- **The config is cloned from the default connection at the call**, and the connection is named
  for its database and host, so a process that switches its default database (the test runner)
  never writes through a connection opened for the old one. It is closed when the callback
  returns.
- **Never write a row the caller's open transaction has written or locked**: the side connection
  waits on its parent's lock while the parent waits on it - a cycle MySQL cannot see, which ends
  only at the lock-wait timeout. It also sees only COMMITTED data.
- `Migrate_Dump_Rollback`'s migrate lock opens a connection of its own the same way, held for a
  whole run rather than one call.

## Naming Conventions

- **Primary keys**: All tables must have `id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY` (exception: sessions table uses Laravel's VARCHAR id)
- **Foreign keys**: Always suffix with `_id` (e.g., `user_id`, `site_id`)
- **Boolean columns**: Always prefix with `is_` (e.g., `is_active`, `is_published`)
- **Timestamp columns**: Always suffix with `_at` (e.g., `created_at`, `updated_at`, `deleted_at`)
- **Integer types**: Use BIGINT for all integers, TINYINT(1) for booleans only
- **No unsigned integers**: All integers should be signed for easier migrations
- **UTF-8 encoding**: All text columns use UTF-8 (utf8mb4_unicode_ci collation)

## Schema Rules

- **BIGINT ID required**: ALL tables must have `id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY` - no exceptions (SIGNED for easier migrations)
- **Raw SQL migrations**: Use DB::statement() with raw MySQL, not Laravel schema builder
- **Restricted commands**: Several dangerous commands disabled (migrate:rollback, db:wipe, etc.)
- **Required columns enforced**: All tables have the audit authorship pairs (created_by_id/created_by_type, updated_by_id/updated_by_type), timestamps, etc. Soft-deleting tables also carry the deletion pair (deleted_by_id/deleted_by_type).
- **UTF-8 encoding standard**: All text columns use UTF-8

## Migration Commands

```bash
php artisan make:migration:safe     # Create whitelisted migration
php artisan migrate                 # Run migrations (snapshot-protected in the dev container,
                                    #   against a local database - see man migrations)
php artisan migrate:status          # View pending migrations
```

## Mode-Aware Behavior

**Development mode** (`RSX_MODE=development`), in the RSpade DEV container, against a
LOCAL database host - all three required (`Maint_Migrate::snapshot_protection_available()`):
- Automatically creates database snapshot before migrations
- On success: commits changes, regenerates constants, recompiles bundles
- On failure: automatically rolls back to snapshot

Anywhere else - the PRODUCTION container included, since it ships `mysql-client` only -
the run proceeds bare and prints every reason protection is off. See
`rsx:man migrations`, SNAPSHOT PROTECTION.

**Debug/Production mode** (`RSX_MODE=debug` or `production`):
- No datadir snapshot; no rollback unless `--dump-rollback` is given
- Schema normalization still runs
- Constants and bundles NOT regenerated

**`migrate --dump-rollback`** (`Migrate_Dump_Rollback`, this directory): wherever the
datadir snapshot is unavailable, a gzipped mysqldump in `storage/state/migrate_dump/` is
taken before migrating and restored on failure. The run record is `_migration_runs`
(created on first use, excluded from normalization and the schema rules) plus a marker
file; the next migrate restores an unfinished run's dump only when that run's ROW still
says `migrating`, and only deletes the leftovers of a finished one. `decide()` is the pure
decision table. See `rsx:man migrations`, DUMP ROLLBACK.
