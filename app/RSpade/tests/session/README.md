# Concern: session

## Domain overview & applicability

Session management: the static `Session` facade that backs all authentication
and tenant context in RSpade. It resolves the current login identity, the
site-specific user, the active site (tenant), the portal properties, and
CSRF state. In web context it is cookie-backed. In CLI/test context IDENTITY is
declared through static overrides (creating nothing), while a SESSION row is
minted on demand: the first `get_session_id()` creates a real `TYPE_CLI`
`_sessions` row for the process, held for its lifetime and deleted when it
ends. Site-scoped models and audit
attribution read `Session::get_site_id()` / `get_user_id()`, so the correctness
of this subsystem underpins multi-tenant isolation across the whole framework.

Adjacent pieces covered here: `RsxAuth` (the credential check that classifies and
records every login outcome), `Login_History` (successful-login audit rows plus the
ephemeral per-email/per-IP failure counters, a readable statistic), `Login_Throttle`
(the framework's brute-force ENFORCEMENT - failures counted per client IP, a lockout,
and the thrown `Auth_Throttled_Exception` that `RsxAuth::attempt()` raises before any
lookup) and `User_Agent` (device/browser parsing used by session listings).

## Source files

- `app/RSpade/Core/Session/Session.php` - the facade + the `_sessions` Eloquent model
- `app/RSpade/Commands/Rsx/Ajax_Debug_Command.php` - `rsx:ajax`, whose `--user`/`--site`
  establish a CLI identity through `Session::impersonate()` (`Ajax_Debug_Identity_Test`, with
  the fixture endpoint `Ajax_Debug_Identity_Fixture_Controller` beside it)
- `app/RSpade/Core/Session/User_Agent.php` - user-agent parsing
- `app/RSpade/Core/Session/Login_History.php` (and `_login_history` table)
- `app/RSpade/Core/Auth/RsxAuth.php` - attempt() / login() / logout() / has_enabled_membership()
- `app/RSpade/Core/Auth/Login_Throttle.php`, `Auth_Throttled_Exception.php` - the
  per-IP brute-force throttle (config `rsx.sessions.login_throttle`)
- `app/RSpade/Core/Models/Login_User_Model.php`, `User_Model.php`, `Site_Model.php`
- Cleanup: `app/RSpade/Core/Session/Session_Cleanup_Service.php` (scheduled task)

## Man page(s)

- `man/session.txt` (reconciled)

## Testable surface

- CLI impersonation: `set_site_id` / `set_login_user_id` / `impersonate` /
  `reset_impersonation`, the `get_*` resolvers, `is_logged_in`, `has_session`,
  CSRF accessors in CLI. (php - default isolation)
- The developer flag: `login_users.is_developer` on the initial user, its absence from
  an ordinary identity's `toArray()` payload, and `Session::is_developer()` reading it
  from the signed-in login identity. (php - default isolation)
- The CLI session ROW: on-demand minting, the TYPE_CLI stamp, laziness (nothing
  minted until demanded), identity syncing onto the row, and end-of-process
  deletion. (php - default isolation)
- Login history: success rows + queries, failures as ephemeral counters (no row),
  window-bounded failed-attempt counting, retention prune. (php)
- Login throttle: the failure budget and the lockout it triggers, per-IP isolation,
  the enable switch, reset(), the exact refusal message and its retry_after_seconds,
  and the ruling that a caller with NO client IP is never throttled. The ambient-IP
  throw out of `RsxAuth::attempt()` cannot be driven from CLI (there is no client IP
  there, by design), so that half is verified live over HTTP. (php + http)
- Credential attempts: the outcomes RsxAuth::attempt() classifies and records
  (not-found incl. soft-deleted, wrong password, disabled membership, success), the
  `$record` opt-out, and malformed input recording nothing. The `$touch_last_login` flag is
  web-only - the CLI branch of `Session::set_login_user_id()` returns before the stamp - so
  it is verified live over HTTP rather than in php. (php + http)
- Site membership (`users.is_enabled`), the framework's own switch, in both places it is
  enforced: at sign-in (`has_enabled_membership()`, `attempt()` failing exactly like a wrong
  password with `STATUS_FAILED_DISABLED` recorded, and `login()` returning false without
  touching the session - which is what closes the second-factor, federated and dev-auth
  doors) and at request time (`Session::enforce_enabled_membership()` logging out a session
  whose membership was disabled or deleted). The end-to-end transport answers - a login
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
- User-agent parsing: browser/OS/device detection, automation detection,
  summary formatting. (php - pure, no DB)
- Session token lookup + expired-session cleanup. (php - commits, needs reset)
- Web-mode session creation, cookie round-trip, token regeneration, CSRF against
  a stored token, multi-device session management. (http - live server)

## Documents

- `test_catalog.md` - full catalog (implemented + deferred).
