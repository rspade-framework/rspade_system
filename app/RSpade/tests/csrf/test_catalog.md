# Test catalog: csrf

Status legend: `implemented` | `deferred` (reason) | `blocked` (see issues) | `planned`.
Type: php / cli / asset / http / playwright. Last updated: 2026-08-09.

## Csrf_Enforce_Test (php, no-db isolation) - enforce() decision matrix, in-process

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| csrf-01 | foreign Origin rejected | php | POST, HTTP_ORIGIN=evil host | HttpResponseException | implemented |
| csrf-02 | foreign Referer rejected (no Origin) | php | POST, HTTP_REFERER=evil host | HttpResponseException | implemented |
| csrf-03 | same-origin + no session allowed | php | POST, Origin=localhost, no session | no throw | implemented |
| csrf-04 | header-less + no session allowed | php | POST, no Origin/Referer, no session | no throw (non-browser caller) | implemented |
| csrf-05 | session present + missing token rejected | php | acting_as_site, no token | HttpResponseException | implemented |
| csrf-06 | session present + bad token rejected | php | acting_as_site, _csrf_token=bogus | HttpResponseException | implemented |
| csrf-07 | ajax reject shape is json 200 | php | /_ajax URI, session, no token | response 200 + `_success` | implemented |
| csrf-08 | native reject shape is 419 | php | native URI, session, no token | response 419 | implemented |
| csrf-09 | portal facade path (foreign Origin) | php | enforce($req, true), evil Origin | HttpResponseException | implemented |

Note: the "session present + a VALID token -> ACCEPT" case is NOT expressible in CLI
(`Session::$_session` is null under CLI, so `verify_csrf_token()` can never return true). It is
covered by the http round-trip below.

## Csrf_Reject_Contract_Test (php, no-db isolation) - the rejection contract + @csrf realm

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| csrf-10 | portal-prefixed /_ajax is ajax-shaped | php | /_portal/_ajax URI, evil Origin, portal facade | 200 + `_success` + mismatch reason | implemented |
| csrf-11 | portal-prefixed /_upload is ajax-shaped | php | /_portal/_upload URI, evil Origin | 200 + `_success` | implemented |
| csrf-12 | handler chain returns ajax rejection verbatim | php | render(HttpResponseException) | same status + body | implemented |
| csrf-13 | handler chain returns 419 rejection verbatim | php | render(HttpResponseException) | 419 + mismatch text | implemented |
| csrf-14 | Playwright request keeps the contract | php | render() with X-Playwright-Test | 200 + same body (not a 500 dump) | implemented |
| csrf-15 | rejection survives app.debug off | php | render() with app.debug=false | 200 + same body (not the fatal screen) | implemented |
| csrf-16 | portal rejection verbatim | php | render(HttpResponseException), portal URI | 200 + same body | implemented |
| csrf-17 | @csrf is realm-aware | php | Blade::compileString('@csrf') | branches on is_portal_request(), both facades | implemented |
| csrf-18 | @csrf emits nothing session-less | php | Blade::render('@csrf') in CLI | empty output | implemented |
| csrf-19 | @csrf names both facades fully-qualified | php | compiled directive | both FQCNs present | implemented |

Note: csrf-14/csrf-15 are the two variants that pin the fix independently of environment -
Playwright_Exception_Handler dumps ANY unrecognised Throwable as a 500 regardless of debug,
and Web_Exception_Handler renders the fatal screen whenever development+app.debug is not the
case. Both would replace the csrf contract if the short-circuit were removed.

## csrf_roundtrip.sh (http) - accept path + full dispatcher seam over real HTTP

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| csrf-http-01 | session-less login needs no token | http | POST /login, no token, no Origin | 302 (login works) | implemented |
| csrf-http-02 | token readable from window.rsxapp.csrf | http | GET authenticated page | csrf hex present | implemented |
| csrf-http-03 | token-less session POST rejected | http | POST /_ajax probe, cookie, no token | body contains "CSRF token mismatch" | implemented |
| csrf-http-04 | valid X-CSRF-Token header accepted | http | POST probe + header token | no "CSRF token mismatch" | implemented |
| csrf-http-05 | valid _csrf_token body field accepted | http | POST probe + body-field token | no "CSRF token mismatch" | implemented |
| csrf-http-06 | staff ajax rejection contract | http | POST /_ajax probe, foreign Origin | 200 + `"error_code":"unauthorized"` | implemented |
| csrf-http-07 | portal ajax rejection contract | http | POST /_portal/_ajax probe, foreign Origin | 200 + same JSON | implemented |
| csrf-http-08 | native form rejection status | http | POST /login, foreign Origin | 419 + "CSRF token mismatch" | implemented |
| csrf-http-09 | Playwright rejection not a 500 dump | http | POST /_ajax probe + X-Playwright-Test | 200 + JSON contract | implemented |

## The exempt paths - covered in the concerns that own them

`Rsx_Csrf::enforce()` returns early for three hardcoded paths, each carrying its own
authorization. The rows proving an exemption is PATH-EXACT live with the feature that needs
it, because that is where a change would break them, and each is written as a matched pair -
the same cross-site POST, one path apart, with opposite outcomes.

| ID | Purpose | Type | Where | Status |
|----|---------|------|-------|--------|
| csrf-exempt-01 | `/_sso/apple/callback` passes the origin check (Apple's `form_post` is cross-site by construction), and the identical POST to any sibling `/_sso/*` path is still rejected | http | `tests/sso/http/sso_http_surface.sh` steps 3-4 | implemented |
| csrf-exempt-02 | the exempt Apple leg does NO WORK - it re-emits a whitelist of three parameters and 303s to the GET leg, resolving no provider and reading no session | php | `tests/sso/php/Sso_Controller_Test.php` | implemented |
| csrf-exempt-03 | `/_csp-report` accepts the browser's unattended report POST (no page, no form, no token to attach) | http | `tests/csp/http/csp_header_and_collector.sh` step 4 | implemented |
| csrf-exempt-04 | `/_mail/unsubscribe` accepts an RFC 8058 one-click POST carrying its own HMAC, server-to-server with no browser | http | not yet pinned - `tests/mail/` | planned |
