# Concern: errors (Error_Screens)

## Domain

What a terminal request outcome RENDERS. Every dispatch that ends without an
application response - a gate denial, an unmatched URL, an uncaught exception -
comes out of one renderer per outcome instead of a mix of `abort()` calls and
Laravel's unthemed default blades.

Server side (`App\RSpade\Core\Errors\Error_Screens`), one funnel and one
`Error_Context` per failure:

| Outcome | Entry point | Result |
|---------|-------------|--------|
| Denial | `unauthorized(Request, ?realm)` | 302 to the realm's login route (no session) or a 403 page |
| Unmatched URL | `not_found(Request)` | 404 page |
| Crash | `fatal(Request, ?Throwable)` | 500 page; detail only for a developer caller (`Rsx_Diagnostics`), an error id otherwise |
| CSRF on a native form | `expired(Request)` | 419 page |
| Coded validation on a web GET | `bad_request(Request, string)` | 400 page carrying the reason |
| Any other abort() status | `http_status(Request, int, string)` | that status, as a page |
| Development browse of `/error/<code>` | `preview(Request, string)` | the page as it would look |

`Error_Pages::resolve()` decides WHICH page renders: the realm's own
`/error/<status>`, then `/error/generic`, a portal falling to the staff pair, and
the framework's standalone Blade when nothing is declared or the application's
page fails.

The SPA twin (`Core/SPA/Error_Screens.js`) renders the same three outcomes into
the live layout's content area, using the components the running bundle registered
with `Error_Screens.set_components()` (the template's set lives in
`rsx/theme/components/feedback/errors/`, the panel's in `Sys/app/sys/`). The registry's
browser rows run on the panel and are catalogued in `sys_panel` (RP-ERR-*).

## Applicability

- The unauthorized SPLIT (login redirect vs 403) lives here and nowhere else -
  both dispatchers and the exception chain call into it.
- Redaction is a security property keyed on the CALLER, not a formatting choice:
  an error page is fully inspectable with curl, and a development site may be
  public. `Rsx_Diagnostics::caller_sees_detail()` is the one predicate every
  channel asks (web, Ignition, Ajax, batch, API).
- The exception-chain seam must stay BEHIND the dispatch bootstrapper. RSX
  routing is a Laravel 404 the bootstrapper catches; a handler claiming 404s
  ahead of it would take every RSX route offline.

## Source files under test

- `app/RSpade/Core/Errors/Error_Screens.php`
- `app/RSpade/Core/Errors/Error_Pages.php`, `app/RSpade/Core/Errors/Error_Context.php`
- `app/RSpade/Core/Dispatch/Route_ManifestSupport.php` (ROUTE-ERROR-01, and the
  portal twin that calls it)
- `app/RSpade/Core/Session/Rsx_Csrf.php` (the native 419)
- `resources/views/errors/rsx_error.blade.php`
- `app/RSpade/Core/Exceptions/Web_Exception_Handler.php`
- `app/RSpade/Core/Dispatch/Dispatcher.php` (terminal paths, `page_failure_response()`; both realms)
- `app/RSpade/Core/SPA/Error_Screens.js`, `app/RSpade/Core/SPA/Spa.js`

## Test fixtures

- `php/Error_Screens_Route_Fixture_Controller.php` - one routable GET surface
  (`/test-errors/record/:id`) standing in for the deep page a denied caller was trying
  to reach. `Login_Redirect` drops an underscore-led or unroutable target, so the
  threading assertion needs a route that is neither, and the framework declares none of
  its own. Indexed only while the suite is running.
- `php/Error_Pages_Fixture_Controller.php` - the application error pages the funnel
  tests render, plus two ordinary GET routes that end on a coded outcome. Declared at
  `/test-error-pages/...` rather than under `/error/`: error-page patterns are one
  global namespace per realm, so a fixture there would collide with the application's
  real pages for as long as the suite is indexed. `Error_Pages::_testing_set_resolver()`
  points resolution at it instead.

## Behavior of record

- `php artisan rsx:man error_pages` - the declaration, ROUTE-ERROR-01, the funnel
- `php artisan rsx:man auth_gates` - ERROR SCREENS
- `php artisan rsx:man class_override` - the PHP customization story
- `php artisan rsx:man login_redirect` - what the redirect thread validates

## Testable surface

- **php**: the split matrix (anonymous / authenticated / explicit portal realm),
  statuses and bodies for every screen, the redaction rule across modes, the
  dispatcher's unmatched-URL path, the exception-handler mapping + ordering, the
  resolution chain, the two fallbacks (a page that throws, a page that answers with
  a coded response), the development preview and its absence in a sealed build, and
  ROUTE-ERROR-01 in both realms.
- **playwright**: the SPA screens (gate denial renders at the denied URL with
  history moved; an unmatched SPA URL renders the not-found body inside the live
  layout). Covered today by `rsx:debug --eval` probes rather than a committed
  spec - see the catalog.
- **http**: the curl status matrix (302 / 200 / 404, staff and portal). Covered
  today by manual curl during the epic - see the catalog.
