# derived_columns

Derived columns: a column a model declares in `$columns` as
`'col' => ['derived' => 'method', 'from' => ['source', ...]]`, which the model's own `save()`
computes from other columns of the same record (or, through `rederive()`, from data
elsewhere) and which nothing ever assigns.

This concern covers the model-layer mechanics - the declaration accessor, the save pipeline
(guard, derive, the derived-only write), `rederive()` and `_derived_drift()`, the revision
exclusion, the bulk-builder and Form_Edit refusals, and the first-use schema check. The
declaration rules (MODEL-COLUMNS-01's derived checks, MODEL-DERIVED-01) are tested under
`tests/code_quality` (`Model_Derived_Rules_Test`), and the JS stub emission under
`tests/codegen` (`Model_Stub_Derived_Test`).

## Source under test

- `app/RSpade/Core/Database/Models/Rsx_Model_Abstract.php` - the `$columns` grammar
  docblock; `derived_columns()` (dependency order, the cycle refusal) and
  `revision_excluded_columns()` built in `__column_views()`; `save()` (the guard
  `__assert_no_explicit_derived_assignment()`, `__apply_derivations()`, the derived-only
  detection, timestamps off and `_merge_derived_only_write()`); `rederive()`;
  `_derived_drift()`; `_assert_columns_exist()` / `_columns_missing_from()`.
- `app/Database/RestrictedEloquentBuilder.php` - `update()`: the single-write branch's
  `_merge_derived_only_write()` call, `_refuse_derived_column_write()`, and
  `_has_side_effect_surface()` counting derived columns.
- `app/RSpade/Core/Forms/Form_Edit.php` - `set()`'s derived refusal and `run()`'s
  `_assert_columns_exist()`.
- `app/RSpade/Core/Database/Rsx_Rederive.php` - `check()`, `rebuild()`, `rederive_table()`;
  `app/RSpade/Commands/Rsx/Derived_Check_Command.php` / `Derived_Rebuild_Command.php`
  (`rsx:derived:check` / `rsx:derived:rebuild`). The migrate queue and drain are tested under
  `tests/migrate` (`Rederive_Queue_Drain_Test`).
- `app/RSpade/CodeQuality/Rules/Models/ModelEnumColumns_CodeQualityRule.php` - MODEL-ENUM-01,
  the same column comparison in `rsx:check`.

## Defining documentation

The `$columns` docblock on `Rsx_Model_Abstract` is the in-code grammar reference;
`rsx:man model`.

## Fixtures

Runtime tables created by `Derived_Column_Fixture_Tables` in each class's `setup()` and
dropped in `teardown()`, shaped like normalized tables (`updated_at` ON UPDATE
CURRENT_TIMESTAMP(3), the audit authorship pairs), with `Rsx_Schema::forget()` after the DDL
so the schema check and casts see them.

- `Derived_Plain_Fixture_Model` - plain model, `full_name` from `first_name` + `last_name`;
  a UNIQUE `code` makes a save fail after deriving; counts derive calls.
- `Derived_Chain_Fixture_Model` - `doubled` from `base_value`, `quadrupled` from `doubled`,
  declared in the opposite order.
- `Derived_Site_Fixture_Model` - site-scoped, `$revisions`, `$realtime`, an `after_update()`;
  `label` from `name`.
- `Derived_Lookup_Fixture_Model` + `Derived_Category_Fixture_Model` - `category_name` read
  from another table.
- `Derived_Missing_Column_Fixture_Model` - a `from` naming a column its table lacks.
- `Derived_Trashable_Site_Fixture_Model` - site-scoped, soft-deleting; `label` from `name` and
  `site_fixture_count`, a site-scoped read of `Derived_Site_Fixture_Model` (proves the rederive
  walk declares each record's site).
- `Derived_Unstable_Fixture_Model` - `tick` never settles (a new number per call), or throws
  with `$fail` set; reaches the rederive pass cap.

## Testable surface

- php: derive on insert / on a changed source / not otherwise, dependency order, the cycle
  refusal, the guard (existing record, strict new record, retry after a failed save, a hand
  assignment after the framework's write); `rederive()` (no-op, save, unsaved and dirty
  refusals, a model with none, a derivation from another table); the derived-only save
  (`updated_at` and the `updated_by` pair byte-identical by raw SQL, realtime and
  `after_update()` still queued); `_derived_drift()`; revision exclusion (create, update,
  derived-only); the bulk refusals and the per-record path; Form_Edit; the schema check
  (pure comparison, absent table, save, Form_Edit, never on read, memo, MODEL-ENUM-01);
  `rsx:derived:check` (drift reported, nothing changed, non-zero exit) and
  `rsx:derived:rebuild` (repairs with the drain's output, site lock taken, realtime sent,
  non-zero exit when values never settle).
