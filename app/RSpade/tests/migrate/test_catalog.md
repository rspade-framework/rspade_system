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
| MNCE-01 | execute_migrations() fires migrate.normalize_schema.complete as an action with an empty payload | php | method source | trigger_action('migrate.normalize_schema.complete', []) present | implemented | 2026-08-28 |
| MNCE-02 | the trigger sits AFTER the post-migration migrate:normalize_schema call | php | method source offsets | trigger offset > last normalize offset | implemented | 2026-08-28 |
| MNCE-03 | the trigger sits BEFORE create_initial_user_if_needed() | php | method source offsets | trigger offset < initial-user offset | implemented | 2026-08-28 |
| MNCE-04 | the trigger sits BEFORE build_revision_dictionary_if_stale() | php | method source offsets | trigger offset < dictionary offset | implemented | 2026-08-28 |
| MNCE-05 | nothing catches around the trigger - a throwing handler rolls the run back | php | source between trigger and initial user | no 'catch' | implemented | 2026-08-28 |
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
