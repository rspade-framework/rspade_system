# Concern: session

## Domain overview & applicability

Session management: the static `Session` facade that backs all authentication
and tenant context in RSpade. It resolves the current login identity, the
site-specific user, the active site (tenant), the portal sign-in, and
CSRF state. In web context it is cookie-backed: ONE `_sessions` row per browser,
pointing at the staff and portal USER SESSIONS (`_user_sessions`) signed in on it. A
CLI/test process runs a VIRTUAL session: identity is declared through static overrides
and nothing is ever written - no `_sessions`, `_user_sessions` or `_audit_sessions` row,
and `get_session_id()` answers null (a test whose subject belongs to a browser acts as one
with `__as_browser()` / `$acts_as_browser`). An API key has one row of its own and the
rsx:debug harness one row per account. Site-scoped models and audit
attribution read `Session::get_site_id()` / `get_user_id()`, so the correctness
of this subsystem underpins multi-tenant isolation across the whole framework.

Adjacent pieces covered here: `RsxAuth` (the credential check that classifies and
records every login outcome), `Login_History` (successful-login audit rows plus the
ephemeral per-email/per-IP failure counters, a readable statistic), `Login_Throttle`
(the framework's brute-force ENFORCEMENT - failures counted per client IP, a lockout,
and the thrown `Auth_Throttled_Exception` that `RsxAuth::verify_credentials()` raises before any
lookup) and `User_Agent` (device/browser parsing used by session listings).

## Source files

- `app/RSpade/Core/Session/Session.php` - the facade + the `_sessions` Eloquent model
- `app/RSpade/Commands/Rsx/Ajax_Debug_Command.php` - `rsx:ajax`, whose `--user`/`--site`
  establish a CLI identity through `Session::impersonate()` (`Ajax_Debug_Identity_Test`, with
  the fixture endpoint `Ajax_Debug_Identity_Fixture_Controller` beside it)
- `app/RSpade/Core/Session/User_Agent.php` - user-agent parsing
- `app/RSpade/Core/Session/Audit_Session.php` - the acting-context record and its readers
  (`describe()`, `join()`, `list_page_for_user_session()`)
- `app/RSpade/Core/Session/Login_History_Abstract.php` - the history store, written once for both realms;
  `Login_History.php` (staff, `_login_history`) and `Portal_Login_History.php` (portal,
  `_portal_login_history`) bind it
- `app/RSpade/Core/Auth/Auth_Password_Reset_Required_Exception.php` - the refusal
  `verify_credentials()` throws for a flagged identity (`password_reset_required`, cleared by
  `Login_User_Model_Abstract` / `Portal_User_Model_Abstract` booted() on a password change)
- `app/RSpade/Core/Auth/RsxAuth.php` - verify_credentials() / login() / logout() / has_enabled_membership()
- `app/RSpade/Core/Auth/Login_Throttle.php`, `Auth_Throttled_Exception.php` - the
  per-IP brute-force throttle (config `rsx.sessions.login_throttle`)
- `app/RSpade/Core/Models/Login_User_Model.php`, `User_Model.php`, `Site_Model.php`
- Cleanup: `app/RSpade/Core/Session/Session_Cleanup_Service.php` (scheduled task)
- `app/RSpade/Core/Session/Rsx_Session_Controller.php` (the SPA's page-view report)
- `app/RSpade/Core/Js/Rsx_Session.js` (page-view reporting, the lost-session announcement)

## Man page(s)

- `man/session.txt` (reconciled)

## Testable surface

- CLI impersonation: `set_site_id` / `set_login_user_id` / `impersonate` /
  `reset_impersonation`, the `get_*` resolvers, `is_logged_in`, `has_session`,
  CSRF accessors in CLI. (php - default isolation)
- The developer flag: `login_users.is_developer` on the initial user, its absence from
  an ordinary identity's `toArray()` payload, and `Session::is_developer()` reading it
  from the signed-in login identity. (php - default isolation)
- The virtual session: no row of any kind however the process signs in, out, switches
  site or records history; `_begin_virtual()` / `_end_virtual()`; values refused with no
  browser; the test-browser seam. (php - default isolation)
- The rotated-token refusal and the harness row reuse, over real HTTP (`http/`).
- Login history: success rows + queries, failures as ephemeral counters (no row),
  window-bounded failed-attempt counting, retention prune; the portal binding's rows, site,
  scope key and separation from the staff rows. (php)
- Password reset required: the staff refusal (thrown last, recorded as a row even under
  record: false) and the flag's clearing on both identity models. (php)
- Login throttle: the failure budget and the lockout it triggers, per-IP isolation,
  the enable switch, reset(), the exact refusal message and its retry_after_seconds,
  and the ruling that a caller with NO client IP is never throttled. The ambient-IP
  throw out of `RsxAuth::verify_credentials()` cannot be driven from CLI (there is no client IP
  there, by design), so that half is verified live over HTTP. (php + http)
- Credential attempts: the outcomes RsxAuth::verify_credentials() classifies and records
  (not-found incl. soft-deleted, wrong password, disabled membership, success), the
  `$record` opt-out, and malformed input recording nothing. The `$touch_last_login` flag is
  web-only - the CLI branch of `Session::set_login_user_id()` returns before the stamp - so
  it is verified live over HTTP rather than in php. (php + http)
- Site membership (`users.is_enabled` + `sites.is_enabled`), the framework's own switches, in both places it is
  enforced: at sign-in (`has_enabled_membership()`, `verify_credentials()` failing exactly like a wrong
  password with `STATUS_FAILED_DISABLED` recorded, and `login()` returning false without
  touching the session - which is what closes the second-factor, federated and dev-auth
  doors) and at request time (`Session::enforce_enabled_membership()` logging out a session
  whose membership was disabled or deleted). The site switch (`sites.is_enabled`) is the
  second half of the same definition (`User_Model::is_active()` / `->active()`): a membership
  on a disabled or deleted site is refused at sign-in and ends at request time, other sites
  are unaffected, and the Default site (id 0) refuses to be disabled. The end-to-end transport answers - a login
  redirect for a page, the `auth_required` envelope for an Ajax call - are deferred: the http
  harness runs against the development database and must not disable a live account there.
  (php)
- Session termination: the self-only `terminate_session()`, the GUARDED cross-user
  primitives (`terminate_session_for_user` / `terminate_all_sessions_for_user`, whose
  authorization is self-or-`can_admin_role` and whose REFUSAL THROWS while ABSENCE
  returns false/0), the unchecked framework-internal `_deactivate_sessions_for_user()`,
  and the `session.terminated` event fired once per deactivated row. The realtime
  refresh push each path emits has no in-process assertion seam and is verified live.
  (php)
- The password-change sign-out (`Session::_end_sessions_for_password_change()`, called from
  `Login_User_Model_Abstract` / `Portal_User_Model_Abstract` `booted()`): a saved new password
  ends the identity's other web sign-ins with `SIGN_OUT_PASSWORD_CHANGED`, sparing the
  changing browser (every one ends with no browser) and never touching an API key's
  session; staff and portal. (php)
- User-agent parsing: browser/OS/device detection, automation detection,
  summary formatting. (php - pure, no DB)
- Session token lookup + expired-session cleanup. (php - commits, needs reset)
- Web-mode session creation, cookie round-trip, token regeneration, CSRF against
  a stored token, multi-device session management. (http - live server)

### Session lifetime, sign-in rotation and user sessions

- http (`session_sign_in_rotation.sh`): one browser row throughout; a sign-in and a sign-out
  rotate its tokens in place; the just-replaced token is refused for Ajax and sub-resources
  and is a guest for a page navigation; an older token is a stranger; signing out stamps the
  user session and clears the browser's staff values (its guest value stays); A/B/A on one
  browser reuses A's user session (sign_in_count 2) and replaces B's.
- php (`Session_User_Sessions_Test`): the engine those flows are built from - find-or-create
  on the unique tuple, the migration's tuple hash, the first-sign-in stamp on the browser,
  per-realm value clearing (a realm's sign-out or replacement clears that realm's values),
  impersonation suspension and its return, the riding-impersonation rule.
- http (`session_lifetime.sh`): the two limits against real user sessions whose clocks were
  moved back - page navigation vs. the Ajax grace, the session_expired envelope, the
  page-view report, last_seen, what does and does not count as a page view, the once-a-minute
  last_active write and the 30-day unused-browser retirement at lookup.
- playwright (`session_expired.js`): the client announces a lost sign-in once per page to the
  registered handler (or reloads), and reports SPA page views at most once a minute.
- php (`Session_Cleanup_Test`): only guest browsers (`first_signed_in_at` null) are
  collected; a browser with a sign-in, current or ended, is kept; the hourly sweep signs out
  the current sign-ins past a limit plus the Ajax grace (per realm, the earlier expiry's
  reason, never a sign-in suspended behind an impersonation); the daily maintenance step
  makes browsers unused past `unused_session_days` inactive (never an API key's row), signing
  out what is still signed in on them (`SIGN_OUT_BROWSER_UNUSED`).
- php (`Audit_Session_List_Page_Test`): `Audit_Session::list_page_for_user_session()`, the
  display-page reader the session screens page a user session's audit sessions through -
  scope, order, published sorts, the address/agent filter, validation.
- php (`Session_Values_Test`): the value store - reads never mint, expiry at read time, and
  the three realms (staff, portal, guest) as separate namespaces, null = the request's
  experience.

## Documents

- `test_catalog.md` - full catalog (implemented + deferred).
