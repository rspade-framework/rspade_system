# Concern: database

The ORM layer's framework-specific behavior: the restricted query builder, bounded
result sets, and the guards that keep a query's cost predictable.

## Applicability

Everything here is framework-core (`system/app/Database/`, `system/app/RSpade/Core/Database/`).
Nothing in this concern is application-facing except through the model surface.

## Source under test

- `system/app/RSpade/Core/Database/Rsx_Result_Set.php` - the foreach-able whole-set handle
- `system/app/Database/RestrictedEloquentBuilder.php` - `result_set()`, the row-count
  tripwire on `get()`, and the bulk `update()`/`delete()` keyset walks
- `system/app/RSpade/Core/Database/Models/Rsx_Model_Abstract.php` - the column accessors
  (`getColumns()`, `hasColumn()`, `field_length()`) and the attribute-read surface
  (`getCasts()`, `__get()`, `__isset()`, and the enum magic properties they answer)
- `system/app/RSpade/Core/Database/Database_BundleIntegration.php` - the JS model stub
  generator, which bakes its `field_length()` table from the model's own answer

## Behavior defined by

- `php artisan rsx:man model` (RESULT SETS, `$unbounded`, the automatic casts)
- `php artisan rsx:man enums` (the BEM magic properties and the static lookups)
- The "Do The Whole Job - Bounded Result Sets" section of `CLAUDE.md`
- `docs.dev/audits/framework_internal_audit_checklist.md` (the recurring audit)

## Testable surface

| Area | Type | Status |
|---|---|---|
| Rsx_Result_Set iteration / count / accessors / laziness | php | implemented (`Rsx_Result_Set_Test`) |
| Row-count tripwire: fires, stays silent, never truncates | php | implemented (`Result_Set_Tripwire_Test`) |
| Bulk update/delete per-record side effects over a keyset walk | php | covered in the `realtime` + `lifecycle` concerns |
| field_length(): varchar length, null for other types, throw on an unknown column, CTI span | php | implemented (`Field_Length_Test`) |
| The generated JS stub's field_length table agrees with the model, minus system columns | php | implemented (`Field_Length_Test`) |
| DB-UNBOUNDED-01 lint rule fires / suppresses | cli | deferred - verified manually by planting violations; needs a fixture-file harness |
| Schema-derived casts: datetime -> ISO string, date, TINYINT(1) -> bool, type-ref -> class name | php | implemented (`Model_Attribute_Read_Test`) |
| `mergeCasts()` stays per instance; two classes on different tables keep separate cast maps | php | implemented (`Model_Attribute_Read_Test`) |
| Enum magic reads (`field__label`/`__constant`/custom, `isset()`, the instance and static lookup forms) | php | implemented (`Model_Attribute_Read_Test`) |
| A `_`-prefixed system column reads back through the slow path and stays out of `toArray()` | php | implemented (`Model_Attribute_Read_Test`, own fixture table) |
