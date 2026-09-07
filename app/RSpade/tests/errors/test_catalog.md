# Test Catalog: errors (Error_Screens)

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| ERR-01 | Anonymous denial goes to login, not a 403 | php | `unauthorized()` with no session | 302, Location contains `/login` | implemented | 2026-08-07 |
| ERR-02 | The intended URL is threaded for the return trip | php | `unauthorized()` on `/clients/view/5` | Location carries `redirect=` with the target | implemented | 2026-08-07 |
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
| ERR-13 | Other statuses keep their own views | php | handler with HttpException(429) | null (declines) | implemented | 2026-08-07 |
| ERR-14 | Development + app.debug keeps the debug error page | php | handler with a plain exception, dev mode, debug on | null (declines) | implemented | 2026-08-07 |
| ERR-15 | Production renders the redacted screen through the chain | php | handler with a plain exception, production mode | 500, redacted | implemented | 2026-08-07 |
| ERR-16 | The handler runs after the dispatch bootstrapper | php | priorities + config registration | 1100 > 1000, registered | implemented | 2026-08-07 |
| ERR-17 | SPA gate denial renders at the denied URL, layout alive | playwright | dispatch to an action with an ungranted `@auth` | URL updated, Unauthorized component present, no action mounted | planned (proved by `rsx:debug --eval` probe during W4; no committed spec) | 2026-08-07 |
| ERR-18 | Unknown SPA URL renders the not-found body in the layout | playwright | `Spa.dispatch()` to an unmatched current URL | Not_Found component present, layout alive | planned (same) | 2026-08-07 |
| ERR-19 | `Error_Screens.fatal()` renders and stops the action | playwright | direct call | Generic component present, action null | planned (same) | 2026-08-07 |
| ERR-20 | curl status matrix, staff and portal | http | anon gated / authed gated / unknown URL / portal gated | 302 / 200 / 404 / 302 portal login | planned (manual curl during W4) | 2026-08-07 |
| ERR-21 | A missing theme error component fails loud | playwright | unregister the component, call a screen | throws naming the component | planned | 2026-08-07 |
