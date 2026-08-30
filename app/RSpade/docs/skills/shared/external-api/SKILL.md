---
name: external-api
description: Building externally-consumable REST endpoints with #[Api_Endpoint] and #[Api_Param] on Rsx_Api_Controller_Abstract - route rules, Bearer key auth and the headless session, param validation, response contract, versioning, key scopes (Grant|Deny METHOD path rules in _api_keys.scopes), file upload and download over the API, and the /apidocs tester. Use when exposing an API to an external consumer or integration, adding a new /api/vN/ route or version, uploading or downloading a file through the API (multipart/form-data, the 'file' param type, POST /api/v1/files, attaching an uploaded file to a record), minting API keys, narrowing a key with scope rules or scope presets (config('rsx.api.scope_presets')), or diagnosing a 401 before route match, a 403 insufficient_scope, an API-GET-PURE-01 manifest-build failure, a 422 on an undeclared param, or a manifest scan that dies on an attribute argument.
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
- A `file` param is POST-only, never a `:token`, and never defaulted (see Files).
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

`Authorization: Bearer rsx_...` ONLY -> a cookie-less headless `Session` identity (`Session::is_logged_in()`/`get_user()`/`get_user_id()`/`get_site_id()` all work; NO cookie, NO `_sessions` row, NO Set-Cookie; `get_session_id()==0`). Site scope applies automatically — **never hand-write `where('site_id')`**.

Keys are minted and revoked at **Settings > API Keys** (the plaintext key is shown once). Missing header -> 401, bad key -> 401, **both before route match** - so a bad key cannot be used to probe which paths exist.

`_api_keys.scopes` narrows ONE key below its holder — see **Key scopes** below. `_api_keys.user_role_id` stays RESERVED and NOT enforced; do not restrict a key with it.

## The `rsx:api:*` CLI

**The namespace exists so a SCRIPT can grant itself access to this API and then use it** — an importer or an exporter mints a key, calls `/api/vN` with `Authorization: Bearer <key>` like any other client, and exits. There is no privileged CLI channel into the API: the CLI mints a credential, and the script speaks the same HTTP the outside world speaks.

```bash
php artisan rsx:api:key:create --user=<id|email> [--site=] [--name=] [--expires=] [--environment=live|test] [--scope=<rule>]... [--json]
php artisan rsx:api:key:temp   --user=<id|email> [--site=] [--expires="1 hour"] [--scope=<rule>]... [--json]
php artisan rsx:api:key:list   --user=<id|email> [--site=] [--all] [--json]
php artisan rsx:api:key:delete <id> [--purge] [--force] [--json]
php artisan rsx:api:openapi    [--user=<id|email>] [--site=] [--compact]
```

- **`--user` is required and has NO default** — it is an option because every framework command spells it that way (`rsx:debug`, `rsx:ajax`), and the requirement is enforced in the command, naming the flag. A users.id or an email.
- **`--site` disambiguates, it does not select.** `users.id` is a global PK, so a numeric `--user` needs no site (a mismatched `--site` is refused). `users.email` is not unique across sites, so an email matching in two sites is **refused with the candidates listed** rather than a tenant being guessed.
- **`--expires`** takes an ISO datetime or a relative span. On `create` there is **no default** — no expiry means "until revoked". `temp` defaults to `1 hour` and names every key it mints **`Temporary (CLI)`**, so `list` and Settings > API Keys both say what it is.
- **`delete` revokes** and keeps the row, and its request-log history with it; **`--purge` removes the key outright and CASCADE-deletes that history** (`_api_request_log.api_key_id` is a real FK). That difference is the reason to prefer revoke. Under `--json` it **requires `--force`** — a prompt would write into the stream the script is parsing and then block forever.
- **Minting is NOT gated on `users.is_api_access_enabled`** (shell access outranks any in-app permission, and a provisioning script must be able to mint for a user it is about to enable) — but `create`/`temp` **warn**, and report `api_access_enabled: false`.

**The `--json` envelope**, one shape for every `key:*` command, stdout carrying JSON and nothing else:

```json
{"ok": true,  "command": "rsx:api:key:create", "data": {"key": "rsx_live_...", "api_key": {...}, "user": {...}}}
{"ok": false, "command": "rsx:api:key:create", "error": {"code": "user_not_found", "message": "..."}}
```

A failure is **still JSON and still exits non-zero**. `error.code` is machine-stable: `user_required`, `user_not_found`, `user_ambiguous`, `user_site_mismatch`, `site_invalid`, `site_without_user`, `expires_invalid`, `expires_in_past`, `environment_invalid`, `key_not_found`, `confirmation_required`.

**`rsx:api:openapi` has no `--json`** — it has only a machine form, so a flag that can only ever be passed is noise. A successful run emits the bare document (generators read that shape; wrapping it would mean every consumer unwrapping it); errors use the envelope above. Pretty-printed unless `--compact`. `--user` narrows it to what that identity's `#[Auth]` gates admit, via `Api_Tester_Key::accessible_targets_for_user()`.

**End to end** — an importer that grants itself access, uses it, and lets the credential expire:

```bash
KEY=$(php artisan rsx:api:key:temp --user=importer@example.com --expires="2 hours" --json | jq -r .data.key)

curl -s -H "Authorization: Bearer $KEY" https://app.example.com/api/v1/clients | jq .

# Nothing to clean up: the key stops working in two hours.
# To end it sooner: php artisan rsx:api:key:delete <id> --force
```

Full flag reference, exit codes and the error-code roster: `rsx:man external_api`, under API KEYS.


## Param validation

Input precedence: **route params > GET query > JSON/form/multipart body.**

- 422 per-field on a missing required param, a bad type, or **any undeclared param**.
- 400 on an unparseable JSON body.

The undeclared-param rejection is deliberate: a client sending `?limt=50` gets told, instead of silently receiving unfiltered data.

Every param is validated AND coerced, except `file` — that one is validated only and reaches the endpoint as the raw `UploadedFile`. An `UploadedFile` offered for a scalar param is refused, so a temp path can never be smuggled into a string.

## Files

**The framework moves the bytes; your app owns "attach".** Three shipped endpoints:

| Endpoint | Returns |
|---|---|
| `POST /api/v1/files` | 201 + the file payload, including an unclaimed `key` |
| `GET /api/v1/files/:key` | metadata, URLs, `preview_status`, `text_status` |
| `GET /api/v1/files/:key/text` | `{key, text, text_status}`, or 409 while pending |

Upload is `multipart/form-data`, declared with the **`file` param type**:

```php
#[Api_Endpoint('/api/v1/files', methods: ['POST'])]
#[Api_Param('file', type: 'file', required: true, description: '...')]
```

A `file` param is **POST-only, never a `:token`, never defaulted** — all three are manifest-scan failures. It is **validated but never coerced**: the endpoint receives the `UploadedFile` as it arrived. It also fixes the part name (read it from the catalog rather than assuming `'file'`), and drives the OpenAPI projection to `multipart/form-data` with a binary part.

**Attaching is the app's endpoint, deliberately.** Which record, which category and who may do it are policy the framework cannot answer:

```php
$attachment = File_Attachment_Model::find_by_key($params['key']);
if (!$attachment || !$attachment->can_user_assign_this_file()) { /* refuse */ }
$attachment->add_to($record, 'documents');   // add_to = many; attach_to = replace
```

`can_user_assign_this_file()` is **structural** — unclaimed, and in this tenant. It is not a per-user check, and it **works fine under a Bearer identity** (a key is a staff session with a real `site_id`). Removing needs `$record->find_attachment($id_or_key, $category)`, which returns null unless the attachment belongs to that record in that category — resolving with a bare `find()` would let any attachment id in the site be deleted through any record's URL.

Worked example: `system/app/RSpade/resource/reference_app/app/api/v1/clients_api_controller.php` (and `tasks_api_controller.php`).

**Downloads need no API endpoint.** `/_download`, `/_inline`, `/_thumbnail/*`, `/_download_zip` and `/_preview/pdf` all accept `Authorization: Bearer`, so an integration fetches bytes with no cookie. A bad Bearer is a 401 there — it never degrades to anonymous.

**Status vocabulary** (`pending` / `available` / `error` / `unsupported`): rendering and extraction are async, so a just-uploaded document reads `pending`. Poll; never assume. `error` is terminal and never auto-retried.

## Request logging

Every request — success and every failure path — writes one `_api_request_log` row: `verb`, `path`, `handler`, `status`, `duration_ms`, `ip`, nullable identity columns, plus **what was exchanged**:

| Column | Notes |
|---|---|
| `request_body` | redacted + capped; **NULL for any upload** |
| `response_error_code` / `response_error_message` | from the error envelope; NULL on success |
| `response_bytes` | body size actually sent |

`response_error_code IS NULL` is the success predicate. **The body is never stored for an upload** — the test is the request (files present, or a multipart content type), not the endpoint name, so it covers future upload endpoints automatically. Credential-shaped keys (`password`, `secret`, `token`, `authorization`, `api_key`, `credential`, `private_key`) are `[redacted]` at any depth; a bare **`key` is deliberately not redacted** because in this API that is the attachment key you need when tracing an attach. Values cap at 4000 bytes, the whole payload at 25000, both marked `...[truncated]`.

Indexed by key, by `ip`, and by `created_at`. Retention is `config('rsx.api.log_retention_days')` (default 30), pruned by a daily `#[Task]`.

## Versioning

URL-only, **exact-match at runtime** - there is no fallthrough from `/api/v2/x` to a v1 handler. Ship a new version as a new path.

The docs GROUP by (verb, path with the `vN` stripped) and show the newest version <= the one selected, so `/apidocs/v1` shows a v1 endpoint even when v2 exists elsewhere.

## Docs and tester

- `/apidocs` (+ `/apidocs/vN`): per-endpoint cards, generated curl/php/js/python/powershell samples, a sidebar filter, and a live tester (paste a key created in Settings > API Keys, or let the page mint itself a one-hour `API Tester (temporary)` key).
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

Dev calls must use the `APP_URL` host or loopback; CSRF N/A (no cookie session); FPC never applies; `Main_Abstract::pre_dispatch` is skipped for API (Portal precedent). `_api_keys.user_role_id` is RESERVED, NOT enforced (`scopes` IS — see above).

## Key scopes

A key otherwise carries its holder's **entire** authority. `_api_keys.scopes` narrows one key; `Api_Scopes` (static, pure) is the whole meaning.

```
Grant GET  /api/v1/billing/*
Grant POST /api/v1/billing/*
Deny  POST /api/v1/invoices/*/void
```

- Keyword `Grant`/`Deny`, method `GET`/`POST`, pattern matched against the **full path with `/api/vN/` retained**. `*` = exactly one segment; `**` = zero or more, **last segment only**; a wildcard is a whole segment (`foo*` is refused). Blank lines and `#` comments ignored. A malformed line **throws naming the line**, at save — never at first request.
- **NULL/blank = unrestricted.** Any rule flips the key to **deny-by-default**, so a preset never writes a blanket Deny.
- **Specificity decides, not order** (more literals, then fewer wildcards; a **Deny wins any tie**). That is what makes combining two rule sets set union — order-independent, so a mint UI has no ordering to get wrong.
- **Scopes subtract only**: `effective = the user's LIVE permissions ∩ the rules`. Evaluated per request, never frozen at mint. A rule can never grant what the user lacks.
- Dispatcher order: verb gate → bearer auth → route match → **SCOPE** → param validation → `#[Auth]` gates → controller.

**Two different 403s.** `insufficient_scope` (`{"error":{"code","message","required":"POST /api/v1/x"}}`) means *mint a wider key*; `forbidden` from the gates means *ask your administrator for the permission*. Both are logged in `_api_request_log`. The file-serving web routes (`/_file/`, `/_thumbnail/`, …) clamp a scoped key against the synthetic target `GET /api/v1/files/**` and answer the same 403.

**`API-GET-PURE-01`** — a GET-only handler's body may not contain `->save(` `->delete(` `->update(` `::create(` `->raw_bulk(` `DB::statement|insert|update|delete(` `Task::dispatch(`; it is a **manifest-build failure**, because "read-only" is only expressible as a GET grant. Escape: `@API-GET-PURE-01-EXCEPTION <rationale>` in the method docblock — **the rationale is required**, a bare tag still fails. Only the handler's own body is scanned; a private helper it calls is not followed.

**Presets are APP data.** The framework never names a scope. `config('rsx.api.scope_presets')` (`[['name','description','rules'], …]`) is read by the app's key UI and by nothing in core — mirroring how `Auth_Gates` never names a permission.

```php
Api_Key_Model::generate($user_id, $name, 'live', null, null, $scopes);
$key->is_unrestricted();  $key->get_scope_rules();  $key->set_scopes($text);
Api_Scopes::decide($scopes, 'GET', '/api/v1/contacts');   // the dispatcher's question
Api_Catalog::resolve_for_scopes(1, $scopes);              // pure: takes TEXT, not a key
```

**Keys are immutable after mint** — no edit endpoint anywhere; revoke and re-mint. `GET /api/v1/me` reports `key.scopes`; `rsx:api:key:list` shows a rule count (full text under `--json`); `rsx:api:openapi --key=<id>` narrows the document to gates ∩ that key's rules.

## Config

`rsx.api.log_retention_days` (default 30) - every request logs to `_api_request_log`, pruned by a daily cleanup task.

## Full reference

`php artisan rsx:man external_api`. Related: `rspade:file-attachments` (the attachment model, the upload gate, the retention window).
