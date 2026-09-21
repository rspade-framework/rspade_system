---
name: migrations
description: "RSX database migrations and the column kinds a model declares over them - make:migration:safe, raw SQL enforcement (the Schema builder is prohibited), the forward-only no-rollback philosophy, the self-containment rule (no model class and no Type_Ref_Registry - MIGRATION-MODEL-01), automatic schema normalization and audit columns, and the datadir snapshot that auto-rolls-back a failed run (and the three conditions that decide whether it is taken at all). Use when creating or altering any table or column, adding a field to a model, choosing a column type (an enum is BIGINT + $enums, a polymorphic reference is a type-ref pair, rich text is TEXT + $text_types), converting an existing column to a declared text type, creating or altering a table, adding a column or index, writing or running a migration, troubleshooting a Schema-builder violation or a failed migrate, wondering whether it is safe to run migrate, or deciding where migrate sits in a production deployment (before the build) and what .migration_whitelist, rsx:migrate:check_consistency and migrate.normalize_schema.complete do there, or when migrate reports every migration as unauthorized or says the whitelist is not valid JSON."
---

# RSX Database Migrations

## Philosophy

RSX enforces a forward-only migration strategy with raw SQL:

1. **Forward-only** - No rollbacks, no `down()` methods
2. **Raw SQL only** - Direct MySQL statements, no Schema builder
3. **Fail loud** - Migrations must succeed or fail with clear errors
4. **Snapshot safety** - a snapshot is taken wherever one can be taken, and the run says out loud when one cannot

---

## Schema Builder is Prohibited

All migrations **must** use `DB::statement()` with raw SQL:

```php
// [OK] CORRECT
DB::statement("CREATE TABLE products (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00
)");

// [NO] WRONG - Schema builder prohibited
Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->string('name');
});
```

**Prohibited**: `Schema::create()`, `Schema::table()`, `Schema::drop()`, `Blueprint`, `$table->` chains

---

## Development Workflow

```bash
# 1. Create migration
php artisan make:migration:safe create_products_table

# 2. Write migration with raw SQL

# 3. Run migrations (snapshot-protected where the snapshot is possible - see below)
php artisan migrate
```

Where snapshot protection is engaged (see below), `migrate` automatically:
- Creates database snapshot before running
- Commits on success (regenerates constants, recompiles bundles)
- Auto-rollbacks on failure (database restored to pre-migration state)

### When a snapshot is taken - all three required

The mechanism is PHYSICAL: stop the local supervised mysqld, copy `/var/lib/mysql`,
and on failure wipe that directory and copy the backup back. So it happens only when:

1. **`RSX_MODE=development`**.
2. **The RSpade DEVELOPMENT container** - `/.rspade_container_dev`, not merely
   `/.rspade_container`. The PRODUCTION container carries the latter but ships
   `mysql-client` ONLY: no mysqld to stop, no datadir to copy.
3. **A LOCAL database host** - `localhost`, `127.0.0.1`, `::1`, or a configured unix
   socket. Against an external database, `/var/lib/mysql` is not the database at all.

`--framework-only` and the framework-internal `--_no-snapshot` suppress it for a run
even where the mechanism is available.

**Anywhere else the run proceeds bare and prints every reason protection is off** -
there is no rollback of any kind. Development mode outside ANY RSpade container is
refused outright (different rule: stopping somebody else's MySQL is not ours to do).

**Why the production container no longer snapshots**: it never could - that image has
no mysqld. The dangerous half was always the ROLLBACK, which would repopulate a datadir
that is not the live database and report a successful rollback while the real database
stayed broken. **A false rollback is worse than none**, so a rollback that cannot be
performed is neither attempted nor advertised. `migrate:restore` applies the identical
predicate and refuses, naming the condition, leaving the flag and snapshot untouched.

**Run `migrate` with its FULL output** - never pipe it through `| tail`/`| head` or
otherwise truncate: the snapshot/rollback narrative IS the diagnostic, and a truncated
run hides which step failed.

**A migrate cancelled mid-run** (Ctrl-C, killed process) leaves the migration flag and
the snapshot behind with nobody to restore them. `php artisan migrate:restore` performs
the exact restore a failed run performs automatically - and reports "No migration is in
progress - nothing to restore" when there is no flag. A FAILED restore keeps the flag
and the snapshot in place (the snapshot is the only copy of the pre-migration data).

**Never put a timeout anywhere on the snapshot/rollback path.** A 60-second cap on `run_privileged_command()` once failed a MySQL datadir snapshot on a large database, and the same cap sat unnoticed on the ROLLBACK path - where firing would have abandoned a half-copied datadir and destroyed the very state the snapshot existed to protect. A datadir copy takes as long as the data requires; slowness here is normal and is never evidence of a hang.

---

## Automatic Normalization

The system auto-normalizes types after migration. You can write simpler SQL:

| You Write | System Converts To |
|-----------|-------------------|
| `INT` | `BIGINT` |
| `TEXT` | `LONGTEXT` |
| `FLOAT` | `DOUBLE` |
| `TINYINT(1)` | Preserved (boolean) |

**Auto-added columns - NEVER declare these yourself.** The schema-hygiene pass adds `created_at`/`updated_at TIMESTAMP(3)` plus the polymorphic authorship PAIRS (`created_by_id` + `created_by_type`, `updated_by_id` + `updated_by_type`, and `deleted_by_id` + `deleted_by_type` wherever the table has `deleted_at`), and converges any older spelling by rename.

**Never reference them positionally (`AFTER updated_by`) or by a pre-pair name** - the rename would leave your `ALTER` pointing at a column that no longer exists.

---

## A Migration Never References Application Code

A migration is a **forward-only historical record that must replay cleanly from scratch forever**. A model class is **current code** that gets renamed and deleted. Naming one couples the two, and the day that class is retired a from-scratch replay of the whole chain hard-fails at a file nobody has touched in a year.

So a migration carries **no model class, no `Type_Ref_Registry`, no service** - only raw SQL and plain PHP. `rsx:check` enforces it as **MIGRATION-MODEL-01** (severity high; detection is over the PHP token stream, so a class name in a comment or a string literal is never a violation).

`Type_Ref_Registry::class_to_id('Foo_Model')` is how this usually gets broken: it validates the name against the LIVE manifest, so once `Foo_Model` is deleted every replay dies with `Cannot create type ref for 'Foo_Model': Class not found in manifest.` Resolve the id against the table instead, class name as a **string**, table name hardcoded:

```php
$type_ref_id = function (string $class_name, string $table_name): int {
    $existing = DB::select("SELECT id FROM _type_refs WHERE class_name = ?", [$class_name]);
    if (!empty($existing)) {
        return (int) $existing[0]->id;
    }
    DB::statement(
        "INSERT INTO _type_refs (class_name, table_name, created_at, updated_at) VALUES (?, ?, NOW(3), NOW(3))",
        [$class_name, $table_name]
    );
    return (int) DB::getPdo()->lastInsertId();
};

$contact_id = $type_ref_id('Contact_Model', 'contacts');
DB::statement("UPDATE activities SET eventable_type = {$contact_id} WHERE eventable_type = 'Contact_Model'");
```

Ids are still never hardcoded - the closure resolves or creates the row at replay time, so it is correct on a fresh install and in every environment.

**An exception is essentially never justified.** A table name and raw SQL always suffice - including for a BACKFILL, which is the case people most often reach for a model to do: SELECT the rows and UPDATE them. The only shape that has ever earned one is data seeding that needs model BEHAVIOUR no SQL can reproduce (bytes that must travel through the file-upload pipeline, a value that must be written through an encryption cast). It declares itself in the file docblock, and the rationale is required - a bare marker is itself a violation:

```php
/**
 * @MIGRATION-MODEL-01-EXCEPTION <why raw SQL cannot do this>
 */
```

Schema work, type-ref lookups and backfills are never the exception; convert those.

**The second hazard, for any migration that does write through a model: on a production host THE MANIFEST CAN BE AHEAD OF THE DATABASE.** A production build bakes every model's column map in at build time, so a deployment that builds before it migrates is running models that know columns the tables do not have yet - and a write through such a model fails on an unknown column, part-way through the run, on the box where a half-applied migration costs the most. Raw SQL against a table name describes the schema AT THAT POINT IN HISTORY and cannot drift.

---

## What a column holds is declared on the model

The migration carries the STORAGE type only. What the column means is a model declaration,
and each kind has its own skill:

| Column | Migration | Model | Skill |
|---|---|---|---|
| enum (status, type, role) | `BIGINT` | `public static $enums` | `rspade:model-enums` |
| polymorphic reference | `BIGINT` `_type` + `_id` pair | `$type_ref_columns` | `rspade:polymorphic` |
| rich text / custom notation | `TEXT` | `public static $text_types` | `rspade:text-types` |
| type-specific 1:1 fields | a detail table | `$detail_tables` | `rspade:detail-tables` |

A text type exists so the encoding of a TEXT column (sanitized HTML from a WYSIWYG, an
`{{Entity:id}}` notation) is stated once and every print, edit, export and index asks the
value instead of remembering. **Converting an existing plain-text column is two acts**: a
raw-SQL migration that re-encodes the rows into the type's storage form (this rule forbids
calling the type class here; reproduce what its `from_string()` produces), then the
`$text_types` declaration.

---

## No Defensive Coding

A migration runs against a state you already know. Write the exact transformation.

```php
// [NO] WRONG - guessing at the current state
if (Schema::hasColumn('products', 'sku')) { ... }
DB::statement("ALTER TABLE products ADD COLUMN IF NOT EXISTS sku VARCHAR(50) NULL");
$exists = DB::select("SELECT * FROM information_schema.columns WHERE ...");

// [OK] CORRECT - know the state, state the transformation
DB::statement("ALTER TABLE products ADD COLUMN sku VARCHAR(50) NULL AFTER name");
```

No `IF EXISTS`, no `information_schema` queries, no fallbacks. **Failures fail loud** - in development the automatic snapshot rollback is what handles recovery, so a migration that guesses is hiding a defect for no benefit.

---

## Detail Tables

A 1:1 detail table for class-table inheritance is NOT hand-written DDL - use `Rsx_Detail_Table::create($detail_table, $parent_table, $columns)`, which emits the surrogate id, the UNIQUE FK, the cascade and the audit columns. See the `rspade:detail-tables` skill.

---

## Migration Examples

### Simple Table (Recommended)

```php
public function up()
{
    DB::statement("
        CREATE TABLE products (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            stock_quantity INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            category_id INT NULL,
            INDEX idx_category (category_id),
            INDEX idx_active (is_active)
        )
    ");
}
```

**Notes**:
- `INT` becomes `BIGINT` automatically
- `TEXT` becomes `LONGTEXT` automatically
- `created_at`/`updated_at` added automatically
- `TINYINT(1)` preserved for booleans

### Adding Columns

```php
public function up()
{
    DB::statement("
        ALTER TABLE products
        ADD COLUMN sku VARCHAR(50) NULL AFTER name,
        ADD COLUMN weight DECIMAL(8,2) NULL,
        ADD INDEX idx_sku (sku)
    ");
}
```

### Foreign Keys

```php
public function up()
{
    DB::statement("
        ALTER TABLE orders
        ADD CONSTRAINT fk_orders_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON DELETE CASCADE
    ");
}
```

---

## Required Table Structure

**Every table MUST have**:

```sql
id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY
```

This is non-negotiable. Use SIGNED (not UNSIGNED) for easier future migrations.

---

## Foreign Key Columns

Foreign key columns **must match** the referenced column type exactly:

```sql
-- If users.id is BIGINT, then:
user_id BIGINT NULL  -- [OK] Matches

-- Column names ending in _id are assumed to be foreign keys
```

---

## Debug/Production Workflow

```bash
# In debug or production mode (RSX_MODE=debug or production)
php artisan migrate
```

In debug/production mode:
- No snapshot protection and no rollback
- Schema normalization still runs, and the app hook fires after every pass
- **NO SOURCE FILE IS WRITTEN.** Constants, model docblocks and bundles are not regenerated - they are SOURCE, committed from the development box that authored the migration - and a missing `.migration_whitelist` is skipped rather than created
- **`rsx:migrate:check_consistency` runs at the end and ITS EXIT CODE IS THE COMMAND'S**

**Migrate BEFORE the build.** The manifest bakes every model's column map in at BUILD time and the database moves at MIGRATE time, so the recommended deployment order is `migrate` and then `rsx:mode:set prod`. Build-then-migrate leaves the served code believing in columns the tables do not have yet. The consistency check is what catches the disagreement: a manifest column missing from its table is an `[ERROR]` and exits 1, a table column unknown to the manifest is a `[WARNING]` and exits 0, and the repair is `php artisan rsx:build --force`. It runs in a production mode only - a development box rebuilds its manifest from the live schema on the next request, so the comparison would be "consistent" by construction.

**`.migration_whitelist` is SOURCE.** It sits beside the migrations, lists every migration `make:migration:safe` created, and is a STRAY-FILE TRIPWIRE: a migration in the tree that the file does not list aborts the whole run before the database is touched. It is WRITTEN only in development, CONSULTED in every mode when present, and SKIPPED SILENTLY when absent outside development.

**A whitelist that does not parse ABORTS the run naming the path** ("not valid JSON ... the usual cause is merge conflict markers left in the file"), never degrading into "every migration is unauthorized". **Its merge is the KEY-UNION of both sides' `migrations` maps**, sorted by key - the keys are timestamped filenames, unique by construction, so two sides cannot disagree - and `rsx:git` performs it for you, so resolving one by hand should never be necessary. Doing it as TEXT is the natural move and the wrong one: the result is not JSON.

**`migrate.normalize_schema.complete` fires after EVERY normalize pass** - once before any migration, once after each individual migration, once at the end. N pending migrations means N+1 firings, and a run with nothing pending fires twice. **Write every handler idempotent**, which it was always required to be. Firing after each pass is what makes an incremental box and a from-zero replay produce the same schema at every step.

Ensure migrations are thoroughly tested in development first.

---

## Validation

The migration validator automatically checks for:
- Schema builder usage
- `down()` methods (auto-removed)
- Proper SQL syntax

Violations show clear error messages with remediation advice.

---

## The Fresh-Database Schema Cache

**A database with NO TABLES AT ALL restores a shipped snapshot before it migrates.** When `rsx/resource/db/schema_cache.sql.gz` exists, `migrate` streams it in (plus `uploads_cache.tar.gz` into the blob store) and then **continues with the ordinary run**. It is a PRE-MIGRATE step, never a replacement: migrations newer than the cache apply on top exactly as they always would.

**A stale cache is therefore never a bug**, and it is never yours to refresh on your own initiative. `php artisan rsx:db:dump_cache` rebuilds it, and doing so **takes the app down, wipes and restores the developer's own database and blob store, and rewrites roughly half a megabyte of committed binary**. It is a development-only command that runs every few months, when the operator asks for it by name.

## Troubleshooting

| Error | Solution |
|-------|----------|
| "Found forbidden Schema builder usage" | Replace with `DB::statement()` |
| "Validation failed" | Check migration for prohibited patterns |
| MIGRATION-MODEL-01 from `rsx:check` | Drop the model / registry reference - resolve type-ref ids with the `_type_refs` closure above |
| Foreign key constraint fails | Ensure column types match exactly |

## More Information

Where a migration FILE lives (framework-core vs template-app directory) is a monorepo concern and does not apply to an application - an app's migrations go in its own migrations directory.

Details: `php artisan rsx:man migrations`, `php artisan rsx:man database_schema_architecture`, `php artisan rsx:man prod` (the deployment order and the consistency check)
