# derived_columns - test catalog

One row per test worth having (implemented AND not). Status is one of
`implemented`, `deferred`, `blocked`, `planned`.

## Derived_Column_Save_Test (php)

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DERIVED-SAVE-INSERT | an insert derives the column into the instance and the row | php | Ada / Lovelace | full_name 'Ada Lovelace' | implemented | 2026-11-13 |
| DERIVED-SAVE-INSERT-NULL | a derive method answering null stores NULL | php | no names | NULL | implemented | 2026-11-13 |
| DERIVED-SAVE-DIRTY-FROM | an update of a `from` column re-derives | php | last_name -> Byron | 'Ada Byron' | implemented | 2026-11-13 |
| DERIVED-SAVE-CLEAN-FROM | an update of other columns does not even call the derive method | php | note only | 0 derive calls | implemented | 2026-11-13 |
| DERIVED-SAVE-ORDER | `derived_columns()` is dependency-ordered and a dependent column reads the fresh upstream value, on insert and update | php | chain fixture, 3 then 5 | 6/12, 10/20 | implemented | 2026-11-13 |
| DERIVED-CYCLE | a cycle among derived columns throws a LogicException naming its path | php | anonymous model a <-> b | 'a -> b -> a' | implemented | 2026-11-13 |
| DERIVED-GUARD-EXISTING | assigning a derived column of a saved record throws the ruled message | php | full_name = 'Countess' | LogicException | implemented | 2026-11-13 |
| DERIVED-GUARD-NEW-STRICT | on a new record any assignment throws, the correct value and null included | php | two values | LogicException, nothing written | implemented | 2026-11-13 |
| DERIVED-GUARD-RETRY | a save that failed after deriving is retried without tripping the guard | php | duplicate code, then a free one | saved, derived | implemented | 2026-11-13 |
| DERIVED-GUARD-AFTER-WRITE | a hand assignment after the framework's write still throws | php | failed save, then full_name = 'Admiral' | LogicException | implemented | 2026-11-13 |
| DERIVED-SAVE-IDENTICAL | a recompute to the same value leaves the column out of the write | php | trailing space on last_name | full_name not in getChanges() | implemented | 2026-11-13 |

## Derived_Column_Rederive_Test (php)

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DERIVED-REDERIVE-NOOP | a record whose values are right is not saved and stays clean | php | fresh record | false | implemented | 2026-11-13 |
| DERIVED-REDERIVE-SAVES | a stale value is recomputed and saved; a second call finds nothing | php | raw-SQL stale value | true, then false | implemented | 2026-11-13 |
| DERIVED-ONLY-AUDIT | a derived-only save leaves updated_at and the updated_by pair byte-identical (raw SQL); an ordinary save moves both | php | pinned audit bytes | identical; control differs | implemented | 2026-11-13 |
| DERIVED-ONLY-EFFECTS | a derived-only save queues the realtime frame and after_update() naming the derived column | php | site fixture | 1 frame, changed ['label'] | implemented | 2026-11-13 |
| DERIVED-REDERIVE-OTHER-TABLE | a derivation from another table is reported by drift and brought up to date by rederive() | php | category renamed | 'Tools' | implemented | 2026-11-13 |
| DERIVED-REDERIVE-UNSAVED | rederive() of an unsaved record throws | php | new record | RuntimeException | implemented | 2026-11-13 |
| DERIVED-REDERIVE-DIRTY | rederive() of a record with unsaved changes throws, naming them | php | note pending | LogicException | implemented | 2026-11-13 |
| DERIVED-REDERIVE-NONE | a model with no derived columns answers false and no drift | php | category | false, [] | implemented | 2026-11-13 |
| DERIVED-DRIFT | `_derived_drift()` reports [stored, computed] without saving or dirtying | php | raw-SQL stale value | ['full_name' => ['Stale', 'Ada Lovelace']] | implemented | 2026-11-13 |

## Derived_Column_Revision_Test (php)

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DERIVED-REV-EXCLUDED-LIST | revision_excluded_columns() lists every derived column | php | site fixture | contains 'label' | implemented | 2026-11-13 |
| DERIVED-REV-CREATE | a create's revision records the source and not the derived column | php | insert | no 'label' | implemented | 2026-11-13 |
| DERIVED-REV-UPDATE | an update's revision records the source and not the re-derived column | php | name alpha -> beta | name only | implemented | 2026-11-13 |
| DERIVED-REV-DERIVED-ONLY | a derived-only save records no revision | php | rederive() of a stale label | still 1 revision | implemented | 2026-11-13 |

## Derived_Column_Bulk_Test (php)

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DERIVED-BULK-NAMES-DERIVED | update() naming a derived column throws, bare or table-qualified | php | full_name, table.full_name | LogicException, nothing written | implemented | 2026-11-13 |
| DERIVED-BULK-RAW-NAMES-DERIVED | raw_bulk() is refused the same way | php | raw_bulk()->update(full_name) | LogicException | implemented | 2026-11-13 |
| DERIVED-BULK-FROM-PER-RECORD | an update of a `from` column takes the per-record path on a model with no other side effect, re-deriving each record | php | two records | both re-derived | implemented | 2026-11-13 |
| DERIVED-BULK-FROM-EXPRESSION | a raw Expression on a `from` column throws, with or without raw_bulk() | php | CONCAT(...) | LogicException | implemented | 2026-11-13 |
| DERIVED-BULK-RAW-FROM | explicit raw_bulk() of a `from` column is allowed, derives nothing, and rederive() repairs | php | last_name -> King | stale, then 'Ada King' | implemented | 2026-11-13 |
| DERIVED-BULK-UNRELATED | a bulk update of an unrelated column leaves the derived column | php | note | unchanged | implemented | 2026-11-13 |

## Derived_Column_Form_Edit_Test (php)

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DERIVED-FORM-SET-REFUSED | set() of a derived column throws the ruled message | php | set('label') | LogicException | implemented | 2026-11-13 |
| DERIVED-FORM-SET-MAPPED | set() of a field mapped onto a derived column throws | php | fields: label => shown_label | LogicException | implemented | 2026-11-13 |
| DERIVED-FORM-SET-UNCHANGED | the refusal comes before the changed() test | php | edit not changing label | LogicException | implemented | 2026-11-13 |
| DERIVED-FORM-SOURCE | a create and an edit of the source column derive the column | php | gamma, delta | GAMMA, DELTA | implemented | 2026-11-13 |

## Derived_Column_Schema_Check_Test (php)

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DERIVED-SCHEMA-PURE | `_columns_missing_from()` names a missing declared and a missing `from` column, and exempts the audit type columns | php | synthetic maps | two entries / none | implemented | 2026-11-13 |
| DERIVED-SCHEMA-NO-TABLE | a model whose table is absent throws | php | Schema_Absent_Table_Fixture_Model | LogicException | implemented | 2026-11-13 |
| DERIVED-SCHEMA-SAVE | save() of a model with derived columns refuses a `from` the table lacks | php | `ghost` | LogicException, nothing written | implemented | 2026-11-13 |
| DERIVED-SCHEMA-FORM-EDIT | Form_Edit::run() checks the declaration | php | `ghost` | LogicException | implemented | 2026-11-13 |
| DERIVED-SCHEMA-NOT-ON-READ | an attribute read and toArray() never run the check | php | wrong declaration | values read | implemented | 2026-11-13 |
| DERIVED-SCHEMA-MEMO | a passing check is remembered in Rsx_Schema::derived() | php | plain fixture | null, then true | implemented | 2026-11-13 |
| DERIVED-SCHEMA-RULE | MODEL-ENUM-01 reports the missing `from` column, and a matching model is clean | php | the two fixtures | 1 / 0 violations | implemented | 2026-11-13 |

## Derived_Column_Commands_Test (php / cli in-process)

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DERIVED-CMD-CHECK | rsx:derived:check reports drift with `#id column: stored -> computed`, exits 1, writes nothing | php | 1 stale of 2 | exit 1; example line; row unchanged | implemented | 2026-11-13 |
| DERIVED-CMD-CHECK-CLEAN | a current table exits 0 | php | correct row | exit 0, 'none drifting' | implemented | 2026-11-13 |
| DERIVED-CMD-REFUSE | a table with no model, or a model without derived columns, is an [ERROR] exit 1 | php | two tables | exit 1 each | implemented | 2026-11-13 |
| DERIVED-CMD-REBUILD | rsx:derived:rebuild repairs with the drain's exact output and leaves updated_at alone | php | 1 stale of 2 | exact text; fixed; check exits 0 | implemented | 2026-11-13 |
| DERIVED-CMD-REBUILD-SITE | a site-scoped rebuild succeeds under the command's enforcement window and sends realtime | php | site fixture, 1 stale | labels fixed; 1 frame | implemented | 2026-11-13 |
| DERIVED-CMD-REBUILD-UNSETTLED | never settling: 3 passes for one table, the WARNING, exit 1 | php | unstable fixture | exit 1 | implemented | 2026-11-13 |
