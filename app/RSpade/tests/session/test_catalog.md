# Test catalog: session

Status legend: `implemented` | `deferred` (reason) | `blocked` (see issues) | `planned`.
Type: php / cli / asset / http / playwright. Last updated: 2026-08-30.

## Session_Cli_Test (php, default isolation) - CLI impersonation & resolvers

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-cli-01 | initial user_id null | fresh CLI | get_user_id()===null | implemented |
| sess-cli-02 | initial site_id zero | fresh CLI | get_site_id()===0 | implemented |
| sess-cli-03 | initial not logged in | fresh CLI | is_logged_in()===false | implemented |
| sess-cli-04 | initial no session | fresh CLI | has_session()===false | implemented |
| sess-cli-05 | set_site_id round-trips | set_site_id(N) | get_site_id()===N | implemented |
| sess-cli-06 | has_session after site set | set_site_id(N) | has_session()===true | implemented |
| sess-cli-07 | set_login_user_id round-trips | set_login_user_id(N) | get_login_user_id()===N | implemented |
| sess-cli-08 | login => logged in | set_login_user_id(N) | is_logged_in()===true | implemented |
| sess-cli-09 | logout clears login | logout() | get_login_user_id() null | implemented |
| sess-cli-10 | set null clears user cache | set_login_user_id(null) | user cleared | implemented |
| sess-cli-11 | impersonate sets 3 values | impersonate(s,l,u) | site/login/user all set | implemented |
| sess-cli-12 | impersonate => logged in | impersonate(...) | is_logged_in()===true | implemented |
| sess-cli-13 | reset_impersonation clears | reset | all back to null/0 | implemented |
| sess-cli-17 | __acting_as_site sets site | helper | get_site_id() matches | implemented |
| sess-cli-18 | __reset_session clears site | helper | site===0 | implemented |
| sess-cli-19 | get_user null w/o login id | no login | get_user()===null | implemented |
| sess-cli-20 | get_user null w/o site | login only | get_user()===null | implemented |
| sess-cli-21 | get_login_user null when out | logged out | null | implemented |
| sess-cli-22 | get_site null when site 0 | site 0 | get_site()===null | implemented |
| sess-cli-23 | csrf null in CLI w/o session | CLI | get_csrf_token() null | implemented |
| sess-cli-24 | verify_csrf false w/o session | CLI | false | implemented |
| sess-cli-25 | has_session via impersonate user | impersonate user | has_session true | implemented |
| sess-cli-26 | has_session false if only login id | login id only | has_session false (documents quirk) | implemented |

## Session_Api_Access_Test (php, default isolation) - Session::has_api_access()

The predicate every API seam asks. Two properties: it answers from
users.is_api_access_enabled, and asking it CREATES NOTHING - a question about an
identity must never mint a session row for a caller who has none.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-apiacc-01 | true when the column is set | user 1, flag on | has_api_access() true | implemented |
| sess-apiacc-02 | false when the column is clear | user 1, flag off | has_api_access() false | implemented |
| sess-apiacc-03 | false with no identity | reset CLI state | has_api_access() false | implemented |
| sess-apiacc-04 | the headless Bearer tier is consulted | _set_api_identity, flag on | has_session() false, has_api_access() true | implemented |
| sess-apiacc-05 | asking mints no session row | no identity | _sessions count unchanged, has_session() still false | implemented |
| sess-apiacc-06 | asking with a declared identity mints no row | CLI identity | _sessions count unchanged | implemented |

## Session_Cli_Row_Test (php, default isolation) - the CLI session ROW

get_session_id() yields a real session in every mode: a CLI process mints a TYPE_CLI
_sessions row on first demand, holds it for the process, and deletes it at process
end. Complements Session_Cli_Test, which covers the identity overrides that mint
nothing.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-clirow-01 | first get_session_id() mints a real row | fresh CLI | row exists, TYPE_CLI, ip/ua 'CLI', active, tokens minted | implemented |
| sess-clirow-02 | the process holds ONE session | two calls | same id, one row | implemented |
| sess-clirow-03 | get_session() returns that same row | mint then get_session() | ids match | implemented |
| sess-clirow-04 | has_session() true once minted | mint | false before, true after | implemented |
| sess-clirow-05 | csrf comes from the minted row | mint | get_csrf_token()/verify agree with the row | implemented |
| sess-clirow-06 | the getters mint nothing | get_site_id/get_login_user_id/... | TYPE_CLI row count unchanged | implemented |
| sess-clirow-07 | the identity setters mint nothing | set_site_id/set_login_user_id/impersonate | TYPE_CLI row count unchanged | implemented |
| sess-clirow-08 | set_login_user_id lands on the row | mint then login | row.login_user_id set | implemented |
| sess-clirow-09 | logout clears the login on the row | mint, login, logout | row.login_user_id null | implemented |
| sess-clirow-10 | set_site_id lands on the row | mint then set_site_id | row.site_id set | implemented |
| sess-clirow-11 | identity declared BEFORE the demand seeds the row | impersonate then mint | site + login on the new row | implemented |
| sess-clirow-12 | end-of-process cleanup deletes the row | _cli_end_session() | row gone, handle forgotten | implemented |
| sess-clirow-13 | cleanup is a no-op when nothing was minted | _cli_end_session() cold | row count unchanged | implemented |
| sess-clirow-14 | a later demand mints a FRESH session | end then demand | different id, new row | implemented |
| sess-clirow-15 | reset_impersonation drops the row | mint then reset_impersonation | row gone | implemented |
| sess-clirow-16 | CLI reset deletes rather than deactivates | mint then reset() | row gone (not active=0) | implemented |
| sess-clirow-17 | the shutdown hook actually fires at process exit | real process exit | row absent after exit | deferred (proven by live CLI run; a test cannot exit its own process) |

## Session_Cache_Key_Test (php, no DB) - Rsx_Storage scope key recipe

Locks Rsx_Bundle_Abstract::_compute_cache_key (window.rsxapp.cache_key), the
server-side md5 that replaces the client-side ingredient join Rsx.scope_key() used
to do. Recipe = md5 of the '_'-joined truthy ingredients (session_hash / user id /
site id / build_key), empty parts skipped.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-ck-01 | all ingredients present locks exact md5 | (sesshash,42,1,buildkey123) | md5('sesshash_42_1_buildkey123') | implemented |
| sess-ck-02 | null session_hash skipped (not joined empty) | (null,42,1,buildkey123) | md5('42_1_buildkey123') | implemented |
| sess-ck-03 | recipe == md5 of independent underscore-join | (abc123,7,3,v9) | md5('abc123_7_3_v9') | implemented |
| sess-ck-04 | anonymous: empty user/site skipped | (onlyhash,null,null,buildX) | md5('onlyhash_buildX') | implemented |
| sess-ck-05 | returns 32-char lowercase hex | any | /^[0-9a-f]{32}$/ | implemented |
| sess-ck-06 | different users same session => different keys | user 100 vs 200 | keys differ (invalidation property) | implemented |
| sess-ck-07 | different build_keys => different keys | build_old vs build_new | keys differ | implemented |
| sess-ck-08 | different sites => different keys | site 1 vs 2 | keys differ | implemented |

## User_Agent_Test (php, no DB) - device/browser parsing

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-ua-01 | parse returns required keys | UA string | keys present | implemented |
| sess-ua-02 | null UA => unknown | null | "unknown" fields | implemented |
| sess-ua-03 | empty UA => unknown | "" | "unknown" | implemented |
| sess-ua-04..08 | browser detect chrome/firefox/edge/safari; chrome not safari | UA strings | correct browser | implemented |
| sess-ua-09..12 | OS detect windows/macos/ios/android | UA strings | correct OS | implemented |
| sess-ua-13..15 | device detect desktop/mobile-iphone/tablet-ipad | UA strings | correct device | implemented |
| sess-ua-16 | summary format "Browser on OS" | UA | formatted summary | implemented |
| sess-ua-17..18 | get_summary matches parse; null=>unknown | UA/null | consistent | implemented |
| sess-ua-19..23 | is_automated: headless chrome, playwright true; normal chrome/null/empty false | UA strings | correct bool | implemented |

## Login_History_Test (php, default isolation) - login audit + lockout

Successes are database rows; failures are ephemeral redis counters (2026-08-12 CR), so the
failure tests use unique emails, read the shared per-IP counter as a delta ('CLI' in tests),
and delete their keys in teardown.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-lh-01 | status constants defined | - | constants exist | implemented |
| sess-lh-02..04 | record_success inserts row, sets success status, stores login_user_id | success record | row + fields | implemented |
| sess-lh-05 | record_failure writes NO database row (the unauthenticated-INSERT vector is gone) | one failure | table count unchanged, no row findable by email | implemented |
| sess-lh-06 | no status produces a row - the vocabulary describes the LOG line, not a column | NOT_FOUND + LOCKED(+reason) + 2FA(+user id) | count unchanged, counter = 3 | implemented |
| sess-lh-07 | record_failure increments the per-email counter | 2 failures, fresh email | 0 -> 1 -> 2 | implemented |
| sess-lh-08 | the email counter is normalized (case + whitespace cannot open a fresh window) | ' EMAIL ' then email | one shared counter, value 1 | implemented |
| sess-lh-09 | record_failure increments the per-IP counter | 2 failures, 2 distinct emails | CLI counter delta +2 | implemented |
| sess-lh-10..16 | get_history: array, recorded entries, expected keys, email match, limit, status label, parsed UA summary | queries | correct shape/values | implemented |
| sess-lh-17..19 | failed_attempts_count: zero start, counts failures, a success never counts | records | correct count | implemented |
| sess-lh-20 | the count is bounded by the configured window - a 30-day request answers the window | 1 failure, $minutes = 43200 | 1 (not a 30-day history) | implemented |
| sess-lh-21..22 | failed_attempts_by_ip: counts same IP (delta), zero for unknown IP | records | correct count | implemented |

## Session_Cleanup_Test (php, $requires_db_reset + no-tx) - token lookup & GC

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-gc-01..03 | find_by_token: null for unknown, finds active, ignores inactive | committed sessions | correct row/null | implemented |
| sess-gc-04..05 | cleanup_expired deletes old, keeps recent | aged rows | correct deletion | implemented |
| sess-gc-06 | cleanup_expired returns deleted count | aged rows | int count | implemented |
| sess-gc-07 | cleanup_expired respects custom days arg | days arg | honored | implemented |
| sess-gc-08 | cleanup_sessions applies the identity-aware windows (identified web 3mo vs identity-less 30d) | aged rows of each kind | each expires on its own window | implemented |
| sess-gc-09 | cleanup_sessions expires TYPE_PLAYWRIGHT on its own window, leaving a same-age web row | 2-day-old harness + web rows | harness gone, web kept - proves the sweep reads type, not age | implemented |
| sess-gc-09b | cleanup_sessions expires TYPE_CLI on its own window, leaving a same-age web row | 2-day-old CLI + web rows | CLI gone, web kept - the backstop for a killed process | implemented |
| sess-gc-10 | cleanup_sessions chunked delete clears a backlog | 12 rows, chunk_size 5 | all deleted across 3 statements | implemented |
| sess-gc-10a | cleanup_login_history prunes past the retention window | 400-day + 10-day rows | aged deleted, recent kept | implemented |
| sess-gc-10b | cleanup_login_history is silent when nothing is aged | 3-day row | total_deleted 0, row kept | implemented |
| sess-gc-10c | retention 0 disables the prune entirely | 400-day row, config 0 | total_deleted 0, row kept | implemented |
| sess-gc-10d | cleanup_login_history clears a backlog across chunks | 12 aged rows, chunk_size 5 | all deleted | implemented |
| sess-gc-11 | purge_playwright_sessions takes only this run's harness rows | harness + web in window, harness from a live concurrent run | own harness gone; web and concurrent run untouched | implemented |
| sess-gc-12 | purge_playwright_sessions collects stale harness rows from earlier runs | 3h-idle harness row | deleted without waiting out the playwright window | implemented |
| sess-gc-13 | a PORTAL identity is not read as anonymous | 40-day-idle rows with/without portal_user_id | portal-only kept, identity-less collected | implemented |
| sess-gc-14 | a portal identity expires on the identified-web window | 200-day vs 40-day portal rows | stale gone, fresh kept | implemented |
| sess-gc-15 | the playwright backstop ignores which identity a row carries | 2-day portal harness + portal web | harness gone, web kept | implemented |
| sess-gc-16 | cleanup_expired collects every session past the cutoff | 400-day staff + portal rows | both deleted (one table, one blunt cutoff) | implemented |
| sess-gc-17 | purge_playwright_sessions collects harness rows whatever they carry | staff + portal harness + portal web | both harness rows gone, web kept | implemented |

## Session_Cap_Test (php, $requires_db_reset + no-tx) - concurrent session cap

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-cap-01 | the N most recent web sessions survive, older ones are signed out (STAFF cap deactivates the row; the portal cap clears properties - see PORTAL-PROP-11) | 5 web sessions, cap 3 | 3 newest active, 2 oldest deactivated | implemented |
| sess-cap-02 | no-op under the limit | 2 sessions, cap 25 | both active | implemented |
| sess-cap-03..04 | cap of 0 / null disables the feature | 5 sessions | all still active | implemented |
| sess-cap-05 | another user's sessions are never in scope | cap 1, two users | only the acting user's older row signed out | implemented |
| sess-cap-06 | PLAYWRIGHT + API sessions neither count toward nor are evicted by the cap | cap 2, 2 harness + 1 api + 2 web | all five active - machine rows never consumed a slot | implemented |
| sess-cap-07 | the cap fires on a real web sign-in through set_login_user_id() | http | needs a live web request (setter is a CLI no-op) | deferred |

## http/session_cookie_behavior.sh (http - live server)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-http-00 | session cookie behavior over real HTTP (set/persist) | HTTP requests | cookie behaves per spec | implemented |

(Migrated from the prior bash suite; see the script for exact assertions.)

## http/session_token_immutability.sh (http - live server)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-http-06 | token minted once at creation; login is a pure record update (no rotation, no cookie re-emission); same token then authenticates | anon session -> POST /login -> GET /dashboard | step1 Set-Cookie rsx; step2 NO Set-Cookie rsx + token unchanged; step3 200 | implemented |

## Deferred / planned

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| sess-http-01 | web-mode session creation + cookie set on first activate | http | needs live HTTP context | deferred |
| sess-http-02 | session token + CSRF STABILITY across login (no rotation - owner ruling 2026-07-24) | http | superseded by sess-http-06 (session_token_immutability.sh) | implemented |
| sess-http-03 | CSRF verify against a real stored token | http | needs persisted web session | deferred |
| sess-http-04 | Session::reset() clears cookie in web mode | http | calls setcookie() | deferred |
| sess-http-05 | multi-device: get_sessions_for_user / terminate_* / terminate_all_other | http | thin wrappers; need real session rows over HTTP | deferred |
| sess-06 | get_current_session_info() | php/http | thin wrapper on already-tested parsing | deferred (low value) |
| sess-08 | session type classification at creation (harness header -> TYPE_PLAYWRIGHT) | http | needs a live request carrying X-Playwright-Test; covered end-to-end by the rsx:debug purge behavior | deferred |

## Session_Terminate_Ownership_Test (php) - terminate_session() ownership predicate

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| sess-term-01 | a user terminates their OWN session | php | active row with the caller's login_user_id | true, row deactivated | implemented |
| sess-term-02 | another user's session id is fail-closed | php | active row owned by a different login_user_id | false, row still active | implemented |
| sess-term-03 | an anonymous session row (no owner) is not terminable | php | active row with login_user_id NULL | false, row still active | implemented |
| sess-term-04 | a caller with no login identity terminates nothing | php | logged out, any active row | false, row still active | implemented |

## Session_Terminate_For_User_Test (php) - the GUARDED cross-user primitives

`terminate_session_for_user()` / `terminate_all_sessions_for_user()` authorize
self-or-can_admin_role and THROW `AjaxUnauthorizedException` on refusal, while ABSENCE
still returns false/0. `_deactivate_sessions_for_user()` is the unchecked
framework-internal path. Roles used: ROOT_ADMIN(200) over MANAGER(500), MANAGER over
MANAGER (peer), USER(600) under MANAGER.

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| sess-termfu-01 | an actor whose role may administer the target's terminates it | php | ROOT_ADMIN acting, MANAGER target's active row | true, row deactivated | implemented |
| sess-termfu-02 | self-service works through the cross-user function | php | actor terminates own row | true, row deactivated | implemented |
| sess-termfu-03 | ABSENCE is false, never a throw | php | valid authority, unknown session id | false | implemented |
| sess-termfu-04 | the operator's own CURRENT session is refused with false | php | actor names its live CLI session id | false, row still active | implemented |
| sess-termfu-05 | a PEER is refused | php | MANAGER acting on MANAGER | AjaxUnauthorizedException, row intact | implemented |
| sess-termfu-06 | a subordinate reaching upward is refused | php | USER acting on MANAGER | AjaxUnauthorizedException, row intact | implemented |
| sess-termfu-07 | no acting identity is refused | php | logged out, any target | AjaxUnauthorizedException ('logged-in actor'), row intact | implemented |
| sess-termfu-08 | a target with no users row on the acting site is refused | php | login identity with no site user | AjaxUnauthorizedException ('no such user on the acting site') | implemented |
| sess-termfu-09 | bulk termination by an admin, sparing one | php | 3 rows, except = one of them | returns 2, two deactivated, spared row active | implemented |
| sess-termfu-10 | bulk termination by a peer is refused | php | MANAGER acting on MANAGER | AjaxUnauthorizedException, no row touched | implemented |
| sess-termfu-11 | bulk termination with no acting identity is refused | php | logged out | AjaxUnauthorizedException, row intact | implemented |
| sess-termfu-12 | the unchecked internal path works with no session context | php | logged out, 2 rows | returns 2, both deactivated | implemented |
| sess-termfu-13 | session.terminated payload for a cross-user termination | php | admin terminates target row | 1 event: actor/target/session_id, scope 'admin' | implemented |
| sess-termfu-14 | session.terminated scope is 'self' from terminate_session() | php | user terminates own row | 1 event, scope 'self', actor = user | implemented |
| sess-termfu-15 | session.terminated scope is 'internal' with a null actor | php | unchecked helper | 1 event, scope 'internal', actor null | implemented |
| sess-termfu-16 | each terminate path pushes a realtime session refresh | php/http | any deactivation | one push_session_refresh('staff', id) per row | deferred (no in-process assertion seam for the emissions outbox; verified live via the B3 harness, no-throw + queued) |
| sess-termfu-17 | the concurrent-session cap does NOT emit termination events | php | sign-in past max_web_sessions_per_user | rows evicted, no session.terminated | deferred (documented divergence: cap eviction is capacity management, not termination) |

## Rsx_Auth_Attempt_Test (php, default isolation) - attempt() classification + recording

attempt() is the only place that can tell the three outcomes apart, so it is the place that
records them. Failure counters are redis keys outside the per-test transaction: unique emails per
test, per-IP counter read as a delta ('CLI'), keys deleted in teardown.

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| sess-auth-01 | an unknown address is classified NOT_FOUND | php | fresh email + any password | false, no row, email counter 1, log line carries the email + failed_not_found | implemented |
| sess-auth-02 | a wrong password against a real identity is classified FAILED_PASSWORD | php | real user, bad password | false, no row, counter 1, no identity, last_login untouched, log line carries failed_password | implemented |
| sess-auth-03 | a soft-deleted identity is NOT_FOUND (SoftDeletes global scope), even with the right password | php | deleted user, correct password | false, no row, counter 1, no identity, failed_not_found logged | implemented |
| sess-auth-04 | success records exactly ONE row and establishes the identity | php | real user, correct password | true, 1 SUCCESS row w/ login_user_id, get_login_user_id matches, failure counter 0 | implemented |
| sess-auth-05 | $record = false records no failure | php | real user, bad password, record: false | false, no row, no counter increment | implemented |
| sess-auth-06 | $record = false success authenticates without recording or stamping (the 2FA pre-check shape) | php | correct password, record: false, touch_last_login: false | true, no row, last_login null, identity set | implemented |
| sess-auth-07 | malformed input is not an attempt | php | {}, email only, password only, empty password | false x4, no row, no email counter, no IP counter | implemented |
| sess-auth-08 | $touch_last_login: a real login stamps last_login and a dev-auth harness login does not | http | POST /login vs rsx:debug --user=N | last_login bumped / unchanged | deferred (no CLI observability: Session::set_login_user_id() returns from its CLI branch before the stamp; verified live during B2) |

## Login_Throttle_Test (php, default isolation) - brute-force throttle

The framework's own rate limit on the authentication surface: FAILURES counted per client
IP, a lockout when the budget is spent, and a THROWN refusal. State is two redis keys per
address, outside the per-test transaction - every test invents its own synthetic address,
passes it explicitly, and clears it in teardown; the throttle config is saved and restored
around the class. Time is never waited on: the lockout stores its expiry instant, so
"it expires" is asserted as a countdown bounded by the configured minutes.

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| sess-thr-01 | the first attempts-1 failures cost nothing | php | attempts 3, two failures | retry_after_seconds 0, require_not_throttled() returns | implemented |
| sess-thr-02 | the budgeted failure locks the address | php | attempts 3, three failures | retry_after_seconds > 0, require_not_throttled() throws Auth_Throttled_Exception | implemented |
| sess-thr-03 | the exception carries the exact message and the remaining time | php | attempts 1, one failure | message "You're doing that too fast", retry_after_seconds > 0 and <= lockout | implemented |
| sess-thr-04 | the lockout is a countdown inside the configured minutes | php | lockout_minutes 1 | 0 < retry_after_seconds <= 60 | implemented |
| sess-thr-05 | reset() releases the address AND its counter | php | lock, reset, one more failure | free after reset; the next failure counts from zero | implemented |
| sess-thr-06 | the lockout is per IP - never a denial-of-service tool | php | two addresses, one locked | attacker locked, bystander free | implemented |
| sess-thr-07 | disabled counts nothing and enforces nothing | php | enabled false, three failures, then re-enable | free throughout; re-enabling reveals no hidden lockout | implemented |
| sess-thr-08 | the switch releases an existing lockout | php | lock, then disable | retry_after_seconds 0 | implemented |
| sess-thr-09 | a caller with no client IP is never throttled | php | CLI, attempts 1, two failures | nothing counted, nothing enforced | implemented |
| sess-thr-10 | attempt() therefore still works in CLI | php | CLI, failure recorded, then attempt() | answers the credential question rather than refusing | implemented |
| sess-thr-11 | Login_History::record_failure() reaches the throttle | php | record_failure() in CLI | the call is made and stays harmless (no address) | implemented |
| sess-thr-12 | attempt() THROWS for a locked-out web client, correct password included | http | 10 wrong passwords then the correct one from one X-Forwarded-For address | the 11th answers "You're doing that too fast"; another address is unaffected | deferred (no CLI observability: Session::get_client_ip() is null in CLI by design, so the ambient-IP throw cannot be driven from php; verified live over HTTP against /login and /_portal/login on 2026-08-30) |

Session::set_temporary_site_id() - a DECLARED tenant that writes nothing, via
`Session_Temporary_Site_Test`.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| sess-tmpsite-01 | declaring a tenant creates NO _sessions row, however much is then asked of the session | php | set_temporary_site_id(4242), then has_session/get_site_id/is_logged_in/get_login_user_id/get_user_id | row count unchanged | implemented | 2026-08-24 |
| sess-tmpsite-02 | an existing row is NOT rewritten, and is restored on clear | php | minted CLI row at site 9, then declare 4242 | row.site_id stays 9; get_site_id 4242; after clear, 9 | implemented | 2026-08-24 |
| sess-tmpsite-03 | get_site_id() returns the declared tenant | php | declare 7 | 0 before, 7 after | implemented | 2026-08-24 |
| sess-tmpsite-04 | has_session() answers TRUE - site-scoped code guards on it before trusting get_site_id() | php | declare 7 | false before, true after | implemented | 2026-08-24 |
| sess-tmpsite-05 | a tenant is not an identity - declaring one signs nobody in | php | declare 7 | is_logged_in false, get_login_user_id null, get_user_id null | implemented | 2026-08-24 |
| sess-tmpsite-06 | it outranks a declared CLI context | php | impersonate(site 9), then declare 4242 | 9, then 4242 | implemented | 2026-08-24 |
| sess-tmpsite-07 | clear returns to normal resolution | php | declare 7, then clear | has_temporary false, has_session false, site_id 0 | implemented | 2026-08-24 |
| sess-tmpsite-08 | clear is safe with nothing declared - one assignment, not an undo of a write | php | clear with no declaration | no throw, row count unchanged | implemented | 2026-08-24 |
| sess-tmpsite-09 | re-declaring replaces rather than stacking | php | declare 7 then 8, one clear | 8, then 0 | implemented | 2026-08-24 |
| sess-tmpsite-10 | the test reset seam drops it, so it cannot leak between tests | php | declare 7, reset_impersonation() | has_temporary false, site_id 0 | implemented | 2026-08-24 |
| sess-tmpsite-11 | in WEB mode it emits no Set-Cookie and mints no row | http | a request that declares a tenant with no session cookie | no Set-Cookie, no row | deferred (structural: the method has no mode branch and both reader branches sit BEFORE self::init(), so the cookie is never read; would need a dedicated probe route to observe) | 2026-08-24 |
