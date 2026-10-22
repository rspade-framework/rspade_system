# Core/Portal — the framework side of the client portal

The portal is a **second authenticated experience** running parallel to the staff
app, with its own route table, session facade and auth realm. It is dispatched by the ONE
page pipeline, `Dispatch/Dispatcher`, in the portal realm. This directory is the framework half;
the application half lives in `rsx/portal/` (which has its own CLAUDE.md).

- `Rsx_Portal.php` — the facade. `is_portal_request()` answers which experience a
  request is - it reads the realm `Dispatch/Rsx_Request_Channel` classified once per
  request (`set_portal_request()` declares it in CLI/tests); `Route()`, `internal_url()`,
  `strip_prefix()`.
- `Portal_Session.php` — a static FACADE over the portal-property subset of the
  session row. **Not a model** — no `::where()`, no instances.
- `Portal_Route_ManifestSupport.php` / `Portal_Spa_ManifestSupport.php` —
  `#[Portal_Route]` and `@portal_spa` indexing. Portal routes live in a
  **separate manifest table** from staff `#[Route]`s.
- `Rsx_Portal_Url.php` — the portal's address: `PORTAL_URL` parsed as a TEMPLATE
  (`:slug` / `:site_id` placeholder in multi tenancy), `check()`, the `PORTAL_ENABLED` /
  `PORTAL_CUSTOM_DOMAINS` switches and the settings `fingerprint()` the manifest stamps.
  `for_site(?int)` is ONE site's address (custom domain, else the filled template) or
  FALSE when it has no portal; null = the current site (a portal request's resolved site,
  else `Session::get_site_id()`; single tenancy site 1). `origin()`/`prefix()`/`host()`/
  `is_separate_host()` answer for the current site and throw without one;
  `client_payload()` is `window.rsxapp.portal`. `Rsx_Portal::Route()`/`portal_path()` take
  `site_id:` and answer false; inside a portal request they use the request's base.
- `Portal_Site_Resolver.php` — which site a portal request serves, read from its host and
  path (custom domain, then the template); the verdict (served / 301 / not served); cached
  site lookups forgotten by `Site_Model`'s hooks; the host patterns both hostname guards
  accept.
- `Portal_Url_ManifestSupport.php` — judges the address at MANIFEST BUILD (every mode):
  `check()` plus the fixed prefix's collision with staff routes (`find_route_conflicts()`).
  Nothing about `PORTAL_URL` is validated per request.
- `Portal_Main_Abstract.php` — base for the app's `rsx/portal_main.php`.
- `Portal_Permission_Abstract.php` — marker base for the app's
  `Portal_Permission` facade.
- `Portal_Authorizable.php` — the trait supplying the gated `portal_fetch()`.

## Invariants worth stating out loud

- **ONE session per browser.** One session cookie, one `_sessions` row, shared by
  the staff app and the portal. The row points at the browser's current staff
  sign-in and current portal sign-in, each a `_user_sessions` row; the EXPERIENCE
  is a property of the REQUEST. Both signed in at once is NORMAL. Never mix the
  two facades, and never delete or deactivate the row on portal logout — sign
  the portal user session out (`Session::_sign_out_current('portal', ...)`), which
  clears the portal pointer and the portal realm's session values.
- **Identity is not experience.** App code that must fork forks on
  `Rsx_Portal::is_portal_request()`, never on `is_logged_in()`.
- **The URL is the authority for the portal's site.** `Portal_Site_Resolver` reads it
  from the request (site 1 in single tenancy; the `:slug` / `:site_id` / custom domain in
  multi), `Rsx_Request_Channel` classifies with it, the front controller answers the 301 /
  404 verdicts, and `Dispatcher` declares the site per request
  (`Portal_Session::_declare_resolved_site()`) before `Portal_Main::init()` - signing out a
  portal sign-in of another site. The site is never stored. `set_site_id()` is CLI-only
  (commands, tasks, tests) and throws in a web request. `get_site_id()` throws when nothing
  declared one.
- **Realms are separate namespaces.** `is_logged_in` exists in both and means
  different things; a cross-realm check name is a build error. At the shared
  transports (Ajax, ORM, upload) a realm mismatch DENIES before any check name
  resolves.
- Framework surfaces serving both experiences (flash alerts, CSRF, ORM fetch)
  resolve the experience themselves. **Never hand-roll a portal variant** of one.
- View as Client is READ-ONLY, enforced by the framework deny-by-default: a portal Ajax
  endpoint runs during it only when marked `#[Portal_Impersonation_Readable]`. The app's
  job is marking its reads and showing a read-only experience.

## Pointers

`rsx:man portal` · `rsx:man portal_tenancy` · `rsx:man auth_gates` · `rsx:man session` ·
skill `rspade:portal-core` · app side: app skill `portal-app`, `rsx/portal/CLAUDE.md`
