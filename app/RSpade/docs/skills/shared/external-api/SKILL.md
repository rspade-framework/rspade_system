---
name: external-api
description: "Building external REST endpoints - #[Api_Endpoint] + #[Api_Param] on Rsx_Api_Controller_Abstract under /api/vN/, Bearer rsx_ keys, rsx:api:key:create, key scopes and --read-only, POST /api/vN/files, #[Api_Versions(through: N | every: true)], /apidocs, #[Writer] on a POST, Rollback_Response, Site_Lock::write_exception() in a reader, object-only response bodies, _duration_ms / _completed_at, _request_ref replay, GET /api/vN/openapi.json (the framework's OpenAPI document, any key), Rsx_Api::document() for an unstamped document, x-build / Rsx::build_info(). Use when exposing an API or minting a key, or on a 401, 403 insufficient_scope, 403 read_only_key, a 422 on an undeclared param, 409 request_ref_reused, 410 request_response_expired, 410 api_version_retired, 'Every external API response body is a JSON object', API-GET-PURE-01, or a scan dying on an attribute argument."
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
- **An endpoint is a READER of its site unless it declares `#[Writer]`.** A POST that creates, updates, deletes or dispatches declares it: the request runs under the site's write lock in one transaction (nothing else changes the site meanwhile; a throw rolls it all back; a returned error response commits what was written - validate first). A reader that saves a site-scoped model fails with `Site lock enforcement: ... by API X::y without site N's write lock`. `#[Writer]` on a GET is refused (`API-GET-PURE-01`). Skill `rspade:site-locks`.
- **To answer an error AND undo the write**, throw it: `throw new Rollback_Response(Rsx_Api::validation_error($fields, 'Row 14 is invalid'));` - the writer rolls back and the client gets exactly the status and body the returned response would have produced (stamped, nothing recorded for a `_request_ref`).
- **A writer never holds its lock across a call to another system** (a provider API, a token refresh): declare `#[Manual_Lock]`, make the call unlocked, write what it returned in a short `Site_Lock::write()` - a rotated token in its own section at once. A reader that only records a view or audit entry uses `Site_Lock::write_exception()` and stays a reader (`rsx:man site_locks`, HOLDING A LOCK WELL).
- **Return right away**: an export or batch pass is a task, with the feature's own status and result endpoints (the API has no generic task interface).

**GET IS FOR READS, POST IS FOR WRITES.** A GET endpoint is a READ; anything that changes application data — create, update, delete, a state change, a send, a queued job that will do one of those — is a POST. Incidental bookkeeping a GET performs (a request log row, a last-used stamp, a counter, a cache fill) is fine: the test is whether a caller who never made the request could tell from the app's own screens. This is not REST style — it is the only thing that gives "read" a meaning, and it is enforced from both ends: **`API-GET-PURE-01`** at build time on the handler, and **`_api_keys.read_only`** at runtime on the caller (see Read-only keys). Waive `API-GET-PURE-01` with `@API-GET-PURE-01-EXCEPTION <rationale>` for that incidental bookkeeping only — never to let a GET mutate, because the waiver removes the meaning of every read-only key in the system.

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

**Every body is a JSON object.**

| You return | Client gets |
|---|---|
| a model | 200, redacting `toArray()` - `neverExport` fields stripped, enum `__label` + `__MODEL` included |
| an associative array | 200 with that JSON object |
| nothing to report | return `['result' => 'success']` |
| `Rsx_Api::created($data)` | 201 |
| `Rsx_Api::not_found()` / `unauthorized()` / `forbidden()` | 404 / 401 / 403 |
| `Rsx_Api::validation_error($fields)` | 422 |
| `Rsx_Api::error(...)` | the error you name |
| `Rsx_Api::document($array)` | 200, the object EXACTLY as given - no timing fields, no echoed `_request_ref` |
| a file / streamed response | sent as it is (downloads are exempt) |
| `null`, a list, `[]`, a scalar, a Collection | **refused**: a 500 naming the endpoint (`Every external API response body is a JSON object ... a list goes inside an object (['items' => $list])`), a writer rolled back |

There is no 204. Error shape is always `{"error":{"code","message","fields"?, ...documented extras}}`, built only by `Api_Dispatcher::_error()` - branch on `error.code`, never the message.

**Every object answer carries `_duration_ms` and `_completed_at`** (ISO UTC) at the top level. **`_request_ref`** (a system param on every endpoint, 1-128 chars, never in `$params`; `#[Api_Param]` names never start with `_`) is echoed on every response; on a writer it names the request, so a retry with the same ref and body is answered from the record (`_replayed: true`, original timing) instead of run twice - a different body is 409 `request_ref_reused`, a pruned record 410 `request_response_expired`. Only a success records its ref; nobody signed in = echoed, never honoured. `rsx:man request_history`.

**A standard-shaped document is the one unstamped object**: an OpenAPI root admits only its own fields and `x-` extensions, so the timing fields would make it invalid. Answer it with `Rsx_Api::document($array)` (a plain `Response`, `application/json`) - `stamp_response()` stamps a `JsonResponse` only. Never for an ordinary answer.

## Authentication

`Authorization: Bearer rsx_...` ONLY -> the key owner's `Session` identity, immutable for the request (`Session::is_logged_in()`/`get_user()`/`get_user_id()`/`get_site_id()` all work; NO cookie, NO Set-Cookie). **One session per key**: the key's first call mints a `TYPE_API` `_sessions` row plus a user session for its owner, reused by every later call, so the key's use is ONE entry (`is_api`) in the owner's `get_sessions_for_user()` (revoking the key ends it; the terminate functions do not), `get_session_id()` answers that row, `has_session()` is false (not a browser), `put_value()` throws, and acts are attributed to an audit session per caller address + user agent. Revoked/expired keys sign their user session out; deleting a key cascades its session away. Site scope applies automatically — **never hand-write `where('site_id')`**.

Keys are minted and revoked at **Settings > API Keys** (the plaintext key is shown once). **Every mint and every end of a live key is an EVENT** the model fires after commit - `api_key.created` / `api_key.revoked` (`how`: `revoked` | `deleted`), `{api_key, user, actor, audit_session_id, ip_address}` (`Api_Key_Event`) - so audit and notify from ONE handler, never from each controller (`rsx:man external_api`, KEY EVENTS). A rolled-back mint, ending an already-dead key and plain expiry fire nothing. Missing header -> 401, bad key -> 401, **both before route match** - so a bad key cannot be used to probe which paths exist.

`_api_keys.scopes` narrows ONE key below its holder — see **Key scopes** below. `_api_keys.user_role_id` stays RESERVED and NOT enforced; do not restrict a key with it.

## The `rsx:api:*` CLI

**The namespace exists so a SCRIPT can grant itself access to this API and then use it** — an importer or an exporter mints a key, calls `/api/vN` with `Authorization: Bearer <key>` like any other client, and exits. There is no privileged CLI channel into the API: the CLI mints a credential, and the script speaks the same HTTP the outside world speaks.

```bash
php artisan rsx:api:key:create --user=<id|email> [--site=] [--name=] [--expires=] [--environment=live|test] [--scope=<path>]... [--read-only] [--json]
php artisan rsx:api:key:temp   --user=<id|email> [--site=] [--expires="1 hour"] [--scope=<path>]... [--read-only] [--json]
php artisan rsx:api:key:list   --user=<id|email> [--site=] [--all] [--json]
php artisan rsx:api:key:delete <id> [--purge] [--force] [--json]
php artisan rsx:api:openapi    [--user=<id|email>] [--site=] [--compact]
```

- **`--user` is required and has NO default** — it is an option because every framework command spells it that way (`rsx:debug`, `rsx:ajax`), and the requirement is enforced in the command, naming the flag. A users.id or an email.
- **`--site` disambiguates, it does not select.** `users.id` is a global PK, so a numeric `--user` needs no site (a mismatched `--site` is refused). `users.email` is not unique across sites, so an email matching in two sites is **refused with the candidates listed** rather than a tenant being guessed.
- **`--expires`** takes an ISO datetime or a relative span. On `create` there is **no default** — no expiry means "until revoked". `temp` defaults to `1 hour` and names every key it mints **`Temporary (CLI)`**, so `list` and Settings > API Keys both say what it is - and marks it **`is_temporary`** (`generate(..., $is_temporary)`), the flag the key events carry, so a handler skips announcing it on the flag, never the name.
- **`delete` revokes** and keeps the row, and its request-log history with it; **`--purge` removes the key outright; its request-log rows survive with `api_key_id` set NULL** (`_api_request_log.api_key_id` is a real FK, `ON DELETE SET NULL`), so the audit trail no longer names which key it was. That is the reason to prefer revoke. Under `--json` it **requires `--force`** — a prompt would write into the stream the script is parsing and then block forever.
- **`--read-only`** mints a key that may execute GET requests only; `key:list` shows an Access column (`read-only` / `read+write`, `read_only` under `--json`).
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
| `POST /api/vN/files` | 201 + the file payload, including an unclaimed `key` |
| `GET /api/vN/files/:key` | metadata, URLs, `preview_status`, `text_status` |
| `GET /api/vN/files/:key/text` | `{key, text, text_status}`, or 409 while pending |

All three (and `GET /api/vN/me`) are served at every version the application serves - see Versioning below.

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

**Downloads need no API endpoint.** `/_download`, `/_inline`, `/_thumbnail/*`, `/_download_zip` and `/_preview/pdf` all accept `Authorization: Bearer`, so an integration fetches bytes with no cookie. A bad Bearer is a 401 there — it never degrades to anonymous. **An endpoint that returns file URLs takes them from the model's builders** (`get_download_url()`, `get_url()`, `get_thumbnail_url()`, `get_preview_url()`), never from the key: a builder's URL carries the attachment's content version (`?v=`), and a file URL without the current one is served `Cache-Control: no-store` — so a client uses `urls` as given and re-reads them rather than keeping one.

**Status vocabulary** (`pending` / `available` / `error` / `unsupported`): rendering and extraction are async, so a just-uploaded document reads `pending`. Poll; never assume. `error` is terminal and never auto-retried.

## Request logging

Every request — success and every failure path — writes one `_api_request_log` row: `verb`, `path`, `handler`, `status`, `duration_ms`, `ip` (`$log->ip`; the row stores an `_ip_addresses` id), nullable identity columns, plus **what was exchanged**:

| Column | Notes |
|---|---|
| `request_body` | redacted + capped; **NULL for any upload** |
| `response_error_code` / `response_error_message` | from the error envelope; NULL on success |
| `response_bytes` | body size actually sent |

`response_error_code IS NULL` is the success predicate. **The body is never stored for an upload** — the test is the request (files present, or a multipart content type), not the endpoint name, so it covers future upload endpoints automatically. Credential-shaped keys (`password`, `secret`, `token`, `authorization`, `api_key`, `credential`, `private_key`) are `[redacted]` at any depth; a bare **`key` is deliberately not redacted** because in this API that is the attachment key you need when tracing an attach. Values cap at 4000 bytes, the whole payload at 25000, both marked `...[truncated]`.

Indexed by key, by address (`ip_address_id`), and by `created_at`. Retention is `config('rsx.api.log_retention_days')` (default 30), pruned by a daily `#[Task]`.

## Versioning

URL-only, **exact-match at runtime** - there is no fallthrough from `/api/v2/x` to a v1 handler (it would let a `/api/v2/*`-scoped key reach v1 handlers; never add one). Ship a new version as a new path. A path another version serves but this one does not answers **410 `api_version_retired`** with `error.versions` naming the serving versions; a path no version serves is 404. A version whose endpoints answer non-objects (a bare list, a 204) is moved to a version that answers objects and then retired (end its range).

**Carry unchanged endpoints into v2 by declaring a range, never by copying attributes**: `#[Api_Versions(through: 2)]` on the controller serves every endpoint in it at v1 AND v2 (the manifest expands real `/api/v2/...` route rows, one handler); `through: N` on an `#[Api_Endpoint]` overrides it - the endpoint v2 revises says `through: 1`. A range below the pattern's own version, or an expanded address another declaration claims, fails the build ("Duplicate route definition" naming the range).

**An endpoint that belongs to no versioned resource FOLLOWS the application** with `#[Api_Versions(every: true)]` on its controller: it is served at every version the application's other endpoints serve, from its declared version up (its own version alone when they serve none). The framework's five endpoints (`/me`, `/files`, `/files/:key`, `/files/:key/text`, `/openapi.json`) carry it, so they answer at `/api/v3/...` once the app serves v3, and stop answering (410) at a version the app retires. An application endpoint at the same address as a follower is a "Duplicate route definition" build failure - never declare your own `/api/vN/me`, `/api/vN/files...` or `/api/vN/openapi.json`. `#[Api_Versions]` takes exactly one of `through: N` or `every: true`; an endpoint's own `through:` still overrides the controller's.

**A v2 controller needs its own class name** - class names are unique per file type and namespaces are not consulted, so a `V1\` / `V2\` split of one class name is a build failure. Name it `Contacts_V2_Api_Controller` and put `#[Api_Resource('Contacts')]` on BOTH controllers: that is the group the docs page and OpenAPI tags list them under (absent, the group derives from the class name and v2 shows as a separate "Contacts V2").

The docs GROUP by (verb, path with the `vN` stripped) and show the newest version <= the one selected, so `/apidocs/v1` shows a v1 endpoint even when v2 exists elsewhere. `@api-deprecated <note>` in an endpoint's docblock marks it deprecated (docs + OpenAPI) with no successor needed. **Is anything still calling v1?** `Api_Route_Usage::last_called_for_version(1)` - `_api_route_usage` keeps each route pattern's last authenticated call, refreshed at most once a minute. `rsx:api:openapi --api-version=2` emits one version's surface for client generation. Details: `rsx:man external_api` (VERSIONING).

## Docs and tester

- `/apidocs` (+ `/apidocs/vN`): per-endpoint cards, generated curl/php/js/python/powershell samples, a sidebar filter, and a live tester (paste a key created in Settings > API Keys, or let the page mint itself a one-hour `API Tester (temporary)` key).
- **The application owns the route and the gate — and nothing else.** The framework declares no route and has no visibility setting: the console exists only if you route to it, protected by whatever `#[Auth]` that route carries. Everything behind the call, the bundle included, is the framework's:

```php
#[Auth('can_manage_integrations')]          // your check
#[Route('/dev/api-reference')]
public static function api_docs(Request $request, array $params = []) {
    return Rsx_Api_Docs::page();   // the whole page, bundle and all
}

#[Route('/dev/api-reference/openapi.json')] // the console's openapi.json link: a browser download
public static function api_openapi(Request $request, array $params = []) {
    return response()->json(Rsx_Api_Docs::openapi_document(), 200, [
        'Content-Type' => 'application/json; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="openapi.json"',
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
```

- **The framework produces DATA, the app produces the RESPONSE.** `page()` renders a response; `openapi_document()` returns an array. Headers, status, and inline-vs-download belong to the route that declared them.
- **Gate the console on `Session::has_api_access()`, not on a role** — it is the same predicate `Api_Dispatcher` applies to a Bearer key, so the console cannot advertise an API the viewer will be refused. Render the denial with `Error_Screens::unauthorized($request)` (login redirect for an unidentified caller, themed 403 for an identified one); `response_unauthorized()` is the Ajax envelope and would reach a browser as JSON.

- **You declare no bundle and forward no hook.** `_Apidocs_Bundle` is framework-owned (it lives in the framework's own application tree, `system/app/RSpade/Sys/app/apidocs/`, and wears that tree's `--rsx-*` theme) and is named by the framework's own view; there is no supported way to restyle or extend the console and nothing to re-adopt when it changes. (Bundle validation normally demands that a bundle cover its dispatching controller's directory — that check is waived for a FRAMEWORK view, which is what makes a framework-side bundle legal on an app route.) Worked example, controller only: `system/app/RSpade/resource/reference_app/app/apidocs/`.
- **`page(sections: [...])` adds the application's own prose** - its error codes and what to do about each, its conventions: each section exactly `['title' => ..., 'markdown' => ...]`, listed under "Guides" atop the sidebar and shown at `<mount>?section=<slug>` (slug from the title). Markdown is GFM (a table is a table - write an error inventory kept as data out as one) through `Markdown_Rendition::render()`, so raw HTML is discarded. Any other key, a blank title or Markdown, or two titles sharing a slug throws `InvalidArgumentException` at the call. Content only - the framework owns the markup and styles. Worked example: the reference app's `Apidocs_Controller::__sections()`.
- **`page()`'s first argument restricts the listing** (default `true`): only endpoints whose `#[Auth]` gates pass for the API key the viewer supplied — scoped to the **key's** user, not the viewer. With no key it lists nothing and the landing card asks for one; supplying one **reloads**, because the catalog is baked in at render.
- Restricted listing hides what a caller cannot use; it is **not access control** — `Api_Dispatcher` gates every call regardless. That gate is: the key resolves, its user is active, AND `users.is_api_access_enabled` is set. All three failures return the one uniform `unauthorized` / "Invalid or expired API key" 401, so a caller never learns which.
- The filter works by briefly becoming the key's user (`Api_Tester_Key::accessible_targets()` -> `Session::_set_api_identity` + `Auth_Gates::can_access`, torn down in a `finally`). The identity fence is the whole fence: gates evaluate live, so the answers follow whichever identity is installed at the moment of the ask.
- **A program reads the OpenAPI 3.1 document from the framework's `GET /api/vN/openapi.json`** (`Openapi_Api_Controller`, served at every version the app serves, like `/me`) - THE one way a program fetches it. It answers THAT version's whole published surface (`Api_Openapi::document(null, N)`, `info.version` vN, listing itself under the `OpenAPI` tag), unstamped (`Rsx_Api::document()`), gated `is_logged_in` like `/me`: any valid key whose user has API access (the app's `Main::pre_dispatch` may still answer 403 `account_refused`), read-only keys included. **Key scopes never refuse it** - `Api_Scopes` grants `/api/vN/openapi.json` to every scope set. Never declare your own (a duplicate route).
- **The console's `<mount>/openapi.json`** (the reference app's `/apidocs/openapi.json`, gated with the console) is the same document, every version at once, downloaded by a person in that browser - a link cannot carry a bearer key. Feed either to client generators, Postman, or an agent toolchain. Every version is listed at its real path; an endpoint another handler supersedes at a later version, or one carrying `@api-deprecated`, carries `deprecated: true`. `openapi_document($targets, $version)` / `rsx:api:openapi --api-version=N` narrows to one version.
- **Every WRITER operation documents `_request_ref`** (`#[Writer]`, or `#[Manual_Lock]`): an optional body property (`$ref` `components.schemas.RequestRef`, the replay described), plus 409 `request_ref_reused` / 410 `request_response_expired` in its responses; readers carry neither. **`x-build`** at the root is `Rsx::build_info()` - `{commit, built_at, framework_revision, code_key}`, recorded in the seal by `rsx:build` (git, or `--commit` / `--framework-revision` on a host with no `.git`), all but `code_key` null on a development box (`rsx:man prod`, BUILD IDENTITY). `info.version` stays the API version.

### The identity endpoint

**`GET /api/vN/me` ships with the framework** (`Identity_Api_Controller`, served at every version the app serves) — the identity endpoint every RSpade API has. Returns the site-scoped `user_id`, `email`, `site_id`, and the key's own `name`/`prefix`/`expires_at`/`last_used_at`.

Reaching it **is** the validation: a bad, revoked or expired key is a 401 from the dispatcher before it runs, so a 200 means the key is good. It is a contract — path, verb and field names are stable across installs — and deliberately thin, because integrations poll it. `expires_at: null` means no expiry, not unknown.

### Key prefix

`config('rsx.api.key_prefix')`, default `'rsx_'` → keys read `rsx_live_xxxxx`. Rename it per product. **Existing keys are unaffected** — lookup is sha256 of the whole key, never the prefix.

### Console behaviour worth knowing

- **The key is validated before it is accepted** (against `/me` at the newest catalog version), so a bad key fails at the paste rather than later on an unrelated endpoint.
- **The tester validates path parameters only.** Everything else reaches the server unchecked — a blank required field should come back as the endpoint's own 422, which is what a tester is for. An unparseable body is sent too, so you see the dispatcher's `invalid_json`.
- **Unset means omitted** — blank query params and blank body fields are dropped, never sent as `""`.
- **"Fill in values"** swaps the samples to the exact request the tester would send (real key, typed values, merged path params), live. It reads `_Apidocs_Tester::current_values()`, the same method Send uses. Unfilled path params stay as `{name}`, marked red.
- Every sample value is escaped for its target language, and URL values **twice** — percent-encode, then quote for the embedding language (`_apidocs_escaping.js`).

## Interactions

Dev calls must use the `APP_URL` host or loopback; CSRF N/A (no cookie session); FPC never applies; **`Main_Abstract::pre_dispatch` RUNS for API dispatch** (after bearer identity, scopes and gates; `$params['_handler']` is the controller) **and for a key on a file-serving route** (`/_download`, `/_inline`, `/_download_zip`, `/_thumbnail/*`, `/_preview/*` - the key's identity is set before the gates there), and any non-null return is a **403 `account_refused`** - the identity is headless, so a hook branches on `Session::is_api_request()` before any `Session::logout()`. `_api_keys.user_role_id` is RESERVED, NOT enforced (`scopes` IS — see above). A key whose login identity is soft-deleted is refused with the uniform 401. Every file read behind those routes needs the MANDATORY `file.thumbnail.authorize` / `file.download.authorize` handlers (a missing one is a 5xx naming it). The template additionally gates its API on `can_use_api` + `can_view_data` (writes `can_edit_data`) and refuses a key holder without `can_use_api` in `Main::pre_dispatch`.

**The API identity is scoped to the dispatch.** `Api_Dispatcher::dispatch()` clears it in a `finally`, so it never outlives the request it authenticated (an endpoint that throws included). Something that dispatches repeatedly in ONE process - a test harness, a CLI tool, a batch runner - needs no reset of its own between calls, and must not write one: the reset is a framework internal and an application reference to it is refused at manifest build (`NAME-RESERVED-02`).

## Key scopes

A key otherwise carries its holder's **entire** authority. `_api_keys.scopes` narrows one key; `Api_Scopes` (static, pure) is the whole meaning. **A scope is a bare PATH PATTERN and it is a GRANT** — no keyword, no method, no deny form.

```
/api/v1/contacts/*
/api/v1/clients/#/view
/api/?/me
```

- Starts `/api/<version>/…`, where `<version>` is a `vN` literal, `?` or `#`.
- `?` = one segment of any shape. `#` = one all-digits segment. `*` = **last segment only**, and it covers **the prefix itself plus everything below it** (`/api/v1/files/*` reaches `/api/v1/files`, `/api/v1/files/abc` and `/api/v1/files/abc/text`).
- **Each wildcard is a WHOLE segment** — `/api/v1/foo*`, `/api/v1/?_id`, `/api/v1/b#r` are validation errors.
- Literals match **exactly, case-sensitively** (what the router does). **Trailing slash and query string are meaningless on both sides** — `/api/v1/clients`, `/api/v1/clients/` and `/api/v1/clients?page=2` are one path, and none matches `/api/v1/clients/view`.
- **NULL/blank = unrestricted.** Any scope flips the key to **deny-by-default**, so there is nothing to deny and no way to write a denial. The one exception is the OpenAPI document (`/api/vN/openapi.json`), which every key reaches. Ticking two presets is set union, order-independent.
- **The method is not part of a scope.** "Read-only" is not expressible as a scope at all — that is what the key's own `read_only` flag is for (below).
- **A malformed stored scope is IGNORED for matching and STILL COUNTS** — so a key whose only scope is malformed denies everything and **fails closed**, logging `API key #<id>: ignoring malformed scope '<text>' - <reason>` once per request evaluation. Predicate: `$key->has_malformed_scopes()`.
- **Scopes subtract only**: `effective = the user's LIVE permissions ∩ the scopes`. Evaluated per request, never frozen at mint.
- Dispatcher order: verb gate → bearer auth → **read_only** → route match → **SCOPE** → param validation → `#[Auth]` gates → controller.
- **Dispatch is ROUTE-ONLY.** Requests resolve against declared `#[Api_Endpoint]` patterns by path and nothing else; there is deliberately no by-name (`Class::method`) channel and **none may be added** — a scope is a path pattern, so a second addressing scheme would be a scope bypass.

**Validation is one function, one exception.** `Api_Scopes::validate($scope)` throws `Api_Scope_Validation_Exception` whose message IS the rule that was broken. Every write path runs it and the **first** bad scope refuses the whole set, writing nothing — `generate()`, `set_scopes()`, `--scope`, and your own endpoint:

```php
try {
    $scopes = Api_Scopes::canonicalize($text);
} catch (Api_Scope_Validation_Exception $e) {
    return response_form_error($e->getMessage(), ['scopes' => $e->getMessage()]);
}
```

**One matcher, in PHP.** `Api_Scopes::matches()` is the only implementation. A preview or a narrowed listing **asks a PHP endpoint** — never a JavaScript twin, which would drift from what the dispatcher does.

**Two different 403s.** `insufficient_scope` (`{"error":{"code","message","required":"/api/v1/clients/:id/delete"}}` — `required` is the matched **route pattern**, omitted for an `@api-hidden` endpoint) means *mint a wider key*; `forbidden` from the gates means *ask your administrator for the permission*. Both are logged in `_api_request_log`. The file-serving web routes (`/_file/`, `/_thumbnail/`, …) clamp a scoped key against a synthetic path in the files subtree of EVERY version serving the file routes (`/api/vN/files/anything`) and answer the same 403 only when no version's subtree is reached - a key scoped `/api/v3/files/*` downloads what `GET /api/v3/files/:key` describes.

**`API-GET-PURE-01`** — a GET handler's body may not contain a write call (saves, deletes, restores, updates, increments, creates on a model or relation, pivot attach/detach/sync, `->raw_bulk(` `->force_destroy(` `->attach_to(`, raw `DB::` writes, `Task::dispatch(` — the list is `Api_Endpoint_ManifestSupport::GET_MUTATION_TOKENS`, matched case-insensitively); it is a **manifest-build failure**, because a scope carries no method and a read grant's read-only-ness rests entirely on it. A GET handler whose body cannot be located fails too, and **one endpoint may not declare both GET and POST**. Escape: `@API-GET-PURE-01-EXCEPTION <rationale>` in the method docblock — **the rationale is required**, a bare tag still fails. **It is a guardrail, not a proof**: only the handler's own body is scanned; a helper it calls or a dynamic call is not followed.

**Presets are APP data.** The framework never names a scope. `config('rsx.api.scope_presets')` (`[['name','description','scopes'], …]`) is read by the app's key UI and by nothing in core — mirroring how `Auth_Gates` never names a permission.

```php
Api_Key_Model::generate($user_id, $name, 'live', null, null, $scopes);
$key->is_unrestricted();  $key->get_scope_rules();  $key->set_scopes($text);
$key->has_malformed_scopes();
Api_Scopes::decide($scopes, '/api/v1/contacts', $key_id);  // the dispatcher's question
Api_Scopes::reaches_route($scopes, '/api/v1/contacts/:id'); // does it reach an ENDPOINT
Api_Catalog::resolve_for_scopes(1, $scopes);                // pure: takes TEXT, not a key
```

**Keys are immutable after mint** — no edit endpoint anywhere; revoke and re-mint. `GET /api/v1/me` reports `key.scopes`; `rsx:api:key:list` shows a scope count (full text under `--json`); `rsx:api:openapi --key=<id>` narrows the document to gates ∩ that key's scopes ∩ its verbs.

## Read-only keys

`_api_keys.read_only = 1` means the key **may execute GET requests only**; every other verb is refused **403 `read_only_key`**, message `This API key is read-only: GET requests only.` It is the runtime half of the GET-is-for-reads rule above, and the answer to the thing a scope cannot say — a scope is a path pattern with no method in it, which is why the old `Read-only` scope preset was removed rather than rewritten.

- **Decided after the bearer key resolves and BEFORE the route is matched**, so a read-only key learns nothing about which write endpoints exist, and **before the scopes**, so each refusal names what the caller must change: `read_only_key` (this credential cannot write) vs `insufficient_scope` (mint a wider key) vs `forbidden` (ask for the permission).
- **The two narrowings compose and neither implies the other**: `effective = the user's LIVE permissions ∩ the key's scopes ∩ (read_only ? GET : every verb)`. A read-only key may be unrestricted; a scoped key may write.
- **Set at mint, never changed** — `generate(..., $read_only)` is the only writer and there is **no setter**, exactly as with the scopes: widening a credential already in service would change what it can do under the integration holding it.
- **The console hides what the key cannot do**: `Api_Tester_Key::accessible_targets_for_key()` drops every endpoint declaring no GET, and `Api_Catalog::resolve_for_scopes($v, $scopes, $hidden, $read_only)` keeps GET verbs alone — so a listing or a preview panel never offers a call that comes back `read_only_key`.
- The Bearer-accepting web routes (`/_download`, `/_thumbnail/*`, …) are all GET, so they need no clamp of their own.

```php
Api_Key_Model::generate($user_id, $name, 'live', null, null, $scopes, $read_only = true);
$key->read_only;                            // bool
```

`GET /api/v1/me` reports `key.read_only`; `rsx:api:key:create` / `:temp` take `--read-only`; `rsx:api:key:list` has an Access column; Settings > API Keys has the mint checkbox, the grid's Access column and the view modal; `/apidocs` shows a `read-only` badge.

## Config

`rsx.api.log_retention_days` (default 30) - every request logs to `_api_request_log`, pruned by a daily cleanup task.

## Full reference

`php artisan rsx:man external_api`. Related: `rspade:file-attachments` (the attachment model, the upload gate, the retention window).
