# Concern: enums

## Domain overview & applicability

The model enum system: integer-stored fields mapped, at the model level, to
constants, labels, and arbitrary custom properties (badge, order, selectable,
...) via the static `$enums` array on a model. Magic BEM-style accessors
(`$model->field_id__label`, `field_id__badge`) and static helpers
(`field__enum()`, `field__enum_select()`, `field__enum_labels()`,
`field__enum_ids()`) expose this to PHP and, via generated stubs, to JS.
`toArray()` auto-exports the derived properties. Used pervasively for status
fields, types, and dropdowns across every model, so the resolution and ordering
rules must stay exact.

## Source files

- `app/RSpade/Core/Database/Models/Rsx_Model_Abstract.php` - enum resolution,
  `__get`/`__isset` magic, `toArray` export, the `field__enum*` static methods
- Real models exercised by the tests (read their `$enums` for expected values),
  e.g. a model with label/badge/order/selectable and a `Flash_Alert` model.

## Man page(s)

- `man/enums.txt` (one example fixed - see catalog; no behavioral divergence)

## Testable surface

- Static metadata methods: `field__enum()` (all defs incl. non-selectable, with
  metadata, order-sorted), `field__enum_select()` (excludes non-selectable,
  value/label pairs, order-respected), `field__enum_labels()` (full id=>label),
  `field__enum_ids()` (all keys). Generated PHP constants match enum keys. (php, no DB)
- Magic properties: `field__label`/`field__constant`/custom props via `__get`,
  label tracks current field value, `__isset` for known enum props. (php, no DB)
- `toArray()` export of label/constant/custom props/model id, current value
  reflected, raw integer preserved. (php, no DB)

All enum behavior is derived from static `$enums` arrays - no DB rows needed, so
every class sets `$use_database_transactions = false`.

## Documents

- `test_catalog.md` - full catalog.
- (no issues_encountered.md - no code/doc divergence found.)
