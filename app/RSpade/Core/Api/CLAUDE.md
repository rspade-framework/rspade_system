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

**The request history** (`Core/Revisions/Request_History`). `_request_ref` is a system
parameter on every endpoint: `Api_Param_Validator` accepts it undeclared (checked as a string
of 1 to 128 characters, else 422 on the field) and never copies it into `$params`. A writer
(`#[Writer]`, or a `#[Manual_Lock]` that takes a site write lock) records its fingerprint,
redacted payload and timing on its `_transactions` row inside its transaction, and - for a
2xx JSON-object response to a request carrying a reference - the reference, status and body.
A reference this actor already used on this site is answered from the record before the
method runs: the stored status and body plus `_replayed: true` and the original
`_duration_ms` / `_completed_at`, or 409 `request_ref_reused` / 410
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
  `#[Route]`/`#[Ajax_Endpoint]`); a violation fails the manifest build.
- `Api_Param_Validator.php` — pure validate-and-coerce over the baked specs: undeclared keys
  rejected, defaults applied, `file` params passed through, text envelopes unwrapped.
- `Api_Scopes.php` + `Api_Scope_Validation_Exception.php` — the scope language: one bare
  path-pattern GRANT per line, `?` / `#` / trailing `*` as whole segments. `validate()` is the
  ONE validator every write path runs, `matches()` the ONE matcher; scopes only subtract, and a
  malformed stored scope still counts, so the key fails closed.
- `Api_Key_Model(_Abstract).php` — hashed keys (prefix kept for display), revoke, expiry,
  `read_only` fixed at mint, `scopes`. `Api_Request_Log_Model(_Abstract).php` — the request
  log (bodies through `Request_Payload_Redactor`). Both are base + shell (`Core/Models/CLAUDE.md`).
- `Api_Route_Usage.php` — last call per route PATTERN, at most one write a minute: answers
  "is anything still calling v1?".
- `Api_Catalog.php`, `Api_Openapi.php` — read model over the catalog (version display rule,
  resource grouping) and its pure projection into OpenAPI 3.1.
- `Rsx_Api_Docs.php`, `Api_Tester_Key.php` — the `/apidocs` console as one call
  (`Rsx_Api_Docs::page()`): the application owns the route and the gate, the framework owns
  the rest (UI lives in `Sys/app/apidocs/`). The tester key selects what the LISTING shows and
  grants nothing.
- `Rsx_Api.php` — response helpers (`created()`, `not_found()`, the error family over
  `Api_Dispatcher::_error()`).
- `Identity_Api_Controller.php` (`GET /api/v1/me`) and `Files_Api_Controller.php`
  (`POST /api/v1/files`, `GET /api/v1/files/:key[/text]`) — the framework's own endpoints, a
  stable contract across installs. Attaching a file to a record is application policy, never
  a framework endpoint.
- `Api_Cleanup_Service.php` — daily, chunked retention of the request log.
- `Rsx_Webhook.php` — a stub that only logs events; no delivery exists.

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
