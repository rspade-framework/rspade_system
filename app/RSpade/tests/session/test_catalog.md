# Test catalog: session

Status legend: `implemented` | `deferred` (reason) | `blocked` (see issues) | `planned`.
Type: php / cli / asset / http / playwright. Last updated: 2026-10-21.

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

## Developer_Flag_Test (php, default isolation) - login_users.is_developer

The whole of what a developer is: the initial user carries the flag, toArray() carries
the key only when it is true (so absence reads as false), and Session::is_developer()
answers from the signed-in LOGIN identity - never a role, never a hostname.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-dev-01 | the initial user is a developer | baseline login identity 1 | is_developer truthy | implemented |
| sess-dev-02 | the column defaults off | a new login_users row | is_developer falsy | implemented |
| sess-dev-03 | a developer's payload carries the key | find(1)->toArray() | is_developer present and true | implemented |
| sess-dev-04 | an ordinary payload omits it | new identity ->toArray() | no is_developer key at all | implemented |
| sess-dev-05 | the session reads the flag | impersonate(site, login 1, user 1) | is_developer() true | implemented |
| sess-dev-06 | it is the LOGIN identity's flag | impersonate with an ordinary login id | is_developer() false | implemented |
| sess-dev-07 | anonymous is never a developer | reset_impersonation() | is_developer() false | implemented |

## Session_Virtual_Test (php, default isolation) - the VIRTUAL session (no browser, no row)

A process with no browser - a command, a task, this runner - runs a virtual session: no
_sessions, _user_sessions or _audit_sessions row ever, get_session_id() null, identity in
statics. Complements Session_Cli_Test, which covers the declared identity's getters.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-virt-01 | get_session_id() is null and nothing is a session | fresh process | _is_virtual() true; null; has_session() false | implemented |
| sess-virt-02 | get_session() throws | - | RuntimeException naming the virtual session | implemented |
| sess-virt-03 | a declared identity is a session context with no row | impersonate() | has_session() true; get_session_id() null | implemented |
| sess-virt-04 | a command that signs in and out, impersonates, switches site and records a unit of revision history writes no session row of any kind | impersonate/set_login_user_id/set_site_id/logout/get_audit_session_id + Revision::transaction_id() | audit id null; _transactions.audit_session_id null; the three table counts unchanged | implemented |
| sess-virt-05 | a declared audit session is answered, and _end_virtual() ends it | Audit_Session::_declare() | the declared id; null after | implemented |
| sess-virt-06 | _begin_virtual() declares the context given | site, identity, user, impersonator | each getter answers it | implemented |
| sess-virt-07 | _end_virtual() blanks the process, declared tenant included | after begin + set_temporary_site_id | site 0, nobody, no tenant, no impersonation, no session | implemented |
| sess-virt-08 | _begin_virtual() starts fresh | two begins | only the second context | implemented |
| sess-virt-09 | put_value() refuses with no browser | declared identity | Rsx_Caller_Exception "belong to a browser" | implemented |
| sess-virt-10 | the value readers answer absent and create nothing | get_value/forget_value | default; counts unchanged | implemented |
| sess-virt-11 | a test acting as a browser has its row (__as_browser) | __as_browser(), values round trip | get_session_id/get_session the row; values stored on it | implemented |
| sess-virt-12 | reset() is the hard exit and writes nothing | declared impersonation, reset() | not impersonating; counts unchanged | implemented |

## Session_Values_Test (php, $acts_as_browser) - put_value / get_value / forget_value

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-val-01..03 | a read or forget with no session answers the default and creates no row | no browser | default; `_sessions` count unchanged | implemented |
| sess-val-04..09 | round trip by type, upsert, unset default, forget, forgetting an absent key | put/get/forget | values as written; one row per key | implemented |
| sess-val-10..13 | expiry: none and future readable; past reads as absent at once, row left for the pruner | expires_at past/future/null | as stated | implemented |
| sess-val-14..15 | the FK cascade removes values with the row; one session cannot read another's | delete the row | 0 rows | implemented |
| sess-val-16 | each realm is its own namespace: one key, three values, forgotten per realm | 'staff' / 'portal' / 'guest' | three rows by realm id; forgetting one leaves the others | implemented |
| sess-val-17 | no realm = the request's experience | a staff request, then Rsx_Portal::set_portal_request(true) | staff realm, then portal realm | implemented |
| sess-val-18 | Portal_Session's values are the portal realm | Portal_Session::put/get/forget | stored under realm 2; invisible to staff | implemented |
| sess-val-19 | an unknown realm word throws | 'api', 'Staff', 'nobody' | Rsx_Caller_Exception | implemented |

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
| sess-gc-08 | cleanup_sessions deletes only GUEST browsers (no sign-in ever) past rsx.sessions.guest_expiry_minutes; a browser with a sign-in, current or ended, is never deleted | 40-day guest, 200-day signed-in and signed-out browsers, 2-day guest | only the old guest deleted (`guest_deleted`) | implemented |
| sess-gc-08a | the guest predicate is `_sessions.first_signed_in_at`, not a look at user sessions; a guest's values go with it | a 40-day browser stamped first_signed_in_at with no user session; a 40-day guest with a guest value | stamped one kept; guest deleted with its value | implemented |
| sess-gc-09 | cleanup_sessions leaves a harness row alone, however old: a machine row is never a guest | 400-day TYPE_PLAYWRIGHT row with no sign-in | kept | implemented |
| sess-gc-10 | cleanup_sessions chunked delete clears a backlog | 12 rows, chunk_size 5 | all deleted across 3 statements | implemented |
| sess-gc-12 | cleanup_sessions signs out sign-ins past a limit PLUS rsx.sessions.ajax_grace_minutes under the reason a request records (TIMED_OUT_LENGTH / _IDLE): pointer cleared, realm values gone, guest values and the remote token kept; one inside the grace or its limits stays | 8-day-old sign-in, 11h idle, 9h idle (in grace), fresh; 7d/8h/2h limits | first two signed out with their reasons; others signed in | implemented |
| sess-gc-12a | a sign-in found past both clocks is recorded under the EARLIER expiry | signed in 7d3h ago, last page view 7d2h ago | SIGN_OUT_TIMED_OUT_IDLE | implemented |
| sess-gc-12b | only the user session a browser POINTS AT is judged: an impersonator's own sign-in suspended behind a live impersonation stays; an idle impersonation takes it with it | two browsers with own sign-ins 2 days idle, one live and one 11h-idle impersonation | live pair signed in; idle pair both signed out | implemented |
| sess-gc-12c | the portal realm is swept on its own limits, across batches | 7 portal sign-ins 12h idle, chunk_size 3 | all SIGN_OUT_TIMED_OUT_IDLE, no pointer left | implemented |
| sess-gc-12d | limits of 0 read nothing | 60-day sign-in, 20 days idle, staff limits 0/0 | still signed in | implemented |
| sess-gc-11 | deactivate_unused_sessions (daily maintenance) makes browsers unused past rsx.sessions.unused_session_days inactive, never deleted; a browser in use and an API key's row stay active | 40-day signed-in, 31-day guest, 2-day, 90-day TYPE_API rows | first two inactive and present; the others active | implemented |
| sess-gc-11c | retiring an unused browser signs out what is signed in on it (SIGN_OUT_BROWSER_UNUSED, labelled "Browser unused for N days"): pointer cleared, realm values gone, no longer counted signed in; a browser in use keeps its sign-in | 40-day browser with a staff sign-in and a staff value; 2-day browser signed in | signed out with the reason; pointer null; values gone; inactive; fresh sign-in untouched | implemented |
| sess-gc-11a | deactivate_unused_sessions clears a backlog across chunks | 12 45-day browsers, chunk_size 5 | none left active | implemented |
| sess-gc-11b | unused_session_days 0 turns the retirement off | a 400-day browser, config 0 | deactivated 0, still active | implemented |
| sess-gc-10a | cleanup_login_history prunes past the retention window | 400-day + 10-day rows | aged deleted, recent kept | implemented |
| sess-gc-10b | cleanup_login_history is silent when nothing is aged | 3-day row | total_deleted 0, row kept | implemented |
| sess-gc-10c | retention 0 disables the prune entirely | 400-day row, config 0 | total_deleted 0, row kept | implemented |
| sess-gc-10d | cleanup_login_history clears a backlog across chunks | 12 aged rows, chunk_size 5 | all deleted | implemented |
| sess-gc-13 | a PORTAL sign-in is not read as a guest | 40-day-idle browsers with/without a portal user session | portal-only kept, guest collected | implemented |
| sess-gc-23 | cleanup_user_agents deletes a string nothing names and keeps one named by a session, a login-history row or an audit session | four 10-day-old strings, three referenced | only the orphan deleted | implemented |
| sess-gc-24 | a string resolved within the last day is left alone | an unreferenced string resolved now | kept | implemented |
| sess-gc-25 | resolving a string again protects it from the sweep | a 10-day-old orphan, resolved again | kept | implemented |

## Session_Cap_Test (php, $requires_db_reset + no-tx) - concurrent session cap

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-cap-01 | the N most recent signed-in user sessions survive, older ones are signed out (SIGN_OUT_SESSION_CAP, pointer cleared, browser row kept) | 5 signed-in browsers, cap 3 | 3 newest signed in, 2 oldest signed out | implemented |
| sess-cap-02 | no-op under the limit | 2 sessions, cap 25 | both signed in | implemented |
| sess-cap-03..04 | cap of 0 / null disables the feature | 5 sessions | all still signed in | implemented |
| sess-cap-05 | another user's sessions are never in scope | cap 1, two users | only the acting user's older sign-in ended | implemented |
| sess-cap-06 | PLAYWRIGHT + API browsers neither count toward nor are evicted by the cap | cap 2, 2 harness + 1 api + 2 web | all five signed in - machine browsers never consumed a slot | implemented |
| sess-cap-08 | an impersonation of the account is not counted or evicted | cap 1, an impersonation newer than the account's own sign-in | both signed in | implemented |
| sess-cap-07 | the cap fires on a real web sign-in through set_login_user_id() | http | needs a live web request (setter is a CLI no-op) | deferred |

## http/session_cookie_behavior.sh (http - live server)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-http-00 | session cookie behavior over real HTTP (set/persist) | HTTP requests | cookie behaves per spec | implemented |
| sess-http-08 | a SECURE request names the cookie `__Host-rsx`, Secure, Path=/, no Domain | GET a session-minting probe on :8000 (the TLS-terminated listener) | `Set-Cookie: __Host-rsx=...; path=/; secure` with no domain | implemented |
| sess-http-09 | a secure request ignores a planted unprefixed `rsx` cookie | the token from sess-http-08 sent as `rsx=` on :8000 | a fresh `__Host-rsx` with a DIFFERENT token is minted | implemented |

(Migrated from the prior bash suite; see the script for exact assertions.) Needs a
framework-development box: the `/ssr-test` probes are gated `is_framework_developer`.

## Login_History_List_Page_Test (php) - the display-page reader standard

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-page-01 | the answer is one page plus the total of every match, rows in present_record() shape | 5 rows, limit 2 | {rows, total, offset, limit}; 2 rows, total 5, newest first | implemented |
| sess-page-02 | the scope bounds the answer and is required | another identity; no / zero login_user_id | its own row only; InvalidArgumentException | implemented |
| sess-page-03 | offset walks the pages | offsets 0, 2, 4 at limit 2 | every row once, in order | implemented |
| sess-page-04 | the caller sorts by a published name | sort ip_address asc; sort email both ways | ordered; the two ends differ | implemented |
| sess-page-05 | a sort the reader does not publish is refused, never sent to SQL | a column name, an expression, an injection string, an array | InvalidArgumentException each | implemented |
| sess-page-06 | order, limit, offset, filter type and unknown keys are validated | 11 bad queries; the maximum, the default, a numeric string | throws each; 200, 25, 3 | implemented |
| sess-page-07 | the filter searches the whole history - user agent included - before the page is cut | 'firefox' at limit 1; an email part; an address; no match | total 2 with 1 row; 1; 1; 0 | implemented |
| sess-page-08 | wildcard characters in a filter are literal | '%', 'ada_example' | 0 matches each | implemented |

## http/session_sign_in_rotation.sh (http - live server)

Self-seeding (two accounts of its own, via tinker). Needs a framework-development box for the
`/ssr-test` probe. One browser row throughout; tokens rotate in place.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-rotate-01 | a sign-in keeps the browser's row and gives it a new token, in exactly one session cookie | guest row -> POST /login as A | same row id; token differs; one `Set-Cookie: rsx=` | implemented |
| sess-rotate-02 | the row points at a signed-in user session for A, count 1 | row state | staff_user_session_id set; login_user_id A; sign_in_count 1 | implemented |
| sess-rotate-03 | an Ajax call or a sub-resource on the token the sign-in just replaced (in flight during it) is REFUSED with no cookie and nothing minted, and the signed-in cookie keeps working | /dashboard with `Sec-Fetch-Dest: image` and an Ajax call on the copied cookie | 401 with no `Set-Cookie: rsx=`; `session_expired` cause `rotated`, no cookie; `_sessions` count unchanged; A's /dashboard still 200 | implemented |
| sess-rotate-03a | a PAGE NAVIGATION on the just-replaced token is an ordinary guest, so a browser that missed the new cookie recovers | /dashboard with `Sec-Fetch-Dest: document` on the copied cookie | 302 to sign-in; any cookie names a row other than the browser's; A's /dashboard still 200 | implemented |
| sess-rotate-04 | sign-out stamps the user session, clears the pointer and the browser's STAFF values (its guest value stays), rotates the token; the pre-sign-in token, two rotations old, is an ordinary stranger | a staff and a guest value; GET /logout, then /dashboard on the copied cookie | signed_out_at set, reason 1; pointer null; staff value gone, guest value kept; new token, same row; 302 to sign-in, never the browser's row | implemented |
| sess-rotate-05 | another account on the same browser is a second user session on the same row | sign in as B | new user session; same row; new token | implemented |
| sess-rotate-06 | the first account again REUSES its user session (A/B/A) and replaces B's | sign in as A without signing B out | pointer = A's user session, sign_in_count 2, signed in; B's reason 8 (replaced); two user sessions on the row | implemented |

## http/harness_session_reuse.sh (http - live server)

Self-seeding (an account of its own, via tinker); mints the signed dev-auth headers through
Dev_Auth_Token::mint(), so it needs this box's development grant and a framework-development
box for the `/ssr-test` probe.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-harness-01 | a dev-auth sign-in lands on a TYPE_PLAYWRIGHT row, and its traffic records no audit session | dev-auth GET of the audit probe, fresh jar | row type 2 signed in as the account; probe answers no id; no `_audit_sessions` row for the browser | implemented |
| sess-harness-02 | a second run (fresh jar) is pointed at the same row, minting nothing | dev-auth GET, another fresh jar | same row id; `_sessions` count unchanged | implemented |
| sess-harness-03 | a harness sign-in past max_length_minutes is not reused: signed out as timed out, and a new row minted | signed_in_at moved 400 days back, dev-auth GET | a different TYPE_PLAYWRIGHT row; the old user session reason 2 | implemented |
| sess-harness-04 | the unsigned X-Playwright-Test marker alone makes an ordinary browser | GET with the header only | a TYPE_WEB row | implemented |

## http/session_lifetime.sh (http - live server)

Self-seeding. Moves a real sign-in's clocks back (on its user session) against the shipped
defaults (7 days from sign-in, 8 hours idle, 2 hours of grace). An ended sign-in = the user session stamped signed
out with the reason, the pointer cleared, the browser row kept active.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-life-01 | inside the grace window an Ajax call and a page sub-request still answer | signed_in_at 30d+1h ago | `_success` true; 200 for `Sec-Fetch-Dest: image`; still signed in | implemented |
| sess-life-02 | a page navigation past the limit signs out, redirects to sign-in and says why | same sign-in, `Sec-Fetch-Dest: document` | 302 to /login; user session signed out reason 2; the message on the sign-in page | implemented |
| sess-life-03 | past the grace an Ajax call signs out and answers session_expired | last_page_view_at 30d+3h ago | `error_code` session_expired, cause idle; reason 3 | implemented |
| sess-life-04 | the page-view report gets no grace | signed_in_at 30d+1h ago, POST page_view | session_expired; reason 2 | implemented |
| sess-life-05 | the page-view report stamps the user session and last_seen on the account | stamp 5 minutes old | stamp advanced; login_users.last_seen set | implemented |
| sess-life-06 | an Ajax call is not a page view | stamp 5 minutes old, any Ajax call | stamp unchanged | implemented |
| sess-life-07 | rendering a page is a page view | GET /dashboard | stamp advanced | implemented |
| sess-life-08 | last_active is written at most once a minute | last_active 20s ago, then 90s ago; an Ajax call each | unchanged / advanced | implemented |
| sess-life-09 | a browser unused for rsx.sessions.unused_session_days is made inactive at lookup and is a stranger (its sign-ins are signed out through the same Session::_deactivate_browsers() sess-gc-11c covers) | last_active 31 days ago; GET /dashboard | 302 to sign-in; row inactive | implemented |

## Audit_Session_Test (php) - the acting context a logged act points at

The key is user session (a guest: the browser) x site x address x user agent; the test
process is virtual, so the keying is driven through Audit_Session::_id_for_context().

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-audit-02 | a process with no browser records no audit session, and asking mints none | declared CLI identity | null; count unchanged; no session | implemented |
| sess-audit-02b | a declared audit session is answered | _declare(id) | get_audit_session_id() = id | implemented |
| sess-audit-01 | one context is one row however often it is asked for | one context, twice | same id; site, identity, browser, user session, TYPE_WEB recorded | implemented |
| sess-audit-03 | a different user session, address, user agent or site is a different row; the first context is its first row again | four variants, then the first | four distinct ids; the first again | implemented |
| sess-audit-04 | a guest is keyed on its browser | no identity, two browsers | same browser same id; another browser another id; null identity and user session | implemented |
| sess-audit-06 | a row minted in a rolled-back transaction is not answered again | begin, mint, roll back, mint | first row gone; a row exists for the second id | implemented |
| sess-audit-07 | a user agent is stored once and in full | a 1000+ character string, twice; a variant | same id; different id; the whole string; one row | implemented |
| sess-audit-08 | no user agent is null | null, '' | null id, null string | implemented |
| sess-audit-10 | an audit session is read back through the framework, address and user agent as strings | get_audit_session($id); id 0 | the recorded context; null | implemented |
| sess-audit-11 | a session row answers its user agent as the string it was given | a 400+ character agent assigned to a row | the same string from a fresh read | implemented |
| sess-audit-12 | join() adds every get_audit_session() field as audit_<name> to the caller's query, keeps its own columns, and lists a row with no audit session | a query-builder query over two rows, one attributed | 2 rows; fields set / null; the joined names equal get_audit_session()'s keys | implemented |
| sess-audit-13 | column() names an audit field for where() and orderBy(); an unknown field or an unqualified column is refused | filter on ip_address, sort on first_seen_at; bad names | 1 match; InvalidArgumentException | implemented |
| sess-audit-14 | join() on an Eloquent query still answers the caller's models | Transaction_Model query under a declared audit session | a Transaction_Model with audit_login_user_id and its own description | implemented |
| sess-audit-09 | a recorded unit of work carries the audit session, and none where nobody with a browser acted | Revision::transaction_id() under a declared id, then with none | the declared id; null | implemented |

## Audit_Session_List_Page_Test (php) - the audit sessions of one user session

`Audit_Session::list_page_for_user_session()`, the display-page reader behind the session
screens; rows minted through `_id_for_context()` with their clocks set by the test.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-auditpage-01 | one page plus the total of the user session's audit sessions only, most recently seen first, rows in describe()'s shape plus device_summary; offset walks on | three of the user session, one of another; limit 2, then offset 2 | total 3; two then one, in order; the shape | implemented |
| sess-auditpage-02 | the published sorts, and a filter over the address and the user agent whose wildcards are literal | two rows; sort ip_address desc, first_seen_at asc; filters on an address, a user agent, '%' | the orders; one match each; 0 | implemented |
| sess-auditpage-03 | the user session is required and the query is validated | none, zero, an unpublished sort, an unknown key, a limit over 200 | InvalidArgumentException each | implemented |

## Ip_Address_Test (php) - client addresses stored once in _ip_addresses

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-ip-01 | an address is one row however often it is resolved | resolve twice, reset, resolve; a second address | same id each time; different id; one row | implemented |
| sess-ip-02 | resolving is memoized until _reset() | resolve, delete the row, resolve; reset; resolve | memo answers the old id; a new id after reset | implemented |
| sess-ip-03 | no address is null | null, '', 'CLI'; address(null/0) | null; no 'CLI' row | implemented |
| sess-ip-04 | a full-length IPv6 address is kept whole | 39-character IPv6 | read back intact | implemented |
| sess-ip-05 | describe() answers the address and every geocode field, null until located | fresh row; row with geocode set | null geocode; the stored values | implemented |
| sess-ip-06 | join() adds describe()'s fields as <prefix>_<name>, keeps the query's columns, lists a row with no address | two _login_history rows, one located | fields set / null; names equal describe()'s keys | implemented |
| sess-ip-07 | column() names a joined field for where()/orderBy(); bad field, prefix or column refused | filter on ip_address, sort on country | 1 match; InvalidArgumentException | implemented |
| sess-ip-08 | a row minted in a rolled-back transaction is not answered again | begin, resolve, roll back, resolve | row gone, then minted again | implemented |

## http/audit_session.sh (http - live server)

Self-seeding. Needs a framework-development box for the `/ssr-test` probe.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-audit-http-01 | a cookie-less caller gets an audit session - address and agent recorded, no session row - and no session cookie for asking | GET the probe | id; no `Set-Cookie: rsx=`; row as stated | implemented |
| sess-audit-http-02 | the same context answers the same id | same request again | same id | implemented |
| sess-audit-http-03 | a different user agent is a different context | another `User-Agent` | different id | implemented |
| sess-audit-http-04 | a signed-in browser's audit session names its identity, the browser and the user session | sign in, GET the probe | session_id, user_session_id, login_user_id match the browser row and its staff user session | implemented |

## playwright/session_expired.js (playwright)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| SESSION-EXPIRED-01 | a session_expired envelope calls the registered handler with the server message | envelope fed to Ajax | one call, the message | implemented |
| SESSION-EXPIRED-02 | later refusals on the same page do not call it again | session_expired, then auth_required | still one call | implemented |
| SESSION-EXPIRED-03 | auth_required on a signed-in page is the same event | auth_required alone | one call | implemented |
| SESSION-EXPIRED-04 | a page rendered for nobody announces nothing | is_auth false | no call | implemented |
| SESSION-EXPIRED-05 | with no handler registered the page reloads | session_expired | a load event | implemented |
| SESSION-PAGEVIEW-01 | a completed SPA dispatch reports a page view at most once a minute; the boot load already counted | spa_dispatch_ready x3 | 0, then 1, then still 1 | implemented |
| SESSION-PAGEVIEW-02 | nothing is reported once the sign-in is known to be gone | dispatch after the announcement | 0 | implemented |

## Session_Cookie_Flags_Test (php, no DB) - the session cookie's name and attributes

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-cookie-01 | the Secure flag follows the scheme in development and is forced elsewhere | plain http / https / X-Forwarded-Proto in each mode | per mode | implemented |
| sess-cookie-02 | a Secure cookie is named `__Host-rsx` | terminated / https development, plain http in debug and production | `__Host-rsx` | implemented |
| sess-cookie-03 | the unprefixed `rsx` exists only on a development plain-http request (a browser rejects a non-Secure `__Host-` cookie) | plain http, development | `rsx` | implemented |
| sess-cookie-04 | one attribute set: Path=/, no Domain, HttpOnly, Lax | options() | as listed | implemented |

## Client_Ip_Test (php, no DB) - the one client-address resolver

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-ip-01 | an untrusted peer is the client; its X-Forwarded-For is ignored | peer 203.0.113.5 with forged XFF (incl. a forged loopback hop) | 203.0.113.5 | implemented |
| sess-ip-02 | through a loopback peer the rightmost untrusted XFF entry is the client | `<spoof>, <client>[, ::1]` via 127.0.0.1 / ::1 | `<client>` | implemented |
| sess-ip-03 | a device in front of nginx is the client until declared in rsx.http.trusted_proxies, then skipped | `<spoof>, <client>, 10.1.2.3`, undeclared / CIDR / address | 10.1.2.3 / `<client>` / `<client>` | implemented |
| sess-ip-04 | X-Forwarded-Host is never trusted | loopback peer + `X-Forwarded-Host: evil.example` | the request's own host | implemented |
| sess-ip-05 | a rotated X-Forwarded-For from a non-loopback peer does not move the Login_Throttle key | live POST /login from the container's own address with a forged XFF | the counter is keyed on the peer, not the header | verified live 2026-10-05 (needs a non-loopback source address; not scripted) |

## http/session_link_handshake.sh (http - live server)

The linked-session handshake end to end, as two cookie jars (staff, portal side) against the
live server in its configured same-host layout (SKIP on a separate-host box; SKIP when the
application's can_impersonate denies its most privileged role). Self-seeding via tinker.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-http-07 | the three legs end with the portal side's ONE rsx cookie set to the staff row's token; the replaced portal-side row is deactivated; the nonce cookie is cleared; the staff row carries the impersonation; a replayed leg 3 is refused | seeded staff row + portal-side row, leg 1 from Session_Link::begin_impersonation() | leg 1 302 + rsx_link; leg 2 302; leg 3 302 with exactly one `Set-Cookie: rsx=<staff token>`; replaced row active=0; replay 400 with the generic message | implemented |

## Deferred / planned

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| sess-http-01 | web-mode session creation + cookie set on first activate | http | needs live HTTP context | deferred |
| sess-http-03 | CSRF verify against a real stored token | http | needs persisted web session | deferred |
| sess-http-04 | Session::reset() clears cookie in web mode | http | calls setcookie() | deferred |
| sess-http-05 | multi-device: get_sessions_for_user / terminate_* / terminate_all_other | http | thin wrappers; need real session rows over HTTP | deferred |
| sess-06 | get_current_session_info() | php/http | thin wrapper on already-tested parsing | deferred (low value) |

## Session_Terminate_Ownership_Test (php) - terminate_session() ownership predicate

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| sess-term-01 | a user ends their OWN sign-in on another browser | php | a browser signed in as the caller | true; user session signed out (reason 4), pointer cleared, browser row kept | implemented |
| sess-term-02 | another user's user session id is fail-closed | php | a browser signed in as another login | false, still signed in | implemented |
| sess-term-03 | a signed-out user session is not terminable | php | the caller's user session already signed out | false | implemented |
| sess-term-04 | a caller with no login identity terminates nothing | php | logged out, any signed-in user session | false, still signed in | implemented |

## Session_Terminate_For_User_Test (php) - the GUARDED cross-user primitives

`terminate_session_for_user()` / `terminate_all_sessions_for_user()` authorize
self-or-can_admin_role and THROW `AjaxUnauthorizedException` on refusal, while ABSENCE
still returns false/0. `_deactivate_sessions_for_user()` is the unchecked
framework-internal path. `developer_terminate_sessions()` is authorized by
`Session::is_developer()` alone and runs through that internal path. Roles used: ROOT_ADMIN(200) over MANAGER(500), MANAGER over
MANAGER (peer), USER(600) under MANAGER.

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| sess-termfu-01 | an actor whose role may administer the target's terminates it | php | ROOT_ADMIN acting, MANAGER target's active row | true, row deactivated | implemented |
| sess-termfu-02 | self-service works through the cross-user function | php | actor terminates own row | true, row deactivated | implemented |
| sess-termfu-03 | ABSENCE is false, never a throw | php | valid authority, unknown session id | false | implemented |
| sess-termfu-04 | the operator's own CURRENT session is refused with false | php | actor names the user session on the browser it is using (Session::$_browser_session_id_for_tests) | false, still signed in | implemented |
| sess-termfu-05 | a PEER follows the whitelist | php | a role acting on a second holder of the same role | permitted and the row deactivated when the role names itself in can_admin_roles (equal-or-lower administration); otherwise AjaxUnauthorizedException, row intact | implemented |
| sess-termfu-06 | a subordinate reaching upward is refused | php | USER acting on MANAGER | AjaxUnauthorizedException, row intact | implemented |
| sess-termfu-07 | no acting identity is refused | php | logged out, any target | AjaxUnauthorizedException ('logged-in actor'), row intact | implemented |
| sess-termfu-08 | a target with no users row on the acting site is refused | php | login identity with no site user | AjaxUnauthorizedException ('no such user on the acting site') | implemented |
| sess-termfu-09 | bulk termination by an admin, sparing one | php | 3 rows, except = one of them | returns 2, two deactivated, spared row active | implemented |
| sess-termfu-10 | bulk termination by a peer follows the whitelist | php | a role acting on a second holder of the same role | 1 row ended when the role names itself in can_admin_roles; otherwise AjaxUnauthorizedException, no row touched | implemented |
| sess-termfu-11 | bulk termination with no acting identity is refused | php | logged out | AjaxUnauthorizedException, row intact | implemented |
| sess-termfu-12 | the unchecked internal path works with no session context | php | logged out, 2 rows | returns 2, both deactivated | implemented |
| sess-termfu-13 | session.terminated payload for a cross-user termination | php | admin ends the target's user session | 1 event: actor/target/user_session_id/session_id (the browser), scope 'admin' | implemented |
| sess-termfu-14 | session.terminated scope is 'self' from terminate_session() | php | user terminates own row | 1 event, scope 'self', actor = user | implemented |
| sess-termfu-15 | session.terminated scope is 'internal' with a null actor | php | unchecked helper | 1 event, scope 'internal', actor null | implemented |
| sess-termfu-16 | each terminate path pushes a realtime session refresh | php/http | any deactivation | one push_session_refresh('staff', id) per row | deferred (no in-process assertion seam for the emissions outbox; verified live via the B3 harness, no-throw + queued) |
| sess-termfu-17 | the concurrent-session cap does NOT emit termination events | php | sign-in past max_web_sessions_per_user | rows evicted, no session.terminated | deferred (documented divergence: cap eviction is capacity management, not termination) |
| sess-termfu-18 | `developer_terminate_sessions()`: a developer holding a subordinate role ends ONE session of an identity with no users row on the acting site (no role is consulted) | php | developer acting, stranger identity with two rows | 1; named row inactive, other active; one session.terminated: actor = developer, scope 'admin' | implemented |
| sess-termfu-19 | `developer_terminate_sessions()` with no id ends every session but the developer's own current one | php | target with two rows; developer with one other row | 2 then 1; current session active; event scope 'self' on the developer's own identity | implemented |
| sess-termfu-20 | `developer_terminate_sessions()` ABSENCE is 0, never a throw | php | unknown id, another identity's row, the current session id | 0 each; rows untouched | implemented |
| sess-termfu-21 | `developer_terminate_sessions()` REFUSES a non-developer | php | an administering role; nobody signed in | AjaxUnauthorizedException for the one and the bulk form; row intact | implemented |

## Rsx_Auth_Verify_Credentials_Test (php, default isolation) - verify_credentials() classification + recording, and no sign-in

verify_credentials() is the only place that can tell the three outcomes apart, so it is the place that
records them. Failure counters are redis keys outside the per-test transaction: unique emails per
test, per-IP counter read as a delta ('CLI'), keys deleted in teardown.

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| sess-auth-01 | an unknown address is classified NOT_FOUND | php | fresh email + any password | null, no row, email counter 1, log line carries the email + failed_not_found | implemented |
| sess-auth-02 | a wrong password against a real identity is classified FAILED_PASSWORD | php | real user, bad password | null, no row, counter 1, no identity, last_login untouched, log line carries failed_password | implemented |
| sess-auth-03 | a soft-deleted identity is NOT_FOUND (SoftDeletes global scope), even with the right password | php | deleted user, correct password | null, no row, counter 1, no identity, failed_not_found logged | implemented |
| sess-auth-04 | success answers the identity, records exactly ONE row and signs NOBODY in | php | real user, correct password | the identity, 1 SUCCESS row w/ login_user_id, get_login_user_id null, failure counter 0; login() then signs in | implemented |
| sess-auth-09 | a check leaves an existing session as it was | php | signed in as A; verify B's pair (right, then wrong) | still signed in as A both times | implemented |
| sess-auth-05 | $record = false records no failure | php | real user, bad password, record: false | null, no row, no counter increment | implemented |
| sess-auth-06 | $record = false success answers the identity and writes nothing (the 2FA pre-check shape) | php | correct password, record: false | the identity, no row, last_login null, nobody signed in | implemented |
| sess-auth-07 | malformed input is not an attempt | php | {}, email only, password only, empty password | null x4, no row, no email counter, no IP counter | implemented |
| sess-auth-08 | $touch_last_login: a real login stamps last_login and a dev-auth harness login does not | http | POST /login vs rsx:debug --user=N | last_login bumped / unchanged | deferred (no CLI observability: Session::set_login_user_id() returns from its CLI branch before the stamp; verified live during B2) |

## Password_Verifier_Test (php, default isolation) - equal-time refusal of an unknown address

An unknown address must cost what a known one costs, or the clock enumerates accounts. The
mechanism is pinned deterministically through the dummy-verification counter
(`Password_Verifier::$_dummy_verifications`), never by wall-clock timing. Every call passes
record: false, so nothing outside the per-test transaction is written.

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| sess-pwv-01 | the dummy hash is held to the current hashing config | php | `Password_Verifier::_dummy_hash()` | `Hash::needsRehash()` false; same algorithm as `Hash::make()` | implemented |
| sess-pwv-02 | the dummy's own plaintext never authenticates an unknown address | php | fresh email + the dummy plaintext | null | implemented |
| sess-pwv-03 | staff: an unknown address pays exactly one dummy verification | php | fresh email | null; counter +1 | implemented |
| sess-pwv-04 | staff: a soft-deleted identity takes the same path | php | trashed identity, correct password | null; counter +1 | implemented |
| sess-pwv-05 | staff: a known address pays its own hash, not the dummy | php | real identity, wrong password | null; counter +0 | implemented |
| sess-pwv-06 | portal: an unknown address pays exactly one dummy verification | php | portal request, fresh email | null; counter +1 | implemented |
| sess-pwv-07 | portal: a known address pays its own hash, not the dummy | php | real portal user, wrong password | null; counter +0 | implemented |

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
| sess-thr-10 | verify_credentials() therefore still works in CLI | php | CLI, failure recorded, then verify_credentials() | answers the credential question rather than refusing | implemented |
| sess-thr-11 | Login_History::record_failure() reaches the throttle | php | record_failure() in CLI | the call is made and stays harmless (no address) | implemented |
| sess-thr-12 | verify_credentials() THROWS for a locked-out web client, correct password included | http | 10 wrong passwords then the correct one from one X-Forwarded-For address | the 11th answers "You're doing that too fast"; another address is unaffected | deferred (no CLI observability: Session::get_client_ip() is null in CLI by design, so the ambient-IP throw cannot be driven from php; verified live over HTTP against /login and /_portal/login on 2026-08-30) |

Session::set_temporary_site_id() - a DECLARED tenant that writes nothing, via
`Session_Temporary_Site_Test`.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| sess-tmpsite-01 | declaring a tenant creates NO _sessions row, however much is then asked of the session | php | set_temporary_site_id(4242), then has_session/get_site_id/is_logged_in/get_login_user_id/get_user_id | row count unchanged | implemented | 2026-08-24 |
| sess-tmpsite-02 | an existing row is NOT rewritten, and the process's own tenant returns on clear | php | a test browser row, process tenant 9, then declare 4242 | row unchanged, no user session; get_site_id 4242; after clear, 9 | implemented | 2026-10-21 |
| sess-tmpsite-03 | get_site_id() returns the declared tenant | php | declare 7 | 0 before, 7 after | implemented | 2026-08-24 |
| sess-tmpsite-04 | has_session() answers TRUE - site-scoped code guards on it before trusting get_site_id() | php | declare 7 | false before, true after | implemented | 2026-08-24 |
| sess-tmpsite-05 | a tenant is not an identity - declaring one signs nobody in | php | declare 7 | is_logged_in false, get_login_user_id null, get_user_id null | implemented | 2026-08-24 |
| sess-tmpsite-06 | it outranks a declared CLI context | php | impersonate(site 9), then declare 4242 | 9, then 4242 | implemented | 2026-08-24 |
| sess-tmpsite-07 | clear returns to normal resolution | php | declare 7, then clear | has_temporary false, has_session false, site_id 0 | implemented | 2026-08-24 |
| sess-tmpsite-08 | clear is safe with nothing declared - one assignment, not an undo of a write | php | clear with no declaration | no throw, row count unchanged | implemented | 2026-08-24 |
| sess-tmpsite-09 | re-declaring replaces rather than stacking | php | declare 7 then 8, one clear | 8, then 0 | implemented | 2026-08-24 |
| sess-tmpsite-10 | the test reset seam drops it, so it cannot leak between tests | php | declare 7, reset_impersonation() | has_temporary false, site_id 0 | implemented | 2026-08-24 |
| sess-tmpsite-11 | in WEB mode it emits no Set-Cookie and mints no row | http | a request that declares a tenant with no session cookie | no Set-Cookie, no row | deferred (structural: the method has no mode branch and both reader branches sit BEFORE self::init(), so the cookie is never read; would need a dedicated probe route to observe) | 2026-08-24 |

## Session_Temporary_Site_Leak_Test (php, transactions on) - a declared tenant does not outlive its test

`set_temporary_site_id()` declares a tenant for the rest of the script and OUTRANKS every
other tier, the CLI declaration included. That is correct for its real callers - a web request
or a one-shot command - and `Rsx_Initial_User::create()` deliberately leaves one standing. A
TEST RUN is not that script: the runner creates the baseline user in-process, so a declaration
was left behind in a process that then ran every remaining class. `__acting_as_site()` still
wrote, but `get_site_id()` kept answering the baseline site, so a fixture seeded "in another
site" landed in the baseline one and every cross-tenant assertion after it passed vacuously.

The two methods are ONE test and their order is the mechanism.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-templeak-01 | a declaration is in force inside the test that made it | `set_temporary_site_id(1)` | `has_temporary_site_id()` true | implemented |
| sess-templeak-02 | the next test does not inherit it | the following method | `has_temporary_site_id()` false | implemented |
| sess-templeak-03 | acting as a site means something again | `__acting_as_site(42)` | `get_site_id()` is 42, not the leaked tenant | implemented |


## Ajax_Debug_Identity_Test (php, default isolation) - `rsx:ajax --user=` establishes the whole identity

Runs the command in-process (Artisan::call) against `Ajax_Debug_Identity_Fixture_Controller`,
an endpoint that reports the three identity parts as it sees them. Pins the 2026-09-04 field
report: the command used to set only the login identity, so `get_user_id()` answered null in
every user-scoped endpoint while the actor stamp was correct.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-ajaxdbg-01 | --user + --site set all three parts | `--user=1 --site=<baseline>` | login 1, site, users.id 1 | implemented |
| sess-ajaxdbg-02 | email form resolves the same | `--user=<email of login 1>` | users.id 1 | implemented |
| sess-ajaxdbg-03 | site resolved from the single users row | `--user=1` (no --site) | baseline site, users.id 1 | implemented |
| sess-ajaxdbg-04 | no users row on the site is a real state, named | login user with no row, `--site --show-context` | exit 0, user_id null, context line says NULL and why | implemented |
| sess-ajaxdbg-05 | --show-context prints the resolved site user | `--user=1 --site --show-context` | "Set user_id to 1 (...)" | implemented |
| sess-ajaxdbg-06 | no site anywhere, no --site: refused | login user with no rows | exit 1, `site_required`, names --site | implemented |
| sess-ajaxdbg-07 | several sites, no --site: refused | rows on sites A and B | exit 1, `site_required`, lists A and B | implemented |
| sess-ajaxdbg-08 | several sites, --site chooses | rows on A and B, `--site=B` | site B and B's users row | implemented |


## Enabled_Membership_Test (php, default isolation) - `users.is_enabled` and `sites.is_enabled` are the framework's

The framework's own site-membership switch, enforced in two places: at sign-in
(`RsxAuth::verify_credentials()` / `RsxAuth::login()` / `has_enabled_membership()`) and at request time
(`Session::enforce_enabled_membership()`, which the dispatcher and the Ajax browser entry point
call ahead of the `#[Auth]` gates). Failures are redis counters outside the per-test
transaction, so every test uses a fresh email and deletes its keys in teardown. Memberships are
written inside a DECLARED tenant - the site-scope trait sets `site_id` from the session and
refuses a cross-site save or delete.

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| sess-enabled-01 | the only membership is disabled: refused like a wrong password, classified FAILED_DISABLED | php | identity + one `is_enabled = 0` membership, correct password | `verify_credentials()` null, no identity, no success row, counter 1, `failed_disabled` logged | implemented |
| sess-enabled-02 | no membership at all is the same answer | php | credential row only, correct password | `verify_credentials()` null, no identity | implemented |
| sess-enabled-03 | one enabled membership out of two authenticates | php | disabled on site 1, enabled on a second site | `verify_credentials()` answers the identity, identity set, one SUCCESS row | implemented |
| sess-enabled-04 | `has_enabled_membership()` reads across every site | php | none -> disabled -> enabled | false, false, true | implemented |
| sess-enabled-05 | `login()` refuses without touching the session or recording | php | disabled identity | false, session identity unchanged, no history row | implemented |
| sess-enabled-06 | request time: disabling a membership ends the session | php | acting identity, then `is_enabled = 0` | `enforce_enabled_membership()` false, `get_login_user_id()` null | implemented |
| sess-enabled-07 | request time: a DELETED membership ends it too | php | acting identity, then soft-delete the row | false, logged out | implemented |
| sess-enabled-08 | anonymous is permitted and asking creates nothing | php | no identity | true, `has_session()` false | implemented |
| sess-enabled-09 | end to end: a disabled membership mid-session redirects a page to login and answers an Ajax call `auth_required` | http | live session, disable the row, request a page / call an endpoint | 302 to login / `auth_required` envelope | deferred (the http harness runs against the development database; disabling a live account's membership there is not a fixture the suite may create - verified by hand with `rsx:debug` during W2) |
| sess-enabled-10 | an enabled membership on a DISABLED site is refused like a wrong password | php | one enabled membership, its site `is_enabled = 0` | `verify_credentials()` null, no identity, no success row, counter 1, `failed_disabled` logged; `login()` false | implemented |
| sess-enabled-11 | a disabled site locks out only its own memberships | php | enabled memberships on a disabled and an enabled site | `verify_credentials()` answers the identity, identity set | implemented |
| sess-enabled-12 | `->active()` / `is_active()` - the one definition a site picker lists and accepts from - drop a disabled or deleted site | php | memberships on an enabled, a disabled and a soft-deleted site | scope lists only the enabled site; `is_active()` true / false / false | implemented |
| sess-enabled-13 | request time: disabling the SITE ends the session | php | acting identity, then `sites.is_enabled = 0` | `enforce_enabled_membership()` false, logged out | implemented |
| sess-enabled-14 | the Default site (id 0) refuses to be disabled | php | `Site_Model::find(0)`, `is_enabled = 0`, save | `RuntimeException` "cannot be disabled", row unchanged | implemented |
| SESSION-USER-EXPORT-01 | User_Model::toArray() and its JSON encoding (window.rsxapp.user, model fetch, the API) omit invite_code, invite_accepted_at and invite_expires_at | php | user 1 with an invite code set in memory | none of the three keys present; the code never appears in the JSON | implemented |

## User_Preference_Variables_Test (php) - get_variable / set_variable on the user records

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| pref-var-01 | a write is read back from the table | set, then a fresh instance | unset: default and not had; set: the value, had | implemented |
| pref-var-02 | values round-trip; null is a value | false, 0, '', a list, a map, null; a second write | each returned unchanged; one row per key; null had and answered over the default | implemented |
| pref-var-03 | forget | an absent key; a set key | no error; gone on the instance and in the table | implemented |
| pref-var-04 | three records, three stores | the same key on an identity, a membership, a portal user | three values; another identity has none | implemented |
| pref-var-05 | never in the payload | `toArray()` on the writer and on fresh instances | no trace of the key or the value | implemented |
| pref-var-06 | soft delete keeps, row removal cascades | soft delete, then delete the row | value still read; then zero rows | implemented |
| pref-var-07 | an unsaved record is refused | `set_variable()` on a new model | a RuntimeException saying to save first | implemented |
| SESSION-IMP-01 | the staff session listing leaves an impersonation of the user out by default | php | the user's own user session and an impersonation user session of them | only the own one; `is_impersonation` false | implemented | 2026-10-21 |
| SESSION-IMP-02 | asked for, the impersonation is answered and marked | php | the same rows, `$include_impersonations = true` | two entries; `is_impersonation` true on the impersonated one | implemented | 2026-10-11 |
| SESSION-IMP-03 | the site filter and the impersonation filter apply together | php | an impersonation row, listed for its site | 0 by default, 1 on request | implemented | 2026-10-11 |
| SESSION-IMP-04 | the portal listing leaves a View as Client session out by default and marks it on request | php | one own portal row and one staff row viewing as the portal user | only the own row; both on request, marked | implemented | 2026-10-11 |
| SESSION-IMP-05 | the daily task ends a staff impersonation older than `rsx.sessions.impersonation_max_days` together with the impersonator's sign-in behind it, each keeping its record | php | impersonations started 8 and 6 days ago and the user's own sign-in, limit 7 | the 8-day impersonation and its impersonator's sign-in signed out (reason 11), that browser's pointer null; the others signed in | implemented | 2026-10-21 |
| SESSION-IMP-06 | an old portal View as Client is ended alone | php | a browser signed into staff, viewing as a portal user for 9 days | portal user session signed out (reason 11), portal pointer null, staff sign-in untouched | implemented | 2026-10-21 |
| SESSION-IMP-07 | `0` switches the impersonation age limit off | php | a 400-day impersonation, limit 0 | still signed in | implemented | 2026-10-21 |
| SESSION-LIST-01 | a signed-out user session is listed (marked, with its reason) within `rsx.sessions.unused_session_days`, an older one is not | php | sign-outs 2 and 60 days old | only the 2-day one; `is_signed_in` false, `sign_out_reason` 'Signed out' | implemented | 2026-10-21 |
| SESSION-LIST-02 | a harness browser is never listed | php | a TYPE_PLAYWRIGHT browser and a web one, same account | only the web one | implemented | 2026-10-21 |

## Password_Reset_Required_Test (php, default isolation) - the staff refusal and the flag's lifecycle

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| prr-01 | a flagged identity's correct password throws, carrying the identity, and is recorded | password_reset_required set, right password | Auth_Password_Reset_Required_Exception (MESSAGE, ->identity); one FAILED_PASSWORD_RESET_REQUIRED row, no success row, no failure count; nobody signed in; the reader labels it | implemented |
| prr-02 | the refusal is recorded even with record: false | the same, record: false | throws; the refusal row | implemented |
| prr-03 | a wrong password on a flagged identity is an ordinary null | wrong password | null, no row, one failure count | implemented |
| prr-04 | a flagged identity with no active membership is still null | membership disabled | null, no refusal row, counted (FAILED_DISABLED) | implemented |
| prr-05 | an unflagged identity is unaffected | right password | the identity, one SUCCESS row | implemented |
| prr-06 | a password change clears the staff flag in the same write | new hash saved | stored flag 0, instance false | implemented |
| prr-07 | any other change keeps the staff flag | timezone saved | stored flag 1 | implemented |
| prr-08 | a new password saved WITH the flag keeps it | temporary password + require_password_reset() | stored flag 1 | implemented |
| prr-09 | the portal model clears its flag on a password change, and only then | status change, then set_password() + save | 1 after the status change, 0 after the password | implemented |

## Portal_Login_History_Test (php, default isolation) - the portal binding of the history store

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| plh-01 | rows carry the portal user and its site; the reader answers them labelled, newest first, with no location | record_success + record_refusal outside a portal request | site_id from the portal user; two rows, refusal first, labels; location null; no failure count | implemented |
| plh-02 | the realms never read each other's rows | a staff and a portal row under the same numeric id | each reader answers its own realm only | implemented |
| plh-03 | list_page() takes 'portal_user_id' and refuses the staff key | three rows, limit 2; login_user_id | total 3, two rows; InvalidArgumentException naming Portal_Login_History::list_page | implemented |
| plh-04 | the retention sweep prunes portal rows on the same window | one row aged 400 days | aged row gone, recent kept | implemented |
| plh-05 | a user-agent string a portal row names survives the sweep | an enrollment row's agent aged 10 days | the _user_agents row kept | implemented |

## Model_Never_Export_Union_Test (php, no DB) - $neverExport is one control, unioned up the chain

Fixture chain `Never_Export_Fixture_Base_Model_Abstract` -> `Never_Export_Fixture_Model` (beside the
test, built unsaved). User_Payload_Never_Export_Test covers the user invite columns.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| nexp-01 | a subclass list ADDS to its parent's | fixture leaf declaring one column, base another | never_exported_columns() has both; toArray() strips both, keeps the rest | implemented |
| nexp-02 | a subclass list never leaks upward | the base's never_exported_columns() | the base's own column only | implemented |
| nexp-03 | the identity secrets are on the list | Login_User_Model, Portal_User_Model | password + remember_token; password + metadata (membership, so an override may add) | implemented |
| nexp-04 | makeVisible() cannot reveal a staff identity secret | makeVisible(['password','remember_token']) | neither key, no hash in the JSON | implemented |
| nexp-05 | makeVisible() cannot reveal a portal password | makeVisible('password') | no password key | implemented |
| nexp-06 | a portal user's metadata never serializes, server code still reads it | set_metadata() | no metadata key or value in toArray()/JSON; get_metadata() answers | implemented |

## Session_User_Sessions_Test (php, default isolation) - the user session engine

The browser-addressed primitives every web sign-in and sign-out is built from, driven against
fixture browser rows (the web flow itself is http/session_sign_in_rotation.sh).

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sess-us-01 | the same account on the same browser and site reuses its user session (A/B/A) | activate A, B (A replaced), A again (B replaced) | A's id again, sign_in_count 2, signed in, pointed at; two user sessions on the browser | implemented |
| sess-us-02 | a different site, impersonator or browser is a different user session | four tuples | four ids; the first tuple answers its own id again | implemented |
| sess-us-03 | the tuple hash equals the migration's SQL | staff and portal tuples with nulls | PHP hash = SHA2(CONCAT_WS(...)) | implemented |
| sess-us-04 | a realm's sign-out clears THAT realm's values; the other realm's and the guest values stay | staff + portal sign-ins, a value in each realm; sign each out | staff value gone after the staff sign-out, portal after the portal one, guest kept throughout; row survives | implemented |
| sess-us-04a | another account signing in over a realm (SIGN_OUT_REPLACED) clears it; a site switch (pointer to the same person) keeps it | a staff value; SITE_SWITCHED then REPLACED | kept, then gone | implemented |
| sess-us-09 | the browser's first sign-in in any realm stamps `_sessions.first_signed_in_at`, once | a guest fixture; two portal activations | null, then stamped; the second leaves it | implemented |
| sess-us-05 | ending an impersonation returns the pointer to the suspended sign-in | impersonation + the impersonator's own | pointer = own, still signed in | implemented |
| sess-us-06 | ending the impersonator's sign-in ends the impersonation riding it | the same, sign out the own | both signed out, pointer null | implemented |
| sess-us-07 | signing out twice is a no-op; the first stamp and reason stand | sign out, then again with another reason | second call answers nothing | implemented |
| sess-us-08 | a command has no user session | CLI identity | get_user_session_id() and get_current_session_info() null | implemented |

## Session_Password_Change_Test (php, default isolation) - a password change signs the identity out everywhere else

The consequence lives in the identity models' `updated` hook; the tests save a new password
through the model and read the user sessions back.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| spc-01 | changed from a browser: the other web sign-in ends, this browser and the API key session stay | two web sign-ins + a REALM_API one; browser = the first | other signed out, reason SIGN_OUT_PASSWORD_CHANGED, pointer cleared; first and API signed in | implemented |
| spc-02 | changed with no browser (command, task): every web sign-in ends | two web sign-ins + a REALM_API one; no browser | both signed out with the reason; API signed in | implemented |
| spc-03 | impersonations of the identity end; another identity and a non-password save end nothing | own + impersonation-of + bystander's sign-in | non-password save ends nothing; then own + impersonation end, bystander stays | implemented |
| spc-04 | the portal twin | two portal sign-ins (one browser also staff-signed-in) | other portal sign-in ends with the reason; changing browser and the staff sign-in stay | implemented |
| SITE-DECL-01 | a site-scoped read with no site declared throws, naming the model; a scoped where('site_id', n) is not a declaration | php | `php/Site_Declaration_Test.php`, User_Model::find(1) after a reset | RuntimeException "Site-scoped read of User_Model with no site declared" | implemented | 2026-10-27 |
| SITE-DECL-02 | a declared site (temporary, or site 0 named) reads; without_site_scope() needs no declaration | php | set_temporary_site_id(1), set_site_id(0) | user 1 read on site 1; site 0 read runs scoped to 0; the bypass reads user 1 | implemented | 2026-10-27 |
| SITE-DECL-03 | a site id with no sites row is recorded by the setter and refused at the first scoped query | php | set_temporary_site_id(987654321), then a read | get_site_id() answers the id; the read throws "there is no site 987654321" | implemented | 2026-10-27 |
| SITE-DECL-04 | a row read through without_site_scope() saves inside the same callback and is refused when carried out and saved under another ambient site | php | `php/Site_Declaration_Test.php`, user 1 at declared site 0 | the inner save passes; the outer save throws "serious security issue" | implemented | 2026-10-27 |
