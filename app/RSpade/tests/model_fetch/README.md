# model_fetch

The JavaScript ORM's record lookup: `Model.fetch()` / `Model.fetch_or_null()` in the
browser, the `Orm_Controller` endpoints that serve them, and the batching machinery that
turns N lookups into one request.

## Domain

A page rendering N records used to pay N HTTP round trips - one per `fetch()`. The lookup
path is now three cooperating pieces:

1. **Client batcher** (`Core/Js/Rsx_Js_Model.js`) - every lookup issued during one
   synchronous turn is queued, deduplicated per (model, id), and flushed on a
   `setTimeout(0)` scheduler as ONE request per model (split at
   `rsx.model_fetch.batch_max_ids`). Each caller still gets its own promise, its own
   record, and its own error.
2. **Batch endpoint** (`Core/Database/Orm_Controller::fetch`) - takes `{model, ids}`,
   evaluates the surface's `#[Auth]` gates ONCE, and runs EVERY id through the model's own
   `fetch()`/`portal_fetch()` (the authorization boundary, unchanged). Returns
   `{records: {"<id>": record}}` containing only the ids that resolved.
3. **Preload** (`Core/Database/Orm_Fetch_Preload` + `RestrictedEloquentBuilder::find()`) -
   one `whereIn('id', $ids)->get()` before the loop, held in request RAM, serving the
   primary-key lookup inside each `fetch()` body. Cleared in the endpoint's `finally`.

Two invariants govern the whole thing:

- **Callers observe nothing but speed.** A `fetch()` behaves exactly like an individual
  request with an individual response.
- **Per-id ABSENCE is the one answer for every unresolvable id** - missing row, model
  refusal, gate denial. No per-id reason, no per-id code (anti-enumeration). The
  throw-vs-null choice is made client-side by which method the caller used.

## Source under test

| File | Role |
|------|------|
| `Core/Database/Orm_Controller.php` | `fetch` (batch) + `fetch_relationship` |
| `Core/Database/Orm_Fetch_Preload.php` | Request-RAM row cache |
| `app/Database/RestrictedEloquentBuilder.php` | `find()` override + its guards |
| `Core/Js/Rsx_Js_Model.js` | Client batcher, `fetch`/`fetch_or_null`/`fetch_cached` |
| `Core/Js/Ajax.js` | Transport (`_pending_calls` pruning after distribution) |
| `Core/Ajax/Ajax_Batch_Controller.php` | Batched sub-call error codes (`not_found` arm) |
| `config/rsx.php` | `rsx.model_fetch.batch_max_ids`, `max_relationship_records` |

Behavior of record: `php artisan rsx:man model_fetch`. Gate evaluation on these surfaces
is `rsx:man auth_gates` (and is tested by the `auth_gates` concern, which owns the
denial-is-indistinguishable-from-missing assertion).

## Testable surface

- **php** - endpoint validation and refusals; the records map; `__MODEL` validation;
  preload hit counting (via the query log); the builder guards that decide hit vs miss.
- **playwright** - the client half, which only exists in a browser: N parallel fetches
  collapsing to ONE request, dedup, `fetch_or_null` -> null, `fetch` -> `not_found`,
  chunking past the cap.
- **http** - not applicable; the endpoint is exercised in-process and through the browser.
