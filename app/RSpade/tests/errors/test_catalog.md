# Test Catalog: errors (Error_Screens)

The "deep page the caller wanted" is `php/Error_Screens_Route_Fixture_Controller.php`
(`/test-errors/record/:id`): the intended URL only survives into `?redirect=` when it is
routable and carries no leading underscore, and every framework route is `/_`-prefixed.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| ERR-01 | Anonymous denial goes to login, not a 403 | php | `unauthorized()` with no session | 302, Location contains `/login` | implemented | 2026-08-07 |
| ERR-02 | The intended URL is threaded for the return trip | php | `unauthorized()` on the concern's routed fixture URL | Location carries `redirect=` with the target | implemented | 2026-09-08 |
| ERR-03 | Authenticated denial is a themed 403 | php | `unauthorized()` acting as a user | 403, body contains "Access Denied" | implemented | 2026-08-07 |
| ERR-04 | An explicit realm wins over ambient detection | php | `unauthorized(..., REALM_PORTAL)` | 302 to the portal login route | implemented | 2026-08-07 |
| ERR-05 | not_found renders the themed body and status | php | `not_found()` | 404, body contains "Page Not Found" | implemented | 2026-08-07 |
| ERR-06 | The dispatcher renders its own 404 (no Laravel default) | php | `Dispatcher::dispatch()` on an unmatched URL | 404 themed body | implemented | 2026-08-07 |
| ERR-07 | fatal carries detail outside production | php | `fatal()` in development mode | 500, message + class + origin present | implemented | 2026-08-07 |
| ERR-08 | fatal redacts fully in production | php | `fatal()` in production mode | 500, no message/class/origin | implemented | 2026-08-07 |
| ERR-09 | Debug-mode builds redact with production | php | `fatal()` in debug mode | no exception detail | implemented | 2026-08-07 |
| ERR-10 | fatal works with no Throwable in hand | php | `fatal()` without an exception | 500 themed body | implemented | 2026-08-07 |
| ERR-11 | abort(404) reaches the same screen | php | handler with NotFoundHttpException | 404 themed body | implemented | 2026-08-07 |
| ERR-12 | abort(403) goes through the split | php | handler with HttpException(403), no session | 302 to login | implemented | 2026-08-07 |
| ERR-13 | Any other status is a page too | php | handler with HttpException(429) | 429, body carries the reason | implemented | 2026-09-21 |
| ERR-14 | Development + app.debug keeps the debug error page | php | handler with a plain exception, dev mode, debug on | null (declines) | implemented | 2026-08-07 |
| ERR-15 | Production renders the redacted screen through the chain | php | handler with a plain exception, production mode | 500, redacted | implemented | 2026-08-07 |
| ERR-16 | The handler runs after the dispatch bootstrapper | php | priorities + config registration | 1100 > 1000, registered | implemented | 2026-08-07 |
| ERR-17 | SPA gate denial renders at the denied URL, layout alive | playwright | dispatch to an action with an ungranted `@auth` | URL updated, Unauthorized component present, no action mounted | planned (proved by `rsx:debug --eval` probe during W4; no committed spec) | 2026-08-07 |
| ERR-18 | Unknown SPA URL renders the not-found body in the layout | playwright | `Spa.dispatch()` to an unmatched current URL | Not_Found component present, layout alive | planned (same) | 2026-08-07 |
| ERR-19 | `Error_Screens.fatal()` renders and stops the action | playwright | direct call | Generic component present, action null | planned (same) | 2026-08-07 |
| ERR-20 | curl status matrix, staff and portal | http | anon gated / authed gated / unknown URL / portal gated | 302 / 200 / 404 / 302 portal login | planned (manual curl during W4) | 2026-08-07 |
| ERR-21 | A missing theme error component fails loud | playwright | unregister the component, call a screen | throws naming the component | planned | 2026-08-07 |
| ERR-22 | The exact status page beats the catch-all | php | synthetic tables with both | `/error/404` resolved | implemented | 2026-09-21 |
| ERR-23 | A status with no page falls to the catch-all | php | synthetic table with generic only | `/error/generic` resolved | implemented | 2026-09-21 |
| ERR-24 | The generic preview skips the exact lookup | php | `resolve(500, staff, false)` with both declared | `/error/generic` resolved | implemented | 2026-09-21 |
| ERR-25 | A portal failure reads the portal table first | php | both realms declare `/error/404` | the portal row resolved | implemented | 2026-09-21 |
| ERR-26 | A portal with no page falls to the staff pair | php | staff generic only | the staff row resolved | implemented | 2026-09-21 |
| ERR-27 | Nothing declared resolves to null | php | empty tables | null | implemented | 2026-09-21 |
| ERR-28 | The application page renders with the forced status | php | resolver -> fixture page, `not_found()` | 404 + the fixture marker + the context fields | implemented | 2026-09-21 |
| ERR-29 | A page that throws falls back to the framework page | php | resolver -> throwing fixture | 404 + "Page Not Found", no marker | implemented | 2026-09-21 |
| ERR-30 | A page returning a coded response falls back | php | resolver -> `response_unauthorized()` fixture | 404 + "Page Not Found" | implemented | 2026-09-21 |
| ERR-31 | An error page carries a CSP | php | resolver -> fixture page, `not_found()` | Content-Security-Policy header present | implemented | 2026-09-21 |
| ERR-32 | A native CSRF rejection is the 419 page | php | `Rsx_Csrf::enforce` on a foreign-origin form POST | 419 + "Page Expired" | implemented | 2026-09-21 |
| ERR-33 | `response_not_found()` on a web GET renders the 404 page | php | dispatch a fixture GET route | 404 + "Page Not Found" | implemented | 2026-09-21 |
| ERR-34 | `response_form_error()` on a web GET renders the 400 page | php | dispatch a fixture GET route | 400 + the reason | implemented | 2026-09-21 |
| ERR-35 | Any HTTP status reaches a page through the handler | php | handler with 419 and 418 | each status, each body | implemented | 2026-09-21 |
| ERR-36 | The development preview renders the page | php | dispatch `/error/404` in development | 404 + the marker + `preview=yes` | implemented | 2026-09-21 |
| ERR-37 | A sealed build serves no preview | php | dispatch `/error/500` in production mode | 404 + "Page Not Found" | implemented | 2026-09-21 |
| ERR-38 | ROUTE-ERROR-01 refuses a malformed pattern, a param, a non-GET method and a gated page | php | synthetic manifests through `Route_ManifestSupport::process()` | RuntimeException naming the rule | implemented | 2026-09-21 |
| ERR-39 | ROUTE-ERROR-01 accepts a well-formed pair, class-level gate included | php | same | rows recorded | implemented | 2026-09-21 |
| ERR-40 | The portal enforces the same rule | php | synthetic manifest through `Portal_Route_ManifestSupport::process()` | refused / recorded | implemented | 2026-09-21 |
