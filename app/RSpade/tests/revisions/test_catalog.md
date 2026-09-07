# revisions - test catalog

One row per test worth having (implemented AND not). Status is one of
`implemented`, `deferred`, `blocked`, `planned`.

## Revision_Codec_Test (php)

The storage format: the two-byte prefix, the size rule, the raw escape, and the refusals
on read. Every codec is driven directly through the `_encode_with()` seam rather than
inferred from an input that happens to select it.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| RC-01 | every codec is a byte-exact round trip and records itself in byte 0 | php | sample document, each codec | decode == input; codec_of == codec | implemented | 2026-08-28 |
| RC-02 | byte 1 carries the dictionary id, 0 for a dictionary-free codec | php | sample document | dict codec -> current id; plain -> 0 | implemented | 2026-08-28 |
| RC-03 | under SMALL_MAX the encoder chooses deflate | php | 127-byte document | CODEC_DEFLATE_DICT, smaller than input | implemented | 2026-08-28 |
| RC-04 | above BOTH_MAX the encoder chooses zstd | php | ~80 KB document | CODEC_ZSTD_DICT; round trips | implemented | 2026-08-28 |
| RC-05 | the 1-3 KB band stores the smaller of the two candidates | php | ~1.7 KB document | length == min(deflate, zstd) | implemented | 2026-08-28 |
| RC-06 | incompressible input escapes to RAW | php | 512 random bytes | CODEC_RAW; length == input + 2 | implemented | 2026-08-28 |
| RC-07 | binary and invalid UTF-8 survive byte-exact | php | NUL + invalid UTF-8 bytes | decode == input | implemented | 2026-08-28 |
| RC-08 | deflate is used everywhere when zstd is unavailable | php | large document, zstd seam false | CODEC_DEFLATE_DICT; round trips | implemented | 2026-08-28 |
| RC-09 | an unknown codec byte throws | php | forged prefix byte 97 | RuntimeException "unknown codec byte" | implemented | 2026-08-28 |
| RC-10 | an unknown dictionary id throws | php | forged dictionary byte 255 | RuntimeException "no _revision_dictionaries row" | implemented | 2026-08-28 |
| RC-11 | a payload too short to carry its prefix throws | php | one byte | RuntimeException "shorter than its two-byte prefix" | implemented | 2026-08-28 |
| RC-12 | stored size of a real recorded revision stays in band | php | end-to-end write | LENGTH(changes) below raw JSON | implemented (Revision_Recording_Test) | 2026-08-28 |

## Revision_Dictionary_Test (php)

The derivation: what the vocabulary contains, the order it is in, and when a new
dictionary is minted.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| RD-01 | every column of a known table is in the vocabulary | php | `_revisions` columns | each present as `"col":[` | implemented | 2026-08-28 |
| RD-02 | every enum label of a known model is in the vocabulary | php | `Login_User_Model::$enums` | each present quoted | implemented | 2026-08-28 |
| RD-03 | hot tokens are LAST: structural after columns, `id` after ordinary columns | php | built token list | `{"` last; ordinary < id < structural | implemented | 2026-08-28 |
| RD-04 | the byte buffer keeps the TAIL when over the window | php | oversized token list | length == window; ends with hottest | implemented | 2026-08-28 |
| RD-05 | regeneration builds when the table is empty | php | no rows | new id; current() reports it; sha1 hash, token_count > 0 | implemented | 2026-08-28 |
| RD-06 | regeneration does nothing when the dictionary is fresh | php | row minted seconds ago | null; current unchanged | implemented | 2026-08-28 |
| RD-07 | regeneration builds when the row is past the configured age | php | row aged 31 days, cadence 30 | new id appended; old row still readable | implemented | 2026-08-28 |
| RD-08 | minting an id past 255 is refused | php | existing row id 255 | RuntimeException "would exceed 255" | implemented | 2026-08-28 |
| RD-09 | `bytes_for()` of an absent id throws | php | id with no row | RuntimeException | implemented | 2026-08-28 |
| RD-10 | a cache reset makes `current()` re-read the table | php | row inserted behind the cache | stale before reset, newest after | implemented | 2026-08-28 |

## Revision_Migrate_Seam_Test (php)

Source-structure assertions on the two seams `migrate` owns for this subsystem. The
technique matches `migrate`'s own `Normalize_Schema_Rollback_Test`: what is worth
protecting is that the CALL SITES still exist in a command nobody re-reads, not that
`RsxCache::clear()` works.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| RM-01 | `execute_migrations()` reaches the dictionary step | php | method source | contains the call | implemented | 2026-08-28 |
| RM-02 | the dictionary step runs after the initial user | php | method source | dictionary offset > initial-user offset | implemented | 2026-08-28 |
| RM-03 | the step is skipped under `--framework-only` | php | method source | guarded by the option check | implemented | 2026-08-28 |
| RM-04 | the step delegates to Revision_Dictionary and reports only when it built | php | method source | delegation + `[OK] Revision dictionary` + null guard | implemented | 2026-08-28 |
| RM-05 | both migrate success tails clear the cache | php | both method sources | `RsxCache::clear();` + `[OK] Cache cleared` | implemented | 2026-08-28 |
| RM-06 | `migrate:restore` clears the cache after cleanup | php | handle() source | clear offset > cleanup offset | implemented | 2026-08-28 |
| RM-07 | a real `migrate` run prints the dictionary line on a fresh database | cli | fresh test DB | `[OK] Revision dictionary 1 built` | deferred - a full re-provision to observe one line | 2026-08-28 |

## Revision_Recording_Test (php)

The recording path in `Rsx_Model_Abstract`, driven with real writes against real fixture
tables. Fixtures: `Revision_Fixture_Model` (opt-in, soft-deleting, `$revision_exclude`),
`Revision_Child_Fixture_Model` (`#[Revision_Parent]`), `Revision_Plain_Fixture_Model`
(the opt-out control).

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| RR-01 | a create records every non-null column as [null, value] | php | new record | OPERATION_CREATE; name pair; created_at present | implemented | 2026-08-28 |
| RR-02 | an update records only the changed columns | php | one field edited | exactly one [before, after] pair | implemented | 2026-08-28 |
| RR-03 | a delete records an empty document | php | delete() | OPERATION_DELETE; zero pairs | implemented | 2026-08-28 |
| RR-04 | a restore is its own operation | php | delete() then restore() | OPERATION_UNDELETE; deleted_at pair | implemented | 2026-08-28 |
| RR-05 | the automatic and declared exclusions never appear | php | create with counter + _internal set | no counter, _internal, updated_at, updated_by pair | implemented | 2026-08-28 |
| RR-06 | a write touching only excluded columns records nothing | php | counter edited | no revision, no transaction | implemented | 2026-08-28 |
| RR-07 | an unchanged save records nothing | php | save() with nothing dirty | no revision | implemented | 2026-08-28 |
| RR-08 | a model that did not opt in records nothing | php | plain fixture write | no revision, no transaction | implemented | 2026-08-28 |
| RR-09 | `Revision::without()` suppresses recording | php | write inside without() | record written, no revision | implemented | 2026-08-28 |
| RR-10 | a throw inside without() restores suppression | php | throwing callable | is_suppressed() false; next write records | implemented | 2026-08-28 |
| RR-11 | two writes in one unit share one transaction | php | two saves | one transaction_id; sequences 1,2; revision_count 2; source test | implemented | 2026-08-28 |
| RR-12 | a reset starts a new transaction | php | save, reset, save | different transaction; sequence restarts; declared source | implemented | 2026-08-28 |
| RR-13 | describe() lands before AND after the mint | php | describe, save, describe | description on the row both times | implemented | 2026-08-28 |
| RR-14 | the actor pair is stamped from the acting user | php | `__acting_as_user(1)` | actor_type = User_Model's type ref, actor_id 1 | implemented | 2026-08-28 |
| RR-15 | a child files its revisions under its #[Revision_Parent] | php | child save | record pair = child, root pair = parent | implemented | 2026-08-28 |
| RR-16 | a top-level record is its own root | php | parent save | root pair = own pair | implemented | 2026-08-28 |
| RR-17 | revisions_including_children() reaches the child writes | php | parent + child | revisions() 1, including_children() 2 | implemented | 2026-08-28 |
| RR-18 | transactions_for() finds every unit that touched a record | php | two units | two transactions | implemented | 2026-08-28 |
| RR-19 | for_transaction() returns the unit in sequence order | php | two saves | sequences 1,2 | implemented | 2026-08-28 |
| RR-20 | a bulk update records one revision per row | php | `where()->update()` over 2 rows | 2 revisions | implemented | 2026-08-28 |
| RR-21 | `raw_bulk()` records nothing | php | `raw_bulk()->update()` | 0 revisions | implemented | 2026-08-28 |
| RR-22 | a rolled-back DB transaction leaves no revision row | php | write inside a throwing DB::transaction | `_revisions` count unchanged | implemented | 2026-08-28 |
| RR-23 | toArray() strips `_col` and keeps `__MODEL` | php | fixture toArray() | no _internal; __MODEL present | implemented | 2026-08-28 |
| RR-24 | the compressed blob is never serialized | php | revision toArray() | no `changes` key | implemented | 2026-08-28 |
| RR-25 | a real recorded revision stores compressed | php | end-to-end write | LENGTH(changes) < raw JSON (RC-12) | implemented | 2026-08-28 |
| RR-26 | diff() round-trips from storage | php | re-read row | the recorded pair comes back | implemented | 2026-08-28 |

## Revision_Cleanup_Test (php)

`Revision_Cleanup_Service::cleanup_revisions`, driven directly with a Task_Instance.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| RL-01 | retention 0 deletes nothing and says so | php | 400-day-old transaction | deleted 0; kept_forever true; row survives | implemented | 2026-08-28 |
| RL-02 | rows past the window go, newer ones stay | php | 60-day and 1-day rows | old gone, recent kept | implemented | 2026-08-28 |
| RL-03 | pruning a transaction cascades to its revisions | php | 60-day row with a revision | no `_revisions` rows left for it | implemented | 2026-08-28 |
| RL-04 | a backlog larger than one chunk fully drains | php | 5 old rows, chunk 2 | all five deleted | implemented | 2026-08-28 |

## Revision_Parent_Rule_Test (php, in `tests/code_quality/`)

REVISION-01, driven over synthetic fixture files through the rule's `evaluate_file()` seam.
Cataloged here because the rule is part of this concern's contract.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| RP-01 | a coherent declaration passes | php | recorded child -> recorded parent | 0 violations | implemented | 2026-08-28 |
| RP-02 | an unannotated method is ignored | php | plain hasMany | 0 violations | implemented | 2026-08-28 |
| RP-03 | a child that records nothing is flagged | php | no `$revisions` | 1 violation naming the opt-in | implemented | 2026-08-28 |
| RP-04 | the attribute on a non-belongsTo is flagged | php | hasMany | 1 violation naming belongsTo | implemented | 2026-08-28 |
| RP-05 | an unrecorded parent is flagged | php | belongsTo a non-recording model | 1 violation naming the parent | implemented | 2026-08-28 |
| RP-06 | an unresolvable parent is not judged | php | variable class name | 0 violations | implemented | 2026-08-28 |
| RP-07 | @REVISION-01-EXCEPTION suppresses the finding | php | marker in the docblock | 0 violations | implemented | 2026-08-28 |
