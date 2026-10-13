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
- `system/app/RSpade/Core/Database/Rsx_Schema.php` - the one reader of live column data:
  one `information_schema` query, cached under the state key, behind every schema-derived
  cast and every `field_length()` answer
- `system/app/RSpade/Core/Database/Field_Length_Script.php` and `Field_Length_Controller.php`
  - a compiled bundle's field-length script: the column lengths of the models the bundle
  carries, each the model's own `field_length()` answer, served per bundle and read by the one
  `Rsx_Js_Model.field_length()` (a model stub carries no lengths)

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
| Rsx_Schema: the column shape against SHOW COLUMNS, the lookup order by query count, a model with no table, a real schema change seen when the state key moves | php | implemented (`Rsx_Schema_Test`) |
| field_length(): varchar length, null for other types, throw on an unknown column, CTI span | php | implemented (`Field_Length_Test`) |
| What is published to JavaScript agrees with the model, minus system columns; no stub carries a length table | php | implemented (`Field_Length_Test`) |
| A bundle's field-length script: data, system columns, a model with no table, which names resolve, cache headers, the page's tag | php | implemented (`Field_Length_Script_Test`) |
| `Rsx_Schema::purge_schema_state()`: the entry the key would keep, the build-scoped and page caches, the maintenance no-op | php | implemented (`Rsx_Schema_Test`) |
| DB-UNBOUNDED-01 lint rule fires / suppresses | cli | deferred - verified manually by planting violations; needs a fixture-file harness |
| Schema-derived casts: datetime -> ISO string, date, TINYINT(1) -> bool, type-ref -> class name | php | implemented (`Model_Attribute_Read_Test`) |
| `mergeCasts()` stays per instance; two classes on different tables keep separate cast maps | php | implemented (`Model_Attribute_Read_Test`) |
| Enum magic reads (`field__label`/`__constant`/custom, `isset()`, the instance and static lookup forms) | php | implemented (`Model_Attribute_Read_Test`) |
| A `_`-prefixed system column reads back through the slow path and stays out of `toArray()` | php | implemented (`Model_Attribute_Read_Test`, own fixture table) |
| `refresh()` re-reads a row in place and drops the relations it had read; an empty relation list is not a load, a named relation is still refused | php | implemented (`Model_Refresh_Test`) |

## Fixture models

Two fixture models on two fixture tables, created in `Model_Attribute_Read_Test::setup()`
and dropped in its `teardown()`:

| Fixture | Table | Why it exists |
|---|---|---|
| `System_Column_Fixture_Model` | `model_attribute_read_fixtures` | the only `_`-prefixed SYSTEM column in the tree, plus one enum column with a custom property |
| `Attribute_Read_Enum_Fixture_Model` | `model_attribute_read_enum_fixtures` | a SECOND enum map, on a DIFFERENT table - the per-class and per-class+column memos cannot be proved unshared with one model |

The CTI fixtures the `detail_tables` concern owns are NOT usable for a length or cast
assertion: those answers come from `Rsx_Schema`'s column map, which is read from the migrated
baseline and cached under the state key, and a table created with plain DDL during the run
moves no key. Where a schema-derived answer needs a subject the framework does not declare (a
CTI base, a DATE column), the test DERIVES one from the model registry and skips when the
application has none.
