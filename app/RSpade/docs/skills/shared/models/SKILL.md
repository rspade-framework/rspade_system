---
name: models
description: "Writing a model class - explicit assignment (MassAssignmentException), no eager loading, secrets in public static $neverExport (MODEL-NEVER-EXPORT-01), one fact stored in several columns in public static $fact_groups, derived properties via $appends + a delegating accessor, Model::field_length() / Model.field_length(), bulk ->update() / ->delete() vs ->raw_bulk() vs DB::table(), $record->refresh(). Use when writing a model, adding a computed field JS must see, storing a token or password, storing one edited value across several columns (a date-or-datetime, a type/id pair, a derived amount), or on a \"Model::$fact_groups ...\" LogicException, 'MASS ASSIGNMENT PROHIBITED' or 'Eager loading via with() is not allowed'."
---

# Writing a model

A model extends `Rsx_Model_Abstract` (or `Rsx_Site_Model_Abstract` for a site-scoped table). This skill covers the rules every model class meets; the specialised features have their own skills - `rspade:model-enums`, `rspade:model-fetch`, `rspade:text-types`, `rspade:revisions`, `rspade:detail-tables`, `rspade:actors-and-authorship`, `rspade:polymorphic`, `rspade:realtime`. Contract: `rsx:man model`.

## Assign every field explicitly - mass assignment THROWS

```php
// [NO] throws MassAssignmentException ("MASS ASSIGNMENT PROHIBITED IN RSPADE FRAMEWORK")
User_Model::create($request->all());
$user->fill($params);

// [OK] what a request can write is visible in the code
$user = new User_Model();
$user->first_name = $params['first_name'];
$user->email      = $params['email'];
$user->save();
```

Blocked: `fill()`, `forceFill()`, `create()`, `firstOrCreate()`, `firstOrNew()`, `updateOrCreate()`, `update([...])` on an instance. A record edit from a form saves through `Form_Edit::run()` (`rspade:form-engine`), which is explicit assignment with change tracking.

## Never eager load - `->with()` THROWS

`->with()`, `withCount()`, `$model->load('relation')`, `loadMissing()`, `loadCount()` throw ("Eager loading via with() is not allowed in the RSpade framework"). Load what you need, when you need it, with an explicit query. The rationale: an eager-load chain cascades silently across the database. Lazy relationships (`$client->contacts`) are fine.

## Secrets: `public static $neverExport`

```php
public static $neverExport = ['api_token', 'client_secret'];
```

`toArray()` - the ONE serializer behind `fetch()`, relationship and list payloads, `window.rsxapp.user` and the external API - unsets every listed column AFTER Laravel builds the array, so nothing can put one back. The effective list is the UNION up the inheritance chain (`Model::never_exported_columns()`): an override only ADDS. **Laravel's `$hidden` is not a security control** - `makeVisible()` undoes it; it is for tidiness. `rsx:check` flags a `password`/`token`/`secret` column (or one ending `_password`/`_token`/`_secret`) missing from the list (`MODEL-NEVER-EXPORT-01`); a column exported on purpose is declared `// @MODEL-NEVER-EXPORT-01-EXCEPTION <column> - <reason>`. On a `$revisions` model a never-exported column is recorded with both sides `Revision::REDACTED`. It is STATIC: a non-static redeclaration fails at class load.

## One fact, several columns: `public static $fact_groups`

Some value a person edits as ONE thing is stored across several columns, and which column holds it can depend on the record's shape. The worked case: an activity's "when" - an all-day one keeps its day in `date_start`/`date_end` with the times null, a timed one keeps it inside `time_start`/`time_end` - shown as one Date box either way. Others: a polymorphic type/id pair picked as one parent, an amount derived from quantity x rate, a value kept inside a JSON column beside others.

**The trap**: `Form_Edit` finds conflicts per COLUMN. When the other editor's re-date landed in `time_start` and the stale submission's Date field is stored in `date_start`, nothing matches - the stale save moves the activity back to the old day, a 200, no question.

```php
public static $fact_groups = [
    'when' => ['label' => 'Date and time', 'columns' => ['date_start', 'date_end', 'time_start', 'time_end']],
];
```

- A change saved to ANY column of a group is a change to every form field stored in ANY of its columns - on the record and on each coupled record through its map - always asked about, the question naming the group by its `label` and giving each changed column's before and after (`rsx:man form_conventions`, EDITING A RECORD, FACT GROUPS).
- Column terms, because it is a fact about the model whichever screen edits it; the endpoint's `fields:` / coupled map says which form field each column stores.
- Declare one whenever two or more columns are edited as one thing. There is no "is this a conflict?" callback, on purpose: one answering "no" by mistake silently loses an edit.
- `Model::fact_groups()` reads it, checked against the table's live columns the first time in a process: an unknown column, a column in two groups, a group under two columns or without a label throws a `LogicException` naming the model. The build does not see the database, so a group earns a test: save one column of it, then a stale submission of a field stored in another column is asked about.

## Derived properties: `$appends` + a delegating accessor

A computed value that must reach JavaScript is DECLARED, never hand-copied onto one payload:

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

- **The accessor DELEGATES to the public method** - never re-implement the computation in it, or the two drift.
- Eloquent applies `$appends` inside `parent::toArray()`, so the value rides EVERY payload: `fetch()`, `portal_fetch()`, a relationship, a list. **A key hand-added inside `fetch()` lands on that one payload and on no other**, and nothing notices when a sibling model or the portal twin forgets it.
- The generated `Base_<Model>_Model.js` declares each name as a read-only derived property; `field_length()` answers null for it (not a column), and it gets no enum treatment.
- **Cost**: an accessor runs on EVERY serialization, lists and batch fetches included. One that reads other rows is paid per record - keep such a derivation small and single-record in intent, and let a list select the columns it needs.
- Naming: the property name IS the method name (no aliasing). `get{Property}Attribute` is one of the two Laravel spellings the naming rule exempts.

Worked example: `system/app/RSpade/resource/reference_app/models/party_model.php`.

## Column length: `field_length()`

`Model::field_length('col')` (PHP) and `Model.field_length('col')` (JS) are ONE definition: the max characters of a varchar/char, `null` for every other type (a real answer: "no character limit"). **A server-side length check reads it; it never restates a schema number**, and a form's `$max_length` takes the same call.

- PHP THROWS for a column the model does not have (answering null would read as "unlimited"), and for a table not yet migrated.
- It reads the live schema (`Rsx_Schema`, cached under the state key), so a migration is seen with no rebuild. CTI detail columns answer on the base model.
- JS reads the field-length script `Bundle::render()` emits; a page that prints its bundle's tags by hand has none and the call throws. `_`-prefixed system columns are omitted from it.

## Bulk writes

`Model::where(...)->update()` / `->delete()` on a model with a side-effect surface - realtime, revision recording, an overridden `after_*` hook, or a declared text type with an `after_model_saved()` hook - loads the affected rows and runs each through its own `save()`/`delete()` inside one transaction, so frames, REVISIONS and hooks fire PER RECORD. There is no row cap.

| Write | Fires |
|---|---|
| `Model::where(...)->update()/->delete()` | everything, per record |
| `->raw_bulk()->update()` | NOTHING - one raw statement, for a deliberate mass-maintenance write |
| `DB::table(...)`, raw SQL, `->toBase()` | NOTHING - bypasses the model layer permanently |

After a write that fired nothing, `$record->realtime_emit()` covers realtime (`rsx:man realtime`, BULK WRITES; `REALTIME-BULK-01`).

## Refreshing a stale instance

`$record->refresh()` re-reads the row IN PLACE and returns the same object: attributes become what the database holds, unsaved changes are discarded, and every relation and detail row it had read is DROPPED and re-queried on next read. Use it for an instance left stale by a write that did not go through it (raw SQL, `DB::table()`, `->raw_bulk()`, a service that saved its own copy).
