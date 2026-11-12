# Core/Dispatch — the request spine

**Writing routes, controllers or Main hooks? Read `rsx:man dispatch` and `rsx:man routing`.**
This file says what is in this DIRECTORY, why it is shaped this way, and what must stay true
when editing it.

## The shape

Every HTTP request enters RSX at ONE place. `App\Http\Kernel` hands it to
`Rsx_Front_Controller::handle()` inside the global middleware stack, and **Laravel's router is
never consulted** - no Laravel route, a vendor package's included, is reachable over HTTP.
The request is classified ONCE, run through that channel's pipeline, and any failure is
rendered by that channel's error policy, once. **Nothing that renders an error dispatches
again.**

    Rsx_Request_Channel::classify()     ASSET | API | COMPANION | AJAX | PAGE  (+ realm, + portal site)
            |
    fixed preamble                      revision unit of work, Manifest::init(), hostname tripwire,
            |                           first-run screen, console_debug override (AJAX/PAGE)
    the channel's pipeline              AssetHandler | Api_Dispatcher | Companion_Dispatcher | Dispatcher
            |
    the channel's error policy          plain text | API JSON | Ajax envelope | Error_Screens page

## What is here

- `Rsx_Front_Controller.php` — the one pass above: re-entrancy guard (`shouldnt_happen()`),
  classification, preamble, the portal-address answers (301 to a custom domain, the portal's
  404), the pipeline, and the Throwable-to-response step through `config('rsx.exception_handlers')`.
- `Rsx_Request_Channel.php` — the stored answer to "what kind of request is this":
  `classify()`, `realm()` (`REALM_STAFF` / `REALM_PORTAL`, an attribute of AJAX and PAGE, not
  a channel), `realm_path()`, `is_portal_host()`. Which requests are the portal's is
  `Lib/Portal/Portal_Site_Resolver`'s reading of the URL against `PORTAL_URL`. Every later
  question reads this; nothing re-derives it from `$_SERVER`. Outside a request the answer is
  a staff PAGE.
- `Dispatcher.php` — the ONE pipeline for AJAX and PAGE in **both realms**: the realm picks a
  descriptor (`__realm()`) and everything that differs (route table, Main class, dev-auth
  header, site-membership check, FPC, the `/_/Controller/action` default route) is a key in
  it. PAGE order: public file, `/error/*` preview, route match, bearer key on a file route,
  site membership, the `#[Auth]` gates, `Main::pre_dispatch` and the controller's
  `pre_dispatch` (each with NO site lock, in an enforcement window: `Site_Lock::_run_hook()`,
  as are `Portal_Main::init()` and `Main::unhandled_route()`), then at the site lock
  boundary (`Site_Lock::_run_request()`: no automatic lock unless the route declares
  `#[Reader]` / `#[Writer]`) the action (ALWAYS in the enforcement window,
  `Site_Lock::_enforce()`), `rsx.post_dispatch` and response shaping (a thrown
  `Rollback_Response` rolls the boundary back, then its value is shaped as a returned one);
  then FPC marker, CSP.
  A coded
  failure (an `abort()`, the `Ajax*Exception` family) answers through
  `page_failure_response()`. The Ajax channel is one core, `Core/Ajax/Ajax::execute()`.
- `AssetHandler.php` — build artifacts (`/_compiled/`, `/_vendor/`) by strict filename
  pattern, and the `public/` directories. No session, no CSRF, no route scan; in development
  a missing bundle file compiles the named bundle first.
- `RouteResolver.php` — pattern matching (`/exact`, `:param`, `:param?`, `*`) and parameter
  extraction against the manifest's route tables.
- `Route_ManifestSupport.php` — builds the route index from `#[Route]` at manifest time; each
  row carries its `auth` gate list. Hosts the build FATALs `ROUTE-ERROR-01` (the `/error/`
  prefix) and `ROUTE-VERB-01` (GET/POST only); the portal twin calls both.

## Invariants

- **One entry, one classification, one error render.** A new kind of request is a new
  channel in `Rsx_Request_Channel` with its own pipeline and error policy - never a branch
  inside another channel's pipeline.
- **Staff and portal stay one pipeline.** A realm difference is a descriptor key, never an
  `if (portal)` scattered through `Dispatcher`.
- **`pre_dispatch` performs no authorization**; the gates run before it (`rsx:man auth_gates`).

## References

`rsx:man dispatch`, `rsx:man routing`, `rsx:man auth_gates`, `rsx:man error_pages`,
`rsx:man proxority` · skills `rspade:spa`, `rspade:auth-gates`, `rspade:error-pages` ·
siblings `Core/Ajax/`, `Core/Api/` (`Api_Dispatcher`), `Core/Companion/`, `Lib/Portal/`,
`Core/Errors/`.
