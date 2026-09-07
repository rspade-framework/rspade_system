# detail_tables

Class-Table Inheritance (CTI) for the RSpade ORM: a base model spanning a base table
plus a 1:1 "detail" table selected by a discriminator column, reached as a generated,
eager-embedded relationship that throws on wrong-type access.

## Source under test

- `app/RSpade/Core/Database/DetailTables/Rsx_Detail_Table.php` - migration helper (raw-DDL CREATE TABLE; surrogate id + UNIQUE FK + CASCADE).
- `app/RSpade/Core/Database/DetailTables/Rsx_Detail_Model_Abstract.php` - detail model base (standard `id` PK, `$parent_model`, derived `$parent_key`, `for_parent()`).
- `app/RSpade/Core/Database/Models/Rsx_Model_Abstract.php` - `$detail_tables` map, discriminator resolver, magic detail accessor, `toArray()` embed, post-save shell-create.
- `app/RSpade/Core/Database/Database_BundleIntegration.php` - JS detail accessor + baked discriminator map.
- `app/RSpade/Core/Manifest/Modules/Model_ManifestSupport.php` - base+detail column spanning.
- `app/RSpade/Commands/Rsx/Constants_Regenerate_Command.php` - two-table docblock.

## Man page

`php artisan rsx:man detail_tables` (authoritative behavior).

## Design (locked)

Detail keyed by **surrogate id + UNIQUE FK** (not a literal shared PK), related->primary,
`ON DELETE CASCADE`. Reads eager-embed; writes are per-record (endpoint wraps in a
`DB::transaction`); the framework auto-creates a default detail shell on save (existence
guarantee, shell-able details only, else fail loud). Throw-on-wrong-type in PHP + JS.

## Testable surface

| Area | Type |
|------|------|
| Migration helper DDL shape + transformer composition | php |
| Detail model PK/parent_key derivation, for_parent() | php |
| Discriminator resolver (value->detail class, accessor name, absent type) | php |
| Read: embed into toArray(), magic accessor, throw-on-wrong-type, with_detail N+1 | php |
| Write: auto-create shell, fail-loud on required, cascade delete, immutability | php |
| JS stub: embedded-resolving accessor + map, JS wrong-type throw | playwright/cli |
| Codegen spanning: field_length, merged columns, constants:regenerate, cache bust, collision warn | php/cli |
