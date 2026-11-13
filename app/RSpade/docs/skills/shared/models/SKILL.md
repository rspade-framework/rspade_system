---
name: models
description: "Writing a model class and declaring its columns - public static $columns with its kinds (enum, text_type, type_ref, never_export, revision_exclude, derived + from), the accessors (columns(), enum_columns(), enum_values(), text_type_columns(), type_ref_columns(), never_exported_columns(), revision_excluded_columns(), derived_columns()), derived columns and $record->rederive(), the generated _AUTO_GENERATED_ header, explicit assignment (MassAssignmentException), no eager loading, $fact_groups, appended properties via $appends + a delegating accessor, Model::field_length(), bulk ->update() vs ->raw_bulk() vs DB::table(), refresh(). Use when writing or changing a model, adding a column, choosing whether a column is an enum / text type / type ref / secret / derived, storing a token or password, or on MODEL-COLUMNS-01, MODEL-COLUMNS-02, MODEL-DERIVED-01, MODEL-ENUM-01, MODEL-NEVER-EXPORT-01, 'is a derived column', 'is not an enum column', 'rederive() refused', 'declares derived columns that depend on each other in a cycle', 'MASS ASSIGNMENT PROHIBITED' or 'Eager loading via with() is not allowed'."
---

# Writing a model

A model extends `Rsx_Model_Abstract` (or `Rsx_Site_Model_Abstract` for a site-scoped table). This skill is the one complete reference for how a model declares its columns, plus the rules every model class meets. Deep dives: `rspade:model-enums`, `rspade:text-types`, `rspade:polymorphic`, `rspade:revisions`, `rspade:model-fetch`, `rspade:detail-tables`, `rspade:actors-and-authorship`, `rspade:realtime`. Derived columns in depth: [reference/derived-columns.md](reference/derived-columns.md). Contract: `rsx:man model`, `rsx:man derived_columns`.

**Before adding a column, ask of it every question in [Choosing a kind](#choosing-a-kind-for-a-new-column).** A column that should have been an enum, a text type, a type ref, a secret or a derived column and was left plain is a silent defect: nothing fails, the value is just wrong somewhere.

## Column declarations: `public static $columns`

ONE property says what kind of value each column holds - one entry per column, keyed by column name:

```php
public static $columns = [
    'status_id'     => ['enum' => [
        1 => ['constant' => 'STATUS_ACTIVE',   'label' => 'Active'],
        2 => ['constant' => 'STATUS_ARCHIVED', 'label' => 'Archived', 'selectable' => false],
    ]],
    'description'   => ['text_type' => Rich_Text::class],
    'fileable_type' => ['type_ref' => true],
    'api_secret'    => ['never_export' => true],
    'view_count'    => ['revision_exclude' => true],
    'project_id'    => ['derived' => 'derive_project_id', 'from' => ['parent_type', 'parent_id', 'direct_project_id']],
];
```

| Key | Value | The column is |
|---|---|---|
| `enum` | integer-keyed map of value => properties | a BIGINT whose integers have constants, labels and custom properties |
| `text_type` | a `Rsx_Text_Abstract` subclass | a TEXT column read as a value object, filtered on every write |
| `type_ref` | `true` | the `_type` half of a polymorphic pair: stores a `_type_refs` id, reads as a class name |
| `never_export` | `true` | a secret: never in any payload, redacted in revisions |
| `revision_exclude` | `true` | not recorded in revision history |
| `derived` + `from` | method name + list of this record's columns | STORED, computed by the model on save, never assigned |

**The grammar** (checked at manifest build by `MODEL-COLUMNS-01`, a FATAL - a malformed entry would otherwise fail silently):

- Only those seven keys; anything else is an error. A flag is the literal `true` or absent - never `false`.
- At most ONE of `enum` / `text_type` / `type_ref` per column. `never_export` combines with anything; `revision_exclude` combines with anything except `derived` (a derived column is never recorded already). A `derived` column may also be an enum, text type or type ref - its value is assigned through `setAttribute()`, so the kind still applies.
- `derived` and `from` come together; see [Derived columns](#derived-columns).
- `'column' => []` declares an explicitly plain column.
- **A model with nothing to declare declares nothing** - no `$columns = []`.

**Every declared column, and every `from` name, must exist on the table**: `rsx:check` reports one that does not (`MODEL-ENUM-01`), and the write side throws the first time it asks (`"X_Model::$columns declares 'col', which is not a column of the model's table."` - on save of a model with derived columns, on `rederive()`, on `Form_Edit::run()`). A model READ never checks, so a model read during a migration that has not added the column yet still works.

**There are no other column-declaration properties.** A model never declares `$enums`, `$text_types`, `$type_ref_columns`, `$neverExport` or `$revision_exclude`: the framework reads `$columns` only, so such a property would declare nothing (a "secret" listed there would be sent to every browser), and the manifest build refuses it (`MODEL-COLUMNS-01`: "`X_Model::$enums declares nothing`").

### Inheritance and overrides

The effective declaration overlays every class from `Rsx_Model_Abstract` down to the model, base first. **A class's entry for a column REPLACES its parent's entry for that column wholesale** - entries are never merged key by key. So an override that wants to add `revision_exclude` to an inherited enum restates the enum too. Two protections can never be dropped by a replacing entry (`MODEL-COLUMNS-01`): an entry replacing one that carries `never_export` must carry it too, and the audit type columns `Rsx_Model_Abstract` declares (`created_by_type`, `updated_by_type`, `deleted_by_type`) keep `type_ref`. A class override of a core model (`class User_Model extends User_Model_Abstract`, `rsx:man class_override`) declares only the columns it adds or changes.

### Reading the declaration: the accessors

**Never read `Model::$columns` directly** - it answers ONE class's declaration and misses every inherited entry (`MODEL-COLUMNS-02`). Read:

| Accessor | Answers |
|---|---|
| `Model::columns()` | every effective entry, overlaid |
| `Model::enum_columns()` / `Model::enum_values('col')` | all enum maps / one column's map - THROWS `"X_Model::col is not an enum column"` for any other column |
| `Model::text_type_columns()` / `text_type_or_null('col')` | column => type class |
| `Model::type_ref_columns()` | the type-ref columns, the audit ones included |
| `Model::never_exported_columns()` | the secrets |
| `Model::revision_excluded_columns()` | `revision_exclude` columns plus every derived column |
| `Model::derived_columns()` | column => `['method', 'from']`, in dependency order |

`Model::enum_values('status_id')` is how code reaches an enum's raw map (a label table, a validation list); prefer the generated helpers below for everything they cover. JS: `Model.is_derived_column('col')`; the enum helpers are on the JS model too.

## The kinds

### `enum` - an integer with a meaning

```php
'status_id' => ['enum' => [
    1 => ['constant' => 'STATUS_ACTIVE',  'label' => 'Active',  'badge' => 'bg-success', 'order' => 1],
    2 => ['constant' => 'STATUS_ON_HOLD', 'label' => 'On Hold', 'badge' => 'bg-warning', 'order' => 2],
]],
```

The column is **BIGINT** (never VARCHAR, never MySQL `ENUM`). Each value's `constant` becomes a class constant (`Project_Model::STATUS_ACTIVE`), written into the model by `rsx:constants:regenerate` (`migrate` runs it in development). `label`, `order`, `selectable` (false = hidden from dropdowns, still shown as the current value) and ANY custom property are readable as `$record->status_id__label`, `$record->status_id__badge`; statics `status_id__enum()`, `__enum_select()`, `__enum_labels()`, `__enum_ids()` exist in PHP and JS. Never alias a label into a payload. Depth: `rspade:model-enums`.

### `text_type` - what kind of string a TEXT column holds

```php
'description' => ['text_type' => Rich_Text::class],
```

The column (TEXT) reads as a value object of that type: filtered by its sanitizer on every write, printed by plain interpolation, edited by the widget the type names, travelling to JS as a `{__TEXT, raw, empty}` envelope. **A bare string assigned to it is PLAIN TEXT** (escaped); markup is assigned as the type (`Rich_Text::from_untrusted_encoded($html)`). Emptiness is `is_empty()`, never `=== ''`. Used as a string it throws. `TEXT-TYPE-01` (build FATAL) checks the class, its JS twin, and that no `$casts` entry shadows it. Depth: `rspade:text-types`.

### `type_ref` - the type half of a polymorphic pair

```php
'commentable_type' => ['type_ref' => true],     // with a commentable_id BIGINT beside it
```

A polymorphic reference is ALWAYS the BIGINT pair `{relation}_type` + `{relation}_id`; the `_type` column stores a `_type_refs` integer id and reads/writes as a class basename (`'Client_Model'`), so stock `morphTo()` / `morphMany()` work over it. Never a VARCHAR class name, never two nullable FKs. `POLY-01` (build FATAL) refuses a morph relation over an undeclared type column. The audit `*_by_type` columns are already declared by the base. Depth: `rspade:polymorphic`.

### `never_export` - a secret

```php
'api_token' => ['never_export' => true],
```

`toArray()` - the ONE serializer behind `fetch()`, relationship and list payloads, `window.rsxapp.user` and the external API - removes the column AFTER Laravel builds the array, so `makeVisible()` / `$visible` cannot reveal it. **Laravel's `$hidden` is not a security control.** On a `$revisions` model a change to it is recorded with both sides `Revision::REDACTED` ("it changed", never the value). `rsx:check` flags a `password` / `token` / `secret` column (or one ending `_password` / `_token` / `_secret`) without it (`MODEL-NEVER-EXPORT-01`); one exported on purpose carries `// @MODEL-NEVER-EXPORT-01-EXCEPTION <column> - <reason>`. Server-side reads (`$user->password`) are unaffected.

### `revision_exclude` - not worth recording

```php
'last_seen_at' => ['revision_exclude' => true],
```

For values that change without anybody changing them: counters, last-seen stamps, cached aggregates. The column is still stored - this is NOT a security control (a secret is `never_export`). On a `never_export` column it wins: nothing is recorded at all. `updated_at`, the `updated_by` pair and `_`-prefixed columns are excluded automatically; derived columns are never recorded. Depth: `rspade:revisions`.

### `derived` + `from` - a stored value the model computes

```php
'project_id' => ['derived' => 'derive_project_id', 'from' => ['taskable_type', 'taskable_id', 'direct_project_id']],

public function derive_project_id(): ?int { ... }      // public, non-static, no required argument
```

See the next section.

## Derived columns

A derived column is a **STORED** column (queryable, indexable, filterable in a list) whose value is computed by a public method of the model - which may read other tables - and **never assigned by anybody**. Use one for a value that is a function of other data: the project a task's parent chain reaches, a total kept for sorting, a normalized search key.

- **`save()` derives it** on an INSERT, and on an UPDATE in which one of its `from` columns changed. `from` names columns of THIS record the method reads (may be `[]`, may name another derived column - computed in dependency order - never the column itself; a cycle is a build error and a `LogicException` "declares derived columns that depend on each other in a cycle").
- **Assigning it THROWS** at save (`"Task_Model::project_id is a derived column - Task_Model::derive_project_id() computes it from [...] on save. It is never assigned. Assign its source column(s), or call rederive()."`) - on a new record ANY assignment, even null or the right value. Also refused: `Form_Edit::set()` of it, a bulk `->update()` naming it (`raw_bulk()` included), and a raw `DB::raw()` expression on a `from` column. `MODEL-DERIVED-01` (`rsx:check`) reports the assignment in code before it runs.
- **`$record->rederive(): bool`** recomputes every derived column from the stored record and saves only when a value differs (answers whether it saved). For when what the method reads lives ELSEWHERE and moved. Throws on an unsaved record and on one with unsaved changes ("rederive() refused: the record has unsaved changes"). It is a `save()`, so a site-scoped record needs the site's write lock.
- **There is NO cross-record trigger.** The framework sees only this record's `from` columns. When a derived value depends on another record, the model whose change moves it rederives the dependents itself - a `save()` override calling `parent::save()` then each dependent's `rederive()`, inside one transaction (`Task_Model::save()` / `__rederive_descendants()`).
- **A derived-only save** (only derived columns changed) leaves `updated_at` and the `updated_by` pair untouched and writes no revision; the realtime notice and `after_update()` still fire. Derived columns never appear in revision history.
- **No conditional derivation.** "Editable unless a rule supplies it" is TWO columns: the user's editable source (`direct_project_id`) and the derived column (`project_id`) that prefers the rule and falls back to the source. The form edits the source; while the rule supplies the value its input is disabled showing the derived value, a `form.before_submit` hook removes that field, and the endpoint writes the source only when the key is present - so the stored choice survives.
- **Migrations never save through a model.** A migration that changes what a derived column reads writes values with SQL or calls `Rsx_Rederive::enqueue('table')`; `migrate` rederives queued tables as its last step. Changing a derive METHOD's logic needs a migration that only enqueues. Drift: `rsx:derived:check <table> | --all`; repair: `rsx:derived:rebuild <table> | --all`.

The full treatment - every refusal message, the dependents pattern in code, the form pattern, external API handling, migrations, the drain and the commands: [reference/derived-columns.md](reference/derived-columns.md).

## What is NOT declared in `$columns`

| Concern | Where it lives |
|---|---|
| Dates, datetimes, TINYINT(1) booleans, JSON | read from the live schema: booleans cast to `bool`, dates stay ISO **strings** (never `->format()`; a `'date'`/`'datetime'` cast is blocked by `rsx:check`), DECIMAL reads as a string |
| Length of a varchar | the schema: `Model::field_length('col')` |
| Relationships | `#[Relationship]` methods |
| A computed value JS must see, not stored | an APPENDED PROPERTY: `$appends` + a delegating accessor (below) |
| One fact stored in several columns | `public static $fact_groups` (below) |
| Per-type fields (class-table inheritance) | `public static $detail_tables` (`rspade:detail-tables`) |
| Who created / updated / deleted | the audit pairs `created_by_*` / `updated_by_*` / `deleted_by_*`, stamped by `save()`; never in a migration (`rspade:actors-and-authorship`) |
| A framework feature's per-record state | a `_`-prefixed system column: stripped from payloads, `field_length()` and revision diffs; app-facing columns never take a leading `_` |
| Model-level switches | `$revisions`, `$realtime`, `$unbounded`, `site_id` via `Rsx_Site_Model_Abstract` |

## The generated header

`rsx:constants:regenerate` (run by `migrate` in development) rewrites the `_AUTO_GENERATED_` docblock and enum-constant block of every model from the live table and `$columns`. **Never edit it** - change the declaration or the schema. Each `@property` line states the PHP type a read returns and the column's kind:

```
 * @property int $priority enum: PRIORITY_LOW, PRIORITY_MEDIUM, PRIORITY_HIGH, PRIORITY_URGENT (also $priority__label and the other $priority__* properties)
 * @property \Rsx\Lib\TextTypes\RichText\Rich_Text $description text type: a value object, filtered on write
 * @property string $taskable_type type ref: a model class name, stored as a type-ref id
 * @property-read int $project_id derived: computed by derive_project_id() from taskable_type, taskable_id, direct_project_id; never assigned
 * @property string $password never exported to the browser
 * @property string $last_seen_at not recorded in revisions
 * @property string $hour_estimate decimal
```

`@property-read` marks a derived column (never assign it). DECIMAL and uncast JSON read as `string` (noted `decimal` / `json`); TINYINT(1) as `bool`; dates and datetimes as `string`. A reader of a model file learns every column's mechanics from this header plus `$columns`.

## Choosing a kind for a new column

Ask, in order, for EVERY column you add:

1. **Is it a fixed set of states or types?** -> BIGINT + `enum`. Never a VARCHAR of words, never a boolean that will grow a third state.
2. **Is it text with an encoding** - HTML from an editor, a notation with embedded references? -> TEXT + `text_type`. Plain text stays undeclared.
3. **Does it point at a record of one of SEVERAL models?** -> a BIGINT `{relation}_type` + `{relation}_id` pair, `type_ref` on the `_type` column, plus `$fact_groups` when one form field edits the pair.
4. **Would its disclosure be a security event** (hash, token, secret, key/value bag)? -> `never_export`.
5. **Is it computed from other data and never typed by a person?** -> `derived` + `from`, with a derive method. If a person may ALSO set it when no rule applies, that is two columns (source + derived).
6. **Does it change without anybody changing it** (counter, last-seen, cached aggregate) on a `$revisions` model? -> `revision_exclude` (a derived column needs nothing).
7. **Is it a computed value JS needs but nobody queries?** -> not a column: an appended property.
8. **Is it edited as one thing with other columns?** -> `$fact_groups`.

None of these -> a plain column, declared nowhere.

## Assign every field explicitly - mass assignment THROWS

```php
User_Model::create($request->all());      // [NO] "MASS ASSIGNMENT PROHIBITED IN RSPADE FRAMEWORK"
$user->fill($params);                     // [NO]

$user = new User_Model();                 // [OK] what a request can write is visible in the code
$user->first_name = $params['first_name'];
$user->save();
```

Blocked: `fill()`, `forceFill()`, `create()`, `firstOrCreate()`, `firstOrNew()`, `updateOrCreate()`, `update([...])` on an instance. A record edit from a form saves through `Form_Edit::run()` (`rspade:form-engine`).

## Never eager load - `->with()` THROWS

`->with()`, `withCount()`, `$model->load('relation')`, `loadMissing()`, `loadCount()` throw ("Eager loading via with() is not allowed in the RSpade framework"). Load what you need with an explicit query; lazy relationships (`$client->contacts`) are fine.

## One fact, several columns: `public static $fact_groups`

Some value a person edits as ONE thing is stored across several columns, and which column holds it can depend on the record's shape - an activity's "when" (`date_start`/`date_end` for all-day, `time_start`/`time_end` for timed), a polymorphic type/id pair picked as one parent, a value kept inside a JSON column beside others.

**The trap**: `Form_Edit` finds conflicts per COLUMN. When the other editor's re-date landed in `time_start` and the stale submission's Date field is stored in `date_start`, nothing matches - the stale save moves the activity back, a 200, no question.

```php
public static $fact_groups = [
    'when' => ['label' => 'Date and time', 'columns' => ['date_start', 'date_end', 'time_start', 'time_end']],
];
```

- A change saved to ANY column of a group is a change to every form field stored in ANY of its columns - always asked about, the question naming the group by its `label` (`rsx:man form_conventions`, EDITING A RECORD, FACT GROUPS).
- Column terms, because it is a fact about the model; the endpoint's `fields:` / coupled map says which form field each column stores.
- No "is this a conflict?" callback, on purpose: one answering "no" by mistake silently loses an edit.
- `Model::fact_groups()` reads it, checked against the live table the first time in a process (unknown column, a column in two groups, no label, fewer than two columns -> `LogicException` naming the model). Give every group a test.

## Appended properties: `$appends` + a delegating accessor

A computed value that must reach JavaScript but is not stored is an APPENDED PROPERTY - DECLARED, never hand-copied onto one payload (a value that must be queried or sorted on is a derived column instead):

```php
protected $appends = ['party_id_formatted'];

public function party_id_formatted()                      // the DEFINITION
{
    return '#PA' . str_pad($this->id, 3, '0', STR_PAD_LEFT);
}

public function getPartyIdFormattedAttribute(): string    // only its serialization
{
    return $this->party_id_formatted();
}
```

- **The accessor DELEGATES to the public method** - never re-implement the computation in it.
- Eloquent applies `$appends` inside `parent::toArray()`, so the value rides EVERY payload: `fetch()`, `portal_fetch()`, a relationship, a list. **A key hand-added inside `fetch()` lands on that one payload only.**
- The generated `Base_<Model>_Model.js` declares each name as a read-only appended property; `field_length()` answers null for it.
- **Cost**: an accessor runs on EVERY serialization. One that reads other rows is paid per record.
- The property name IS the method name. `get{Property}Attribute` is one of the two Laravel spellings the naming rule exempts.

Worked example: `system/app/RSpade/resource/reference_app/models/party_model.php`.

## Column length: `field_length()`

`Model::field_length('col')` (PHP) and `Model.field_length('col')` (JS) answer the max characters of a varchar/char, `null` for every other type. **A server-side length check reads it; it never restates a schema number**, and a form's `$max_length` takes the same call. PHP THROWS for a column the model does not have; it reads the live schema (`Rsx_Schema`), so a migration is seen with no rebuild. JS reads the field-length script `Bundle::render()` emits; `_`-prefixed columns are omitted.

## Bulk writes

`Model::where(...)->update()` / `->delete()` on a model with a side-effect surface - realtime, revisions, an overridden `after_*` hook, a text type with `after_model_saved()`, or a derived column - runs each affected row through its own `save()` / `delete()` inside one transaction, so frames, revisions, hooks and derivations happen PER RECORD.

| Write | Fires |
|---|---|
| `Model::where(...)->update()/->delete()` | everything, per record |
| `->raw_bulk()->update()` | NOTHING - one raw statement; never on a derived column; on a `from` column the caller rederives |
| `DB::table(...)`, raw SQL, `->toBase()` | NOTHING - bypasses the model layer |

After a write that fired nothing, `$record->realtime_emit()` covers realtime (`REALTIME-BULK-01`). Inside a migration's `up()` every model write - bulk included - THROWS (`rspade:migrations`).

## Refreshing a stale instance

`$record->refresh()` re-reads the row IN PLACE: attributes become what the database holds, unsaved changes are discarded, every relation and detail row is dropped and re-queried on next read. Use it after a write that did not go through the instance.
