# detail_tables

Class-Table Inheritance (CTI) for the RSpade ORM: a base model spanning a base table
plus a 1:1 "detail" table selected by a discriminator column, reached as a generated,
eager-embedded accessor that throws on wrong-type access.

## Source under test

- `app/RSpade/Core/Database/DetailTables/Rsx_Detail_Table.php` - migration helper (raw-DDL CREATE TABLE; surrogate id + UNIQUE FK + CASCADE).
- `app/RSpade/Core/Database/DetailTables/Rsx_Detail_Model_Abstract.php` - detail model base (standard `id` PK, `$parent_model`, derived `$parent_key`, `for_parent()`).
- `app/RSpade/Core/Database/DetailTables/Detail_Tables_Resolver.php` - pure helpers over a `$detail_tables` map (value -> class, accessor name).
- `app/RSpade/Core/Database/Models/Rsx_Model_Abstract.php` - `$detail_tables` map, discriminator resolver, magic detail accessor (vivifies an unsaved detail with the FK set), `toArray()` embed, `preload_details()`, discriminator immutability.
- `app/RSpade/Core/Database/Model_Stub_ManifestSupport.php` - JS stub detail accessors + baked discriminator map (resolved by `Rsx_Js_Model.__resolve_detail()` in `Core/Js/Rsx_Js_Model.js`).
- `app/RSpade/Core/Database/Rsx_Schema.php` - base+detail column spanning (`model_columns()`).
- `app/RSpade/Commands/Rsx/Constants_Regenerate_Command.php` - two-table docblock.

## Man page

`rsx:man detail_tables` (authoritative behavior).

## Design (locked)

Detail keyed by **surrogate id + UNIQUE FK** (not a literal shared PK), related->primary,
`ON DELETE CASCADE`. Reads eager-embed the persisted detail under `__details`. `save()` does
no detail work: the developer saves the base, then fills and saves the detail the accessor
returns (the persisted row, or a new unsaved one with the parent FK set) in the same
transaction; required detail fields are validated at the endpoint. Throw-on-wrong-type in
PHP + JS. The template application's worked example is its billing entries
(`rsx/models/billable_entry_model.php`, `rsx/tests/Billable_Entry_Test.php`).

## Testable surface

| Area | Type |
|------|------|
| Migration helper DDL shape + transformer composition | php |
| Detail model PK/parent_key derivation, for_parent() | php |
| Discriminator resolver (value->detail class, accessor name, absent type) | php |
| Read: embed into toArray(), magic accessor, throw-on-wrong-type, preload_details() N+1 | php |
| Write: vivified detail with FK pre-set, no auto-create on save, cascade delete, immutability | php |
| JS stub: embedded-resolving accessor + map, JS wrong-type throw | playwright/cli |
| Codegen spanning: field_length, merged columns, constants:regenerate, cache bust, collision warn | php/cli |
