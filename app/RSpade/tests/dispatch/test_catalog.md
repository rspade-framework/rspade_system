# Dispatch test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| DISP-01 | B4.4: a throwing non-controller `pre_dispatch` is NOT swallowed | php | non-controller handler whose static `pre_dispatch` throws | exception propagates out of `__call_pre_dispatch` | implemented | 2026-07-30 |
| DISP-02 | B4.4: a null-returning `pre_dispatch` still lets dispatch proceed | php | non-controller handler, `pre_dispatch` returns null | `__call_pre_dispatch` returns null | implemented | 2026-07-30 |
| DISP-03 | B4.6: AUTH_REQUIRED full-page GET redirects to login with intended URL | php | `Error_Response(ERROR_AUTH_REQUIRED)`, GET the concern's routed page fixture, logged out | `RedirectResponse` to `/login` containing the URL-encoded fixture path as `redirect=` | implemented | 2026-09-08 |
| DISP-04 | B4.6: UNAUTHORIZED while logged out redirects to login | php | `Error_Response(ERROR_UNAUTHORIZED)`, logged out | `RedirectResponse` to `/login` | implemented | 2026-07-30 |
| DISP-05 | B4.6: UNAUTHORIZED while logged in is a 403, not a redirect or 500 | php | `Error_Response(ERROR_UNAUTHORIZED)`, acting as a seeded user | `HttpException` with status 403 | implemented | 2026-07-30 |
| DISP-06 | Channel split: the ajax handler throws unauthorized (JSON), never redirects | php | `Ajax::_handle_special_response(Error_Response(ERROR_UNAUTHORIZED))` | throws `AjaxUnauthorizedException` | implemented | 2026-07-30 |
| DISP-07 | Wire-level split: a logged-out full-page GET to a gated route 302s to login, and an underscore-led framework path is NOT threaded back | http | GET `/_sys` (logged out) | 302 to `/login`, no `redirect=` (Login_Redirect drops a non-page target; its accept half is `Login_Redirect_Test`) | implemented | 2026-09-08 |
| DISP-08 | Wire-level split: same rejection via ajax endpoint keeps JSON contract | http | POST `/_ajax/Rsx_Timezone_Controller/get_settings` (logged out) | no redirect, body has `error_code:unauthorized` | implemented | 2026-09-08 |
| DISP-09 | Dev auth: a `#fragment` in the rsx:debug url is not signed - the harness still renders AUTHENTICATED | http | `rsx:debug /_sys` and `rsx:debug '/_sys#foo=bar'` with `--user=1` | both bodies carry `"is_auth": true`; the fragmented run navigates to `/_sys#foo=bar` | implemented | 2026-09-08 |
| DISP-10 | abort(404) in an RSX action is a 404, not the uncaught-fatal 500 it used to be | php | dispatch `/_test/dispatch/abort-404` | 404 | implemented | 2026-08-22 |
| DISP-11 | abort(403) for a SIGNED-IN caller is a real 403 | php | acting as user 1, dispatch `/_test/dispatch/abort-403` | 403 | implemented | 2026-08-22 |
| DISP-12 | abort(403) for an ANONYMOUS caller keeps the existing login-redirect split | php | logged out, dispatch `/_test/dispatch/abort-403` | 302 to `/login` | implemented | 2026-08-22 |
| DISP-13 | A status with no Error_Screens page keeps its own status and message | php | dispatch `/_test/dispatch/abort-418` | 418, body carries the abort message | implemented | 2026-08-22 |
| DISP-14 | Asset channel (Accept does not prefer html) gets a plain body, never a themed page | php | `Accept: image/webp`, dispatch `/_test/dispatch/abort-404` | 404, message body, no `<html` | implemented | 2026-08-22 |
| DISP-15 | The catch is narrow: a non-HTTP exception still propagates out of dispatch | php | dispatch `/_test/dispatch/throw` | `RuntimeException` escapes | implemented | 2026-08-22 |
| DISP-16 | Wire-level: an unknown preview rendition key is 404, on both the browsed and asset channels, and abort(418) arrives as 418 | http | GET `/_preview/pdf/doesnotexist`, `/_test/dispatch/abort-418` | 404 / plain 404 / 418 | implemented | 2026-08-22 |
| DISP-17 | Dev-auth verifies against the newest grant, and still against the PREVIOUS one after a rotation | php | mint, then `Ide_Bridge_Token::rotate()` | both verify (rotation is never a forgery) | implemented | 2026-09-06 |
| DISP-18 | A credential signed by a RETIRED (third-oldest) grant is refused | php | mint, rotate twice | rejection reason returned | implemented | 2026-09-06 |
| DISP-19 | An EXPIRED credential is refused, and the exp cannot be extended on the wire | php | `exp = time()-1`; then a rewritten exp header | 'expired' / 'signature mismatch' | implemented | 2026-09-06 |
| DISP-20 | A missing/malformed `X-Dev-Auth-Exp` or a missing token is refused | php | null, '', 'soon', '-5', '1.5' | all rejected | implemented | 2026-09-06 |
| DISP-21 | With no grant store nothing mints and nothing verifies | php | store deleted | `mint()` null, `active_secrets()` [] , verify rejects | implemented | 2026-09-06 |
| DISP-22 | Dev-auth is DEVELOPMENT ONLY - a sealed debug build (Laravel says 'local') refuses it | php | `Rsx::_testing_set_mode(debug/production)` | rejection names 'development-only' | implemented | 2026-09-06 |
| DISP-23 | The credential is scoped to one url + user + realm | php | verify against another url / user / portal flag | all three rejected | implemented | 2026-09-06 |
| DISP-24 | The signed payload is the documented wire format (key order + PHP `\/` escaping) | php | `Dev_Auth_Token::payload('/_sys', ...)` - the URL is an opaque HMAC input and never has to route | exact JSON string | implemented | 2026-09-08 |
| DISP-25 | `is_loopback_ip()`: a proxied LOCAL request is loopback, a proxied REMOTE one is not, and forwarding with no declared client fails closed | php | synthetic REMOTE_ADDR + `X-Forwarded-For` / `X-Real-IP` / `X-Forwarded-Host` | true / false / false | implemented | 2026-09-06 |
| DISP-26 | The plain-text trace handler answers the local harness and refuses a non-loopback or forwarded-remote caller, and any non-development mode | php | `Playwright_Exception_Handler::handle()` | response with trace / null / null | implemented | 2026-09-06 |
| DISP-27 | The `X-Playwright-Console-Debug` override answers the local harness only | php | private predicate on `Debugger` | true / false (remote, proxied-remote, debug mode) | implemented | 2026-09-06 |
| DISP-28 | A correctly signed credential whose expiry lies more than one lifetime out is refused (the cap is verifier-enforced) | php | sign with a live grant, exp = now + 86400 | rejection names the lifetime cap | implemented | 2026-09-06 |
