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
| PORTAL-PROP-03 | the impersonation handoff carries only portal properties | php | create_impersonation_session() | portal_user_id/portal_site_id/impersonator_user_id set, staff columns null | implemented | 2026-08-10 |
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
(prefix mode) or from site 0 (domain mode). Each row below declares a STAFF site and a
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

Notes:
- All `php` rows live in `php/Portal_Realm_Site_Seams_Test.php`.
- `Rsx_Portal::set_portal_request()` is the request-context seam; it is a process-wide
  static, so teardown always restores it to false.
- The live counterpart is not a test file: the template's `Main::init()` staff site
  declaration was NEUTRALIZED and a full portal login + site-scoped portal Ajax was run
  over HTTP (workspaces returned the portal tenant's own rows). That proved portal
  tenancy no longer rides the staff line. See the C2 notes in the epic research doc.
