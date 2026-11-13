# Derived columns in depth

A derived column is a STORED column whose value a model method computes and the framework writes. Nobody assigns it: not code, not a form, not the external API, not a migration. The contract is `rsx:man derived_columns`; this file is the how-to. The worked example throughout is `Task_Model` in the reference app (`rsx/models/task_model.php`): `project_id` is the project a task's parent chain reaches, else the user's `direct_project_id`.

## When to use one

- The value is a function of other data and a person never types it.
- It must be QUERIED - filtered, sorted, indexed, joined, summed - so computing it on read is not enough.

A value JavaScript needs but nobody queries is an APPENDED PROPERTY (`$appends`, SKILL.md). A value that is sometimes chosen by a person and sometimes supplied by a rule is two columns (below).

## Declaring one

```php
public static $columns = [
    'project_id' => ['derived' => 'derive_project_id', 'from' => ['taskable_type', 'taskable_id', 'direct_project_id']],
];

/**
 * The derive method of project_id: the project the parent chain reaches, else direct_project_id.
 */
public function derive_project_id(): ?int
{
    return $this->resolve_chain_project_id()
        ?? ($this->direct_project_id !== null ? (int) $this->direct_project_id : null);
}
```

- `derived` names a **public, non-static method of the model taking no required argument**. It returns the value; it may read other tables (a site-scoped read sees the site declared for the request or task; the `migrate` drain and `rsx:derived:*` declare each record's own site). It must be deterministic - the same stored data, the same answer.
- `from` lists the columns of THIS record the method reads. `[]` is legal (the value depends only on other tables - then only `rederive()` and inserts compute it). It may name another derived column (computed first: derived columns run in dependency order). It never names the column itself; derived columns depending on each other in a cycle are a manifest-build error (`MODEL-COLUMNS-01`) and a runtime `LogicException` ("declares derived columns that depend on each other in a cycle: a -> b -> a").
- The column may also carry `enum`, `text_type`, `type_ref` or `never_export` - the computed value is assigned through `setAttribute()`, so the kind applies. Never `revision_exclude` (redundant: it is a build error).
- Every `from` column must exist on the table (`MODEL-ENUM-01` in `rsx:check`; the first save throws otherwise).
- The generated header shows it as `@property-read ... derived: computed by m() from a, b; never assigned`. JS: `Model.is_derived_column('project_id')`, and the stub documents it as read-only.

## When `save()` derives

| Save | Derived columns computed |
|---|---|
| INSERT | all of them |
| UPDATE where a `from` column is dirty | those whose `from` changed (a derived column that just changed counts as changed for the ones after it) |
| UPDATE touching no `from` column | none |
| `rederive()` | all of them |

A computed value equal to the stored one leaves the column clean. The framework derives from THIS record's columns only: it never notices that a row the method reads in another table (or another row of the same table) moved.

## The refusals

All of them say the same thing: write the SOURCE columns.

| Where | What is refused | Message begins |
|---|---|---|
| `save()` | a hand assignment: on a new record ANY presence of the column (even null or the right value), on an existing one a dirty value | `Task_Model::project_id is a derived column - Task_Model::derive_project_id() computes it from [taskable_type, taskable_id, direct_project_id] on save. It is never assigned.` |
| `Form_Edit::set('field', ...)` | a field whose column is derived | `Form_Edit::set('project_id'): Task_Model::project_id is a derived column (...); a form never writes it.` |
| `Model::where(...)->update([...])` | naming a derived column, `->raw_bulk()` included | `... is a derived column - ... A bulk update() never writes it, raw_bulk() included.` |
| `Model::where(...)->update([...])` | a `DB::raw()` expression on a `from` column, `->raw_bulk()` included | `Task_Model: a raw SQL expression cannot be written to taskable_id, which the derived column project_id is computed from` |
| `rsx:check` | `$record->project_id = ...` where the class is evident | `MODEL-DERIVED-01` |

A PLAIN bulk `->update()` of a `from` column is fine: a model with a derived column always takes the per-record path, so each record derives in its own `save()`. `->raw_bulk()->update()` with plain values on a `from` column is allowed and writes no derived value - the caller then calls `rederive()` on each affected record.

## `rederive()`

```php
$changed = $record->rederive();   // true when it saved
```

Recomputes every derived column from the record as stored and saves **only** when a value differs. Use it when what a derive method reads lives elsewhere and has moved.

- Throws on an unsaved record (an unsaved one is derived by its first `save()`), and with a `LogicException` on a record with unsaved changes - they would ride the same save unasked: `Task_Model #12::rederive() refused: the record has unsaved changes (title). Save them, or discard them with refresh(), first.`
- It runs the model's own `save()` - overrides, site-scope checks and site-lock enforcement included. A site-scoped record is rederived inside a `#[Writer]` endpoint or a `Site_Lock::write()` section.
- Answers `false` for a model with no derived columns.

## A derived-only save

A save in which the ONLY changed columns are derived ones - every `rederive()`, and a cascade - is the framework keeping the record consistent, not somebody editing it:

- `updated_at` is held where it was (also against MySQL's `ON UPDATE CURRENT_TIMESTAMP`), and the `updated_by` pair is not stamped.
- No revision is written. Derived columns never appear in revision history in ANY save (`revision_excluded_columns()` includes them).
- The realtime notice is still sent and `after_update()` still fires: a screen showing the value refreshes, and a hook keyed on the column still runs.

## A value that depends on another record: the dependents pattern

The framework has NO cross-record trigger. When record B's derived value reads record A, the code that changes A rederives B - in A's model, so no caller can forget it. The shape is a `save()` override that saves, then rederives the dependents, inside ONE transaction:

```php
public function save(array $options = [])
{
    // Only a change to what the dependents read can move them.
    if (!$this->exists || !$this->isDirty(['taskable_type', 'taskable_id'])) {
        return parent::save($options);
    }

    return DB::transaction(function () use ($options) {
        $result = parent::save($options);

        if ($result) {
            $this->__rederive_descendants();   // $child->rederive() for every task below this one
        }

        return $result;
    });
}
```

- `parent::save()` first: this record's own derived columns are computed there, and the dependents read the saved row.
- Walk EVERY dependent, not only those whose value changed - a dependent whose own value happens to stay the same may still have dependents of its own that move. `Task_Model::__rederive_descendants()` walks the task tree level by level with a visited set (a corrupt cyclic chain ends).
- A dependent's `rederive()` is a derived-only save touching none of ITS `from` columns, so it cascades nothing further by itself; the walk does the cascading.
- The whole thing is one transaction: a failure leaves no half-moved subtree. Under a writer endpoint it is already inside the site's write lock.

## Editable unless a rule supplies it: two columns

There is no conditional derivation - a column is derived always or never. "A user picks the project, unless the parent chain already determines it" is TWO columns:

- `direct_project_id` - plain, the user's own choice, editable always.
- `project_id` - derived from the chain and `direct_project_id`: the chain's project when it reaches one, else `direct_project_id`.

The user's choice is never overwritten: while the chain supplies the value, `direct_project_id` keeps what the user chose and comes back when the chain is broken. Queries, filters and lists read `project_id`; forms and write APIs write `direct_project_id`. A migration introducing such a pair backfills the source column with SQL and enqueues the table (`rsx/resource/migrations/2026_11_13_063004_add_direct_project_id_to_tasks.php`).

## Showing the derived value in a form

The form's input edits the SOURCE column. While the rule supplies the value, the input is disabled and shows the derived value, and the submission must leave the source alone:

1. The page decides, live, whether the rule applies (`Tasks_Edit_Action` asks an endpoint as the parent picker changes) and, when it does, remembers the input's own value, shows the derived one, and disables the input with a note ("Derived from parent chain: ...").
2. The page installs a `form.before_submit` hook that removes that field from the submitted values while the input is disabled.
3. The endpoint writes the source column ONLY when the key is present (`if (array_key_exists('direct_project_id', $values)) $edit->set(...)`, `Task_Model::save_edit()`).

A blank field still means blank: the key IS present when the input is enabled. Worked example: `rsx/app/frontend/tasks/edit/Tasks_Edit_Action.js` and `Task_Model::save_edit()`.

## The external API

A derived column is a READ field: responses carry it, list endpoints may filter on it, and it is never an `#[Api_Param]` of a write - sending it is answered like any unknown parameter (422 "Unknown parameter"). The write parameter is the source column (`rsx/app/api/v1/tasks_api_controller.php`: `direct_project_id`).

## Migrations, drift and the commands

- **A migration never saves through a model** - while `up()` runs, a model `save()` / `delete()` and a bulk builder `update()` / `delete()` THROW (`rspade:migrations`). It changes source columns with `DB::statement()` / `DB::table()`.
- When a migration changes what derived columns are computed from - adds or rewrites a source column, changes data a derive method reads - it either writes the derived values in SQL too, or calls `\App\RSpade\Core\Database\Rsx_Rederive::enqueue('tasks')` (a TABLE name). `migrate` rederives every queued table as its LAST step, with current model code against the final schema, saving only stale records.
- **Changing a derive METHOD's logic** changes every stored value it ever computed. Ship a migration whose `up()` only calls `Rsx_Rederive::enqueue('<table>')`, so every environment recomputes on its next `migrate`; an operator can also run `rsx:derived:rebuild <table>`.
- `php artisan rsx:derived:check <table> | --all` reports records whose stored values differ from what the model computes now (exit 1 on drift, nothing changed). `php artisan rsx:derived:rebuild <table> | --all` repairs them (only differing records are saved, as derived-only saves; realtime frames are sent).

## Testing a derived column

Cover: an insert computes it; changing each `from` column re-derives; an assignment throws; a change to the record it reads elsewhere moves it only through the dependents path (and that path reaches every level); a derived-only save leaves `updated_at` alone and writes no revision; the form keeps the source when the field is omitted. `rsx/tests/Task_Derived_Project_Test.php` is the worked set.
