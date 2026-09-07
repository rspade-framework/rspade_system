# Polymorphic Type References

Covers the `$type_ref_columns` contract: a polymorphic reference is the pair
`{relation}_type` + `{relation}_id`, both BIGINT, with the `_type` column storing a
type-ref integer id (not a class-name VARCHAR). The framework maps between integer
ids and simple class names transparently (see `rsx:man polymorphic`).

## Source under test

- `system/app/RSpade/Core/Database/TypeRefs/` - the registry + cast + morph-map
  registration (`Type_Ref_Registry::register_morph_map()`, `Rsx_Type_Ref_Cast`).
- `system/app/Database/RestrictedEloquentBuilder.php` - type-ref conversion in
  `where*`/`whereIn*` (including the TABLE-QUALIFIED column spelling Eloquent's own
  morph relations produce) and `joinMorph*`.
- `system/app/RSpade/CodeQuality/Rules/Manifest/MorphStringPattern_CodeQualityRule.php`
  - POLY-01, the manifest-fatal rule against the Laravel string-morph pattern (its unit
  tests live in the `code_quality` concern).
- `system/app/RSpade/Core/Models/Portal_Notification_Model.php` (`subject_type` +
  `subject()`), `system/app/RSpade/Core/Files/File_Attachment_Model.php`
  (`fileable_type`), `system/app/RSpade/Core/Search/Search_Index_Model.php`
  (`indexable_type`) - the framework-core type-ref models.

## Testable surface

- **Stock morph relations over an integer discriminator (implemented):**
  `register_morph_map()` registers each type ref under TWO aliases - the simple class
  name AND the integer id - so `morphTo()` resolves the raw integer it reads, while
  `getMorphClass()` still answers with the class-name alias that the cast converts back
  to the integer on a write. Alias ORDER is load-bearing and is pinned.
  `Polymorphic_Morph_Relations_Test` covers: lazy read, relation-object query, relation
  caching, null/dangling references, `associate()`/`dissociate()`,
  `morphMany`/`morphOne` constraints and `->save()`, `whereMorphedTo`,
  `whereHasMorph`, and both the qualified and unqualified `where()` conversion.
- **WHERE-clause conversion (implemented):** `Polymorphic_Where_Conversion_Test` pins which
  spellings `RestrictedEloquentBuilder` converts - bare and SELF-table-qualified yes, a
  qualifier naming another table no - across `where`/`orWhere`/`whereNot`/`orWhereNot`/
  `whereIn` including their short (two-argument) forms, plus integer/null pass-through and
  the silent-zero defense: an unresolvable class-name-shaped value THROWS naming the model,
  the column and the value instead of binding a string against a BIGINT column.
- **Retired type refs (implemented):** `Polymorphic_Retired_Type_Ref_Test` pins what a
  `_type_refs` row whose model class no longer exists does - the poison alias registered by
  `register_morph_map()` (never a silent drop), the named throw from `morphTo()`, the cast
  and `whereHasMorph($rel, '*')`, the `class_to_id()` write refusal, the inertness of a
  retired ref nothing references, and `find_id_by_class_name()` as the cleanup-migration
  seam. It ALSO pins the SILENCE: such a row produces no log line and no `rsx:health` row,
  and the failure message points at the orphan report rather than a prune command that no
  longer exists. Retirement is simulated by inserting a `_type_refs` row for a class no
  file declares, inside the per-test transaction, with the registry's cached state and the
  global morph map restored around it.
- **The orphan report (implemented):** `Type_Ref_Orphan_Report_Test` covers
  `php artisan rsx:type_refs:orphans` and its engine - a vanished-class id and a dangling
  id are both counted while a healthy id is not, the printed SELECT is exact AND is run to
  prove it returns exactly the offending rows, the `--json` shape, the clean-database
  message, and exit 0 in both directions.
- **Deferred:** `joinMorph*` coverage.

Table-rename tracking (`_type_refs.table_name` following a `RENAME TABLE`) belongs to the
migrate pipeline and is tested in the `migrate` concern: `Type_Ref_Table_Rename_Test`.
