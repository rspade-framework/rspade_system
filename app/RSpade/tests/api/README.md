# Concern: api

## Domain overview & applicability

The external REST API subsystem (`/api/vN/...`): attribute-declared endpoints on a
special controller type, framework-owned Bearer authentication that establishes a
cookie-less headless `Session` identity, declarative parameter validation, a version
display/resolution catalog, per-request logging with retention cleanup, and the
app-facing response-helper family, the per-key scopes that narrow one credential
below its holder's authority, and the per-key read-only flag that narrows one credential
to GET requests. This is the surface external integrators (and the
midnight-themed docs/tester page) depend on, so its auth boundary, its serialization
redaction, and its scan-time declaration rules all matter for correctness and security.

## Source files

- `app/RSpade/Core/Api/Api_Endpoint_ManifestSupport.php` - scan-time enforcement + docblock/param baking
- `app/RSpade/Core/Api/Api_Param_Validator.php` - standalone validate + coerce
- `app/RSpade/Core/Api/Api_Catalog.php` - version parse / group / resolve / scope narrowing / LLM catalog
- `app/RSpade/Core/Api/Api_Dispatcher.php` - request pipeline, serialize(), build_response()
- `app/RSpade/Core/Api/Rsx_Api.php` - response helpers (created/no_content/error family)
- `app/RSpade/Core/Api/Api_Key_Model.php` - `_api_keys` (generate incl. read_only/find_by_key/is_valid/set_scopes/has_malformed_scopes)
- `app/RSpade/Core/Api/Api_Scopes.php` - the scope path grammar (validate/normalize/matches/parse_all/decide/reaches_route)
- `app/RSpade/Core/Api/Api_Scope_Validation_Exception.php` - the one validation failure every write path throws
- `app/RSpade/Core/Api/Rsx_Api_Bearer.php` - the one bearer implementation + the web-route scope clamp
- `app/RSpade/Core/Api/Api_Tester_Key.php` - the console's adopted key: gates, and gates INTERSECTED with scopes
- `app/RSpade/Core/Api/Identity_Api_Controller.php` - GET /api/v1/me, which reports the key's own scopes and read_only
- `app/RSpade/Commands/Rsx/Api_Key_Cli_Support.php` - shared --scope parsing, the Access summary + JSON/table shaping
- `app/RSpade/Commands/Rsx/Api_Key_Command.php` / `Api_Key_Temp_Command.php` / `Api_Key_List_Command.php` - the mint and list commands
- `app/RSpade/Core/Api/Api_Request_Log_Model.php` - `_api_request_log` observability row
- `app/RSpade/Core/Api/Api_Cleanup_Service.php` - daily retention prune (`#[Task]`)
- `app/RSpade/Core/Session/Session.php` - `_set_api_identity` / `_reset_api_identity` seam + accessor tiers
- `app/RSpade/Core/Exceptions/Api_Exception_Handler.php` - JSON 500 for uncaught API errors

## Man page(s)

- `man/external_api.txt`

## Testable surface

- Scan-time enforcement: bad prefix, missing route segment, non-GET/POST verb, empty
  methods, missing/duplicate/contradictory/mistyped/overflowing `#[Api_Param]`,
  conflicting attributes, base-class lineage, duplicate route pattern. (php - no DB)
- Param validation + coercion: undeclared-key rejection, required/default handling, the
  int/float/bool/string coercion matrix, compound-value rejection. (php - no DB)
- Catalog: version parse, path-key stripping, version grouping/resolution, LLM catalog
  shape - against the real manifest's v1 endpoints. (php - no DB)
- Catalog scope narrowing (`resolve_for_scopes`): an unrestricted scope set returns the plain
  catalogue unchanged, a scope keeps only the endpoints it reaches with every verb intact,
  nothing matching yields nothing, a narrower scope set is a strict subset, and a malformed
  scope is skipped rather than thrown. (php - no DB)
- Catalog read-only narrowing (`resolve_for_scopes($read_only)`): a read-only listing carries
  GET verbs alone, is a strict subset of the read+write one, composes with the scopes, and
  the flag defaults off. (php - no DB)
- Console access composition (`accessible_targets_for_key`): an unrestricted key equals its
  user's gate answers exactly, a scoped key is a strict subset of them, every published
  target is answered, a rule set reaching nothing reaches nothing, and `current_is_scoped()`
  distinguishes no-key / unrestricted / scoped. A read-only key additionally reaches no
  endpoint that declares no GET, composes that with its scopes, and `current_is_read_only()`
  is three-valued the same way. (php - writes keys in the per-test transaction)
- The CLI --scope flag: a repeatable scope set round-trips through `key:list --json` in
  canonical form, an embedded-newline scope set is accepted, absent --scope stays
  unrestricted, `key:temp` carries a scope, and a malformed scope (including anything written
  in the retired rule language) exits 1 having minted nothing. (cli - in-process
  Artisan::call)
- The CLI --read-only flag: `key:create` and `key:temp` store and report it, omitting it
  mints a read+write key, it is independent of the scopes, and `key:list` carries it in both
  the JSON and the Access column. (cli - in-process Artisan::call)
- Headless Session identity: accessor tiers, `get_session_id()==0`, CSRF null,
  `has_session()` false, immutable-identity + double-set guards, reset. (php - no DB)
- Api key model: generation, sha256 hashing, prefix masking, lookup, revoked/expired
  gating, `is_valid()`, scope normalization on generate/set_scopes, the refusal that writes
  nothing, and `has_malformed_scopes()` over a hand-edited row. read_only: stored on
  generate, defaulted to read+write, cast to a real boolean, independent of the scopes, and
  carrying NO setter (a minted key's access never changes). (php - commits within per-test
  transaction)
- Scope grammar: what `validate()` accepts (literals, each wildcard whole-segment, a
  wildcard version, a trailing slash, a query string) and refuses (partial-segment wildcard,
  misplaced `*`, missing api/version prefix, bad version literal, empty segment, the retired
  rule language); `normalize()` stripping; `matches()` per wildcard including prefix-inclusive
  `*` and trailing-slash/query insensitivity on both sides; `parse_all()` splitting valid from
  malformed without throwing; `canonicalize()` dedupe and whole-set refusal; the decision
  (deny by default, union, version separation) and the malformed-scope FAIL-CLOSED rule; and
  `reaches_route()` over `:param` route rows. (php - no DB)
- API-GET-PURE-01: a GET-only handler carrying a write call fails the scan; a rationalised
  `@API-GET-PURE-01-EXCEPTION` passes and a bare tag does not; prose and string literals in a
  pure handler never trip it. (php - reads a real source fixture)
- Scope enforcement over HTTP: a reachable endpoint 200, an unreachable one 403
  `insufficient_scope` carrying `required` as the ROUTE PATTERN, a path scope covering POST as
  well as GET, the check preceding param validation, the query string ignored, an unchanged
  NULL-scope key, the file-serving web routes clamped the same way, the log row for every
  denial, `GET /api/v1/me` reporting the key's own scopes (null when unrestricted), a
  malformed-only scope set denying every path with its warning in `storage/logs/laravel.log`,
  and `Class/method`-style URLs answering an ordinary 404 (there is no by-name addressing
  channel). (http - live server)
- Read-only enforcement over HTTP: a read-only key GETs 200 and is refused 403
  `read_only_key` on every other verb, the refusal precedes ROUTE resolution (an unknown POST
  path is not a 404), the ORDER against the scopes is proven in both directions (out-of-scope
  GET -> `insufficient_scope`, in-scope POST -> `read_only_key`), a read+write key is
  untouched, `GET /api/v1/me` reports `read_only`, and every denial reaches
  `_api_request_log`. (http - live server)
- Request log row: persistence, nullable identity columns, realtime-silent flag. (php)
- Retention cleanup task: old pruned / new kept / config-driven window / chunked
  backlog. (php)
- Serialization: model redaction (`neverExport`), enum `__label`, `__MODEL`, container
  recursion, and `build_response()` status mapping. (php - no DB)
- Response helpers: status codes + error/bare shapes. (php - no DB)
- Live HTTP dispatch: 401/200/404/405/422/400 shapes, absence of Set-Cookie, bearer
  identity over a cookie, unchanged session count, log side effects. (http - live server)

## Documents

- `test_catalog.md` - full catalog (implemented + deferred).
