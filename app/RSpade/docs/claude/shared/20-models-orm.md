<!-- single-source: never duplicate into another fragment. -->

## MODELS & ORM

**NEVER use mass assignment** — assign every field explicitly, so what a request can write is visible in the code. `User_Model::create($request->all())` is the shape never to write.

**NEVER eager load relationships** — `->with()` throws framework-wide. Load what you need, when you need it.

Bounded result sets (`$unbounded`, `Rsx_Result_Set`, `LIMIT`) are governed by the engineering-mandates fragment.

**Enums** are integer columns (BIGINT, never VARCHAR/ENUM) mapped at the model by a `public static $enums` array to constants, labels and custom properties, read through BEM-style double-underscore magic properties — `Project_Model::STATUS_ACTIVE`, `$project->status_id__label`, `Model.status_id__enum_select()` in JS. Run `rsx:constants:regenerate` after changing one.

**Model fetch** — JavaScript reaches ORM records through ONE framework endpoint, per model, by explicit opt-in:

```javascript
const project = await Project_Model.fetch(1);           // throws if not found
const maybe   = await Project_Model.fetch_or_null(999); // null if not found
const client  = await project.client();                 // lazy relationship
```

**Security is two layers**: `#[Ajax_Endpoint_Model_Fetch]` makes the surface exist, and its MANDATORY `#[Auth]` is evaluated BEFORE any model code — **a denial returns the same generic "not found" as a missing row (anti-enumeration)**. Gates take no arguments, so **record-level rules (ownership, membership, record state) stay in the `fetch()` body**. **`fetch()` is for SECURITY, not aliasing** — never rename a field or format a date in it; it serves LIVE records only; **changes to it require user approval**. A model lacking `fetch()` gets one (a framework model via a class override in `rsx/models/`) — **separate controller endpoints that fetch single records are an anti-pattern**, and a model missing from the JS bundle means STOP and ask.

**Bulk writes**: `Model::where(...)->update()/->delete()` on a model with a side-effect surface (realtime, revision recording, or an overridden `after_*` hook) loads the rows and runs each through its own `save()`/`delete()`, so frames, REVISIONS and hooks fire PER RECORD. `->raw_bulk()` fires NOTHING; `DB::table()`/raw SQL bypasses the model layer entirely.

**Revision history**: `public static $revisions = true;` on a model records every committed `save()`/`delete()` as a `{field: [before, after]}` document, grouped under ONE transaction per unit of work (a web request, an API call, ONE Ajax endpoint call, a task, a test) carrying actor, `source_id`, `endpoint`, `ip`. `protected static $revision_exclude` drops noisy columns; `updated_at`, the `updated_by` pair and every `_`-prefixed system column are dropped automatically. `#[Revision_Parent]` on a child's `belongsTo` files its revisions under the parent's history (**`REVISION-01` is a manifest-build FATAL** on an incoherent declaration). Read with `$record->revisions()` / `revisions_including_children()` and `Revision_Model::diff()`; `Revision::transaction_id()` is a WRITER (it MINTS) an app stores on its own activity row; `Revision::without()` suppresses, `describe()` labels. **There is no restore-this-revision and no automatic activity text — both are the application's job.** Skill `rspade:revisions`. Details: `rsx:man revisions`.

**Detail tables** (class-table inheritance): a base model spanning 1:1 detail tables selected by a discriminator, declared by a `$detail_tables` map and reached as `$party->party_person->first_name` — **throws on wrong-type access** in both languages.

Skills: `rspade:model-fetch`, `rspade:model-enums`, `rspade:detail-tables`, `rspade:revisions`. Details: `rsx:man model_fetch`, `rsx:man enums`, `rsx:man model`.
