# model_fetch - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MF-01 | A request naming no model is a validation error | php | `{ids:[1]}` | ERROR_VALIDATION "Model name is required" | implemented | 2026-08-13 |
| MF-02 | Absent / empty / non-array ids are all the same malformed request | php | three shapes | ERROR_VALIDATION "ids parameter is required" | implemented | 2026-08-13 |
| MF-03 | One non-numeric id fails the whole request | php | `ids:[1,'not-an-id']` | ERROR_VALIDATION naming numeric ids | implemented | 2026-08-13 |
| MF-04 | Over the cap the request is refused, never truncated | php | cap+1 ids | ERROR_VALIDATION naming the cap + config key | implemented | 2026-08-13 |
| MF-05 | The cap counts DISTINCT ids | php | cap+1 entries, 2 distinct | succeeds, returns a records map | implemented | 2026-08-13 |
| MF-06 | An unknown model gets the one generic not-found | php | `model:'No_Such_Model_Anywhere'` | ERROR_NOT_FOUND "Model ... not found" | implemented | 2026-08-13 |
| MF-07 | A mixed batch returns exactly the ids that resolved | php | 2 real + 1 missing | map keyed by the 2 real ids only | implemented | 2026-08-13 |
| MF-08 | All-missing ids succeed with an empty map (= a denial's answer) | php | 2 nonexistent ids | `records == []` | implemented | 2026-08-13 |
| MF-09 | An array return without `__MODEL` still throws | php | `Model_Fetch_Fixture_Model` (its own table) | Rsx_Exception naming `__MODEL` | implemented | 2026-09-08 |
| MF-10 | N ids cost ONE query against the model's table | php | 3 ids | exactly 1 `model_fetch_parent_fixtures` statement, an IN clause | implemented | 2026-09-08 |
| MF-11 | The plural relationship branch preloads its related ids, and the per-id lookups go away | php | fixture parent with 2 children | exactly 1 `model_fetch_child_fixtures` IN-clause preload, 2 statements total | implemented | 2026-09-08 |
| MF-12 | The preload does not outlive the endpoint invocation | php | one fetch call | `Orm_Fetch_Preload::get()` null afterwards | implemented | 2026-08-13 |
| MF-13 | A pristine find() is served from the preload (same instance) | php | populate + find | identity match | implemented | 2026-08-13 |
| MF-14 | A constrained find() misses the preload | php | `where('id',N)->find(N)` | different instance | implemented | 2026-08-13 |
| MF-15 | A scope-stripped find() misses (the withTrashed guard) | php | `withTrashed()->find(N)` | different instance | implemented | 2026-08-13 |
| MF-16 | A column-projected find() misses | php | `find(N, ['id'])` | different instance | implemented | 2026-08-13 |
| MF-17 | A cleared preload serves nothing | php | clear then find | null from get(), fresh instance from find() | implemented | 2026-08-13 |
| MF-18 | get() normalizes the id; a non-numeric id is not a key | php | "N", N, "not-an-id" | hit, hit, null | implemented | 2026-08-13 |
| MF-19 | The preload is keyed by model class | php | other model, same id | null | implemented | 2026-08-13 |
| MF-20 | N parallel fetches of one model produce ONE request | playwright | `/_sys`, `User_Model`: 3 distinct (1 real + 2 missing) + 1 duplicate | 1 `Orm_Controller/fetch` request, 4 per-caller answers | implemented | 2026-09-08 |
| MF-21 | fetch_or_null on a missing id resolves null | playwright | `User_Model`, id 99999999 | `null` | implemented | 2026-09-08 |
| MF-22 | fetch on a missing id rejects with code not_found | playwright | `User_Model`, id 99999999 | `e.code === 'not_found'` | implemented | 2026-09-08 |
| MF-23 | More ids than the cap split into multiple requests | playwright | cap+1 distinct ids | 2 `Orm_Controller/fetch` requests | implemented | 2026-09-08 |
| MF-24 | A denied fetch is indistinguishable from a missing one | php | denying gate vs missing id | identical empty map (owned by the `auth_gates` concern) | implemented | 2026-08-13 |
| MF-25 | A batched sub-call keeps its own error code - `not_found` stays not_found, and the sibling `validation` is not swallowed by the new arm | php | one batch, a not-found sub-call plus a validation sub-call | `C_0.error_code == 'not_found'`, `C_1.error_code == 'validation'` | implemented | 2026-08-13 |
| MF-26 | A completed batched call is forgotten, so a repeat is a fresh request | playwright | debug-mode page, two identical calls | two network requests | deferred - `Ajax._pending_calls` pruning is only observable when `window.rsxapp.ajax_batching` is on, which is off in development; exercising it needs a sealed debug/production build, and the dev-mode harness cannot produce one | 2026-08-13 |
| MF-28 | A raw \Throwable in a batched sub-call (TypeError from a mistyped param) is contained to that sub-call, not a batch-aborting fatal, and its message is redacted in production | php | one batch: an array-valued `model` (TypeError) plus a not-found sibling | `C_0.error_type == 'exception'`, sibling `C_1` still answered; prod branch emits "A server error has occurred." | implemented (dev containment) + live-probe (prod redaction) | 2026-08-13 |
| MF-29 | Anonymous /_ajax/_batch does not leak a class/FQCN/method name in production | http | unauth POST with a bad controller, prod mode | reason == generic string, no FQCN | deferred - shares the `Rsx::is_production()` gate with the direct-path handler; verified by live probe, a prod-mode http fixture would need a sealed build | 2026-08-13 |
| MF-27 | Portal realm resolves `portal_fetch()` through the batch endpoint | playwright | portal page, own + other portal user | own record present, other absent | planned - covered manually via `rsx:debug --portal` | 2026-08-13 |
| MF-30 | `toJSON()` returns the VALUE to serialize, not a JSON string | php | node harness over the real `Rsx_Js_Model.js` | `typeof instance.toJSON() === 'object'` | implemented | 2026-08-27 |
| MF-31 | A model survives the jqhtml data-cache clone `JSON.parse(JSON.stringify(...))` as a readable object, nested model included | php | model with a nested model field | plain object, `id`/`title`/`client.name` readable | implemented | 2026-08-27 |
| MF-32 | The clone reads through a wrapper - `{rec: instance}` keeps `rec.id` | php | wrapper object around a model | `rec` is an object, `rec.id == 42` | implemented | 2026-08-27 |

## Fixtures

The concern owns a fixture family, on tables it creates in each test class's `setup()` and
drops in `teardown()` (`Model_Fetch_Fixture_Tables`):

| Fixture | Table | Role |
|---|---|---|
| `Model_Fetch_Parent_Fixture_Model` | `model_fetch_parent_fixtures` | the batch subject: a gated `fetch()`, a fetchable `#[Relationship]` `children()`, and SoftDeletes for the `withTrashed()` scope guard |
| `Model_Fetch_Child_Fixture_Model` | `model_fetch_child_fixtures` | the related record the plural branch fetches per id, a `belongsTo` back, and a second class for the per-class preload key |
| `Model_Fetch_Fixture_Model` | `model_fetch_marker_fixtures` | the one shape the endpoint refuses: an array return with no `__MODEL` |

The BROWSER half cannot use them, and says so in its own header: the test trees enter the
manifest only while `rsx:test` is running, and the playwright script drives the ordinary web
server, whose manifest carries neither the fixture class nor its table (and whose database is
not the test one). It runs on `/_sys` against `User_Model`, whose generated stub reaches every
bundle - and against the signed-in identity's own record, the one row every install has.
