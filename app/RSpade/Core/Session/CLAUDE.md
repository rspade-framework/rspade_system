# Core/Session — browsers, sign-ins and the audit trail of who acted

**Using sessions from application code? Read `rsx:man session` (BASIC, then ADVANCED) and
skills `rspade:session-auth` / `rspade:audit-session`.** This file says what is in this
DIRECTORY, why it is shaped this way, and what must stay true when editing it.

## The model in one paragraph

A `_sessions` row is a **BROWSER** (one cookie jar), never an authentication realm. It holds
the cookie and CSRF tokens, the session values, and two POINTERS: the staff user session and
the portal user session currently signed in on it - both set at once is normal. A **user
session** (`_user_sessions`) is "where you are signed in": browser x realm x account x site x
impersonator, immutable in identity, reused when the same account signs in again, stamped
(never deleted) on sign-out. An **audit session** (`_audit_sessions`) is the durable ACTING
CONTEXT a record points at: user session x address x user agent x site x display zone,
minted only when something asks for it, never garbage-collected. Three ids, three jobs: the
browser id for browser-scoped state, the user session for "where am I signed in", the audit
session for "who did this, from where".

## What is here

- `Session.php` — the static facade (`Session::`) AND the Eloquent model over `_sessions`.
  Readers create nothing (`has_session()`, `is_logged_in()`, `get_user()`, `get_site_id()`,
  `is_developer()`); `get_session_id()` activates a guest's row through the ONE activation
  path (`__activate()`). Sign-in/sign-out rotate the session and CSRF tokens in place
  (`__rotate_tokens()`; a request still carrying the replaced token is refused, not
  re-minted). Realm-tagged short-lived values (`put_value()` / `get_value()` /
  `forget_value()`). Device-session listing and termination (`get_sessions_for_user()`,
  `terminate_session()`, `terminate_all_other_sessions()`). Impersonation
  (`begin_impersonation()` / `stop_impersonation()`). Tenant declaration for a task
  (`set_temporary_site_id()`). `enforce_enabled_membership()` ends a session whose membership
  or site was disabled. **Every site setter** (`set_site_id()`, `set_temporary_site_id()`,
  `clear_temporary_site_id()`, `impersonate()`, `reset_impersonation()`) first calls
  `Site_Lock::_assert_site_change_permitted()`: refused inside a request handler window and
  under any site lock or bypass; `RsxAuth::enter_site()` (`Site_Lock::_enter_site()`) is the
  one sanctioned change inside a handler (`rsx:man site_locks`, SITE CHANGES). **Virtual sessions** (`_begin_virtual()`): a command, a task or a test
  runs on a row-less session, so `get_session_id()` is null there. `Portal_Session`
  (`Lib/Portal/`) is a facade over the portal pointer through the `_*_portal_*` seams at the
  bottom of this class.
- `Rsx_Session_Cookie.php` — the ONE cookie's name and attributes: `__Host-rsx` whenever
  Secure, plain `rsx` only on a development request over http; the two are never read
  interchangeably.
- `Rsx_Csrf.php` — server-side CSRF at the dispatcher seam: Origin/Referer on every POST,
  the synchronizer token on every session-bearing POST. One token for both experiences.
- `Audit_Session.php` — the acting-context rows: `current_id()`, `describe()`, `join()` /
  `column()` for a datagrid, `_declare()` for a `#[Run_As_Dispatcher]` task. A command or
  plain task has none.
- `Ip_Address.php`, `User_Agent.php` — every recorded address / user-agent string stored once
  and referenced by id (`describe()`, `join()`); rows are never deleted.
- `Login_History_Abstract.php` + `Login_History.php` (staff, `_login_history`) +
  `Portal_Login_History.php` (portal) — sign-in OUTCOMES, one API for both realms. Two stores
  on purpose: rows only for outcomes a stranger cannot cause, ephemeral counters for failures
  (which feed `Core/Auth/Login_Throttle`).
- `Site_Sign_In_History.php` — a staff sign-in ENTERING A SITE (`_site_sign_ins`), written by
  the framework where a session gets its site. The identity history has no site; this is
  what a site's own screens read.
- `Session_Link.php` + `Session_Link_Controller.php` — the three-GET-leg handshake that lets
  a portal on its own host share the staff browser's `_sessions` row for View as Client.
- `Rsx_Signed_Url.php` — HMAC binding a one-time code to its purpose and host (key derived
  per purpose from `app.key`); not a replay defence - the code is the single-use part.
- `Rsx_Session_Controller.php` — the browser's page-view report (`Rsx_Session.report_page_view()`:
  SPA dispatches, hash changes from a person's input, the app's own calls), which
  `max_idle_minutes` is measured from.
- **Sign-in limits have ONE reader, `Session::get_sign_in_limits($realm)`**: the
  `session.limits` resolve hook (validated, memoized per realm per request or task run -
  cleared by `_end_virtual()` and `_testing_reset()`), else `config('rsx.sessions.<realm>')`
  per key. The request check (`__expiry_of()`), the sweep (`_end_timed_out_user_sessions()`)
  and harness-row reuse all go through it; never read the limit keys from config anywhere
  else, or the request check and the sweep can disagree.
- `Session_Cleanup_Service.php` — the retention sweeps (guest rows, lifetime limits, unused
  browsers made inactive, login history, link codes, trusted devices, stale impersonations,
  user agents). A browser that ever had a sign-in is never deleted, only made inactive.
- `Session_Values_Cleanup_Service.php` — reclaims expired `_session_values`; expiry itself is
  enforced at read time, so a missed run is harmless.

## Invariants

- **Every `_` table here is private.** Application code reads them only through these
  classes' published methods (`rsx:man database_schema_architecture`).
- **Readers never mint a row.** Only `get_session_id()` and the writers activate a session;
  CSRF and `has_session()` must stay read-only so a token-less anonymous caller gets none.
- **Nothing about a past sign-in is deleted.** Sign-out stamps; the sweeps make browsers
  inactive; audit sessions, addresses and user agents are permanent.
- The session token is a credential: never stored outside its hash, never in a URL.

## References

`rsx:man session`, `rsx:man csrf`, `rsx:man login_redirect` · skills `rspade:session-auth`,
`rspade:audit-session`, `rspade:two-factor` · siblings `Lib/Portal/` (`Portal_Session`,
`Portal_Site_Resolver`), `Core/Auth/` (`RsxAuth`, `Login_Throttle`), `Lib/SignIn/TwoFactor/`.
