# Test catalog: flash

Status legend: `implemented` | `deferred` (reason) | `blocked` | `planned`.

## Flash_Alert_Cap_Test (php, default isolation) - per-session alert cap

The cap lives on the WRITER because the reader hands the whole set to the browser and
deletes it - a LIMIT on the read would silently drop alerts. It is keyed on
(session_id, is_portal): a shared cap would let a runaway portal page evict the staff
alerts queued on the same browser session.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| flash-cap-01 | trims a runaway session to the limit | 12 alerts, cap 5 | 5 remain | implemented |
| flash-cap-02 | keeps the NEWEST, drops the oldest | 6 alerts, cap 3 | the last 3 ids survive | implemented |
| flash-cap-03 | no trim when under the cap | 4 alerts, cap 50 | 4 remain | implemented |
| flash-cap-04..05 | 0 / null disable the cap | 9 alerts | 9 remain | implemented |
| flash-cap-06 | never reaches another session | two sessions, cap 2 | acting session trimmed, other untouched | implemented |
| flash-cap-07 | the cap is scoped per EXPERIENCE | 6 portal + 4 staff on ONE session, cap 2 | portal trimmed to 2, the staff alerts beside it untouched | implemented |

## Flash_Alert_Realm_Test (php, default isolation) - session + experience scoping

TWO predicates, because they answer two different questions. One browser has ONE session
(one `rsx` cookie, one row) shared by both experiences, so `session_id` keeps ANOTHER
BROWSER's alerts out and can say nothing about which experience queued a row;
`_flash_alerts.is_portal` (stamped on write from `Rsx_Portal::is_portal_request()`,
filtered on read) is what keeps the OTHER EXPERIENCE's alerts out.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| flash-realm-01 | a portal-context write mints exactly ONE session - the browser's | `set_portal_request(true)` + `_resolve_session_id()` | `_sessions` count +1, equal to `Session::get_session_id()` | implemented |
| flash-realm-02 | the staff branch still resolves the staff session | `set_portal_request(false)` | a real id, equal to `Session::get_session_id()` | implemented |
| flash-realm-03 | a read never sees ANOTHER BROWSER's alerts | one alert per session | own message only; the other row still queued | implemented |
| flash-realm-04 | a read consumes only its own session's alerts | one alert per session | own message only; the other row still queued | implemented |
| flash-realm-05 | one-time delivery | 2 alerts, read twice | 2 then 0 | implemented |
| flash-realm-06 | read-path expiry sweep (1 minute) | one 5-min-old + one fresh | only the fresh one delivered | implemented |
| flash-realm-07 | the cap applies per session | 6 on one session + 1 on another, cap 2 | trimmed to 2, other session untouched | implemented |
| flash-realm-08 | two browser sessions hold distinct ids | two sessions | ids differ | implemented |
| flash-realm-09 | both facades resolve the SAME session id | staff then portal `_resolve_session_id()` | equal - which is why is_portal exists | implemented |
| flash-realm-10 | a STAFF read never sees a portal-queued alert on the same session | one staff + one portal alert, same session | staff message only; the portal row still queued | implemented |
| flash-realm-11 | a PORTAL read never sees a staff-queued alert on the same session | same fixture, portal read | portal message only; the staff row still queued | implemented |
| flash-realm-12 | the 1-minute expiry sweep leaves the other experience alone | stale portal + fresh staff, staff read | the stale portal row survives (only the hourly task is experience-blind) | implemented |
| flash-realm-13 | the writer stamps the REQUEST's experience | portal request, then read both ways | staff read empty; portal read delivers it | implemented |

## Flash_Alert_Cli_Test (php, default isolation) - the console contract

Owner ruling 2026-08-09: no session, no DB read/write; STDERR; warning/error also log.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| flash-cli-01 | the writers persist nothing | all four levels | `_flash_alerts` count unchanged | implemented |
| flash-cli-02 | the writers mint no session | success + error | `_sessions` count unchanged, facade not activated | implemented |
| flash-cli-03 | the read is empty and queries nothing | `get_pending_messages()` | `[]` | implemented |
| flash-cli-04 | error/warning reach the Laravel log | error + warning | `Log::error` / `Log::warning` | implemented |
| flash-cli-05 | success/info are terminal-only | success + info | nothing logged | implemented |
| flash-cli-06 | console line format | all four levels | `[FLASH:LEVEL] message`, ASCII | implemented |

Note: the STDERR write itself is not asserted in-process (`fwrite(STDERR, ...)` goes to
the runner's terminal, where it is visible during a run); the line it emits is asserted
through the pure formatter that produces it.

## Flash_Alert_Sweep_Test (php, default isolation) - hourly retention

The read-path expiry is session-scoped and misses abandoned sessions; this is the
unconditional 30-minute age rule.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| flash-sweep-01 | rows past the window are deleted | 45-minute-old alert | deleted, `retention_minutes` 30 | implemented |
| flash-sweep-02 | fresh rows are kept | 2-minute-old alert | survives | implemented |
| flash-sweep-03 | every session is swept | stale + fresh rows across sessions | both stale gone, fresh kept (the sweep is age-based and experience-blind by design) | implemented |
| flash-sweep-04 | the chunk loop drains the backlog | 5 stale rows, chunk_size 2 | all deleted | implemented |

## flash_portal_login_cookie.sh (http) - the realm fix, full stack

The PHP realm tests reach `_resolve_session_id()` through a protected seam because a PHP
test IS a console process, where the public writers deliberately print instead of
persisting. This is the same fix seen through a real browser transaction: a REAL portal
form login (the only way to reproduce the original bug - `rsx:debug --portal` installs the
session directly and never runs the login controller).

Self-seeding: it upserts its own portal account via `php artisan tinker` rather than
depending on a hand-made dev row.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| flash-http-00 | fixture account exists and can log in | tinker upsert of `flash-http-fixture@rspade.test` | `SEEDED:<id>` | implemented |
| flash-http-01 | the login response sets the ONE `rsx` cookie | POST `<prefix>/login` | 302, `rsx=` present, no `rsx_portal=` Set-Cookie | implemented |
| flash-http-02 | the flash READ mints no staff session either | GET the portal landing page | no `rsx=` Set-Cookie (`get_pending_messages()` is the other staff-facade call site) | implemented |
| flash-http-03 | the alert actually arrives | same page body | contains "Welcome to the Client Portal" - proving it was queued against the PORTAL session, not dropped | implemented |
| flash-http-04 | the jar holds exactly one session cookie | cookie jar after the flow | bare `rsx` present, `rsx_portal` absent | implemented |
| flash-http-05 | portal Ajax still passes the csrf seam | POST `<prefix>/_ajax/...` with `window.rsxapp.csrf` | no "CSRF token mismatch" - the exact brick the CR reported | implemented |

Override `PORTAL_PREFIX` for a deployment that repointed `rsx.portal.prefix` (a portal
DOMAIN deployment would use `""`).

## Deferred

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| flash-02 | client queue persistence across navigation (sessionStorage) | playwright | browser-level behavior | deferred |
