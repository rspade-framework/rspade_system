# Portal - Issues Encountered

## ISSUE-1: rspade/ subdirectory migrations are never run on a fresh database  [RESOLVED 2026-06-23]

**Found:** 2026-06-23, during T1 (portal authorization) test provisioning.

**Resolved:** 2026-06-23 (task F-MIG). Migration discovery is now RECURSIVE across
the base paths and the combined file set is ordered by **timestamp basename across
all paths** (not per-directory concatenation), so a migration in a nested
subdirectory (`database/migrations/rspade/`) interleaves with sibling-path
migrations by its timestamp and runs after the tables it references.

Changes:
- `MigrationPaths::get_all_migration_files()` now discovers recursively
  (`_scan_migration_files()`) and orders by basename (`_sort_by_basename()`). This
  is the single source of truth for the complete, ordered migration set.
- The runner (`Maint_Migrate::run_migrations_with_normalization()`), the whitelist
  check (`Maint_Migrate::checkMigrationWhitelist()`), the pending-migration scan
  (`MigrationValidator::get_pending_migrations()`), and `migrate:pending` all use the
  shared recursive discovery / basename ordering, so they agree on one file set.
- The test-DB migration hash (`Rsx_Test_Command::compute_migration_hash`) consumes
  `get_all_migration_files()`, so the 5 `rspade/` files are now in the hash and the
  dump cache keys correctly. (Its `use` import of `MigrationPaths` was also corrected
  to the real namespace `App\RSpade\Core\Database\MigrationPaths`.)
- The `.migration_whitelist` is keyed by basename (matching how Laravel records
  migrations); the 5 `rspade/` basenames are already present in
  `database/migrations/.migration_whitelist`, so no separate subdir whitelist is
  needed and the check passes.

**Verification:** `php artisan rsx:test --framework --fresh` builds a test DB whose
`portal_memberships`, `shared_items`, `portal_projects` tables and
`portal_users.contact_id` column all exist, with the cross-directory FKs
(`portal_projects.project_id -> projects`, `.client_id -> clients`) succeeding (no
errno 1824). The test scaffolding (`__ensure_portal_schema()`) was removed and the
full framework suite is green (211/211). The dev DB is unaffected: the 5 migrations
are already recorded in its `_migrations` table by basename, so `migrate:pending`
reports nothing and they do not re-run.

---

### Original report (for history)

**Found:** 2026-06-23, during T1 (portal authorization) test provisioning.

**What happens:** The client-portal schema (`portal_memberships`, `shared_items`,
`portal_projects`, and the `portal_users.contact_id` column) lives in
`system/database/migrations/rspade/` (moved there by commit
`8b3611f15 "Move app-specific portal migrations to rspade/ subdirectory"`).

`MigrationPaths::get_all_paths()` returns only:
- `database/migrations`
- `rsx/resource/migrations`

Both the migration runner (`Maint_Migrate`) and the migration-hash cache
(`Rsx_Test_Command::compute_migration_hash` via
`MigrationPaths::get_all_migration_files()`) glob those paths **non-recursively**,
so the five migrations under `database/migrations/rspade/` are:
- never executed on a fresh database (e.g. a `--fresh` test DB), and
- not included in the migration hash.

The dev DB still has these tables only because the migrations were applied while
they lived in the root `database/migrations` directory (they are RECORDED in the
dev `_migrations` table). A freshly provisioned DB does not get them.

**Why it matters:** `php artisan rsx:test --framework --fresh` builds a test DB that
is missing the entire client-portal schema. Any test (or app) that relies on a
freshly migrated DB cannot see those tables.

**Why it was NOT fixed under T1:** Adding `database/migrations/rspade` to
`get_all_paths()` is not a one-line safe change:
- The runner globs each path separately and concatenates **without interleaving by
  timestamp basename**, so the rspade FK migrations (FK to `clients`/`contacts`/
  `projects`, which live in `rsx/resource/migrations`) run *before* their referenced
  tables exist -> `errno 1824 Failed to open the referenced table 'contacts'` on a
  fresh migrate. This was reproduced.
- It also interacts with the per-directory `.migration_whitelist` validation.

Fixing this correctly is migration-pipeline work (cross-path ordering by basename,
whitelist handling) with a broad blast radius - out of scope for T1 and explicitly
flagged rather than attempted. Recorded here for human review.

**Workaround in the test (REMOVED):** Until F-MIG,
`Portal_Authorization_Test::setup()` provisioned the needed tables/column
idempotently on the test connection (DDL in `setup()`). That scaffolding
(`__ensure_portal_schema()`) has been deleted now that the pipeline provisions the
schema from `database/migrations/rspade/` on a fresh DB. Removing it also surfaced a
latent test bug the FK-less scaffold had masked: `test_membership_scoped_grant_and_deny`
inserted a `portal_projects` row with a non-existent `project_id` (999); the real
schema's `project_id -> projects` FK now (correctly) rejects that, so the test was
updated to create a real `Project_Model` first.
