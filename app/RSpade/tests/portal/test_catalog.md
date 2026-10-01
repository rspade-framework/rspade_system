# Portal - Test Catalog

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| PORTAL-AUTHZ-01 | own-record: a portal user may read only their own row | php | two portal users, login as A | A.portal_can_read true, B.portal_can_read false | implemented | 2026-06-23 |
| PORTAL-AUTHZ-02 | portal_fetch gates on session + ownership | php | unauth then auth as A | false unauth; array for own; false for other | implemented | 2026-06-23 |
| PORTAL-AUTHZ-03 | membership-scoped: access mirrors membership; non-member row denied | php | member of X only | access X true / Y false; membership row readable; Y project row denied | implemented (application suite) | 2026-09-08 |
| PORTAL-AUTHZ-04 | viewer vs collaborator roles + can_collaborate | php | viewer + collaborator on same client | viewer cannot collaborate; collaborator can | implemented (application suite) | 2026-09-08 |
| PORTAL-AUTHZ-05 | can_collaborate/client_role deny without membership | php | user, no membership | client_role null; can_collaborate false | implemented (application suite) | 2026-09-08 |
| PORTAL-AUTHZ-06 | accessible_client_ids returns only member clients | php | member of A,B not C | [A,B]; C excluded | implemented (application suite) | 2026-09-08 |
| PORTAL-AUTHZ-07 | shared-recipient: only linked contact may read | php | share to contact R | R reads; other contact denied; unlinked user denied | implemented (application suite) | 2026-09-08 |
| PORTAL-AUTHZ-08 | wrong-site membership is invisible -> denied | php | membership on site 1, login on other site | has_client_access false | implemented (application suite) | 2026-09-08 |
| PORTAL-AUTHZ-09 | portal route gating is declarative: a gated route denies anonymous access, a `#[Auth('public')]` route serves it | php | portal login vs SPA route | login 200; spa 302 to portal login with capture | superseded by the auth-gates seam tests (Auth_Gates_Seam_Test) | 2026-08-07 |
| PORTAL-AUTHZ-10 | RETIRED with PORTAL-AUTH-01 (2026-08-07) - an ungated portal surface now fails the MANIFEST BUILD; covered by `Auth_Validation_Test` in the `auth_gates` concern | php | - | - | retired | 2026-08-07 |
| PORTAL-AUTHZ-11 | gate denies a protected route, allows an exempt one (HTTP) | http | curl /_portal/settings vs /_portal/login | 302->login vs 200 | deferred (verified manually via curl) | 2026-06-23 |

Notes:
- PORTAL-AUTHZ-01 and -02 live in `php/Portal_Authorization_Test.php` (framework types
  only). PORTAL-AUTHZ-03 through -08 assert the APPLICATION's own portal models and
  permission facade, so they live in the application suite
  (`rsx/tests/Portal_Client_Authorization_Test.php`).

## Portal_Session_Terminate_Ownership_Test (php) - terminate_session() ownership predicate

| ID | Purpose | Type | Input | Expected | Status | Last updated |
|----|---------|------|-------|----------|--------|--------------|
| PORTAL-TERM-01 | a portal user terminates their OWN session | php | a _sessions row carrying the caller's portal identity | true, portal properties cleared, ROW survives | implemented | 2026-08-10 |
| PORTAL-TERM-02 | another portal user's session id is fail-closed | php | row owned by a different portal_user_id | false, row intact | implemented | 2026-08-05 |
| PORTAL-TERM-03 | a caller with no portal identity terminates nothing | php | logged out, any row | false, row intact | implemented | 2026-08-05 |

## Portal_Site_Declaration_Test (php) - the portal site contract (set_site_id)

| ID | Purpose | Type | Input | Expected | Status | Last updated |
|----|---------|------|-------|----------|--------|--------------|
| PORTAL-SITE-01 | a declared site is what get_site_id() returns | php | set_site_id(77) | 77 (no config, no default) | implemented | 2026-08-09 |
| PORTAL-SITE-02 | re-declaring the same site is idempotent | php | set_site_id(1) twice | 1, no throw | implemented | 2026-08-09 |
| PORTAL-SITE-03 | a declaration is portal state in CLI | php | reset then set_site_id | has_session false -> true | implemented | 2026-08-09 |
| PORTAL-SITE-04 | reset() clears the CLI declaration | php | set_site_id then reset | get_site_id throws | implemented | 2026-08-09 |
| PORTAL-SITE-05 | an undeclared site throws, naming set_site_id + the man page | php | nothing declared | RuntimeException citing rsx:man portal | implemented | 2026-08-09 |
| PORTAL-SITE-06 | get_site() fails over the same way | php | nothing declared | RuntimeException | implemented | 2026-08-09 |
| PORTAL-SITE-07 | a non-positive site id is refused | php | set_site_id(0) / (-3) | Rsx_Caller_Exception | implemented | 2026-08-09 |
| PORTAL-SITE-08 | CLI may re-scope to another site (no request boundary) | php | set_site_id(1) then (77) | 77 | implemented | 2026-08-09 |
| PORTAL-SITE-09 | declaring creates no session row | php | set_site_id + get_site_id | _sessions count unchanged | implemented | 2026-08-09 |
| PORTAL-SITE-10 | a portal session's site is stamped at creation and never rewritten | php | persisted portal row | site_id stable | implemented | 2026-08-09 |
| PORTAL-SITE-11 | a web request with NO declaration 500s with the guidance message | http | remove the template declaration, GET /_portal/login | 500 naming set_site_id + rsx:man portal | verified manually 2026-08-09 (needs a broken template to run) | 2026-08-09 |

Notes:
- All `php` rows live in `php/Portal_Site_Declaration_Test.php`.
- CLI cannot exercise `__activate()` (no portal session row is minted in CLI by
  design), so row-creation-uses-the-declared-site is covered by the live portal
  login round-trip, not by a php test.

## Portal_Session_Realm_Test (php) - PROPERTY isolation on the one session row

There is one session per browser and no realm discriminator. The staff and portal
identities are two sets of COLUMNS on that row, and both being set at once is legal.
These rows hold the property boundary that replaced the old row boundary.

| ID | Purpose | Type | Input | Expected | Status | Last updated |
|----|---------|------|-------|----------|--------|--------------|
| PORTAL-PROP-01 | a portal identity sets no staff property | php | row with portal_user_id | login_user_id null, site_id 0, portal_site_id set | implemented | 2026-08-10 |
| PORTAL-PROP-02 | both identities on ONE row is legal | php | row with both ids | both readable | implemented | 2026-08-10 |
| PORTAL-PROP-03 | View as Client writes only portal properties | php | Session::_apply_portal_impersonation() on a row with a staff login | portal_user_id/portal_site_id/impersonator_user_id/impersonation_started_at set; login_user_id intact, impersonator_login_user_id null | implemented | 2026-10-01 |
| PORTAL-PROP-04 | a session with no portal identity is not a portal session | php | staff-only row token | Portal_Session::find_by_token null; Session::find_by_token finds it | implemented | 2026-08-10 |
| PORTAL-PROP-05 | a portal session resolves through both facades | php | portal row token | both find it - it is one row | implemented | 2026-08-10 |
| PORTAL-PROP-06 | the staff session list never returns a portal-only row | php | portal row, staff list for the same integer | empty | implemented | 2026-08-10 |
| PORTAL-PROP-07 | the portal session list never returns a staff-only row | php | staff row, portal list for the same integer | empty | implemented | 2026-08-10 |
| PORTAL-PROP-08 | portal termination never reaches a staff-only row | php | terminate a staff-only row id | false, row intact | implemented | 2026-08-10 |
| PORTAL-PROP-09 | staff termination never reaches a portal-only row | php | terminate_all_sessions_for_user | 0 affected, row active | implemented | 2026-08-10 |
| PORTAL-PROP-10 | portal termination preserves the staff login on the SAME row | php | row with both ids, terminate | portal props cleared, login_user_id + active intact | implemented | 2026-08-10 |
| PORTAL-PROP-11 | the portal cap CLEARS the oldest beyond the limit | php | 3 sessions, cap 2 | oldest row survives with portal_user_id null | implemented | 2026-08-10 |
| PORTAL-PROP-12 | the portal cap never counts or evicts harness sessions | php | 1 playwright + 2 web, cap 2 | all three keep their portal identity | implemented | 2026-08-10 |
| PORTAL-PROP-13 | the portal cap never touches a staff property | php | staff row with the same integer | login_user_id + active intact | implemented | 2026-08-10 |

## Portal_Realm_Site_Seams_Test (php) - "which site is this?" forks on the EXPERIENCE (B-76)

Every framework seam that resolves a site used to ask the STAFF facade unconditionally,
so on a portal request the tenant came from whatever the co-resident staff cookie was on
(portal on the application host) or from site 0 (portal on its own host). Each row below declares a STAFF site and a
DIFFERENT PORTAL site, then asserts which one the seam picks; under the old behavior every
portal row returns the staff site.

| ID | Purpose | Type | Input | Expected | Status | Last updated |
|----|---------|------|-------|----------|--------|--------------|
| PORTAL-SEAM-01 | the ORM tenant boundary answers with the portal's declared site | php | portal request, staff site A, portal site B | get_current_site_id() == B | implemented | 2026-08-10 |
| PORTAL-SEAM-02 | the staff branch is untouched | php | staff request | get_current_site_id() == A | implemented | 2026-08-10 |
| PORTAL-SEAM-03 | a model written on a portal request belongs to the portal tenant | php | save a Portal_User_Model on a portal request | site_id == B; invisible to a staff request on A | implemented | 2026-08-10 |
| PORTAL-SEAM-04 | a portal request with no declared site THROWS, never scopes to 0 | php | portal request, nothing declared | RuntimeException naming set_site_id | implemented | 2026-08-10 |
| PORTAL-SEAM-05 | Rsx_Time::get_site_timezone() follows the experience | php | site A New_York, site B Paris | portal Paris; staff New_York | implemented | 2026-08-10 |
| PORTAL-SEAM-06 | the get_user_timezone() memo is not shared across experiences | php | staff -> portal -> staff | New_York, Paris, New_York | implemented | 2026-08-10 |
| PORTAL-SEAM-07 | a site-scoped Rsx_Settings value stores under the portal site | php | set on staff then on portal | two rows; each experience reads its own | implemented | 2026-08-10 |
| PORTAL-SEAM-08 | the Rsx_Throttle bucket is keyed on the portal site | php | same action+user on both experiences | portal call not throttled by the staff one; row filed under B | implemented | 2026-08-10 |
| PORTAL-SEAM-09 | Rsx_Sms files a queue row + blocklist lookup under the portal site | php | portal request send() | _sms_queue.site_id == B | deferred - no portal SMS caller exists yet; the fork is identical to Rsx_Mail's, which is covered by the mail concern | 2026-08-10 |
| PORTAL-IMP-RO-01 | the #[Portal_Impersonation_Readable] mark is baked into the surface index | php | fixture controller, marked `read` + unmarked `write` | `impersonation_readable` true / absent | implemented (`Portal_Impersonation_Read_Only_Test`) | 2026-09-25 |
| PORTAL-IMP-RO-02 | while impersonating, an unmarked portal Ajax endpoint is refused before it runs; a marked one runs | php | `Ajax::internal` in the portal realm with an impersonator set | AjaxUnauthorizedException "read-only session", write never ran; read ran | implemented (`Portal_Impersonation_Read_Only_Test`) | 2026-09-25 |
| PORTAL-IMP-RO-03 | without impersonation both run | php | same, no impersonator | both counters 1 | implemented (`Portal_Impersonation_Read_Only_Test`) | 2026-09-25 |
| PORTAL-IMP-RO-04 | the framework's portal-reachable reads a portal page needs carry the mark | php | surface index | Orm fetch / fetch_relationship, Spa_Session get_state, Realtime tokens, File_Preview info marked | implemented (`Portal_Impersonation_Read_Only_Test`) | 2026-09-25 |

Notes:
- All `php` rows live in `php/Portal_Realm_Site_Seams_Test.php`.
- `Rsx_Portal::set_portal_request()` is the request-context seam; it is a process-wide
  static, so teardown always restores it to false.
- The live counterpart is not a test file: the template's `Main::init()` staff site
  declaration was NEUTRALIZED and a full portal login + site-scoped portal Ajax was run
  over HTTP (workspaces returned the portal tenant's own rows). That proved portal
  tenancy no longer rides the staff line.

## Portal_Route_Parity_Test (php) + playwright/portal_route_parity.js - one URL generator

Rsx_Portal::Route() / Rsx_Portal.Route() select and generate with Rsx's own routines and add
only the portal base, so the reserved `at` key rides the #at= anchor on a portal URL exactly as
on a staff one (rsx:man anchors).

| ID | Purpose | Type | Input | Expected | Status | Last updated |
|----|---------|------|-------|----------|--------|--------------|
| PORTAL-ROUTE-01 | staff URL: whole-token replacement, query, anchor last | php | fixture `/test-route-parity/:id/:id_type`, `{id:5,id_type:7,at:'a b',x:1}` | `/test-route-parity/5/7?x=1#at=a%20b` | implemented | 2026-09-25 |
| PORTAL-ROUTE-02 | portal URL is the staff URL with the portal base | php | the portal twin fixture, same params | `portal_path()` of the staff URL | implemented | 2026-09-25 |
| PORTAL-ROUTE-03 | an empty anchor appends nothing | php | `at => ''` | no fragment | implemented | 2026-09-25 |
| PORTAL-ROUTE-04 | the JS twin: same parity, #at= not ?at=, non-string action refused, a portal SPA action resolved by name | playwright | probe routes written into `Rsx._routes` / `Rsx_Portal._routes` on `/_sys`, a probe portal SPA class | identical URLs; refusal names "must be a string" | implemented (`playwright/portal_route_parity.js`) | 2026-09-25 |
| PORTAL-ROUTE-05 | the hash argument: the portal URL carries the hash state before the anchor, identical to the staff URL with the portal base (the JS twin is DISP-53 d) | php | `['tab' => 'a b', 'gone' => null]` with the PORTAL-ROUTE-01 params | `portal_path('/test-route-parity/5/7?x=1#tab=a%20b&at=a%20b')` | implemented (`Portal_Route_Parity_Test`) | 2026-09-25 |

## Portal_Url_Test (php) - PORTAL_URL: derivation, refusals, classification, URL generation

| ID | Purpose | Type | Input | Expected | Status | Last updated |
|----|---------|------|-------|----------|--------|--------------|
| PORTAL-URL-01 | a blank PORTAL_URL derives APP_URL's origin + /_portal; default port dropped, non-default kept | php | `parse('', APP_URL)` | origin/host/prefix/separate | implemented | 2026-10-01 |
| PORTAL-URL-02 | blank PORTAL_URL with an empty APP_URL derives only the prefix | php | `parse('', '')` | origin '', prefix /_portal | implemented | 2026-10-01 |
| PORTAL-URL-03 | a path on the application host is a same-host prefix (case, trailing slash normalised) | php | `https://APP.../clients/` | prefix /clients, not separate | implemented | 2026-10-01 |
| PORTAL-URL-04 | a host of its own, no prefix | php | `https://Portal...:443/` | prefix '', separate | implemented | 2026-10-01 |
| PORTAL-URL-05 | a host of its own with a prefix and a port | php | `http://portal...:8443/x/y/` | origin with port, prefix /x/y | implemented | 2026-10-01 |
| PORTAL-URL-06 | `$HOSTNAME` / `${HOSTNAME}` resolve in PORTAL_URL before config | php | `patch_environment()` over a raw PORTAL_URL | OS hostname substituted | implemented | 2026-10-01 |
| PORTAL-URL-07 | usable values pass; http under the development allowance | php | `check()` | null | implemented | 2026-10-01 |
| PORTAL-URL-08 | nothing is checked while APP_URL is empty | php | garbage PORTAL_URL, empty APP_URL | null | implemented | 2026-10-01 |
| PORTAL-URL-09 | equal to APP_URL is refused after normalisation (slash, port, case, scheme) | php | five spellings | "must not equal APP_URL" | implemented | 2026-10-01 |
| PORTAL-URL-10 | the APP_URL scheme rule applies (https outside development, http/https only) | php | http without allowance, ftp | APP_URL's messages naming PORTAL_URL | implemented | 2026-10-01 |
| PORTAL-URL-11 | a non-absolute value is refused | php | bare host, bare path | "must be an absolute" | implemented | 2026-10-01 |
| PORTAL-URL-12 | credentials, query and fragment are refused | php | three values | "may carry only" | implemented | 2026-10-01 |
| PORTAL-URL-13 | malformed path segments are refused | php | `.`, empty segment, `%`, space | "path segments" | implemented | 2026-10-01 |
| PORTAL-URL-14 | a framework-owned first segment is refused; /_portal allowed; deeper segments free | php | api, error, ws, `_` names | "belongs to the framework" | implemented | 2026-10-01 |
| PORTAL-URL-15 | the rsx:health "Portal URL" row reports the derivation and FAILs a refusal | php | three configs | OK / OK / FAIL | implemented | 2026-10-01 |
| PORTAL-URL-16 | default layout: prefix decides on any host (loopback included); artifacts realm-agnostic | php | synthetic requests | channel/realm/portal_host/realm_path | implemented | 2026-10-01 |
| PORTAL-URL-17 | a same-host prefix wins over staff routes beneath it | php | `rsx.portal.url` = APP_URL + /clients | /clients/view/5 portal | implemented | 2026-10-01 |
| PORTAL-URL-18 | a separate host without a prefix is the portal throughout; API refused there; app host staff | php | own host, no prefix | per row | implemented | 2026-10-01 |
| PORTAL-URL-19 | a separate host with a prefix: outside-prefix paths stay portal realm, /api outside is the refused API, the prefix means nothing on the app host | php | own host + /x | per row | implemented | 2026-10-01 |
| PORTAL-URL-20 | outside the prefix on the portal host is the portal 404 | php | front controller, portal fixture route in and out of /x | 200 / 404 | implemented | 2026-10-01 |
| PORTAL-URL-21 | a same-host portal generates paths from CLI and loopback | php | `Route()`, `portal_path()` | `/_portal/...` | implemented | 2026-10-01 |
| PORTAL-URL-22 | a separate-host portal generates absolute URLs off its host and paths on it | php | ambient request on app host vs portal host | absolute / relative | implemented | 2026-10-01 |
| PORTAL-URL-23 | `rsx_absolute_url()` passes an absolute URL through; the emailed-link spelling is right from staff | php | absolute input; `rsx_absolute_url(Route())` | unchanged / portal origin | implemented | 2026-10-01 |
| PORTAL-URL-24 | `is_under_prefix()` / `strip_prefix()` respect segment boundaries and query strings | php | `/_portalx`, `/_portal?tab=1` | per row | implemented | 2026-10-01 |
| PORTAL-URL-25 | the boot guard throws the first refusal on a live boot | cli | a refused PORTAL_URL in .env, any artisan command | RuntimeException naming PORTAL_URL | deferred (needs .env edited; `validate()` is `check()` + throw, covered by 07-14) | 2026-10-01 |

## Portal_Host_Reach_Test (php) - the framework file and report endpoints answer in the portal realm

| ID | Purpose | Type | Input | Expected | Status | Last updated |
|----|---------|------|-------|----------|--------|--------------|
| PORTAL-REACH-01 | every framework endpoint a portal page uses is in BOTH route tables, on one handler, with the same verbs | php | manifest `routes` vs `portal_routes` for /_upload, /_icon_by_extension, /_download, /_inline, /_download_zip, /_thumbnail/*, /_preview/*, /_csp-report | same Class::method and methods | implemented | 2026-10-01 |
| PORTAL-REACH-02 | under the default prefix they classify portal and resolve in the portal table; the bare path stays staff | php | `/_portal/_thumbnail/...`, `/_portal/_inline/k`, `/_portal/_preview/pdf/k`, POST `/_portal/_csp-report`, bare `/_inline/k` | realm, realm_path, handler per row | implemented | 2026-10-01 |
| PORTAL-REACH-03 | on a portal host of its own they are portal requests at the root | php | `rsx.portal.url` = own host | portal realm, portal-table handler | implemented | 2026-10-01 |
| PORTAL-REACH-04 | on a portal host with a prefix they are under it | php | own host + /x | `/x/_inline/k` -> inline | implemented | 2026-10-01 |
| PORTAL-REACH-05 | `Rsx_Portal::internal_url()` and the attachment URL builders follow the request's realm | php | staff, default-prefix portal, own host, own host + /x | bare / `/_portal/...` / bare / `/x/...` | implemented | 2026-10-01 |
| PORTAL-REACH-06 | the CSP report-uri names the realm's own collector | php | `report_path()`, `compose('portal')` | `/_portal/_csp-report`; bare at the root of its own host | implemented | 2026-10-01 |
| PORTAL-REACH-07 | the portal collector is CSRF-exempt (foreign Origin, no token) and a portal POST beside it is not | php | `Rsx_Csrf::enforce()` | no throw / HttpResponseException | implemented | 2026-10-01 |
| PORTAL-REACH-08 | an API key presented to a portal-realm file route is the API's 404; no key leaves the request untouched | php | `Rsx_Api_Bearer::authenticate_web_request()` | 404 not_found / null | implemented | 2026-10-01 |
| PORTAL-REACH-09 | a signed-in portal user loads a shared document's thumbnail, inline view and preview on a separate portal host | http | PORTAL_URL on its own host, a portal session, a shared attachment | 200 bytes on each | deferred (needs PORTAL_URL set in .env; the orchestrator's live check) | 2026-10-01 |

## Portal_Session_Impersonation_Test (php) - begin_impersonation_from_staff()

Staff "View as Client" has one entry point. Same host: the impersonation lands on the
caller's own row and the landing URL comes back. Separate host: leg 1 of the linked-session
handshake comes back and nothing is applied yet. The caller's `can_impersonate` answer is set
by `Portal_Impersonation_Grant_Fixture` (the check is the application's rule).

| ID | Purpose | Type | Input | Expected | Status | Last updated |
|----|---------|------|-------|----------|--------|--------------|
| PORTAL-IMP-01 | same host: the impersonation is written onto the caller's own row | php | blank PORTAL_URL, granted staff caller | `/_portal/`; portal_user_id/portal_site_id/impersonator_user_id/impersonation_started_at on the row, login_user_id intact; is_impersonating() | implemented | 2026-10-01 |
| PORTAL-IMP-02 | same host under another prefix lands under it | php | PORTAL_URL = APP_URL + /clients | `/clients/` | implemented | 2026-10-01 |
| PORTAL-IMP-03 | begin never touches the target's last_login | php | begin | last_login still null | implemented | 2026-10-01 |
| PORTAL-IMP-04 | separate host: leg 1 on the portal origin under its prefix, code stored as its hash, nothing applied yet | php | PORTAL_URL https://portal.example.test/x | URL `https://portal.example.test/x/_session_link/open?c=..&s=..`; no session token in it; `_session_links` row bound to the caller's row; no portal props on the row | implemented | 2026-10-01 |
| PORTAL-IMP-05 | a caller whose can_impersonate denies is refused | php | grant false | AjaxUnauthorizedException; nothing started | implemented | 2026-10-01 |
| PORTAL-IMP-06 | the impersonator id must be the signed-in staff user | php | someone else's id | RuntimeException | implemented | 2026-10-01 |
| PORTAL-IMP-07 | is_impersonating()/get_impersonator_user_id() follow the CLI flag | php | cli_set_impersonator_user_id | true / id | implemented | 2026-10-01 |
| PORTAL-IMP-08 | stop_impersonation() clears it | php | stop | false | implemented | 2026-10-01 |

## Portal_Session_Link_Test (php) - the linked-session handshake, leg by leg

A portal on its own host with a prefix (`https://portal.example.test/x`). Each leg is a
request rebuilt on the host it is addressed to, with exactly that host's cookies. Legs 1 and 2
and every leg-3 refusal run through `Session_Link_Controller`; leg 3's success runs through
`Session_Link::complete()`, because the cookie seam it feeds refuses in CLI - the cookie itself
is proved by `session/http/session_link_handshake.sh` (sess-http-07).

| ID | Purpose | Type | Input | Expected | Status | Last updated |
|----|---------|------|-------|----------|--------|--------------|
| PORTAL-LINK-01 | every leg is in both route tables on one handler, GET only | php | manifest | `/_session_link/{open,confirm,complete}` in `routes` and `portal_routes` | implemented | 2026-10-01 |
| PORTAL-LINK-02 | the portal legs resolve under the prefix on the portal host; confirm resolves staff on the app host | php | classify + resolve | portal realm / staff realm | implemented | 2026-10-01 |
| PORTAL-LINK-03 | happy path: leg 1 nonce cookie (path /x/_session_link, HttpOnly, Lax) + 302 to the staff host; leg 2 applies the impersonation to the staff row + 302 to the portal host; leg 3 names the staff row; every code burned; no URL carries the token | php | three legs | as stated | implemented | 2026-10-01 |
| PORTAL-LINK-04 | leg 1 refuses a tampered signature and burns nothing | php | bad `s` | 400 generic page; code survives; genuine link then works | implemented | 2026-10-01 |
| PORTAL-LINK-05 | leg 1 refuses a replayed code | php | second use | 400; no nonce | implemented | 2026-10-01 |
| PORTAL-LINK-06 | leg 1 refuses an expired code | php | expires_at in the past | 400 | implemented | 2026-10-01 |
| PORTAL-LINK-07 | leg 1 refuses its URL presented on the staff host | php | same query on APP_URL's host | 400 | implemented | 2026-10-01 |
| PORTAL-LINK-08 | leg 2 refuses a replayed code | php | second use | 400 | implemented | 2026-10-01 |
| PORTAL-LINK-09 | leg 2 refuses a tampered signature | php | bad `s` | 400; nothing applied | implemented | 2026-10-01 |
| PORTAL-LINK-10 | leg 2 refuses an expired code | php | expires_at in the past | 400; nothing applied | implemented | 2026-10-01 |
| PORTAL-LINK-11 | leg 2 refuses another leg's code even correctly signed (the leg binding is in the store) | php | leg-1 code re-signed for leg 2 | 400; nothing applied | implemented | 2026-10-01 |
| PORTAL-LINK-12 | leg 2 refuses a browser whose staff cookie names another row | php | another live row's token | 400; neither row linked | implemented | 2026-10-01 |
| PORTAL-LINK-13 | leg 2 refuses a browser with no staff cookie | php | no rsx | 400; nothing applied | implemented | 2026-10-01 |
| PORTAL-LINK-14 | leg 2 refuses an impersonator whose can_impersonate now denies | php | grant withdrawn after leg 1 | 400; nothing applied | implemented | 2026-10-01 |
| PORTAL-LINK-15 | leg 3 refuses a missing nonce cookie, and the refusal burns the code | php | no rsx_link | 400; the right nonce afterwards gets null | implemented | 2026-10-01 |
| PORTAL-LINK-16 | leg 3 refuses a mismatched nonce cookie | php | random nonce | 400 | implemented | 2026-10-01 |
| PORTAL-LINK-17 | leg 3 refuses a replayed code | php | second use | 400 | implemented | 2026-10-01 |
| PORTAL-LINK-18 | leg 3 refuses an expired code | php | expires_at in the past | 400 | implemented | 2026-10-01 |
| PORTAL-LINK-19 | leg 3 refuses a tampered signature | php | bad `s` | 400 | implemented | 2026-10-01 |
| PORTAL-LINK-20 | Session_Cleanup_Service::cleanup_session_links removes expired links only | php | one live, one expired | expired gone, live kept | implemented | 2026-10-01 |
| PORTAL-LINK-21 | Session::_clone_session_to_this_host() refuses in CLI | php | call in CLI | RuntimeException (no browser) | implemented | 2026-10-01 |
| PORTAL-LINK-22 | a live separate-host round trip on the dev box (two real hosts) | http | needs PORTAL_URL on another host in the box's .env | - | deferred (the suite never edits a box's .env, and a docker worker's .env is the dev box's defaults; the host binding is PORTAL-LINK-07/11 in-process and the cookie is sess-http-07 over HTTP in the same-host layout) | 2026-10-01 |
