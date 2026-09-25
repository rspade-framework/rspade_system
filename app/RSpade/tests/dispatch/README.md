# Dispatch concern

The request's path into RSX: the front controller (one entry point, one classification,
one error policy per channel), then the one dispatcher for both realms: URL -> route
match -> handler load -> pre_dispatch hooks -> action -> response building, or, on the
AJAX channel, the one Ajax core. Covered: the front controller and the channel
classification, the default `/_/Name/action` route, the auth-rejection surface of the
dispatcher (the seam where a denial becomes an HTTP response), abort() statuses and
thrown coded failures on a page, direct/batched Ajax transport parity, the
ROUTE-VERB-01 build rule, and the dev-auth credential.

## Source under test

- `app/RSpade/Core/Dispatch/Rsx_Front_Controller.php` - `handle()`, the kernel's router
  destination: the re-entrancy guard, the preamble, the channel pipelines and the
  per-channel error policy (an ASSET failure is plain text; every other failure is handed
  to the exception handler chain exactly once).
- `app/RSpade/Core/Dispatch/Rsx_Request_Channel.php` - `classify()`: ASSET / API / AJAX /
  PAGE plus the staff/portal realm, decided once; `Rsx_Portal::is_portal_request()` reads
  it.
- `app/Http/Kernel.php` - `dispatchToRouter()`: Laravel's router is never consulted.
- `app/RSpade/Core/Dispatch/Route_ManifestSupport.php` - ROUTE-VERB-01: a `#[Route]` /
  `#[Portal_Route]` may declare GET and/or POST only.
- `app/RSpade/Core/Dispatch/Dispatcher.php`
  - `__call_main_pre_dispatch()` - the realm's Main::pre_dispatch; `__call_action()` - the controller or non-controller static `pre_dispatch`, then the action
    (B4.4: a throwing hook must NOT be swallowed).
  - `__handle_special_response()` - the FULL-PAGE path that turns an
    `Error_Response` into an HTTP response (B4.6 / B-31: unauthorized full-page GET
    must redirect to login or 403, never 500).
  - `__redirect_to_login()` - context-aware (staff / portal) login redirect that
    threads the intended URL via `Login_Redirect::capture()`.
  - `page_failure_response()` - the page answer for an `abort()` or a thrown coded
    exception (`AjaxUnauthorizedException` and family), shared with
    `Web_Exception_Handler`.
  - `__handle_dev_auth()` / `__handle_portal_dev_auth()` - the rsx:debug harness
    identity assertion (signed `X-Dev-Auth-*` headers), one per realm. A PRESENT-but-rejected token names its failure through
    `console_debug('AUTH', ...)`; it never silently renders anonymous without saying so.
- `app/RSpade/Core/Debug/Dev_Auth_Token.php` - the ONE mint + verifier both realms
  use (the staff and portal verifiers differ only by the realm argument), the
  wire format, the grant-keyed signature and the 60-second credential lifetime.
- `app/RSpade/Core/Ide/Ide_Bridge_Token.php` - `active_secrets()`, the signing key
  source (the `ide` concern owns the store's own behavior).
- `app/RSpade/Core/Debug/Playwright_Exception_Handler.php` - the plain-text stack
  trace (it declines an HTTP status, which renders as the page a browser would get), and `app/RSpade/Core/Debug/Debugger.php` - the `X-Playwright-Console-Debug`
  override. Two disclosure paths opened by UNSIGNED headers, so both additionally
  require development mode and `is_loopback_ip()` (`app/RSpade/helpers.php`).
- `app/RSpade/Commands/Rsx/Route_Debug_Command.php` - signs the FRAGMENT-FREE url
  (a browser never transmits a fragment) while Playwright navigates the full url, and
  hands the credential to the node child through its ENVIRONMENT, never argv.
- `app/RSpade/Core/Response/Error_Response.php` - `redirect` is always null; the
  dispatcher (not the response object) decides the routing.
- `app/RSpade/Core/Ajax/Ajax.php` - `handle_browser_request()` / `handle_batch_request()`,
  the two AJAX transports over ONE core (`execute()`) and ONE envelope
  (`call_envelope()` / `error_envelope()`); the batch cap
  (`rsx.ajax.batch_max_calls`) and whole-batch refusal; and `_handle_special_response()`,
  the in-process channel that throws `AjaxUnauthorizedException` /
  `AjaxAuthRequiredException` (JSON `error_code`), never redirects.

## Behavior that defines correctness

- A non-controller `pre_dispatch` that THROWS denies access - the exception bubbles
  to the framework exception handler; it is never downgraded to a log line + proceed.
- Full-page (non-ajax) GET, not authenticated (`ERROR_AUTH_REQUIRED`, or
  `ERROR_UNAUTHORIZED` while `!Session::is_logged_in()`): 302 to the context-correct
  login route (`Login_Controller` / `Portal_Login_Controller`) with the intended URL
  threaded as `?redirect=`.
- Full-page GET, authenticated but forbidden (`ERROR_UNAUTHORIZED` while logged in):
  a genuine 403 (`abort(403)`), not a login redirect and not a 500.
- One entry, one render. RSX dispatch runs once per request (a second entry is
  `shouldnt_happen()`), and a failure anywhere in it is rendered once by the channel's
  policy - a build-artifact miss is a plain-text 404, a 404 raised after the action
  returned is the 404 page with the action run once. No Laravel route is reachable, and
  the external API answers 404 on the portal's dedicated domain.
- The default route `/_/Controller/action` qualifies only a `Rsx_Controller_Abstract`
  method with a `#[Route]` surface (never an `#[SPA]` bootstrap, never Ajax/fetch/API), no
  `/error/` route and scalar query values; GET redirects to its real URL, POST runs it
  only when its route accepts POST. `GET /_/<JsSpaAction>/index` redirects to that staff
  SPA action's `@route` URL; POST never qualifies for a JS action.
- A thrown coded failure on a page (`AjaxUnauthorizedException`, `AjaxNotFoundException`,
  ...) is the page its code deserves - login redirect or 403, 404 - never a 500, whether
  the dispatcher or `Web_Exception_Handler` answers it; a HEAD failure keeps its status and
  has no body.
- A direct and a batched Ajax call to the same endpoint produce the identical envelope for
  every outcome; a batched call runs on the real request with its own params; a batch over
  the cap or malformed is refused whole with 400 and nothing in it runs.
- `abort()` INSIDE an RSX-dispatched action produces its own status: the Dispatcher
  converts it at the seam that invoked the action.
  404 and 403 render the same `Error_Screens` the `Web_Exception_Handler` uses; every
  other status keeps its status, headers and message. A request whose `Accept` header
  does not prefer `text/html` (the `<img>` / `fetch()` channel that the framework file
  routes serve) gets the bare status and a one-line body instead of a themed page.
  Only `HttpExceptionInterface` is converted - anything else propagates untouched.
- The Ajax channel answers the same rejection with the `error_code` JSON contract and no
  redirect.
- The dev-auth credential is signed with the local development GRANT (never APP_KEY),
  EXPIRES, is scoped to one url + user + realm, verifies against either active grant
  (a rotation mid-run is not a forgery) and against no retired one, and is refused
  outside development mode - `Rsx::is_development()`, because Laravel's
  `app()->environment()` reports `local` for a sealed debug build too.
- The two Playwright disclosure paths answer the local harness only. The unsigned
  header is never the gate: development mode AND a loopback caller are both required,
  where loopback means the peer is loopback and every address a proxy declared in
  `X-Forwarded-For` / `X-Real-IP` is loopback as well.

## Test fixtures

- `php/Front_Controller_Fixture_Controller.php` - `/_test/front/*` routes for the front
  controller and the default route: an action whose result raises a 404 after it
  returned, one that re-enters the front controller, a GET-only and a GET+POST route, and
  an Ajax endpoint. `$invocations` counts action runs.

- `php/Ajax_Parity_Fixture_Controller.php` - one Ajax endpoint per outcome (success,
  validation, thrown denial, not-found, abort 404, crash, real-caller echo) plus one page
  route that throws a denial (`/_test/front/thrown-denial`); `$invocations` counts
  endpoint bodies run.
- `php/Ajax_Main_Hook_Probe.php` - stands in for the application's Main in
  `Ajax_Transport_Parity_Test`, recording each `pre_dispatch` call or halting it.
- `php/Dispatch_Abort_Fixture_Controller.php` - `/_test/dispatch/*` routes whose actions
  only `abort()`, for the status-code seam.
- `php/Dispatch_Page_Fixture_Controller.php` - `/test-dispatch/page`, a routable GET page
  with NO leading underscore. The full-page rejection tests assert the intended URL is
  threaded back as `?redirect=`, and `Login_Redirect` drops an underscore-led target, so
  that assertion needs a route of this shape - which the framework declares none of.

## Testable surface

- **php** (in-process, direct on `Dev_Auth_Token` and the handler/predicate): the
  credential matrix (`Dev_Auth_Token_Test`) and the disclosure gates
  (`Playwright_Disclosure_Gate_Test`, which binds requests with synthetic
  `REMOTE_ADDR` / forwarded headers).
- **php** (in-process, reflection on the two protected methods): the throw-not-
  swallowed behavior, the null-proceeds path, the three full-page routing outcomes,
  and the ajax-channel pin. Plus the `abort()` matrix, dispatched through
  real fixture routes (`Dispatch_Abort_Fixture_Controller`, `/_test/dispatch/*`)
  because the behavior only exists at the dispatch seam.
- **http** (curl / live rsx:debug run): the dev-auth fragment regression, and the
  wire-level channel split - a logged-out full-page
  GET 302s to `/login?redirect=...`; the same rejection via an ajax endpoint returns
  `error_code:unauthorized` with no redirect. Plus `abort_status.sh`: an unknown
  `/_preview/pdf/:key` is 404 on the wire (browsed and asset channels alike) and
  `abort(418)` arrives as 418.
