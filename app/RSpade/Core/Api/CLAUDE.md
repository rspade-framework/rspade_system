# Core/Api — the external REST API

**Writing an endpoint or minting a key? Read skill `rspade:external-api` and
`rsx:man external_api`.** This file says what is in this DIRECTORY, why it is shaped this way,
and what must stay true when editing it.

## The shape

`/api/vN/...` is its own channel (`Core/Dispatch/Rsx_Request_Channel::API`), handed whole to
`Api_Dispatcher`. An endpoint is a static method on a class extending
`Rsx_Api_Controller_Abstract`, declared with `#[Api_Endpoint]` + `#[Api_Param]`; the manifest
bakes every declaration into the route table (type `api`) and a documentation catalog. **The
controller carries no behavior**: authentication, scopes, param validation, the gates,
logging and response building all belong to the dispatcher, so by the time a method runs the
caller is a headless, cookie-less `Session` identity and `$params` holds only validated
`#[Api_Param]` values. Responses are bare JSON with real HTTP statuses; errors are
`{"error":{"code","message","fields"?}}`, built only by `Api_Dispatcher::_error()`
(`Rsx_Api::error()` and the history's refusals delegate to it) - no `{success,data}`
envelope.

**Every body is a JSON object** (`Api_Dispatcher::__api_response()`): an endpoint answering
null, a list, an empty array, a scalar, a collection or a JSON-list response is refused with
a `RuntimeException` naming the endpoint - the channel's 500, rolling a writer back. A
download (a file or streamed response, or any non-empty non-JSON body) is exempt and sent as
it is. The companion channel shares `build_response()` but not this rule (its null is a 204).
**Every object answer carries `_duration_ms` and `_completed_at`** (ISO UTC, the Ajax
envelope's format) at the top level, added by `stamp_response()`: timed from the dispatch's
`hrtime` start (a failure rendered before dispatch began: from the request's start); a
writer that recorded itself answers with its recorded values, a replay with the originals.
**A retired version answers 410** `api_version_retired` with `versions` naming the versions
that serve the path (`__retired_version_response()`, the same matcher over the
`api_endpoints` rows of the other versions, method-aware); a path no version serves, or any
path under a version above the newest declared one (never existed, so not retired), is the
404. Both sit after authentication, so an anonymous caller learns nothing about paths. An
`#[Api_Param]` name never starts with `_` (the system-parameter namespace; build failure).

**Dispatch order** (`Api_Dispatcher`), each step refusing before the next can leak anything:
the portal's own host has no API (404) -> verb gate (GET/POST, else 405) -> **Bearer key
first** (a uniform 401, so nobody probes which endpoints exist) -> the key's `read_only` flag
(403 `read_only_key`) -> exact route match (404, or 410 when another version serves the
path) -> the key's scopes (403 `insufficient_scope`, before params are read) -> param validation (422 per field) -> the
`#[Auth]` gates (403) -> the application's account policy (`Main::pre_dispatch`, 403
`account_refused`) and the controller's own `pre_dispatch` -> the method -> serialization.
Every request, success or failure, is one `_api_request_log` row. `Main::pre_dispatch` and
the controller's `pre_dispatch` run with NO site lock, each in an enforcement window
(`Site_Lock::_run_hook()`); then the method through serialization runs at the site lock
boundary (`Site_Lock::_run_request()`, the key's site): reader by default, `#[Writer]` =
write lock + one transaction, `#[Manual_Lock]` = none; the enforcement window is the method
alone; a transaction the method leaves open is rolled back and fails the request; the log row
is written after the lock is released. A thrown `Rollback_Response` is caught outside the boundary (already
rolled back) and its carried response goes through the same `__api_response()` as a returned
one.

**The request history** (`Core/RequestHistory/Request_History`). `_request_ref` is a system
parameter on every endpoint: `Api_Param_Validator` accepts it undeclared (checked as a string
of 1 to 128 characters, else 422 on the field) and never copies it into `$params`. A writer
(`#[Writer]`, or a `#[Manual_Lock]` that takes a site write lock) records its fingerprint,
redacted payload and timing on its `_transactions` row inside its transaction, and - for a
2xx JSON-object response to a request carrying a reference - the reference, status and body.
A reference this actor already used on this site is answered from the record before the
method runs: the stored status and body plus `_replayed: true` and the original
`_duration_ms` / `_completed_at`, or 409 `request_ref_reused` (on the same endpoint
`error.completed_at` and, while kept, `error.original` - the stored body) / 410
`request_response_expired`. Every response object echoes a sent `_request_ref` at the top
level - success, refusal, replay, the channel's 500 - through `Api_Dispatcher::stamp_response()`,
the one point every answer passes, which also adds the timing fields.

## What is here

- `Api_Dispatcher.php` — the pipeline above, the shared serializer, the request log write,
  `stamp_response()`.
- `Request_Payload_Redactor.php` — THE rules for storing a request's input (credentials
  redacted, values and payload capped, uploads and unparseable bodies never stored), used by
  the request log and the writer request history alike.
- `Rsx_Api_Bearer.php` — the ONE "Bearer header -> headless identity" implementation, used by
  the dispatcher and by the file-serving routes (`/_download`, `/_inline`, `/_thumbnail/*`,
  `/_preview/*`) through `authenticate_file_route()`, so a file has one URL for a browser and
  an integration alike. A caller learns that a key does not work, never why.
- `Api_Endpoint_ManifestSupport.php` — scan-time validation of every declaration (the
  `/api/vN/` prefix, GET/POST only, an `#[Api_Param]` per `:token`, no mixing with
  `#[Route]`/`#[Ajax_Endpoint]`); a violation fails the manifest build. It also expands
  version ranges (`through:` / `#[Api_Versions(through: N)]`) and followers
  (`#[Api_Versions(every: true)]`, recorded after everything else) into ordinary rows.
- `Api_Param_Validator.php` — pure validate-and-coerce over the baked specs: undeclared keys
  rejected, defaults applied, `file` params passed through, text envelopes unwrapped.
- `Api_Scopes.php` + `Api_Scope_Validation_Exception.php` — the scope language: one bare
  path-pattern GRANT per line, `?` / `#` / trailing `*` as whole segments; the OpenAPI
  document's path is granted to every key. `validate()` is the
  ONE validator every write path runs, `matches()` the ONE matcher; scopes only subtract, and a
  malformed stored scope still counts, so the key fails closed.
- `Api_Key_Model(_Abstract).php` — hashed keys (prefix kept for display; `key_hash` in
  `$neverExport`), revoke, expiry, `read_only` and `is_temporary` fixed at mint, `scopes`,
  and `tester_session_id` (see Tester keys below). Deleting a key signs its listed user
  session out (`SIGN_OUT_API_KEY_DELETED`, the `deleting` hook; the session rows are kept,
  `_sessions.api_key_id` is `ON DELETE SET NULL`). Its `booted()` hooks hand every
  insert, revocation and deletion of a live key to `Api_Key_Event.php`, which fires
  `api_key.created` / `api_key.revoked` after commit with the acting identity
  (`Session::_acting_identity()`, shared with the password events) - the only place a key's
  life is announced, so no path that writes a key may bypass the model.
  `Api_Request_Log_Model(_Abstract).php` — the request log (bodies through
  `Request_Payload_Redactor`). Both are base + shell (`Core/Models/CLAUDE.md`).
- `Api_Route_Usage.php` — last call per route PATTERN, at most one write a minute: answers
  "is anything still calling v1?".
- `Api_Catalog.php`, `Api_Openapi.php` — read model over the catalog (version display rule,
  resource grouping) and its projection into OpenAPI 3.1: `_request_ref` on every writer
  operation (the lock mode read off the same surface index the dispatcher reads), with 409 /
  410 among its responses, and `x-build` = `Rsx::build_info()` (the build identity the seal
  records) at the root. A program reads it from the framework's
  `Openapi_Api_Controller.php` (below); the console's `<mount>/openapi.json` is the
  application's browser download of the same document.
- `Rsx_Api_Docs.php`, `Api_Tester_Key.php` — the `/apidocs` console as one call
  (`Rsx_Api_Docs::page()`): the application owns the route and the gate, the framework owns
  the rest (UI lives in `Sys/app/apidocs/`). The application may hand it content only:
  `page()`'s `$sections` (`title` + `markdown`), validated and rendered through
  `Markdown_Rendition::render()` at the call, baked into `rsxapp_data()` as `{slug, title, html}`. The tester key selects what the LISTING shows and
  grants nothing.
- `Rsx_Api.php` — response helpers (`created()`, `not_found()`, the error family over
  `Api_Dispatcher::_error()`), and `document()`: a JSON object in a plain `Response`, the one
  object answer `stamp_response()` sends as written (it stamps a `JsonResponse` only) - for a
  standard-shaped body such as an OpenAPI root, where the timing fields are invalid.
- `Identity_Api_Controller.php` (`GET /api/vN/me`), `Files_Api_Controller.php`
  (`POST /api/vN/files`, `GET /api/vN/files/:key[/text]`) and `Openapi_Api_Controller.php`
  (`GET /api/vN/openapi.json`: `Rsx_Api::document(Api_Openapi::document(null, N))`, N parsed
  from the request path, the full published surface of that version, unstamped; gated
  `is_logged_in` like `/me`, listed in the catalog and the document under its own `OpenAPI`
  resource) — the framework's own endpoints, a stable contract across installs. Attaching a
  file to a record is application policy, never a framework endpoint. **Scopes never refuse
  the document**: `Api_Scopes::decide()` / `reaches_route()` grant `/api/vN/openapi.json` to
  every scope set (after the malformed-scope warning). All three carry
  `#[Api_Versions(every: true)]`: declared at v1, the
  manifest expands them in a SECOND pass to every version the other declarations serve (from
  the declared version up; v1 alone when nothing else is declared), so they live at the
  application's supported prefix and leave a version the application retires (410).
  `Rsx_Api_Bearer::authenticate_file_route()` probes a scoped key against
  `/api/vN/files/anything` for every version serving `/files/:key`
  (`Api_Catalog::versions_serving()`), granting on the first.
- `Api_Cleanup_Service.php` — daily, chunked retention of the request log.
- `Rsx_Webhook.php` — a stub that only logs events; no delivery exists.

## Tester keys - bound to the browser session that minted them

The `/apidocs` console's "Temporary Key" (`_Apidocs_Controller::mint_temporary_key()` ->
`Api_Key_Model::generate_tester_key()`) records the minting browser in
`_api_keys.tester_session_id`; a non-null value IS the tester-key marker (`is_tester_key()`),
distinct from `is_temporary` (a CLI `rsx:api:key:temp` key: bearer-only, any script, no
browser). `Rsx_Api_Bearer::authenticate()` - the one bearer check, for the API and the
file-serving routes alike - admits a tester key only when the request's session cookie names
that browser, signed in (staff, within its limits) as the key's user
(`Session::_cookie_staff_sign_in()` + `is_bound_to()`); anything else is the uniform 401. So a
tester key works ONLY through the tester (whose `fetch()` sends `credentials: 'same-origin'`)
and expires after its hour as before. **The check is narrow and one-way**: the cookie is read
only to refuse, never loaded as the request's session, never an identity, never a substitute
for the key, and ignored for every other key; the API channel still runs no CSRF (the bearer
key is the authorization). **No created/revoked notice is sent for a tester key, on purpose**:
`api_key.created` / `api_key.revoked` fire flagged and the reference handler skips it, because
the key cannot be used outside the session that minted it - a hijacked copy gains nothing that
session does not already have, so a notice would warn of nothing.

## Invariants

- **No privileged channel.** A CLI script reaches the API with a key over ordinary HTTP
  (`rsx:api:key:*`); dispatch is route-only, because a by-name channel would bypass scopes.
- **A GET handler has no side effects** (`API-GET-PURE-01`, manifest-build FATAL), which is
  what makes a read-only key mean something.
- Never a class constant in an attribute argument: reflection resolves it before the
  autoloader is ready.

## References

`rsx:man external_api` · skill `rspade:external-api` · siblings `Core/Dispatch/`,
`Core/Session/` (the API session row), `Core/Files/`, `Sys/app/apidocs/`.
