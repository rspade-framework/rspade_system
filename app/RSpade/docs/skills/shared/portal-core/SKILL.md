---
name: portal-core
description: "The framework's client-portal machinery - [Portal_Route] and @portal_spa routing, portal tenancy (rsx.tenancy single|multi, the PORTAL_URL template with its :slug / :site_id placeholder, PORTAL_ENABLED, PORTAL_CUSTOM_DOMAINS off|override|only, sites.portal_domain, sites.is_portal_enabled, Portal_Site_Resolver reading the site from the URL), per-site URLs (Rsx_Portal_Url::for_site(), Rsx_Portal::Route(..., site_id:) answering false, window.rsxapp.portal.base), the portal password check (Portal_Auth::verify_credentials()) and sign-in history (Portal_Login_History), the portal's own second factors / passkeys (Rsx_Portal_Two_Factor) and federated sign-in (Rsx_Portal_Sso, rsx.sso.portal_enabled), Portal_Session::put_value(), Portal_Main_Abstract::init(), the Portal_Session facade over the shared session row, the portal auth realm, Portal_Permission_Abstract, portal_fetch()/portal_can_read() and PORTAL-MODEL-FETCH-01, the internal-endpoint channel and Ajax.upload(), View as Client (Portal_Session::begin_impersonation_from_staff() and the linked-session handshake), and Portal_Notification_Model. Use when adding a portal page or endpoint, making the portal multi-tenant or moving it to its own host, prefix, tenant subdomains or custom domains, building a staff link into a site's portal, when the build fails \"Invalid client portal address.\" (\"rsx.tenancy = multi requires PORTAL_URL to carry a tenant placeholder\", \"but rsx.tenancy is single\", \"must END in /:slug or /:site_id\", \"collides with these staff routes\"), when a tenant's portal answers 404 or an old portal address 301s, when an endpoint answers \"This site has no client portal.\", when Portal_Session::set_site_id() throws \"is for commands, tasks and tests only\" or Portal_Session::get_site_id() throws, when a View as Client link answers \"This link has expired or was already used.\", when a portal Ajax call resolves in the staff realm, when exposing a model to portal JavaScript, or when marking a read endpoint #[Portal_Impersonation_Readable] for read-only impersonation, or hitting \"This is a read-only session; changes are disabled.\""
---

# The portal framework

Template-side counterpart: app skill `portal-app` (ships in `rsx/resource/skills/`).

A second authenticated experience for external users (clients, vendors), running **parallel** to the staff app with its own realm of the one dispatcher, routing table and permission facade. The always-on fragment carries the mandates; this skill is the framework machinery you build against.

**Framework** (`App\RSpade\Core\Portal\`, `App\RSpade\Core\Models\`): `Portal_Session`, `Rsx_Portal` (PHP+JS), `Portal_Main_Abstract`, `Portal_Permission_Abstract`, `Portal_Authorizable`, `Portal_User_Model`, `Portal_Notification_Model`, `#[Portal_Route]` / `@portal_spa` manifest support.
**Application** (`/rsx/portal/` + `rsx/portal_main.php`, `rsx/portal_permission.php`): the middleware, the SPA bootstrap, the layouts, auth controllers, screens, and every app-specific model - all of it yours, and described by the app skill `portal-app`.

---

## Part A - Routing and the portal's address

**Where the portal lives is `PORTAL_URL`** (`config('rsx.portal.url')`), a TEMPLATE written like `APP_URL` (or a bare path on `APP_URL`'s host). Beside it: `rsx.tenancy` (`single` | `multi`, APPLICATION config in `rsx/resource/config/rsx.php`, no `.env` key), `PORTAL_ENABLED` (default true) and `PORTAL_CUSTOM_DOMAINS` (`off` | `override` | `only`). Per site: `sites.portal_domain` (unique, normalised on save, throws for a non-hostname - `Site_Model::normalize_portal_domain()` is the same rule for a form) and `sites.is_portal_enabled` (default on).

```
PORTAL_URL                          served at
-- single tenancy (site 1, always; a placeholder is refused)
(blank)                             APP_URL's host, under /_portal
/clients                            APP_URL's host, under /clients
https://portal.myapp.com/           its own host, no prefix
https://portal.myapp.com/clients    its own host, under /clients
-- multi tenancy (exactly one :slug or :site_id)
(blank)                             APP_URL's host, under /_portal/:site_id
/_portal/:slug                      APP_URL's host, one path per site
https://:slug.myapp.com             a subdomain per site
```

- **A path placeholder ENDS the path after at least one fixed segment** (`/_portal/:slug`, never `/:slug`) and lives on the APPLICATION's host only - a path placeholder on a portal host of its own (`https://portal.myapp.com/x/:slug`) is refused, since a tenant's portal is its own subdomain or domain; **a host placeholder is the whole first label** (`https://:slug.myapp.com`).
- **Judged at MANIFEST BUILD, every mode**, each failure FATAL as "Invalid client portal address." plus the rule: equal to `APP_URL`, https rule as `APP_URL`, query/fragment/credentials, a segment outside `[A-Za-z0-9_-]`, a first segment `api` / `error` / `ws` / any `_` name but `_portal`, a placeholder in single / none in multi, and a same-host FIXED prefix that **collides with a staff `#[Route]`, `@route` or API route** (listed in the error). The four values are in the build fingerprint: a dev box rebuilds on a change, a sealed box refuses until `rsx:build --force`. The `rsx:health` "Portal URL" row reports the derivation.
- **Same host:** a path under the portal's base is the portal; everything else is staff. **Own host** (a portal host, a tenant subdomain, a custom domain): every request there is the portal; a path outside the base is the portal's 404; the external API does not exist there.

**The site is read from the URL, every request** (`Portal_Site_Resolver`): a custom domain (override|only), else the template's slug or id, else - single tenancy - site 1. An address serving no site (unknown slug/id, site deleted/disabled/`is_portal_enabled` off, under `only` any template address) is the portal's **404**; under `override` a template address of a site with a `portal_domain` is a **301** to the same path on `https://<domain>`. Both happen before any application code (an Ajax call gets a plain-text 404). Product layouts, DNS/TLS, the upsell: `rsx:man portal_tenancy`.

`Rsx_Portal::is_portal_request()` / `Rsx_Portal.is_portal()` report context (decided once, before dispatch); `Rsx_Portal::strip_prefix($url)` gives the path inside the portal. Full rules: `rsx:man portal` (PORTAL URL).

### Server-rendered pages

```php
#[Portal_Route('/login', methods: ['GET','POST'])]
#[Auth('public')]
public static function index(Request $request, array $params = []) { }
```

Public static methods only; the pattern may contain `:params`; `methods` defaults to `['GET']`. These land in the manifest's **portal route table, entirely separate from the main `#[Route]` table**. Use them for login, register, password reset, logout. As with main routes, `$params` carries the GET query string AND the URL params (URL params win on a clash) - read query values from `$params`, never `$request->query()`. POST data is not in `$params`; use `$request`. **A route is written once for every tenant** - the dispatcher and the SPA router strip each tenant's base (`/_portal/acme`) before matching.

### Authenticated SPA screens

```javascript
/** @decorator */
@route('/projects/:id')
@layout('Portal_Layout')
@portal_spa('Portal_Spa_Controller::index')
@auth('is_logged_in')
class Portal_Project_View_Action extends Spa_Action { }
```

The portal counterpart of `@spa`: GET routes served through the single `Portal_Spa_Controller` bootstrap, navigating client-side. Nested layouts work exactly as in the staff SPA.

### URLs - mandatory, never hardcode

```php
Rsx_Portal::Route('Portal_Login_Controller')            // /_portal/login
Rsx_Portal::Route('Portal_Project_View_Action', 123)    // integer -> 'id'
Rsx_Portal::Route('...Action', ['id' => 1, 'tab' => 'x'])
Rsx_Portal::Route('Portal_Login_Controller', site_id: $client->site_id)  // ONE site's portal
Rsx_Portal::portal_path('/login')                       // a non-route portal path (site_id: too)
rsx_absolute_url(Rsx_Portal::Route(...))                // absolute, from any context
```

**`Route()` / `portal_path()` answer a path on the portal's host and an ABSOLUTE URL off it** (a staff page, CLI, a task - when the portal has its own host). `rsx_absolute_url()` passes an absolute URL through, so an emailed link is always `rsx_absolute_url(Rsx_Portal::Route(...))`. Never `Rsx::Route()` for a portal page and never hand-join the prefix.

**A URL is for ONE site** - `site_id:`, or the current one (a portal page's resolved site; a staff page's signed-in site; site 1 in single tenancy) - and **answers `false` when that site has no portal** (feature off, site disabled or opted out, under `only` no domain). `Rsx_Portal_Url::for_site(?int)` answers the address (`{origin, prefix, host, separate_host}`) or false. Staff code builds a link for the RECORD's site and refuses first, writing and sending nothing:

```php
if (Rsx_Portal_Url::for_site($client->site_id) === false) {
    return response_error(Ajax::ERROR_VALIDATION, 'This site has no client portal.');
}
```

Worked example: `system/app/RSpade/resource/reference_app/app/frontend/clients/clients_controller.php`. `Rsx_Portal_Url::origin()` / `prefix()` / `host()` / `url()` / `is_separate_host()` answer for the current site and THROW without a portal.

JS: `Rsx_Portal.Route(...)` (current site only, no `site_id`; false while `window.rsxapp.portal.base` is null - hide the portal action then), `Rsx_Portal.is_portal()`, `Rsx_Portal.user()`, `Rsx_Portal.is_impersonating()`, and the address `Rsx_Portal.origin()` / `prefix()` / `base()` / `is_separate_host()` - read from `window.rsxapp.portal` (`{origin, prefix, separate_host, base, app_host_prefix}`), which is in EVERY bundle, staff included.

---

## Part B - Portal_Main and the site

One `rsx/portal_main.php` extending `Portal_Main_Abstract`:

| hook | when |
|---|---|
| `init()` | once per process, at the top of the first portal request - **the FIRST application code in the portal stack**; the site is already declared |
| `pre_dispatch($req, $params)` | before every matched route, **AFTER the `#[Auth]` gates have already run**, and for an unmatched URL; return null to continue, a Response to halt. Also once per Ajax CALL (before its endpoint and gates; `$params['controller']`/`['action']`): non-null refuses that call only - a coded result keeps its code and reason, anything else is `unauthorized` |
| `unhandled_route($req, $params)` | no portal route matched (after `pre_dispatch`); return null for the 404 page |

Order: the URL's site declared (framework) -> `Portal_Main::init` -> CSRF -> dev auth -> route match -> **`#[Auth]` gates** -> `Portal_Main::pre_dispatch` -> controller `pre_dispatch` -> handler.

The portal is the one `Dispatcher` in the PORTAL realm (`rsx:man dispatch`, THE REALM DESCRIPTOR), so everything else is the staff behaviour: `$params` carries `_method` / `_route` / `_handler` for a matched route and the query string for an unmatched one; a returned string is an HTML body; `response_unauthorized()` and friends get the full-page split; and an `abort()` or a thrown coded exception (`AjaxUnauthorizedException` from `require_permission()`, `AjaxNotFoundException`, ...) is answered with the portal's own login redirect / 403 / 404 / 400 page (`rsx:man dispatch`, CODED FAILURES).

**`pre_dispatch` performs NO authorization.** It is for tenant setup, redirects, and the login-redirect capture.

### The site - THE URL IS THE AUTHORITY

**The framework reads the portal's site from the request's URL and declares it for the request before `Portal_Main::init()`.** The application declares nothing; `init()` just inits the session:

```php
// rsx/portal_main.php
public static function init(): void
{
    Portal_Session::init();   // Portal_Session::get_site_id() already answers
}
```

- **Never stored** - no session column, no cookie, no config key. A portal user belongs to one site, so the sign-in already implies it.
- **The URL wins over a sign-in**: a live portal sign-in for another site is signed out (`SIGN_OUT_SITE_SWITCHED`) and the request continues signed out - only in a path layout, where every tenant shares one cookie.
- `get_site_id()` answers the request's site, else the portal sign-in's own; with neither it **throws** - **a portal that does not know its tenant must not pick one.** Login carries no site argument (`set_portal_user_id(?int)` uses the request's site). A login, register or reset handler reads `get_site_id()` and checks every credential, invite code and token against it - never adopts the site of the row it found.
- **`Portal_Session::set_site_id()` is for commands, tasks and tests ONLY** and throws in a web request. In CLI it may be re-declared (moving a test between tenants); the runner clears it after every test method (`Portal_Session::_testing_reset()` - the runner's call, never a test's).

**Every framework site seam reads that site on a portal request** - `Rsx_Site_Model_Abstract::get_current_site_id()` (global scope, forced `site_id` on create, cross-site fatals, site write lock), plus `Rsx_Mail`, `Rsx_Sms`, `Rsx_Time` (the site-timezone tier; portal accounts have no personal zone), `Rsx_Settings`, `Rsx_Throttle`. **So portal code never hand-scopes a query** with `where('site_id', ...)`, and a staff `Session::set_site_id()` is the STAFF app's tenancy only.

---

## Part C - Portal_Session

`Portal_Session` is a static **FACADE over the PORTAL SIGN-IN of the one session the browser has** - not a second session, not a model. A portal sign-in is a USER SESSION in the portal realm (browser x portal user x site x View-as-Client impersonator, reused when that user signs in there again); the browser's row points at the current one. There is no `Portal_Session::where()`, no instances, no portal session table.

```php
Portal_Session::init(): void                  // load from cookie (no create)
Portal_Session::is_logged_in(): bool
Portal_Session::has_session(): bool           // creates nothing
Portal_Session::get_user() / get_portal_user()      // ?Portal_User_Model
Portal_Session::get_portal_user_id(): ?int
Portal_Session::get_site_id(): int            // the URL's site (Part B); THROWS without one
Portal_Session::set_site_id(int): void        // CLI/tests only; THROWS in a web request
Portal_Session::set_portal_user_id(?int): void// log in / log out
Portal_Session::logout(): void
Portal_Session::get_session_id(): ?int        // the browser's session id (SHARED with Session); null in a command or task
Portal_Session::get_csrf_token(): ?string     // the browser's ONE token (SHARED)
Portal_Session::verify_csrf_token(string): bool
Portal_Session::put_value($key, $value, ?$expires_at) / get_value($key, $default) / forget_value($key)
                                              // the browser's session values in the PORTAL realm
                                              // (Session::put_value(..., 'portal')); CLEARED by a
                                              // portal sign-out; throw in a command, task or API call
Portal_Session::_testing_reset(): void        // the RUNNER's per-test clean slate

// "where you're signed in" - user sessions (entry shape: rspade:session-auth)
Portal_Session::get_sessions_for_user(?int $id = null, bool $include_impersonations = false): array
Portal_Session::terminate_session(int $user_session_id): bool    // OWN, another browser, fail-closed
Portal_Session::terminate_all_other_sessions(): int               // + forgets their 2FA trust
Portal_Session::terminate_all_sessions_for_user(int $portal_user_id, ?int $except_user_session_id = null): int  // unguarded
```

**A portal sign-in ENDS by being signed out** - `logout()`, the lifetimes (`rsx.sessions.portal`: 7 days / 8 hours idle), the terminate calls, the sign-in cap, `stop_impersonation()` - which stamps the user session with a reason, clears the browser's portal pointer and its PORTAL session values, and rotates the tokens when it is this browser. **None of them deletes or deactivates the browser's row**, which may carry a staff sign-in. A second-factor login parks its own values AFTER `begin_challenge()` (which signs the portal out first). `get_sessions_for_user()` leaves a View as Client out unless `$include_impersonations`; the template lists the entries active within 3 months (`rsx/lib/sessions/session_listing.php`).

**Never read or write the staff sign-in from portal code, and never the portal sign-in from main-app code.** That rule is for APPLICATION code: the framework surfaces serving both experiences resolve the experience themselves, so portal code writes them exactly like staff code -

```php
Flash_Alert::success('Welcome to the Client Portal!');   // this session; stamped as portal
@csrf                                                    // this session's one token
```

**Do not hand-roll a portal variant of either.** A portal-specific CSRF input or flash mechanism reintroduces the coupling these surfaces exist to hide.

**When app code must fork, fork on `Rsx_Portal::is_portal_request()` - never on `is_logged_in()`. Identity is not experience**: both identities set at once on one row is NORMAL (same human, same browser).

---

## Part D - Authorization

### Gates

Declarative, exactly like staff: every portal surface carries `#[Auth(...)]` / `@auth(...)` naming **portal-realm** checks, evaluated by the `Dispatcher` (portal realm) before any application code, and the manifest build FAILS on a surface with none. Public controllers (login, register, password reset, logout) carry `#[Auth('public')]`.

**The gate vocabulary is just the two inherited built-ins** - `public` and `is_logged_in` (resolving against `Portal_Session`). **Every per-client rule is parameterized and therefore record-layer, not a gate.** There is **no `route_is_exempt()` and no `@portal-auth-exempt`** - both are dead syntax.

Realm resolution: an `#[Ajax_Endpoint]` controller under `rsx/portal/` or `app/RSpade/Core/Portal/` defaults to the portal realm; anywhere else, declare `#[Auth_Realm('portal')]`. See `rspade:auth-gates`.

### Portal_Permission

The portal analogue of the staff `Permission`. The marker base `Portal_Permission_Abstract` is framework core; the concrete `Portal_Permission` lives in the app (`rsx/portal_permission.php`) because its membership methods reference app models. All static:

```php
Portal_Permission::is_logged_in(): bool            // also #[Auth_Check] - nameable from #[Auth]
Portal_Permission::current_user(): ?Portal_User_Model
Portal_Permission::current_user_id(): ?int
Portal_Permission::site_id(): int
Portal_Permission::has_client_access(int $client_id): bool
Portal_Permission::client_role(int $client_id): ?int      // VIEWER=1 / COLLABORATOR=2
Portal_Permission::can_collaborate(int $client_id): bool  // role >= COLLABORATOR
Portal_Permission::accessible_client_ids(): array         // for whereIn() scoping
Portal_Permission::can_access(string $target): bool
```

The parameterized helpers are NOT gates (a gate takes no arguments) - they stay inline in function bodies. JS mirror `Portal_Permission.js` reads `window.rsxapp.portal` (`is_auth`, `user`, `client_roles`) and is for **hiding UI affordances only**; the server is the enforcement boundary.

### Record level: portal_fetch() + portal_can_read()

```php
use App\RSpade\Core\Portal\Portal_Authorizable;

class Project_Model extends Rsx_Site_Model_Abstract
{
    use Portal_Authorizable;

    public function portal_can_read(): bool
    {
        return Portal_Permission::has_client_access($this->client_id);
    }
}
```

The trait supplies a gated `portal_fetch($id)` that (1) requires a portal session and (2) defers to `portal_can_read()`. **The single ORM endpoint dispatches to `portal_fetch()` in a portal request and `fetch()` otherwise** - one ORM, the method chosen by context.

`portal_can_read()` is **FAIL-CLOSED** and picks **exactly ONE** visibility rule:

| rule | shape |
|---|---|
| own-record | `$this->id === Portal_Session::get_portal_user_id()` |
| shared-recipient | the row's recipient matches the portal user's linked contact |
| membership-scoped | `Portal_Permission::has_client_access($this->client_id)` |

Core models' implementations use `Portal_Session`; app models' use `Portal_Permission` (no core-to-app dependency).

**`PORTAL-MODEL-FETCH-01`** (`rsx:check`): a model exposing `portal_fetch()` must declare `portal_can_read()`, and a hand-rolled `portal_fetch()` must call it. This is the record layer, which gates deliberately do not cover.

---

## Part E - The internal-endpoint channel

The framework transports and file routes are served in BOTH realms by the same handlers:

```
/_ajax/<Controller>/<action>  AJAX channel -> Ajax::handle_browser_request
/_ajax/_batch                 AJAX channel -> Ajax::handle_batch_request
/_upload, /_download, /_inline, /_download_zip, /_thumbnail/*, /_icon_by_extension
                              File_Attachment_Controller (#[Route] + #[Portal_Route])
/_preview/*                   File_Preview_Controller (both tables)
/_csp-report                  Csp_Report_Controller (both tables)
/_session_link/*              the View as Client handshake (Part F)
```

The AJAX channel (a POST under `/_ajax/`) has no route row: the `Dispatcher` hands it to the one Ajax core in the request's realm.

A portal page calls them under the portal's own prefix - `/_portal/_ajax/...` by default, the bare `/_ajax/...` at the root of a portal host of its own. Both languages derive it:

```javascript
Rsx_Portal.internal_url('/_ajax/Foo/bar');      // PHP twin: Rsx_Portal::internal_url()
//   staff page / request              -> /_ajax/Foo/bar
//   portal page, default PORTAL_URL   -> /_portal/_ajax/Foo/bar
//   portal page, no prefix            -> /_ajax/Foo/bar
```

`Ajax.call()` (and therefore every generated controller stub) already goes through it, and so does every framework file URL (`$attachment->get_url()`, thumbnails, `<Document_Preview>`/pdf.js, the ZIP download) and a portal page's CSP `report-uri`. The `file.*.authorize` gates receive the realm's own user. A Bearer API key on a portal-realm file route answers the API's 404. **Multipart uploads MUST go through `Ajax.upload(form_data)`** - it owns both the channel and the CSRF header, because multipart cannot ride the `$.ajax` chokepoint that normally attaches it. **A raw `fetch('/_upload')` hardcodes the staff path and sends no CSRF token.**

**Why it matters**: on the portal channel the request IS a portal request - CSRF verifies against the portal session, the gates evaluate in the portal realm, and `Orm_Controller` resolves `portal_fetch()`. Calling the bare staff path from a portal page gets the staff realm for all three, and the Ajax and ORM seams additionally REFUSE the cross-realm call outright. **This is what makes a same-host portal behave like one on its own host.**

---

## Part F - Impersonation ("View as Client")

Staff open the portal AS a contact to see exactly what that client sees, in a READ-ONLY session. (The staff-on-staff equivalent is a same-cookie in-place swap and is full read/write - see `rspade:session-auth`.)

A View as Client is a **PORTAL USER SESSION on the staff member's own browser** - the target portal user, its site, and `impersonator_user_id` (the staff `users.id`) - that the browser's portal pointer names while it lasts; the browser's own portal sign-in, if any, stays signed in behind it, and the staff sign-in is untouched. Nothing rotates, and nothing but the opaque token reaches the cookie.

**Starting one** - a staff Ajax endpoint gated `#[Auth('can_impersonate')]`:

```php
$url = Portal_Session::begin_impersonation_from_staff($portal_user_id, Session::get_user()->id, $site_id);
if ($url === false) {     // that site has no portal - nothing was applied
    return response_error(Ajax::ERROR_VALIDATION, 'This site has no client portal.');
}
return ['url' => $url];   // the staff page opens it in a new tab
```

It throws `AjaxUnauthorizedException` unless the staff-realm `can_impersonate` check passes, requires the impersonator id to be the caller's own staff user, and **never touches the contact's `last_login`**. `can_impersonate` is **framework-declared and DENIES by default** (`Permission_Abstract`, `#[Replaceable]`): an app offering View as Client redeclares it on its `Permission` class (`#[Auth_Check]`, its own rule, no `parent::` owed). Open the tab synchronously inside the click (`window.open('', '_blank')`) and point it at the URL when the call returns, or a popup blocker eats it - worked example: `system/app/RSpade/resource/reference_app/app/frontend/contacts/view/Contacts_View_Action.js`.

Whether it crosses hosts is decided by THAT site's portal address (`Rsx_Portal_Url::for_site($site_id)`).

- **Same host:** the caller's own browser points its portal sign-in at the View as Client user session (one cookie, one row); the URL is the portal's landing page.
- **Own host** (a portal host, a tenant subdomain, a custom domain): the browser has a different cookie there and neither host can set the other's. The URL is leg 1 of the **linked-session handshake** - three GET redirects:
  1. portal `<prefix>/_session_link/open` - burns code 1, sets an HttpOnly `rsx_link` nonce cookie, sends the browser to the staff host;
  2. staff `/_session_link/confirm` - burns code 2, REQUIRES the browser's own staff cookie to name the bound row and `can_impersonate` to still pass, applies the impersonation;
  3. portal `<prefix>/_session_link/complete` - burns code 3, REQUIRES the nonce cookie, points the portal host's session cookie at the SAME row.

  Codes are 256-bit, stored hashed in `_session_links`, single-use, HMAC-signed per leg and host (`Rsx_Signed_Url`), valid **120 seconds - a security window, not a timeout**. The session token never appears in a URL. **Any failure answers one 400, "This link has expired or was already used.", and links nothing** - click View as Client again. Afterwards one row serves both hosts: `Session::reset()` on either ends both.

```php
Portal_Session::begin_impersonation_from_staff($portal_user_id, $impersonator_user_id, $portal_site_id): string|false
Portal_Session::stop_impersonation(): void      // signs the view out; the portal pointer falls back; staff untouched
Portal_Session::is_impersonating(): bool
Portal_Session::get_impersonator_user_id(): ?int
```

Protocol detail: `rsx:man portal` (IMPERSONATION).

### READ-ONLY IS ENFORCED BY THE FRAMEWORK - DENY BY DEFAULT

Every Ajax call is a POST, reads included, so the verb cannot tell a write from a read. **While `Portal_Session::is_impersonating()`, a portal-realm Ajax endpoint runs only when it declares `#[Portal_Impersonation_Readable]`**; every other one is refused by `Ajax::execute` (`ERROR_UNAUTHORIZED`, "This is a read-only session; changes are disabled."), after the gates and before any endpoint code. A forgotten mark fails SAFE - the read is refused, never a write left open.

**1. Mark every portal READ endpoint.**

```php
#[Ajax_Endpoint]
#[Portal_Impersonation_Readable]
public static function list(Request $request, array $params = []) { ... }

#[Ajax_Endpoint]                  // a write: no mark - refused during View as Client
public static function reply(Request $request, array $params = []) { ... }
```

The framework marks its own portal-reachable reads (`Orm_Controller::fetch`, `Spa_Session_Controller::get_state`, the Realtime tokens, `File_Preview_Controller`, …). The seam covers Ajax endpoints only: a `#[Portal_Route]` page handling a POST guards itself on `Portal_Session::is_impersonating()`. The shipped app's marks: app skill `portal-app`.

**2. Show a read-only experience.** A persistent banner and disabled submit controls when `Rsx_Portal.is_impersonating()`, with an "Exit read-only view" link to the stop route. **The server check is the boundary; the JS is affordance only.**

Who may impersonate is the app's call.

---

## Part G - Accounts, invites and memberships

`Portal_User_Model` (framework-core, table `portal_users`, site-scoped) is the **account/identity**: `STATUS_*`, `can_login()`, `check_password()`, `set_password()`, `touch_last_login()`, `find_by_email($site_id, $email)`, plus `portal_fetch()`/`portal_can_read()` (own record only). Override it by EXTENDING its base - `class Portal_User_Model extends Portal_User_Model_Abstract` in `rsx/models/`, declaring only what changes (`get_printed_name()`, `get_view_profile_url()` and `portal_can_read()` are `#[Replaceable]` there; a copy of the framework file is refused at manifest build - `rsx:man class_override`). **Keep app concepts (CRM links, memberships) in separate app models**, never bolted onto the core model.

**The portal password check is `Portal_Auth::verify_credentials($site_id, $credentials, $record = true)`** - never `find_by_email()` + `check_password()` by hand. It is `RsxAuth::verify_credentials()`'s twin: `Login_Throttle::require_not_throttled()` first (throws `Auth_Throttled_Exception`), the lookup on exactly `$site_id`, unknown address and wrong password collapsed to null and recorded as `Portal_Login_History::record_failure()`, then `Auth_Password_Reset_Required_Exception` for a correct password on a portal user flagged `password_reset_required` (a row even under `record: false`), then a SUCCESS row when `$record`. It signs nobody in and does NOT ask `can_login()` - that question, and its wording, stay the login function's. With `record: false` (a step still owed) the function records its own miss and its own password-only sign-in. Every portal sign-in outcome - this door, `Rsx_Portal_Two_Factor`, `Rsx_Portal_Sso` - lands in **`Portal_Login_History`** (`Login_History`'s API and statuses over the system table `_portal_login_history`; read it with `get_history_for_user($portal_user_id)` / `list_page(['portal_user_id' => ...])`). Worked example: `system/app/RSpade/resource/reference_app/portal/auth/Portal_Login_Controller.php`; rsx:man portal, SIGNING IN.

**Onboarding is invite-only by default** - no open self-registration. Staff invite a contact, a single-use expiring code is emailed, the recipient sets a password. **The invitation proves email ownership, so accounts are created already verified** (`is_verified = true`) with no separate verification step. **An app adding open signup must verify the email itself.**

**Second factors, passkeys and federated sign-in are framework-owned in this realm too** - `Rsx_Portal_Two_Factor` (TOTP, passkeys, recovery codes, the challenge, passwordless sign-in; skill `rspade:two-factor`) and `Rsx_Portal_Sso` (off until `rsx.sso.portal_enabled`; skill `rspade:sso`). Each keeps its own table, so a staff credential or a staff Google link never signs anybody in here; both admit only a portal user `can_login()` accepts on the request's site, and both refuse enrollment and linking during "View as Client". A portal login with a factor becomes two-stage: park the destination with `Portal_Session::put_value()`, `Rsx_Portal_Two_Factor::begin_challenge()`, redirect to a page hosting `<Two_Factor_Challenge>` - never `set_portal_user_id()` first.

**Everything past the account model is the application's** - memberships, the client concept, what an invite grants, which screens exist. The shipped model is described by the app skill `portal-app`.

## Part H - Notifications (the framework-core activity primitive)

`App\RSpade\Core\Models\Portal_Notification_Model` is the bus portal features emit into - the dashboard "what's new" feed, announcements, request activity. **One row per recipient**: emitting to N users writes N rows, which is the natural shape for a per-user feed with read state. Site-scoped, and `portal_can_read()` is own-recipient (fail-closed).

**App-agnostic by design**: there is NO hard FK to application tables. The recipient is core (`portal_users`), and an app-specific subject is referenced **polymorphically** through the type-ref system (`subject_type` + `subject_id`). **Never FK the primitive to an app table.**

```php
Portal_Notification_Model::emit($portal_user_ids, string $type, array $opts = []);
//   $opts: subject_type (class basename), subject_id, payload (array), site_id
Portal_Notification_Model::feed($portal_user_id, ['limit' => 50, 'unread_only' => true, 'since' => $iso]);
Portal_Notification_Model::unread_count($portal_user_id);
Portal_Notification_Model::mark_read($portal_user_id, $id);      // ownership-guarded, idempotent
Portal_Notification_Model::mark_all_read($portal_user_id);
```

`emit()` works from a staff request (an announcement broadcast) or a portal request alike, because the row's own `site_id` scopes both the write and the realtime publish. Every read/mutate call takes the recipient id and scopes to it - a user can only ever see or change their own. The shipped `Portal_Notifications_Controller` **derives the recipient from the session and never accepts a `portal_user_id` from the client**; do the same in anything you add.

---

## Part I - Config and testing

`config('rsx.tenancy')`: `single` | `multi` (app config, no `.env` key). `config('rsx.portal.*')`: `enabled` (`PORTAL_ENABLED`), `url` (`PORTAL_URL`), `custom_domains` (`PORTAL_CUSTOM_DOMAINS`) - Part A - plus `invitation_expiry_days` (14), `password_min_length` (8), `password_reset_expiry_hours` (1). **There is no key for the portal's site**: it is read from the URL.

```bash
php artisan rsx:debug /dashboard --portal --portal-user=1
php artisan rsx:debug /_portal/acme/dashboard --portal --portal-user=client@x.com
```

`--portal` browses ONE site's portal at its own address (both hosts mapped to loopback): the site a path under a tenant's base names (`/_portal/acme/...`, base removed), else the `--portal-user`'s site, else the current one (site 1 in single tenancy); a path naming no served portal is refused. `--portal-user` takes an id or email; disabled in production. **The harness declares no site of its own** - the dispatcher reads it from the browsed URL, and a `--portal-user` belonging to a DIFFERENT site is refused loudly rather than signed in against that tenant.

In PHP tests (CLI), declare the site the request would have resolved:

```php
Portal_Session::set_site_id(self::SITE_ID);
Portal_Session::cli_set_portal_user_id($portal_user->id);   // 0 = signed out
// Both are cleared by the runner after every test method, so declare the site in
// each test - setup() runs once per CLASS and its declaration does not survive.
```

---

## Troubleshooting

- **The build fails "Invalid client portal address."** The message names the rule. "rsx.tenancy = multi requires PORTAL_URL to carry a tenant placeholder" - leave `PORTAL_URL` blank (`/_portal/:site_id`) or write `/_portal/:slug` / `https://:slug.myapp.com`. "carries a tenant placeholder ... but rsx.tenancy is single" - set `'tenancy' => 'multi'` in `rsx/resource/config/rsx.php` or drop the placeholder. "must END in /:slug or /:site_id, after at least one fixed segment" - `/:slug` and `https://portal.x.com/:slug` both lack the fixed segment. "whole first label" - `https://portal-:slug.x.com` is not a label. "collides with these staff routes" - pick a prefix no staff route uses, or move the routes.
- **"PORTAL_CUSTOM_DOMAINS is \"override\", but rsx.tenancy is single"** (build FATAL). Per-site portal domains are multi-tenant only; a single-tenant portal's domain is `PORTAL_URL`, and `sites.portal_domain` is ignored. Set `PORTAL_CUSTOM_DOMAINS=off`.
- **A sealed box refuses to serve after a portal setting changed.** `rsx.tenancy`, `PORTAL_ENABLED`, `PORTAL_URL`, `PORTAL_CUSTOM_DOMAINS` are in the build fingerprint: `php artisan rsx:build --force`.
- **A tenant's portal answers 404.** No site has that slug/id; the site is deleted, disabled or `is_portal_enabled` off; under `only` the address is a template address; or the slug is not a DNS label (host template). With `PORTAL_ENABLED=false` nothing is a portal.
- **An old portal address answers 301.** `PORTAL_CUSTOM_DOMAINS=override` and the site has a `portal_domain` - by design; sign-ins do not carry across hosts, and passkeys must be enrolled again on the new host.
- **"Portal_Session::set_site_id(...) is for commands, tasks and tests only."** A web request called it - remove the call; the framework declares the URL's site.
- **`Portal_Session::get_site_id()` throws.** In a web request: the code ran outside a resolved portal request. In CLI: the test or command declared no site - `Portal_Session::set_site_id()` first.
- **A staff endpoint answers "This site has no client portal."** `Rsx_Portal_Url::for_site()` / `Rsx_Portal::Route(..., site_id:)` answered false for the record's site - the feature, the site or its portal switch is off, or under `only` it has no domain.
- **A portal user is signed out when switching tenants in development.** Path templates share one cookie; a sign-in for another site ends (`SIGN_OUT_SITE_SWITCHED`).
- **A portal Ajax call is denied, or resolves staff data.** The request went to the bare `/_ajax/...` path, or the controller resolved to the staff realm. Use the generated stubs / `Rsx_Portal.internal_url()`, and declare `#[Auth_Realm('portal')]` on any portal controller outside a portal root.
- **An upload from a portal page 419s or hits the staff path.** It used `fetch('/_upload')`. Use `Ajax.upload(form_data)`.
- **A model returns "not found" to portal JS.** Either it has no `portal_fetch()` (add `Portal_Authorizable` + `portal_can_read()`), or `portal_can_read()` returned false - which is what it should do, fail-closed, for a record outside the user's memberships.
- **Staff signed out when a portal sign-in ended (or vice versa).** Something deleted or deactivated the browser's row instead of signing out one realm's user session. Use the facade; never write session rows by hand.
- **A value parked during a portal login is gone after the challenge.** It was written before `begin_challenge()`, whose sign-out cleared the portal realm. Write it after.
- **A screen fails to load during "View as Client"** with "This is a read-only session; changes are disabled." - an endpoint it calls is a read that lacks `#[Portal_Impersonation_Readable]`.

Details: `rsx:man portal`, `rsx:man portal_tenancy`. Related: `rspade:auth-gates`, `rspade:session-auth`, `rspade:model-fetch`, and the app skill `portal-app` (the shipped screens, layouts, membership model and read-only enforcement).
