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
`{"error":{"code","message","fields"?}}` - no `{success,data}` envelope.

**Dispatch order** (`Api_Dispatcher`), each step refusing before the next can leak anything:
the portal's own host has no API (404) -> verb gate (GET/POST, else 405) -> **Bearer key
first** (a uniform 401, so nobody probes which endpoints exist) -> the key's `read_only` flag
(403 `read_only_key`) -> exact route match (404) -> the key's scopes (403
`insufficient_scope`, before params are read) -> param validation (422 per field) -> the
`#[Auth]` gates (403) -> the application's account policy (`Main::pre_dispatch`, 403
`account_refused`) and the controller's own `pre_dispatch` -> the method -> serialization.
Every request, success or failure, is one `_api_request_log` row.

## What is here

- `Api_Dispatcher.php` — the pipeline above, the shared serializer, the request log write.
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
  log (redacted, capped bodies). Both are base + shell (`Core/Models/CLAUDE.md`).
- `Api_Route_Usage.php` — last call per route PATTERN, at most one write a minute: answers
  "is anything still calling v1?".
- `Api_Catalog.php`, `Api_Openapi.php` — read model over the catalog (version display rule,
  resource grouping) and its pure projection into OpenAPI 3.1.
- `Rsx_Api_Docs.php`, `Api_Tester_Key.php` — the `/apidocs` console as one call
  (`Rsx_Api_Docs::page()`): the application owns the route and the gate, the framework owns
  the rest (UI lives in `Sys/app/apidocs/`). The tester key selects what the LISTING shows and
  grants nothing.
- `Rsx_Api.php` — response helpers (`created()`, `not_found()`, the error family).
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
