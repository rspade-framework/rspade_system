# Dispatch concern

The main-app request dispatcher: URL -> route match -> handler load -> pre_dispatch
hooks -> action -> response building. This concern currently covers the
**auth-rejection surface** of the dispatcher (the seam where a denial becomes an
HTTP response), added with the B4.4 / B4.6 fixes.

## Source under test

- `app/RSpade/Core/Dispatch/Dispatcher.php`
  - `__call_pre_dispatch()` - Main + non-controller static `pre_dispatch` invocation
    (B4.4: a throwing hook must NOT be swallowed).
  - `__handle_special_response()` - the FULL-PAGE path that turns an
    `Error_Response` into an HTTP response (B4.6 / B-31: unauthorized full-page GET
    must redirect to login or 403, never 500).
  - `__redirect_to_login()` - context-aware (staff / portal) login redirect that
    threads the intended URL via `Login_Redirect::capture()`.
  - `__handle_dev_auth()` - the rsx:debug harness identity assertion (signed
    `X-Dev-Auth-*` headers). A PRESENT-but-rejected token names its failure through
    `console_debug('AUTH', ...)`; it never silently renders anonymous without saying so.
- `app/RSpade/Core/Debug/Dev_Auth_Token.php` - the ONE mint + verifier both realms
  use (`Dispatcher` and `Portal_Dispatcher` differ only by the realm argument), the
  wire format, the grant-keyed signature and the 60-second credential lifetime.
- `app/RSpade/Core/Ide/Ide_Bridge_Token.php` - `active_secrets()`, the signing key
  source (the `ide` concern owns the store's own behavior).
- `app/RSpade/Core/Debug/Playwright_Exception_Handler.php` - the plain-text stack
  trace, and `app/RSpade/Core/Debug/Debugger.php` - the `X-Playwright-Console-Debug`
  override. Two disclosure paths opened by UNSIGNED headers, so both additionally
  require development mode and `is_loopback_ip()` (`app/RSpade/helpers.php`).
- `app/RSpade/Commands/Rsx/Route_Debug_Command.php` - signs the FRAGMENT-FREE url
  (a browser never transmits a fragment) while Playwright navigates the full url, and
  hands the credential to the node child through its ENVIRONMENT, never argv.
- `app/RSpade/Core/Response/Error_Response.php` - `redirect` is always null; the
  dispatcher (not the response object) decides the routing.
- `app/RSpade/Core/Ajax/Ajax.php` - `_handle_special_response()`, the SEPARATE
  ajax/API channel that throws `AjaxUnauthorizedException` / `AjaxAuthRequiredException`
  (JSON `error_code`), never redirects.

## Behavior that defines correctness

- A non-controller `pre_dispatch` that THROWS denies access - the exception bubbles
  to the framework exception handler; it is never downgraded to a log line + proceed.
- Full-page (non-ajax) GET, not authenticated (`ERROR_AUTH_REQUIRED`, or
  `ERROR_UNAUTHORIZED` while `!Session::is_logged_in()`): 302 to the context-correct
  login route (`Login_Controller` / `Portal_Login_Controller`) with the intended URL
  threaded as `?redirect=`.
- Full-page GET, authenticated but forbidden (`ERROR_UNAUTHORIZED` while logged in):
  a genuine 403 (`abort(403)`), not a login redirect and not a 500.
- `abort()` INSIDE an RSX-dispatched action produces its own status. The action runs
  inside Laravel's handling of its own `NotFoundHttpException`, so a second throw there
  used to escape as an uncaught fatal 500; the Dispatcher converts it at the seam.
  404 and 403 render the same `Error_Screens` the `Web_Exception_Handler` uses; every
  other status keeps its status, headers and message. A request whose `Accept` header
  does not prefer `text/html` (the `<img>` / `fetch()` channel that the framework file
  routes serve) gets the bare status and a one-line body instead of a themed page.
  Only `HttpExceptionInterface` is converted - anything else propagates untouched.
- The ajax/API channel is unchanged: the same rejection yields the `error_code` JSON
  contract with no redirect.
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
