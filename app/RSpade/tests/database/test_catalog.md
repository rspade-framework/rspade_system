# Test catalog: database

Status legend: `implemented` | `deferred` (reason) | `blocked` | `planned`.
Type: php / cli / asset / http / playwright.

## Rsx_Result_Set_Test (php, default isolation) - the whole-set handle

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| db-rs-01 | foreach reaches every record across pages | 7 rows, chunk 2 | all 7, each exactly once | implemented |
| db-rs-02 | a chunk size of 1 still returns everything | 7 rows, chunk 1 | 7 | implemented |
| db-rs-03 | the set is re-iterable (query cloned, not an exhausted generator) | two foreach passes | same count both times | implemented |
| db-rs-04 | count() matches the row count | 7 rows | 7 | implemented |
| db-rs-05 | count() is ONE aggregate query, not a walk | chunk 1, query log | 1 query, contains count(*) | implemented |
| db-rs-06 | first() returns a record | populated set | a model in the fixture | implemented |
| db-rs-07..08 | is_empty() false when populated, true for a matchless query | both | correct bool, count 0, first null | implemented |
| db-rs-09 | empty set foreach runs zero times | matchless query | 0 iterations | implemented |
| db-rs-10 | all() materializes the whole set | 7 rows | array of 7 | implemented |
| db-rs-11 | forwarded collection methods work AND stay lazy | map()->take(2) | 2 items, walk short-circuits | implemented |
| db-rs-12 | query() returns a usable builder escape hatch | - | builder counts 7 | implemented |
| db-rs-13 | a non-positive chunk size fails loud | chunk 0 | throws | implemented |

## Result_Set_Tripwire_Test (php, default isolation) - dev row-count warning

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| db-tw-01 | warns exactly once when a query exceeds the threshold | 6 rows, threshold 2 | 1 warning | implemented |
| db-tw-02 | the warning names the count, the model and the remedy | threshold 2 | contains model, count, "result_set" | implemented |
| db-tw-03 | silent under the threshold | threshold 7 | no warning | implemented |
| db-tw-04 | silent AT the threshold (strictly greater-than) | threshold 6 | no warning | implemented |
| db-tw-05..06 | 0 / null disable the tripwire | either | no warning | implemented |
| db-tw-07 | the caller still receives EVERY row when warning | threshold 1 | warned, and all 6 rows returned | implemented |

## Audit authorship auto-stamp (`php/Audit_Stamp_Test.php`)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| db-as-01 | INSERT stamps BOTH pairs with the site-scoped user | staff actor, site-scoped record, sites match | created/updated = User_Model + id, in the DB | implemented |
| db-as-02 | the type column holds the _type_refs integer, not a string | one insert | raw value == class_to_id('User_Model') | implemented |
| db-as-03 | UPDATE moves only the updated pair | hand-set created pair, then an update | created pair untouched, updated = User_Model | implemented |
| db-as-04 | an explicitly assigned pair wins over the stamp | both pairs hand-set on insert | hand-set values preserved | implemented |
| db-as-05 | a clean save() is not stamped (no manufactured write) | save() with nothing dirty | updated pair stays NULL | implemented |
| db-as-06 | a record with no site stamps the cross-site identity | staff actor, non-site-scoped model | Login_User_Model | implemented |
| db-as-07 | site 0 is "no site selected", not a site | staff actor at site 0 | Login_User_Model | implemented |
| db-as-08 | nobody signed in leaves the pairs NULL | no actor | all four NULL | implemented |
| db-as-09 | a portal actor in its own site stamps the portal account | portal request, sites match | Portal_User_Model | implemented |
| db-as-10 | a portal actor outside its site stamps NOTHING (no cross-site portal identity) | portal request, non-site-scoped record | NULL pair | implemented |
| db-as-11 | $record->created_by resolves the actor model through the pair | stamped records of both arms | User_Model / Login_User_Model instances | implemented |
| db-as-12 | an unattributed record's relation is null, not an exception | NULL pair | null | implemented |
| db-as-13 | the audit type columns survive a model declaring its own $type_ref_columns | Portal_Notification_Model | subject_type AND both audit columns cast | implemented |
| db-as-14 | a WHERE on an audit type column converts the class name to its type ref | where('created_by_type','User_Model') | the row is found | implemented |

## Audit DELETION stamp (`php/Audit_Delete_Stamp_Test.php`)

The third audit pair, deleted_by_id/deleted_by_type. It exists only where deleted_at does,
it is written on a path save() never sees, and a restore CLEARS it.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| db-ds-01 | a soft delete stamps the deleter, in the DB and on the instance | staff actor, site-scoped soft-deleting record | deleted_by = User_Model + id, type stored as the type ref | implemented |
| db-ds-02 | the stamp rides the soft delete's OWN statement (never a follow-up write) | query log around delete() | exactly ONE update, carrying deleted_at + both pair columns | implemented |
| db-ds-03 | restore() clears the pair | delete then restore | deleted_at + both halves NULL | implemented |
| db-ds-04 | clearing does not require an actor (it is not an attribution) | restore with nobody signed in | pair cleared | implemented |
| db-ds-05 | an explicitly assigned pair wins - and is not dropped by runSoftDelete | pair hand-set before delete() | hand-set values persisted | implemented |
| db-ds-06 | nobody signed in leaves the pair NULL but still deletes | no actor | deleted_at set, pair NULL | implemented |
| db-ds-07 | a HARD delete stamps nothing and cannot (no row survives) | non-soft-deleting model | delete succeeds, row gone | implemented |
| db-ds-08 | the two presence memos are independent (authorship still stamped on a table with no deletion pair) | countries insert | authorship pairs stamped, deleted_by_id column absent | implemented |
| db-ds-09 | $record->deleted_by + get_deleted_by_author() resolve the deleter | deleted record | User_Model instance, non-empty name | implemented |
| db-ds-10 | a live record has no deleter | undeleted record | relation and author both null | implemented |
| db-ds-11 | the deletion stamp reuses the ONE actor matrix (cross-site arm) | non-site-scoped soft-deleting model | Login_User_Model + login user id | implemented |
| db-ds-12 | attachment retention round trip carries then clears the stamp | File_Attachment delete() + undelete() | stamped, then all NULL | implemented |
| db-ds-13 | force_destroy() (deleted_at assigned + save, never SoftDeletes::delete) is stamped too | force_destroy an attachment | deleted_at + pair set | implemented |
| db-ds-14 | the stamp fires on the TRANSITION, so a later save does not rewrite the deleter | save an already-deleted record | original deleter preserved | implemented |

## Deferred

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| db-lint-01 | DB-UNBOUNDED-01 fires on a bare get()/pluck() on an $unbounded model, and stays silent for limit()/result_set() | cli | verified manually by planting violations in a framework file; a permanent test needs a fixture-file harness the rsx:check runner does not have yet | deferred |

## Actor model layer (Actor_Model_Test)

The identity layer behind authorship: who can be named in a `*_by_type` column, what they
print, and where the current viewer may see them. See `rsx:man actors`.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| db-actor-01 | all three framework identity models extend the actor layer | php | User/Portal_User/Login_User | true for each | implemented | 2026-08-09 |
| db-actor-02 | site-scoped actors use the SITE abstract | php | User_Model, Portal_User_Model | extend Rsx_Site_Actor_Model_Abstract | implemented | 2026-08-09 |
| db-actor-03 | the cross-site identity uses the PLAIN abstract (must not carry the site scope) | php | Login_User_Model | extends the plain one, NOT the site one | implemented | 2026-08-09 |
| db-actor-04 | every class the stamp can name is an actor (runtime twin of ACTOR-01) | php | AUDIT_ACTOR_MODELS | true for each | implemented | 2026-08-09 |
| db-actor-05 | all three actor TABLES carry deleted_at | php | users, login_users, portal_users | column present | implemented | 2026-08-09 |
| db-actor-06 | all three actors resolve the SoftDeletes trait through the layer | php | the three classes | trait in class_uses_recursive | implemented | 2026-08-09 |
| db-actor-07 | deleting an actor is a SOFT delete (the row survives for authorship) | php | delete a portal account | find() null, withTrashed() finds it | implemented | 2026-08-09 |
| db-actor-08 | get_printed_name() is never empty on any of the three | php | one of each | non-empty string | implemented | 2026-08-09 |
| db-actor-09 | get_printed_name() still answers when the name fields are empty | php | staff user with blank names + email | non-empty string | implemented | 2026-08-09 |
| db-actor-10 | a TRASHED actor prints the IDENTICAL name (no "(deleted)" marker) | php | name before/after delete | equal strings | implemented | 2026-08-09 |
| db-actor-11 | Login_User_Model has no profile page anywhere | php | a login identity | null | implemented | 2026-08-09 |
| db-actor-12 | a staff user viewing their OWN record gets the profile screen | php | acting user viewing self | url containing profile_display | implemented | 2026-08-09 |
| db-actor-13 | a user-manager viewing ANOTHER user gets the user-management detail screen with the id | php | developer viewing a second staff user | url containing user_management + id | implemented | 2026-08-09 |
| db-actor-14 | the SAME record yields no link for a viewer failing the destination's gate, while their own record still resolves | php | ROLE_USER viewer | null for the other, non-null for self | implemented | 2026-08-09 |
| db-actor-15 | a portal account exposes no staff-side profile route | php | staff realm | null | implemented | 2026-08-09 |
| db-actor-16 | a portal user reaches their OWN account screen and not another's | php | portal realm, two accounts | non-null for self, null for the other | implemented | 2026-08-09 |
| db-actor-17 | get_created_by_author() returns the {name,url} pair for a stamped record | php | record written by a signed-in actor | array with non-empty name | implemented | 2026-08-09 |
| db-actor-18 | a HALF-SET authorship pair resolves to null rather than guessing | php | type null, id set | null | implemented | 2026-08-09 |
| db-actor-19 | authorship display survives the actor being trashed, printing the same name | php | delete the author, then resolve | same name | implemented | 2026-08-09 |

Note for future authors: `Auth_Gates` evaluates every check LIVE, against whoever is signed
in at the moment of the ask, so switching the acting user with `__acting_as_user()` is the
whole setup an identity-dependent assertion needs - there is nothing to invalidate.


## Relationship_Lineage_Test (php, no DB) - get_relationships() unions the lineage

Covers B-78: the manifest indexes methods per FILE, so an inherited `#[Relationship]` is
absent from a subclass's own entry. `get_relationships()` climbs `extends_fqcn` and
terminates at the first ancestor with no manifest entry.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| db-rel-01 | the parent-declared audit relations reach a template model | `Client_Model::get_relationships()` | contains created_by, updated_by, deleted_by | implemented |
| db-rel-02 | own-file relations survive the union | same | contains billing_contact, contacts, owner | implemented |
| db-rel-03 | the premise: those names are NOT in the model's own manifest entry | `php_get_metadata_by_class('Client_Model')` | audit names absent from public_instance_methods | implemented |
| db-rel-04 | the union is de-duplicated | same | count === count(array_unique) | implemented |
| db-rel-05 | a sibling model resolves its OWN set (the memo is per called class) | `Contact_Model::get_relationships()` | has client + created_by, lacks billing_contact | implemented |
| db-rel-06 | a framework-core model inherits them too | `File_Attachment_Model::get_relationships()` | file_storage + the three audit relations | implemented |
| db-rel-07 | the walk terminates outside the manifest | climb Client_Model's extends_fqcn | last visible ancestor's parent is Eloquent's Model | implemented |


## Field_Length_Test (php, no DB writes) - the ONE column-length definition

`Rsx_Model_Abstract::field_length()` is what both sides read: the server calls it directly,
and the JS model stub generator bakes its own `field_length()` table from it. The last three
rows are the round trip - the stub, as an artifact on disk, cannot disagree with the model.

| ID | Purpose | Input | Expected | Status | Last updated |
|----|---------|-------|----------|--------|--------------|
| db-fl-01 | a varchar/char column answers its length | `Client_Model::field_length('name')` / `'zip'` | 255 / 20 | implemented | 2026-09-07 |
| db-fl-02 | every other type answers null - a real answer, not a failure | bigint, datetime, text | null for each | implemented | 2026-09-07 |
| db-fl-03 | an unknown column throws, naming the model and the column | `'nope_not_a_column'` | RuntimeException containing both | implemented | 2026-09-07 |
| db-fl-04 | a CTI base model answers for a DETAIL column it does not physically have | `Party_Model::field_length('first_name')` / `'legal_name'` | 255 each, and neither is in `getColumns()` | implemented | 2026-09-07 |
| db-fl-05 | `Manifest::php_model_columns()` declines a non-model class rather than inventing a map | `'Rsx_Test_Abstract'` | null | implemented | 2026-09-07 |
| db-fl-06 | every length baked into every generated stub equals the model's answer | all stubs in `storage/rsx-build/js-model-stubs/` | equal for every column | implemented | 2026-09-07 |
| db-fl-07 | no publishable column with a length is missing from its stub | same | present in the stub's table | implemented | 2026-09-07 |
| db-fl-08 | the system-column filter is the ONE deliberate divergence: PHP answers, the stub omits | `_`-prefixed (never `__`) columns | absent from every stub, no throw from PHP | implemented | 2026-09-07 |

Note for future authors: db-fl-08 is a latch. No model in this tree declares a `_`-prefixed
column today, so its second loop currently examines none; it starts proving something the
moment a framework feature adds per-record state to an app table (`rsx:man
database_schema_architecture`, column conventions).

## Model_Attribute_Read_Test (php, default isolation) - reading an attribute off a model

`getCasts()` is reached from Eloquent's `hasCast()` once per column per row and `__get()`
fires on every column read, so both are memoized behind a double-underscore fast path.
Every row below asserts an OBSERVABLE answer, never the memo: the memo is correct iff these
answers are.

| ID | Purpose | Input | Expected | Status | Last updated |
|----|---------|-------|----------|--------|--------------|
| db-ar-01 | a datetime attribute is an ISO-8601 UTC string, never a Carbon | `Client_Model::find()->created_at` | matches `YYYY-MM-DDTHH:MM:SS.sssZ`, not a Carbon | implemented | 2026-09-07 |
| db-ar-02 | a DATE column keeps its calendar spelling | `Project_Model->start_date` | `'2026-01-15'` as a string | implemented | 2026-09-07 |
| db-ar-03 | a TINYINT(1) column is a real bool, not 1/0 | `portal_enabled` / `newsletter_opt_in` | `=== true` / `=== false` | implemented | 2026-09-07 |
| db-ar-04 | a type-ref column exposes the simple class name while storing the integer | `created_by_type` | `'User_Model'`, raw value numeric | implemented | 2026-09-07 |
| db-ar-05 | two model classes on different tables never share a cast map (the memo is keyed per class+table) | Client vs Project casts | each table's own columns only; shared column types agree | implemented | 2026-09-07 |
| db-ar-06 | `mergeCasts()` stays on its instance and does not leak to a freshly fetched sibling - the reason `parent::getCasts()` is NOT memoized | mergeCasts on one instance | that instance `'string'`, sibling `'boolean'` and still a real bool | implemented | 2026-09-07 |
| db-ar-07 | enum magic answers label, constant and a CUSTOM property for the current value | `status_id__label` / `__constant` / `__badge`, `priority__label` | Prospect / STATUS_PROSPECT / bg-info / High | implemented | 2026-09-07 |
| db-ar-08 | the map is per class but the ANSWER is per record | change `status_id` in place | label and badge follow the new value | implemented | 2026-09-07 |
| db-ar-09 | `isset()` finds a matching magic key (the `__isset` fast path), so `??` reaches `__get` | `isset($m->status_id__label)` | true; `??` yields the label; false for an undeclared property and for an unknown name | implemented | 2026-09-07 |
| db-ar-10 | the static lookups answer through an instance too | `$m->status_id__enum_ids` / `__enum_labels` | `[1,2,3,4]`; `[1 => 'Active']` | implemented | 2026-09-07 |
| db-ar-11 | `Model::field__enum*()` static form still works, the memoized sort is stable, and a sibling class has its own map | `Project_Model::status__enum*()`, `Client_Model::status_id__enum_ids()` | full metadata, declared ordering, per-class ids | implemented | 2026-09-07 |
| db-ar-12 | a `_`-prefixed SYSTEM column contains no `__`, takes the fast path's short exit and still reads back correctly - while enum magic on the same model keeps working | `System_Column_Fixture_Model->_flag` | value read, `isset()` true, `state_id__label`/`__tone` still answer, `_flag` absent from `toArray()` | implemented | 2026-09-07 |

Note for future authors: db-ar-12 owns the only `_`-prefixed column in this tree. It lives on
a fixture table `Model_Attribute_Read_Test` creates in `setup()` and drops in `teardown()`
(both run outside the per-test transaction), because no shipped model declares one yet. If a
framework feature ever adds a system column to a real table, this row can move onto it and the
fixture can go.
