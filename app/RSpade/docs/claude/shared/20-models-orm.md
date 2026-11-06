<!-- single-source: never duplicate into another fragment. -->

## MODELS & ORM

**NEVER mass-assign** (`fill()`/`create($request->all())` THROW `MassAssignmentException`) - assign every field explicitly. **NEVER eager load** - `->with()`/`withCount()` THROW; load what you need, when you need it. **A secret column goes in `public static $neverExport`** (`MODEL-NEVER-EXPORT-01`) - Laravel's `$hidden` is not a security control. **A computed value that must reach JS is `$appends` + an accessor delegating to the method that defines it** - never a key hand-added inside `fetch()`. **Column length is `Model::field_length('col')` / `Model.field_length('col')`** - never a restated schema number. **Bulk `->update()`/`->delete()` runs each row through `save()`/`delete()` on a model with side effects; `->raw_bulk()` and `DB::table()` fire NOTHING** (no realtime, no revisions, no hooks). Skill `rspade:models`; `rsx:man model`.

**A site-scoped write needs the site's WRITE LOCK** (`#[Writer]`, `Site_Lock::write()`); raw `DB::table()` / `->raw_bulk()` writes are not caught. **`#[Site_Lock_Exempt('<reason>')]`** is for append-only view/audit/telemetry tables only (one call site: `Site_Lock::write_exception()`) - never business data, nor a table read to decide business data.

**Enums** are BIGINT columns mapped by `public static $enums` (`Project_Model::STATUS_ACTIVE`, `$project->status_id__label`, JS `status_id__enum_select()`); never VARCHAR/ENUM. Skill `rspade:model-enums`.

**Model fetch** - JS reaches records through ONE framework endpoint per model, opt-in: `await Project_Model.fetch(id)` / `fetch_or_null()`, lazy relationships `await project.client()`. `#[Ajax_Endpoint_Model_Fetch]` + MANDATORY `#[Auth]`, record-level rules in the `fetch()` body, a denial answers "not found". **`fetch()` is for SECURITY, never aliasing or formatting; changing it requires user approval; a separate controller endpoint fetching one record is an anti-pattern; a model missing from the JS bundle means STOP and ask.** Skill `rspade:model-fetch`.

**Declared TEXT types**: `public static $text_types = ['col' => Rich_Text::class]` makes a TEXT column a value object (`Rsx_Text_Abstract`), filtered on every write and travelling as a `{__TEXT, raw, empty}` envelope. **A bare string assigned to it is PLAIN TEXT** (escaped) - so code composing markup RETURNS THE TYPE (`Type::from_untrusted_encoded()`), never a string, the one silent mistake; **test emptiness with `is_empty()`, never `=== ''`**; side effects go in the type's `after_model_saved()`. Undeclared columns are ordinary strings. Skill `rspade:text-types`.

**Revision history** is `public static $revisions = true;` (one transaction per unit of work; an import declares one per record with `Revision::unit_of_work()`; `REVISION-01` is a build FATAL; **`Revision::transaction_id()` MINTS a transaction** - call it only to store the id). **There is no restore and no automatic activity text; both are the application's job.** Skill `rspade:revisions`.

**Detail tables** (class-table inheritance, `$detail_tables`, `$party->party_person->first_name`) throw on wrong-type access. Skill `rspade:detail-tables`.
