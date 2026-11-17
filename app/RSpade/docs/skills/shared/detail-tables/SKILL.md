---
name: detail-tables
description: "Building class-table inheritance - a base model plus 1:1 detail tables chosen by a discriminator, $detail_tables, Rsx_Detail_Table::create(), Rsx_Detail_Model_Abstract. Use when fields differ by record type, saving a base + detail pair, a detail accessor throws a wrong-type error, a list runs one query per detail (preload_details), a money column on a detail fails with 'is not a money column', or on a DETAIL-01 finding."
---

# Detail Tables (Class-Table Inheritance)

A base model spans a base table PLUS one or more 1:1 **detail** tables, selected by a discriminator column. Type-specific fields live on the detail; universal fields stay on the base. Scope becomes a SCHEMA FACT: the table a field lives in is the constraint, and the access path (`entry.billable_entry_time.hours`) is the documentation.

The template's worked example is its billing entries: `Billable_Entry_Model` (TIME / EXPENSE / FIXED_FEE) with `Billable_Entry_Time_Detail_Model` and `Billable_Entry_Expense_Detail_Model` in `/rsx/models/`, the staff module under `/rsx/app/frontend/billing/` (its `CLAUDE.md` walks the pattern) and `/rsx/tests/Billable_Entry_Test.php`.

## 1. Declare the map on the base

```php
class Billable_Entry_Model extends Rsx_Site_Model_Abstract
{
    public static $detail_tables = [
        'type_id' => [
            self::TYPE_TIME => Billable_Entry_Time_Detail_Model::class,
            self::TYPE_EXPENSE => Billable_Entry_Expense_Detail_Model::class,
            // TYPE_FIXED_FEE absent => no detail row.
        ],
    ];
}
```

**One `$detail_tables` map, and it is the single source of truth** for "what detail a type has". Exactly one discriminator column. A discriminator value absent from the map means that type has no detail at all - all its fields are universal. Several values MAY map to the same detail model (they share one table and one accessor).

## 2. The detail model

```php
class Billable_Entry_Time_Detail_Model extends Rsx_Detail_Model_Abstract
{
    protected $table = 'billable_entry_time_details';
    protected $fillable = [];

    protected static $parent_model = Billable_Entry_Model::class;

    public static $columns = [
        'hours' => [],
        'hourly_rate' => ['money' => true],
    ];
}
```

It is a **STANDARD model** - default `id` primary key, no custom `$primaryKey`/`$incrementing`. The FK column is derived from the parent basename (`Billable_Entry_Model` -> `billable_entry_id`); override `$parent_key` only for a non-conventional name. The accessor name is derived from the detail class basename: `Billable_Entry_Time_Detail_Model` -> `billable_entry_time`.

The detail **inherits the base's authorization**. It has no `fetch()`, no Ajax endpoint of its own - it is reachable only through the base accessor and embed.

## 3. The migration

```php
use App\RSpade\Core\Database\DetailTables\Rsx_Detail_Table;

Rsx_Detail_Table::create('billable_entry_time_details', 'billable_entries', [
    ['name' => 'hours', 'type' => 'DECIMAL(8,2)', 'nullable' => false],
    ['name' => 'hourly_rate', 'type' => 'DECIMAL(12,2)', 'nullable' => false],
]);
```

Signature: `create($detail_table, $parent_table, array $columns, $parent_key = null, $soft_deletes = false)`. The helper emits, as raw DDL:

- the framework-standard surrogate `id BIGINT AUTO_INCREMENT PRIMARY KEY`,
- `{parent}_id BIGINT NOT NULL UNIQUE` + `FOREIGN KEY ... ON DELETE CASCADE`,
- the audit columns (`created_at`/`updated_at` + the authorship pairs; the deleted pair when `$soft_deletes`),
- your columns, which **default to NULL** (declare `'nullable' => false` for NOT NULL).

**It is NOT a literal shared primary key.** The 1:1 is enforced by the UNIQUE constraint and the lifecycle by CASCADE, so every table still honors the universal id-PK rule. The base carries ZERO detail-FK columns - the discriminator selects the table.

## 4. Read - accessor, vivify, wrong-type

```php
$entry->description                     // universal (base)
echo $entry->billable_entry_time->hours;  // time-only (detail)
$entry->billable_entry_expense;          // THROWS - wrong type for a time entry
```

The accessor for the record's ACTIVE type returns the persisted detail row, or - if none exists yet - a **new unsaved detail with the parent FK already set**, cached on the instance so repeated reads return the same object to fill and save. Wrong-type access throws a clear, LOCAL error (the discriminator is on the record; no query is issued) - in PHP **and** in JS, before any `await`. Check the type first; that is the caller's job.

`toArray()` eager-embeds the active PERSISTED detail under a `__details` blob (never under the accessor key, so it cannot collide with the JS accessor method). A vivified-but-unsaved detail is never serialized - and the record remembers it had no persisted detail, so **after saving a vivified detail, `$entry->refresh()` before `$entry->toArray()`**, or the payload leaves the new detail out.

```javascript
const entry = await Billable_Entry_Model.fetch(id);  // the detail rides inside this one request
const time  = await entry.billable_entry_time();      // instant - resolved from the embed, NO network
await entry.billable_entry_expense();                 // THROWS for a time entry
```

**Sets - avoid N+1.** Serializing many base records lazy-loads each detail one at a time. Batch them first:

```php
$entries = Billable_Entry_Model::where(...)->get();
Billable_Entry_Model::preload_details($entries);   // one query per detail table
// now each ->toArray() embeds its detail with no extra query
```

The template's `DataGrid_Abstract` (`rsx/theme/components/datagrid/`) already does this between `get()` and `toArray()` for a grid over a model with detail tables - a row's detail fields are `row.__details.<accessor>.<column>`; add no query of your own.

Which JS accessor to call depends on the discriminator, so it is awaited AFTER the record arrives - a genuine sequential step in `on_load()` (the billing view and edit actions mark it `GENUINE DEPENDENCY`).

## 5. Write - save order is yours

**`save()` does NO detail magic.** The FK lives on the detail, so the detail can only be created AFTER the base has an id. Write both in the SAME transaction - this one pattern covers create and edit, every type:

```php
DB::transaction(function () use ($entry, $hours, $hourly_rate) {
    $entry->type_id = Billable_Entry_Model::TYPE_TIME;   // only ever set on CREATE
    $entry->amount = $hourly_rate->multiply($hours, Rsx_Money::ROUND_HALF_UP);   // composed base column
    $entry->save();

    $detail = $entry->billable_entry_time;   // existing row, or new with billable_entry_id pre-set
    $detail->hours = $hours;
    $detail->hourly_rate = $hourly_rate;
    $detail->save();
});
```

- **Through `Form_Edit::run()`** the shape is the same inside the callback: base fields via `$edit->set()`, the base `save()`, then each detail field the submission changed (`$edit->changed('vendor')`) onto the detail. Only the base is the record `Form_Edit` guards; a detail has no revisions of its own (`Billable_Entry_Model::save_edit()` is the worked example).
- **Composed base columns** (an entry's `amount` from hours x rate) are ordinary model or endpoint code: compute and assign before `$entry->save()`. There is no framework save hook.
- **A money column on a detail is the DETAIL model's.** The base's money accessors cover the base table only: `Billable_Entry_Model::money_from_submitted('hourly_rate', ...)` throws `... is not a money column`. Read it as `Billable_Entry_Time_Detail_Model::money_from_submitted('hourly_rate', $value)`. In JS the base stub's `is_money_column()` / `editor_component_for()` know base columns only - name `Money_Input` directly for a detail money field.
- **Required detail fields are validated at the endpoint, NOT by the ORM** (plus the DB's own NOT NULL). The accessor never inserts a row on its own.
- **The discriminator is immutable.** Changing it on an existing record would orphan the detail, so it throws on save. Set the type only on create.
- **Delete cascades**: deleting the base deletes the detail (`ON DELETE CASCADE`).

## 6. Codegen spans both tables

Detail columns are merged into the base model's column map when it is read from the schema (`Rsx_Schema`), so the base behaves as one logical model:

- `Model.field_length('vendor')` resolves detail columns - a form binds `$max_length=Billable_Entry_Model.field_length('vendor')` even though the column lives on a detail.
- `rsx:constants:regenerate` documents detail columns in the base model's `@property` docblock, each annotated `(detail: <table>)`.
- The JS stub bakes the discriminator plus the value->accessor map and emits the embed-resolving accessors.
- `$columns` entries (an enum, a text type, ...) on a detail column work like any column - the detail model is a real model and casts its own columns.

## 7. DETAIL-01

The rule validates each `$detail_tables` declaration: exactly one discriminator column; every mapped class is an `Rsx_Detail_Model_Abstract` whose `$parent_model` points back at the base; no two distinct detail models derive the same accessor name.

**Not in v1** (do not invent it): query sugar / datagrid auto-join by a detail column (use `whereHas`), a declarative required-by-subtype validation, a guarded `change_type()`, multi-detail or nested CTI.

Details: `rsx:man detail_tables`.
