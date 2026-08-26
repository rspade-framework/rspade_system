---
name: external-api
description: Building externally-consumable REST endpoints with #[Api_Endpoint] and #[Api_Param] on Rsx_Api_Controller_Abstract - route rules, Bearer key auth and the headless session, param validation, response contract, versioning, and the /apidocs tester. Use when exposing an API to an external consumer or integration, adding a new /api/vN/ route or version, minting API keys, or diagnosing a 401 before route match, a 422 on an undeclared param, or a manifest scan that dies on an attribute argument.
---

# External API

Externally-consumable REST endpoints. **Distinct from `#[Ajax_Endpoint]`, which is browser-only** - Ajax endpoints assume a cookie session, a CSRF token, and the JS auto-mapping. An external API endpoint assumes none of that: a Bearer key, a URL, plain JSON.

## Building one

```php
class Contacts_Api_Controller extends Rsx_Api_Controller_Abstract {

    /** List contacts. @api-response {"items":[...],"meta":{...}} */
    #[Api_Endpoint('/api/v1/contacts', methods: ['GET'])]
    #[Auth('is_logged_in')]   // MANDATORY (staff realm - the bearer key IS a staff identity)
    #[Api_Param('page', type: 'int', required: false, default: 1)]
    #[Api_Param('search', type: 'string', required: false)]
    public static function list(Request $request, array $params = []) {
        return ['items' => Contact_Model::paginate(...), 'meta' => [...]];  // 200 bare JSON
    }
}
```

- Extend `Rsx_Api_Controller_Abstract`; methods are `public static (Request $request, array $params = [])`.
- `#[Api_Param]` is repeatable, one per accepted parameter.
- The docblock's `@api-response` sample is what `/apidocs` shows as the response shape.
- `#[Auth]` is mandatory here as on every dispatchable surface.

## Route rules (scan-enforced)

- Path MUST start `/api/vN/` (N = digits).
- **GET/POST only.**
- Every `:token` in the path needs a matching `#[Api_Param]`.
- No `#[Route]`/`#[SPA]`/`#[Ajax_Endpoint]`/`#[FPC]` on the same method.
- `/api/vN` is reserved against `#[Route]`/SPA - a normal route may not claim it.

Violations are a loud manifest-scan `RuntimeException`, not a runtime surprise.

## GOTCHA (LLM landmine)

**NEVER put a class constant in an attribute argument** (`#[Api_Param('x', default: Model::FOO)]`) — reflection resolves it before the autoloader is ready and the scan dies. Literals only.

The failure looks like a broken manifest build with a class-not-found deep in the scanner, and the attribute line rarely appears in the trace. If a scan dies right after you added an endpoint, look here first.

## Response contract

| You return | Client gets |
|---|---|
| a model or Collection | redacting `toArray()` - `neverExport` fields stripped, enum `__label` + `__MODEL` included |
| a plain array | 200 with that JSON |
| `null` | 204 No Content |
| `Rsx_Api::created($data)` | 201 |
| `Rsx_Api::no_content()` | 204 |
| `Rsx_Api::not_found()` / `unauthorized()` / `forbidden()` | 404 / 401 / 403 |
| `Rsx_Api::validation_error($fields)` | 422 |
| `Rsx_Api::error(...)` | the error you name |
| a scalar | **`shouldnt_happen`** - a bare scalar is never a valid API response |

Error shape is always `{"error":{"code","message","fields"?}}`.

## Authentication

`Authorization: Bearer rsk_...` ONLY -> a cookie-less headless `Session` identity (`Session::is_logged_in()`/`get_user()`/`get_user_id()`/`get_site_id()` all work; NO cookie, NO `_sessions` row, NO Set-Cookie; `get_session_id()==0`). Site scope applies automatically — **never hand-write `where('site_id')`**.

Keys are minted and revoked at **Settings > API Keys** (the plaintext key is shown once). Missing header -> 401, bad key -> 401, **both before route match** - so a bad key cannot be used to probe which paths exist.

From the CLI, for provisioning or auditing without a browser:

```bash
php artisan rsx:api:key:create <user> [--name=] [--expires="30 days"] [--environment=live|test]
php artisan rsx:api:key:list   <user> [--all]      # active only unless --all
php artisan rsx:api:key:delete <id>   [--purge] [--force]
```

`<user>` is a users.id or an email. `--expires` takes an ISO datetime or a relative span and has **no default** - no expiry means "until revoked". `delete` **revokes** and keeps the row (request-log rows reference `api_key_id`); `--purge` removes it outright.

`_api_keys.user_role_id` + `scopes` are RESERVED, NOT enforced. Do not build authorization on them; use `#[Auth]` and record-level checks like everywhere else.

## Param validation

Input precedence: **route params > GET query > JSON/form body.**

- 422 per-field on a missing required param, a bad type, or **any undeclared param**.
- 400 on an unparseable JSON body.

The undeclared-param rejection is deliberate: a client sending `?limt=50` gets told, instead of silently receiving unfiltered data.

## Versioning

URL-only, **exact-match at runtime** - there is no fallthrough from `/api/v2/x` to a v1 handler. Ship a new version as a new path.

The docs GROUP by (verb, path with the `vN` stripped) and show the newest version <= the one selected, so `/apidocs/v1` shows a v1 endpoint even when v2 exists elsewhere.

## Docs and tester

- `/apidocs` (+ `/apidocs/vN`): per-endpoint cards, generated curl/php/js/python/powershell samples, a sidebar filter, and a live tester (paste a key created in Settings > API Keys; the page never mints one).
- **The application owns the route and the gate.** The framework declares no route and has no visibility setting — the console exists only if you route to it, protected by whatever `#[Auth]` that route carries:

```php
#[Auth('can_manage_integrations')]          // your check
#[Route('/dev/api-reference')]
public static function api_docs(Request $request, array $params = []) {
    return Rsx_Api_Docs::page($request, Developer_Tools_Bundle::class);
}

#[Route('/dev/api-reference/openapi.json')] // separate, so it can be gated differently
public static function api_openapi(Request $request, array $params = []) {
    return response()->json(Rsx_Api_Docs::openapi_document(), 200, [
        'Content-Type' => 'application/json; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="openapi.json"',
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
```

- **The framework produces DATA, the app produces the RESPONSE.** `page()` renders a response; `openapi_document()` returns an array. Headers, status, and inline-vs-download belong to the route that declared them.
- **Gate the console on `Session::has_api_access()`, not on a role** — it is the same predicate `Api_Dispatcher` applies to a Bearer key, so the console cannot advertise an API the viewer will be refused. Render the denial with `Error_Screens::unauthorized($request)` (login redirect for an unidentified caller, themed 403 for an identified one); `response_unauthorized()` is the Ajax envelope and would reach a browser as JSON.

- **The bundle is yours too** — include your directory plus `app/RSpade/Core/Api`, and forward one hook: `load_rsxapp_data() { return Rsx_Api_Docs::rsxapp_data(); }`. A bundle must cover its controller's directory, so a framework bundle can never serve your route. Worked example: `system/app/RSpade/resource/reference_app/app/apidocs/`.
- **`page()`'s third argument restricts the listing** (default `true`): only endpoints whose `#[Auth]` gates pass for the API key the viewer supplied — scoped to the **key's** user, not the viewer. With no key it lists nothing and the landing card asks for one; supplying one **reloads**, because the catalog is baked in at render.
- Restricted listing hides what a caller cannot use; it is **not access control** — `Api_Dispatcher` gates every call regardless. That gate is: the key resolves, its user is active, AND `users.is_api_access_enabled` is set. All three failures return the one uniform `unauthorized` / "Invalid or expired API key" 401, so a caller never learns which.
- The filter works by briefly becoming the key's user (`Api_Tester_Key::accessible_targets()` -> `Session::_set_api_identity` + `Auth_Gates::can_access`, torn down in a `finally`, with `Auth_Gates::reset_memo()` on the way in AND out). Miss either reset and the filter silently reflects the wrong user.
- **OpenAPI 3.1 document at `/apidocs/openapi.json`** (gated with the console), generated by `Api_Openapi::document()` from the same manifest catalog. Feed it to client generators, Postman, or an agent toolchain. Every version is listed at its real path; a superseded endpoint carries `deprecated: true`.

### The identity endpoint

**`GET /api/v1/me` ships with the framework** (`Identity_Api_Controller`) — the one endpoint every RSpade API has. Returns the site-scoped `user_id`, `email`, `site_id`, and the key's own `name`/`prefix`/`expires_at`/`last_used_at`.

Reaching it **is** the validation: a bad, revoked or expired key is a 401 from the dispatcher before it runs, so a 200 means the key is good. It is a contract — path, verb and field names are stable across installs — and deliberately thin, because integrations poll it. `expires_at: null` means no expiry, not unknown.

### Key prefix

`config('rsx.api.key_prefix')`, default `'rsx_'` → keys read `rsx_live_xxxxx`. Rename it per product. **Existing keys are unaffected** — lookup is sha256 of the whole key, never the prefix.

### Console behaviour worth knowing

- **The key is validated before it is accepted** (against `/api/v1/me`), so a bad key fails at the paste rather than later on an unrelated endpoint.
- **The tester validates path parameters only.** Everything else reaches the server unchecked — a blank required field should come back as the endpoint's own 422, which is what a tester is for. An unparseable body is sent too, so you see the dispatcher's `invalid_json`.
- **Unset means omitted** — blank query params and blank body fields are dropped, never sent as `""`.
- **"Fill in values"** swaps the samples to the exact request the tester would send (real key, typed values, merged path params), live. It reads `Api_Tester::current_values()`, the same method Send uses. Unfilled path params stay as `{name}`, marked red.
- Every sample value is escaped for its target language, and URL values **twice** — percent-encode, then quote for the embedding language (`api_docs_escaping.js`).

## Interactions

Dev calls must use the `APP_URL` host or loopback; CSRF N/A (no cookie session); FPC never applies; `Main_Abstract::pre_dispatch` is skipped for API (Portal precedent). `_api_keys.user_role_id` + `scopes` are RESERVED, NOT enforced.

## Config

`rsx.api.log_retention_days` (default 30) - every request logs to `_api_request_log`, pruned by a daily cleanup task.

## Full reference

`php artisan rsx:man external_api`.
