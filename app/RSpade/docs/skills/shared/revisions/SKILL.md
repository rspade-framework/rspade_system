---
name: revisions
description: Recording and reading per-model revision history in RSpade - opting a model in with $revisions and $revision_exclude, filing a child's writes under its parent with #[Revision_Parent], the one transaction per unit of work, reading it back with revisions() / revisions_including_children() / Revision_Model::diff(), storing Revision::transaction_id() on an application activity row, and suppressing a backfill with Revision::without(). Use when a screen must show what changed and who changed it, when adding $revisions to a model, when responding to a REVISION-01 manifest-build failure, when a bulk write records too many or no revisions, or when a read throws "unknown codec byte" or "unknown dictionary id".
---

# Revision history

Every committed `save()` / `delete()` on an opted-in model is recorded as a
`{field: [before, after]}` document, grouped under ONE transaction per unit of work.
Contract: `php artisan rsx:man revisions`.

**The transaction is the point.** A revision says "this field changed". The question a
history screen asks is "what did that one action change" - a client save that also wrote
three contact rows is ONE thing the user did, and the `_transactions` row is what says so.

## Opting in

```php
class Client_Model extends Rsx_Site_Model_Abstract
{
    public static $revisions = true;

    // Columns that move without the user having changed anything.
    protected static $revision_exclude = ['open_task_count', 'last_seen_at'];
}
```

That is the whole wiring - shaped exactly like `$realtime`, and off costs one static read.

`updated_at`, the `updated_by` pair and every `_`-prefixed system column are excluded
automatically. `created_at` and the `created_by` pair are NOT: they appear once, on the
create, where they are the record's provenance.

**`$revision_exclude` is not a security control.** An excluded column is still stored in
the record. To keep a value out of the database, do not put it there.

## Filing a child under its parent

```php
class Contact_Model extends Rsx_Site_Model_Abstract
{
    public static $revisions = true;          // required - see check 1 below

    #[Relationship]
    #[Revision_Parent]
    public function client() { return $this->belongsTo(Client_Model::class); }
}
```

Now `$client->revisions_including_children()` returns the writes that landed on its
contacts too, as ONE indexed query on the stored root pair.

**ONE parent, not a cascade.** The first declared `#[Revision_Parent]` with a non-null
foreign key wins; the root is never resolved transitively (a transitive root would have to
be recomputed for every existing revision the day a middle link moved).

**`REVISION-01` is a manifest-build FATAL** with three checks: the declaring model records
revisions, the annotated method returns a `belongsTo`, and the parent records revisions
too. Every failure mode is silent at runtime - a history screen quietly missing rows,
months later - which is why it is fatal. Suppress a genuine exception with
`@REVISION-01-EXCEPTION`.

A polymorphic parent (`morphTo`) cannot carry the attribute: a revision has exactly one
root, and only a `belongsTo` names exactly one parent row. `Task_Model` in the reference
app is the worked example of a model that opts in and deliberately declares no parent.

## Reading it back

```php
foreach ($client->revisions() as $revision) {              // this record only
    $revision->operation_id__label;                        // Created/Updated/Deleted/Restored
    $revision->diff();                                     // ['name' => ['Acme', 'Acme Ltd']]
    $revision->transaction->actor;                         // who
    $revision->transaction->source_id__label;              // Web/Ajax/API/Task/CLI/Test
    $revision->transaction->endpoint;                      // 'Clients_Controller::save'
}

$client->revisions_including_children();                   // + every #[Revision_Parent] child
Revision::transactions_for($client);                       // the units of work, newest first
Revision::for_transaction($id);                            // one unit's revisions, in sequence
```

All of them return `Rsx_Result_Set` - the set is unbounded, so a screen pages it.

## The activity-row recipe (the app writes the sentence)

The framework never writes "User X updated Client Y". It records fields, actors and
endpoints; it does not write sentences, because a sentence is the application's vocabulary
("Updated" is wrong when the change was an approval). So the app records its own activity
row and **stores the transaction id on it**:

```php
$activity = new Activity_Model();
$activity->text = 'Approved the proposal';
$activity->transaction_id = Revision::transaction_id();   // WRITER - it MINTS the row
$activity->save();
```

Later, `Revision::for_transaction($activity->transaction_id)` walks exactly the field
writes that sentence covered.

**`transaction_id()` is the only writer on the facade.** Anything that merely wants to look
at the current transaction calls `current_transaction()`, which never mints.

## The viewer recipe

The reference app's worked example is one endpoint feeding one component:

- `system/app/RSpade/resource/reference_app/app/frontend/revisions/revisions_controller.php`
- `system/app/RSpade/resource/reference_app/theme/components/view/revision_history/`
- `system/app/RSpade/resource/reference_app/app/frontend/clients/history/Clients_History_Action.js`

The three decisions worth copying:

1. **The endpoint reads the record through the model's own `fetch()`**, so every
   record-level rule that model enforces governs its history too and a denial is
   indistinguishable from a missing row. No second gate to drift from the first.
2. **`record_type` is resolved against an ALLOWLIST**, never used as a class-name lookup -
   otherwise one endpoint becomes "read the history of any table in the database".
3. **Nothing is formatted server-side.** The payload ships stored values under their real
   field names; the browser turns an enum id into a label with the generated stub's
   `Model.field__enum_labels()`, an `_at` / `_date` column through `Rsx_Time` / `Rsx_Date`
   (JQHTML-DATETIME-01), and a null into `<Empty_Value>`.

```jqhtml
<Revision_History $record_type="Client_Model" $record_id=_record.id />
```

## Suppressing a write that is not a user action

```php
Revision::without(function () use ($client) {
    $client->open_task_count = $client->tasks()->count();
    $client->save();
});
```

A backfill, a data migration, a recomputed counter. Suppression is restored in a `finally`,
so a throw inside the callable cannot leave recording off.

`Revision::describe('Nightly contact import')` labels the unit of work; it works before or
after the first revision, and nothing keys on it.

## Gotchas

- **Bulk.** `Model::where(...)->update()/->delete()` records ONE REVISION PER RECORD (it
  runs each row through `save()`). `->raw_bulk()` records nothing; `DB::table()` and raw
  SQL bypass the model layer and record nothing. Same rule realtime follows, same reason:
  only the per-record path can produce a per-record diff.
- **There is no manual recording call.** Realtime has `$record->realtime_emit()` for a
  write that bypassed the model layer; revisions have no equivalent, deliberately - a write
  the model layer never saw has no *before* the framework can vouch for. Route it through
  `save()`.
- **No restore-this-revision, and adding one is not a small feature.** A revision records
  what the user CHANGED, not what the record WAS: a delete stores an empty document, an
  update stores only what moved, excluded columns are not stored at all. Restoring also
  crosses foreign keys that may no longer resolve, soft-deleted rows, children created
  later and uniqueness another row now satisfies - which of those to honour is an
  APPLICATION decision, written against the app's own rules with the history as evidence.
- **A `_`-prefixed column is invisible everywhere** - stripped by `toArray()`, skipped in
  the JS stub's `field_length()` map, excluded from every diff. Never give an app-facing
  column a leading underscore. (`__` is the framework key prefix and is untouched.)
- **An Ajax BATCH is one transaction PER CALL**, not one for the batch: each call snapshots
  the facade, resets it, runs and restores. A task worker starts a fresh one per task.
- **A rolled-back database transaction takes its revisions with it.** They are written on
  the same connection, immediately - never `afterCommit`. That is the intended behavior.
- **An update that changed nothing records nothing**, and an update whose every changed
  column was excluded records nothing either.
- **A child's writes missing from the parent's history** means no `#[Revision_Parent]`, or
  the foreign key was null at write time - the root pair is written at write time and is
  never recomputed.
- **"unknown codec byte N" / "unknown dictionary id N" on read.** The row was written by a
  build that knows a format this one does not, or its `_revision_dictionaries` row is gone.
  **Dictionary rows are append-only and must never be deleted** - every stored payload
  names the dictionary it was written against, in its prefix byte.
- **Retention defaults to KEEP FOREVER** (`rsx.revisions.retention_days` = 0). Only
  `_transactions` is ever deleted; `_revisions` follows by FK cascade.

Details: `php artisan rsx:man revisions`. Related: `rspade:realtime` (the same bulk rule),
`rspade:actors-and-authorship` (who the actor pair names), `rspade:model-fetch` (the gate
the history endpoint reuses).
