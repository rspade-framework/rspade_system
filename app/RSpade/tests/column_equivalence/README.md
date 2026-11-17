# Concern: column_equivalence

## Domain overview & applicability

`Column_Equivalence` is the ONE answer to "are these two values of this column the same
value?", decided by the column's declared kind (`$columns`: `money`, `text_type`, `type_ref`,
`blank_distinct`) and its schema type (`Rsx_Schema`: type, nullability, DECIMAL scale,
fractional-second precision) - never by guessing from the values. It is implemented twice,
PHP and JavaScript, with the same algorithms step for step (decimal digit-string arithmetic,
an integer proleptic-Gregorian calendar, half-up fraction rounding), and both run one shared
vector file.

Every dirty answer of every model goes through it: `Rsx_Model_Abstract::originalIsEquivalent()`
asks the rule, so a value assigned in another shape of the same value (`1` over `'1.00'`, an
ISO instant over its stored form, a reordered JSON document, `''` over NULL on a nullable text
column) writes nothing, records no revision, moves neither `updated_at` nor the `updated_by`
pair, and fires no realtime frame and no `after_update()`. The revision writer records each
side in the column's storage form and skips pairs equal under the rule;
`Revision::meaningful_changes()` applies the same judgement to diffs stored earlier - keeping a
type-ref pair or a fact group whole, counting what it leaves out, and returning a retired
model's diff as stored. `recorded_dirty()` / `recorded_changes()` answer which columns a save's
revision records, from the writer's own definition (`__recorded_pairs()`), and
`Form_Edit::written_fields()` the same by form field. A save whose only dirty columns are
derived or `revision_exclude` columns leaves the audit stamp alone.

## THE VECTOR FILE

`resource/column_equivalence_vectors.json` (never scanned by the manifest): `schema` 1,
`text_type_fixture` (the fixture text type's simple name), and `cases`, each
`{id, group, descriptor, a, b, expect, only?, why?}`:

- `descriptor` is the browser form: `{kind, nullable?, scale?, fsp?, blank_distinct?, text_type?}`.
- `a` / `b` are plain JSON values or one-key tags: `{"money": "1.50"}` (an `Rsx_Money`),
  `{"money_envelope": "1.50"}` (the `{__MONEY}` envelope), `{"float": "1.0"}` (a PHP float),
  `{"text": "raw"}` (a fixture text-type value), `{"obj": {...}}` / `{"list": [...]}` (a
  decoded JSON object / list).
- `expect` is `true` / `false`, or `{"throws": true}`; every case is run in BOTH orders.
- `only: "js"` marks a browser-only case (a type-ref class name against an id throws there,
  having no registry).

## Source files

- `app/RSpade/Core/Database/Models/Column_Equivalence.php` - the rule, descriptors,
  `storage_form()`, `to_browser()`
- `app/RSpade/Core/Js/Column_Equivalence.js` - the browser twin
- `app/RSpade/Core/Database/Models/Rsx_Model_Abstract.php` - `same_value()`,
  `blank_distinct_columns()`, `originalIsEquivalent()`, `save()` (the clean-save and unstamped
  paths, `__dirty_only_unrecorded()`, `_merge_unstamped_write()`), `_record_revision_on_save()`,
  `_revision_value()`, `__recorded_pairs()`, `recorded_dirty()`, `recorded_changes()`,
  `_revision_records_column()`, `_revision_unrecorded_lookup()`
- `app/RSpade/Lib/Revisions/Revision.php` - `meaningful_changes()`, `__fact_units()`
- `app/RSpade/Core/Forms/Form_Edit.php` - `written_fields()` (tested in `tests/forms/`, FE-WRITTEN-FIELDS)
- `app/RSpade/Core/Database/Rsx_Schema.php` - the `fsp` key, `fractional_precision()`
- `app/RSpade/Core/Database/Field_Length_Script.php` - `equivalence_for_models()`, the
  script's third line `Rsx.__column_equivalence_data`
- `app/RSpade/Core/Js/Rsx_Js_Model.js` - `column_descriptor()`, `same_value()`
- `app/RSpade/CodeQuality/Rules/Models/ModelColumns_CodeQualityRule.php` - `blank_distinct`
  in MODEL-COLUMNS-01

## Man page(s)

- `rsx:man model` (COLUMN EQUIVALENCE), `rsx:man revisions` (WHAT A SAVE RECORDS, API REFERENCE),
  `rsx:man form_conventions` (EDITING A RECORD)

## Testable surface

- php: the vectors (`Column_Equivalence_Vectors_Test`); the model wiring, storage forms,
  descriptors, the reader helper and the recorded-column accessors
  (`Column_Equivalence_Model_Test`, on the runtime table `column_equivalence_fixtures`, whose
  model also declares a text type on a DECIMAL column, a derived column and a fact group;
  `Column_Equivalence_Fixture_Text.js` is the fixture text type's JS twin)
- playwright: the same vectors in the browser (`playwright/column_equivalence_vectors.js`,
  run with `node`)
