<!-- single-source: never duplicate into another fragment. -->

## ENDPOINTS

**Ajax endpoints are browser-only.** A `#[Ajax_Endpoint]` static method on a controller (with its MANDATORY `#[Auth]`) becomes callable from JavaScript under the controller's own name — no route, no URL, no wiring: `await My_Controller.save({name: 'Test'})`. **`$.ajax()` is overridden to THROW** — always call `Controller.method()` instead.

**Every Ajax response is HTTP 200**, success and failure alike; the `{_success, _ajax_return_value}` envelope carries the outcome and the JS side resolves or rejects on it. Errors are `response_error(Ajax::ERROR_*, $message)` (`ERROR_VALIDATION`, `ERROR_NOT_FOUND`, `ERROR_UNAUTHORIZED`, `ERROR_AUTH_REQUIRED`, `ERROR_FATAL`, `ERROR_GENERIC`). **Unhandled errors already log and surface to the user**, so catch only what you handle specifically. CLI: `php artisan rsx:ajax Controller action --args='{"k":"v"}' [--user=] [--site=]`.

**The external API is the other kind**: static methods on a class extending `Rsx_Api_Controller_Abstract`, declared with `#[Api_Endpoint]` + repeatable `#[Api_Param]`. The path MUST start `/api/vN/`, GET/POST only, every `:token` needs an `#[Api_Param]` — violations are a loud manifest-scan failure. Auth is **`Authorization: Bearer rsx_...` ONLY**, producing a cookie-less headless `Session` identity, so **site scope applies automatically and you never hand-write `where('site_id')`**. Docs and a live tester at `/apidocs`.

**A key may be NARROWED below its holder**: `_api_keys.scopes` holds `Grant|Deny METHOD /api/vN/path` rules (`*` one segment, `**` the rest, last only), NULL = unrestricted, any rule = deny-by-default, most-specific wins with Deny taking ties — checked before the `#[Auth]` gates and refused with a **403 `insufficient_scope`** naming the `required` target. Rules only ever SUBTRACT from the user's live permissions, and **`API-GET-PURE-01`** (manifest-build FATAL, escape `@API-GET-PURE-01-EXCEPTION <rationale>`) keeps a GET handler side-effect-free so a read-only grant means what it says.

**A CLI script grants ITSELF API access through `rsx:api:*`** — `key:create` / `key:temp` (short-lived, self-expiring) / `key:list` / `key:delete`, plus `rsx:api:openapi` for the spec, all taking `--user=<id|email>` (**required, no default**) and `--json`. The script then calls `/api/vN` over ordinary bearer HTTP: **there is no privileged CLI channel into the API.** Flags, envelope and exit codes: `rsx:man external_api`.

**GOTCHA (LLM landmine): NEVER put a class constant in an attribute argument** (`#[Api_Param('x', default: Model::FOO)]`) — reflection resolves it before the autoloader is ready and the scan dies. Literals only.

Skills: `rspade:ajax-error-handling` (envelope, per-field validation, `Form_Utils`), `rspade:external-api` (response contract, param validation, versioning, key minting). Details: `rsx:man external_api`.
