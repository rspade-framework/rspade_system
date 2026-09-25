# migrate - test catalog

One row per test worth having (implemented AND not). Status is one of
`implemented`, `deferred`, `blocked`, `planned`.

## Normalize_Schema_Rollback_Test (php)

Pins B4.1: `migrate:normalize_schema` must perform NO logical rollback and must not
falsely report "rolled back successfully". Source-structure assertions against the
command's `handle()` catch path (no migration/DDL executed - cannot damage the DB).

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| NSR-01 | command never invokes migrate:rollback (no logical rollback) | php | command source | no `migrate:rollback` token | implemented | 2026-07-30 |
| NSR-02 | Artisan facade no longer used (logical rollback removed) | php | command source | no `Artisan::call` | implemented | 2026-07-30 |
| NSR-03 | command never claims a successful rollback | php | command source | no `rolled back successfully` | implemented | 2026-07-30 |
| NSR-04 | catch block re-throws the original exception | php | handle() catch source | contains `throw $e;` | implemented | 2026-07-30 |
| NSR-05 | catch block disables query echo before re-throw | php | handle() catch source | contains `disable_query_echo()` | implemented | 2026-07-30 |
| NSR-06 | catch block carries no recovery logic | php | handle() catch source | no rollback call / false success msg | implemented | 2026-07-30 |

## Normalize_Schema_Single_Alter_Test (php)

`migrate:normalize_schema` applies ALL of a table's normalizations in ONE `ALTER TABLE t
clause, clause, ...` per pass instead of one statement per change - every MODIFY / ADD INDEX
/ CONVERT was its own full pass over the rows. Runs a real pass against a throwaway table
shaped like `user_profiles` before normalization.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| NSA-01 | a table receives exactly ONE ALTER TABLE per pass, carrying every expected clause | php | unnormalized probe table | 1 captured ALTER; all 12 clauses present | implemented | 2026-08-24 |
| NSA-02 | the resulting schema is correct (audit pairs, TIMESTAMP(3)+defaults, DATETIME(3), order BIGINT NULL, utf8mb4) | php | same pass | SHOW CREATE TABLE matches | implemented | 2026-08-24 |
| NSA-03 | an index on a column ADDED in the same statement still lands (pending-column tracking; closes the latent next-pass gap) | php | same pass | created_at / updated_at / order_idx present | implemented | 2026-08-24 |
| NSA-04 | the order triggers are created after the flush, not before | php | same pass | both triggers exist | implemented | 2026-08-24 |
| NSA-05 | idempotence - a normalized table gets ZERO ALTER statements on the next pass | php | second pass | 0 captured ALTERs | implemented | 2026-08-24 |
| NSA-06 | the refusal path emits no DDL at all (clauses queued, throw precedes the flush) | php | populated created_by + created_by_id | throw; 0 ALTERs; schema byte-identical | implemented | 2026-08-24 |

## Normalize_Schema_Timestamp_Index_Test (php)

`migrate:normalize_schema` gives every table an index LEADING with `created_at` and one
leading with `updated_at`, deciding coverage by the leading column and never by the index
name. Real passes against throwaway probe tables.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| NTI-01 | an index already leading with the column satisfies the pass whatever its name | php | `idx_probe_created (created_at)`, `idx_probe_updated_then_id (updated_at, id)` | no second copy; those two stay the only leading indexes | implemented | 2026-09-25 |
| NTI-02 | a composite that only CONTAINS the column does not count | php | `(site_id, created_at)` only | `created_at` and `updated_at` added; the composite untouched | implemented | 2026-09-25 |
| NTI-03 | after a pass, every table leads an index with each timestamp | php | the whole test database | no table lacks either | implemented | 2026-09-25 |

## Deferred integration coverage (destructive - infra harness follow-up)

Proving the pre/post/mid-loop normalize failures route to the REAL recovery
(`Maint_Migrate::rollback_snapshot()` + `cleanup_migration_mode()`) requires a real
MySQL datadir, Docker, and supervisor control of mysqld. These are destructive
(they stop/restore the datadir) and must run in a dedicated infra harness against
`rspade_test`, never in-process. Not attempted in this ticket.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| NSR-E2E-01 | pre-migration normalize failure triggers datadir snapshot restore + clears `.migrating` flag/backup | cli | migrate with a normalize-forcing fault before migrations | datadir restored; no orphaned flag/backup; exit 1 | deferred (destructive; needs MySQL datadir harness) | 2026-07-30 |
| NSR-E2E-02 | post-migration normalize failure triggers datadir snapshot restore + cleanup | cli | migrate with a normalize-forcing fault after migrations | datadir restored to pre-migration state; exit 1 | deferred (destructive; needs MySQL datadir harness) | 2026-07-30 |
| NSR-E2E-03 | mid-loop normalize failure triggers datadir snapshot restore + cleanup | cli | migrate with a normalize-forcing fault between two migrations | datadir restored; migrations reverted; exit 1 | deferred (destructive; needs MySQL datadir harness) | 2026-07-30 |
| MSN-01 | migrate:status_notice always exits 0 and, when it emits, matches the exact documented wording | php | run the command | exit 0; any output matches the "There are N unapplied migrations pending..." format | implemented | 2026-07-30 |

## Preflight_Mysqld_Topology_Test (php)

Pins B-47: `Maint_Migrate::preflight_mysqld_topology()` fails loud, before anything is
stopped, when the mysqld topology is not the single supervised instance the datadir
snapshot assumes - so `supervisorctl stop mysql` can never leave a stray mysqld serving
while the snapshot silently times out. Pure classification driven by four overridden
process probes; no process spawned, no DB touched.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| PMT-01 | zero mysqld (cold datadir) is not the rogue condition | php | running=[] | no throw | implemented | 2026-08-14 |
| PMT-02 | one mysqld == supervisor's pid (exec case) passes | php | running=[100], supervised=100 | no throw | implemented | 2026-08-14 |
| PMT-03 | one mysqld descended from supervisor's pid (mysqld_safe wrapper) passes | php | running=[200], supervised=150, 200 child of 150 | no throw | implemented | 2026-08-14 |
| PMT-04 | a stray second mysqld aborts, tags supervised vs stray, offers the stray's kill only | php | running=[100,999], supervised=100 | throw naming B-47; `sudo kill 999`; never `sudo kill 100` | implemented | 2026-08-14 |
| PMT-05 | a lone unsupervised mysqld (supervisor reports mysql stopped) aborts | php | running=[777], supervised=null | throw naming B-47; "does NOT report a RUNNING mysql program" | implemented | 2026-08-14 |
| PMT-06 | serving mysqld != supervisor's managed pid aborts | php | running=[777], supervised=555 | throw naming B-47; "Supervisor manages mysqld pid 555" | implemented | 2026-08-14 |

## Snapshot_Protection_Predicate_Test (php)

Pins the predicate deciding whether `migrate` may perform the stop-MySQL / snapshot /
restore-on-failure dance at all: development mode AND the RSpade DEVELOPMENT container
(`/.rspade_container_dev` - the PRODUCTION container carries `/.rspade_container` but ships
mysql-client only) AND a LOCAL database host. Anywhere else the run proceeds bare, because
a rollback into a `/var/lib/mysql` that is not the live database would report a successful
rollback while the real database stayed broken. Three overridden environment probes plus
two invocation suppressors; no container, no DB, no process.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| SPP-01 | all three conditions true -> protected, no reasons | php | dev + dev container + 127.0.0.1 | available; engaged; reasons == [] | implemented | 2026-08-26 |
| SPP-02 | not development mode -> bare | php | development=false | unavailable; reason names the mode | implemented | 2026-08-26 |
| SPP-03 | not an RSpade container -> bare | php | container=false | unavailable; reason names /.rspade_container | implemented | 2026-08-26 |
| SPP-04 | PRODUCTION-target container -> bare (the case this exists for) | php | container=true, dev_container=false | unavailable; names /.rspade_container_dev + mysql-client only | implemented | 2026-08-26 |
| SPP-05 | external database host -> bare | php | host=db.internal.example.com | unavailable; reason names the host | implemented | 2026-08-26 |
| SPP-06 | --framework-only suppresses the run, mechanism still available | php | framework_only=true | available; NOT engaged; reason names --framework-only | implemented | 2026-08-26 |
| SPP-07 | --_no-snapshot suppresses the run, mechanism still available | php | flag=true | available; NOT engaged; reason names the flag | implemented | 2026-08-26 |
| SPP-08 | EVERY applicable reason is reported, not just the first | php | prod container + remote host + both suppressors | 3 reasons, all named | implemented | 2026-08-26 |
| SPP-09 | loopback spellings are local (case/whitespace/bracketed ::1) | php | localhost, LOCALHOST, ' 127.0.0.1 ', ::1, [::1] | local | implemented | 2026-08-26 |
| SPP-10 | a configured unix socket is local regardless of host | php | host=db.example.com + unix_socket set | local | implemented | 2026-08-26 |
| SPP-11 | hosts merely BEGINNING with a loopback literal are remote | php | 127.0.0.1.attacker.example, localhost.example.com, 127.0.0.10, 1270.0.1, ::11, '' | NOT local | implemented | 2026-08-26 |
| SPP-12 | an empty unix_socket does not make a remote host local | php | host=db.example.com, unix_socket='' | NOT local | implemented | 2026-08-26 |
| MNCE-01 | fire_post_normalize_hook() fires the event as an action with an empty payload | php | method source | trigger_action('migrate.normalize_schema.complete', []) present | implemented | 2026-09-15 |
| MNCE-02 | the FINAL firing sits AFTER the post-migration migrate:normalize_schema call | php | method source offsets | last firing offset > last normalize offset | implemented | 2026-09-15 |
| MNCE-03 | the final firing sits BEFORE create_initial_user_if_needed() | php | method source offsets | firing offset < initial-user offset | implemented | 2026-09-15 |
| MNCE-04 | the final firing sits BEFORE build_revision_dictionary_if_stale() | php | method source offsets | firing offset < dictionary offset | implemented | 2026-09-15 |
| MNCE-05 | nothing catches around the dispatcher or the final firing - a throwing handler rolls the run back | php | dispatcher source + span to initial user | no 'catch' | implemented | 2026-09-15 |
| MNCE-06 | the dispatch lives in exactly ONE method - no second hand-written trigger | php | whole command file | one trigger_action for the event | implemented | 2026-09-15 |
| MNCE-07 | the dispatcher is called after every normalize pass (2 in execute_migrations, 1 in the loop) | php | both method sources | call counts 2 and 1 | implemented | 2026-09-15 |
| MNCE-08 | the mid-loop firing follows its normalize call and its failure check | php | loop source offsets | firing after both | implemented | 2026-09-15 |
| MNCE-09 | the pre-migration firing follows the pre pass and precedes the migration loop | php | method source offsets | between the two | implemented | 2026-09-15 |
| TRTR-01 | `RENAME TABLE a TO b` is recognised | php | plain statement | one from/to pair | implemented | 2026-09-01 |
| TRTR-02 | Backticks and a `db`.`table` qualifier are stripped - `_type_refs` stores a bare name | php | quoted, qualified statement | bare names | implemented | 2026-09-01 |
| TRTR-03 | A multi-rename list records every pair | php | `RENAME TABLE a TO b, c TO d;` | two pairs, in order | implemented | 2026-09-01 |
| TRTR-04 | `ALTER TABLE a RENAME [TO\|AS] b` and the bare `RENAME b` form are recognised | php | all three spellings | one pair each | implemented | 2026-09-01 |
| TRTR-05 | Multi-line heredoc whitespace does not defeat the parser | php | newline-wrapped statement | one pair | implemented | 2026-09-01 |
| TRTR-06 | `RENAME COLUMN` / `RENAME INDEX` / `RENAME KEY` are NOT table renames | php | all three | nothing recorded | implemented | 2026-09-01 |
| TRTR-07 | Ordinary DDL (CREATE TABLE, ALTER ... ADD COLUMN) records nothing | php | two statements | nothing recorded | implemented | 2026-09-01 |
| TRTR-08 | A renamed table is followed into `_type_refs.table_name`, narrating class + both names | php | planted registry row + rename | row updated, one narrative entry | implemented | 2026-09-01 |
| TRTR-09 | A chain of renames lands the row on the FINAL name (ordered replay, not a map) | php | a->b then b->c | table_name = c, two entries | implemented | 2026-09-01 |
| TRTR-10 | Renaming a table no type ref names changes and narrates nothing | php | unknown table rename | empty | implemented | 2026-09-01 |
| TRTR-11 | apply() is a no-op when the run renamed nothing | php | no observations | empty | implemented | 2026-09-01 |
| TRTR-12 | The statement macro every migration passes through hands the SQL to the observer | php | register_query_transformer() source | `Type_Ref_Table_Rename::observe(` present | implemented | 2026-09-01 |
| TRTR-13 | execute_migrations() resets at the start and applies the renames in the same run | php | method source | reset() + apply_type_ref_table_renames() present | implemented | 2026-09-01 |

## Make_Migration_Whitelist_Location_Test (php)

`make:migration:safe` records its whitelist entry BESIDE THE FILE IT AUTHORIZES. Every migration
directory carries its own `.migration_whitelist` and the migrator reads the one next to each file,
so the entry has to follow `--path`. A downstream field report measured the split: the file landed
under `system/` and the entry in the application tree, where it authorized nothing and dirtied a
tree the migration does not live in.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| MIGRATE-WHITELIST-PATH | the entry lands in the directory --path names, and no other whitelist is touched | php | a real mint into a scratch dir under app/RSpade/temp | file + .migration_whitelist in that dir, entry names the file, app whitelist byte-identical | implemented | 2026-09-08 |

## Post_Normalize_Hook_Per_Migration_Test (php)

`migrate.normalize_schema.complete` fires after EVERY normalize pass - pre-migration, each
per-migration pass, and post-migration - so a run of N pending migrations fires it N+1 times
and a run with nothing to migrate fires it twice. Before this, a from-zero replay fired the
hook once at the end, so every mid-history migration saw a schema no incremental box ever
produced and a migration reading a hook-computed column died with an unknown-column error
(a downstream field report, 2026-09-15). The per-migration firings are measured by running
the REAL loop against a scratch migration directory with a fake migrator and a stubbed
normalize call; the two firings inside `execute_migrations()` are pinned structurally by
MNCE-07 and carried here as a constant.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| PNH-01 | two pending migrations produce one mid-loop firing | php | 2 staged migration files | 1 firing | implemented | 2026-09-15 |
| PNH-02 | N pending migrations produce N-1 mid-loop firings | php | 4 staged migration files | 3 firings | implemented | 2026-09-15 |
| PNH-03 | a single pending migration has no BETWEEN and fires nothing mid-loop | php | 1 staged file | 0 firings | implemented | 2026-09-15 |
| PNH-04 | nothing to migrate never enters the loop | php | 0 staged files | 0 firings | implemented | 2026-09-15 |
| PNH-05 | the whole-run total is N+1 (and 2 when nothing is pending) | php | 0,1,2,4,7 staged files | 2,2,3,5,8 | implemented | 2026-09-15 |
| PNH-06 | a FAILED normalize pass throws and fires nothing | php | stubbed exit code 1 | throw; 0 firings | implemented | 2026-09-15 |

## Check_Consistency_Test (php)

`rsx:migrate:check_consistency` compares the SEALED manifest's column map against the live
schema after a production migrate, and `migrate` propagates its exit code. Driven against
real fixture tables in the test database plus a replaced model map in `Manifest::$data`.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| CC-01 | development mode is a fatal refusal naming why | php | dev mode | exit 1; "runs in a production mode only" | implemented | 2026-09-15 |
| CC-02 | debug is a production mode and the check runs there | php | debug mode | exit 0 | implemented | 2026-09-15 |
| CC-03 | no manifest index is a fatal refusal naming rsx:build --force | php | build root redirected to an empty dir | exit 1; names the file and the repair | implemented | 2026-09-15 |
| CC-04 | a detail-table base model passes - merged detail columns are never compared against the base table | php | base model carrying a detail-sourced column | exit 0; the detail column is never named | implemented | 2026-09-15 |
| CC-05 | a manifest column missing from its table is an ERROR and exits 1, with the two-orderings explainer | php | one extra manifest column | exit 1; names the column, rsx:build --force, migrate, rsx:man prod | implemented | 2026-09-15 |
| CC-06 | every discrepancy is listed before the verdict | php | two extra manifest columns | both named; verdict counts 2 | implemented | 2026-09-15 |
| CC-07 | a manifest table missing from the database is an ERROR | php | a model whose table was never created | exit 1; names the table | implemented | 2026-09-15 |
| CC-08 | a table column unknown to the manifest is a WARNING and exits 0 | php | one extra database column | exit 0; warning names it | implemented | 2026-09-15 |
| CC-09 | a warning and an error together exit 1 with both reported | php | both at once | exit 1; both named | implemented | 2026-09-15 |
| CC-E2E-01 | a production `migrate` returns the check's non-zero exit as its own | cli | prod-mode migrate against a mismatched build | exit 1 | deferred (covered by the prod lifecycle bash test, Phase E) | 2026-09-15 |

## Whitelist_Prod_Mode_Test (php)

`.migration_whitelist` is SOURCE: WRITTEN only in development, CONSULTED in every mode,
SKIPPED silently when absent outside development. It was the one ungated source write left on
a production `migrate` (a downstream field report, 2026-09-15). Driven through the command's
own `whitelist_locations()` seam against a throwaway sandbox.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| WPM-01 | createInitialWhitelist() refuses loudly in debug and production | php | each prod mode | nothing written; refusal names why | implemented | 2026-09-15 |
| WPM-02 | development writes it | php | dev mode | file created | implemented | 2026-09-15 |
| WPM-03 | an absent whitelist in a production mode is skipped SILENTLY and the run proceeds | php | prod modes, no file | true; nothing written; no output | implemented | 2026-09-15 |
| WPM-04 | an absent whitelist in development is created and the run proceeds | php | dev mode, no file | true; file created | implemented | 2026-09-15 |
| WPM-05 | a present whitelist is enforced in EVERY mode - a stray migration aborts the run | php | all three modes, one unlisted file | false; the stray is named | implemented | 2026-09-15 |
| WPM-06 | a fully listed tree passes in every mode and the file is never rewritten | php | all three modes | true; whitelist contents unchanged | implemented | 2026-09-15 |

## Constants_Regenerate_Scope_Test (php)

`rsx:constants:regenerate` never rewrites a file under `system/` unless this box is a
framework-developer box - and the test is on the RESOLVED declaring file, because a shell
model in `rsx/models/` declares its `$table` in an abstract base under `app/RSpade/`, which is
the file the command would actually write (dirtying the downstream `system/` submodule on
every development migrate).

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| CRS-01 | a framework file is refused downstream | php | app/RSpade path, flag false | false | implemented | 2026-09-15 |
| CRS-02 | a framework file is allowed for a framework developer | php | same path, flag true | true | implemented | 2026-09-15 |
| CRS-03 | the abstract half of a split core model is refused downstream | php | a real *_Model_Abstract.php | false | implemented | 2026-09-15 |
| CRS-04 | an application file is writable in either posture | php | rsx/ path, both flags | true | implemented | 2026-09-15 |
| CRS-05 | a nonexistent framework path still classifies | php | a deleted app/RSpade path | false | implemented | 2026-09-15 |
| CRS-06 | a sibling directory sharing the prefix is not the framework tree | php | app/RSpade_extras path | true | implemented | 2026-09-15 |
| CRS-07 | the gate is applied AFTER resolve_declaring_file(), not to the model's own file record | php | handle() source offsets | gate offset > resolve offset | implemented | 2026-09-15 |

## Migration_Whitelist_Parse_Test (php)

The whitelist is parsed in ONE place (`Maint_Migrate::read_whitelist_entries()`), and a
file that does not parse is an ERROR naming the path - never an empty map, which would
fire the stray-file tripwire on every migration in the tree under a message that names
`make:migration` and not the file. The same class covers `bin/lib/merge_migration_whitelist.php`,
the resolver `rsx:git` runs to produce the union in the first place.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| MWP-01 | a whitelist carrying merge conflict markers refuses the run, naming the path and the usual cause | php | conflicted file, dev mode | false; "not valid JSON"; never "Unauthorized migrations detected" | implemented | 2026-09-21 |
| MWP-02 | a valid whitelist listing the tree passes silently | php | listed tree | true; no output | implemented | 2026-09-21 |
| MWP-03 | the parse seam answers null for markers and for JSON with no migrations map, and [] for a whitelist that lists nothing | php | three files | null, null, [] | implemented | 2026-09-21 |
| MWP-04 | the resolver produces the sorted key-union in the writer command's own JSON shape, and the accounting line the proxy quotes | php | base/ours/theirs | 3 keys in filename order; ours=2 theirs=2 merged=3 | implemented | 2026-09-21 |
| MWP-05 | an EMPTY base stage (the file is new on both sides) is not a failure | php | empty base | both sides' keys | implemented | 2026-09-21 |
| MWP-06 | a stage that is not a whitelist exits 2 naming the stage, and writes nothing | php | malformed ours, then a theirs with no migrations map | exit 2 both times | implemented | 2026-09-21 |
