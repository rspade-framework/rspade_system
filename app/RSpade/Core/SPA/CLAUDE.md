# Core/SPA - the SPA runtime's implementation

**Building a SPA screen? Do not read this file.** The bootstrap controller, the
`@route`/`@layout`/`@spa`/`@auth`/`@title` decorators, `Spa.dispatch`/`Spa.redirect`, the title
ladder and the module layout are the fragment `31-spa-and-pages.md`, the skill `rspade:spa` and
`rsx:man spa` (route table: `rsx:man routing`; the scroll contract: `rsx:man anchors`). This file
only says what is in this DIRECTORY.

## What is here

Two halves that never meet at runtime: ONE build-time PHP module that turns decorators into route
rows, and the client-side orchestrator that consumes the same decorators off the class objects.

**Build time**

- `Spa_ManifestSupport.php` - extends `Full_ManifestSupport_Abstract`, listed in
  `config('rsx.manifest_support')`. `rebuild()` drops every `type === 'spa'` row from
  `data['routes']` and re-derives all of them from `data['js_subclass_index']['Spa_Action']`,
  reading the decorator array off each action's already-indexed file record. It owns the `spa`
  rows and nothing else - `routes` is shared with the standard and API row types, so the reset is
  scoped by type. Four loud failures: a `@route` action with no `@spa`, a `@spa` naming a class
  `php_classes` does not carry, a pattern under `/api/vN`, and a pattern any route type already
  claims. `Auth_ManifestSupport::merge_gate_lists()` is called on the bootstrap method as a
  validation pass; `_parse_decorators()` also collects `layout` and `auth`, which are NOT written
  to the row - the client reads both off the class.

**Runtime (all of it shipped by `Core_Bundle`'s `app/RSpade/Core/SPA` include, so it is in every
bundle)**

- `Spa_App.blade.php` - `@rsx_id('Spa_App')`, which is the `SPA` constant (`Core/constants.php`).
  The whole bootstrap document: the bundle's head plus `<div id="spa-root">`. `$bundle::render()`
  has no default - every caller passes `rsx_view(SPA, ['bundle' => 'X_Bundle'])`.
- `Spa.js` - the orchestrator and the bulk of the directory. Route registry, URL matching and
  generation, browser integration, `dispatch()`, the layout chain, anchor scrolling, the detached
  loader. Statics are the live state: `routes`, `layout`, `_action` (read through `Spa.action()`,
  which falls back to `$('.Spa_Action')`), `route`, `params`.
- `Spa_Action.js` - the page. `on_load()`, static/instance `url()` + `dispatch()`, the title ladder
  (`page_title()`, `get_static_title()`, `await_loaded()`) and the breadcrumb trio.
- `Spa_Layout.js` - the persistent wrapper. `$content()` (i.e. `$sid('content')`),
  `resolve_page_title(paint, placeholder)` - the ONE way a layout obtains a title - the
  `on_action(url, action_name, args)` hook, and `show_debug_exception()`.
- `Spa_Decorators.js` - seven `/** @decorator */` functions, each storing one static on the class:
  `route` -> `_spa_routes[]`, `layout` -> `_spa_layouts[]` (unshift, because decorators run
  bottom-up and the array is outermost-first), `spa` -> `_spa_controller_method`, `title` ->
  `_spa_title`, `auth` -> `_auth_checks[]`, `portal_spa` -> `_spa_controller_method` +
  `_is_portal_spa`, `spinner_until_ready(mode)` -> `_spa_spinner_until_ready` ('uncached' |
  'always', anything else throws at class definition).
- The spinner itself is `Core/Ui/Spinner_Overlay.js` (+ `spinner_overlay.scss`), which
  `Spa_Action.show_spinner()` / `hide_spinner()` wrap. `_resolve_layout_chain()` decides the
  decorator's hold (`_spinner_until_ready_applies()`, calling `was_ready_before()` only for
  'uncached') and hides it at the action's `ready()`; the previous action's spinner is hidden
  before the content area is emptied (it sits OUTSIDE it, as a sibling of the action element),
  `Error_Screens._render()` hides the action's, and `Exception_Handler` calls
  `Spinner_Overlay.hide_all()`.
- `Error_Screens.js` - the JS twin of `Core/Errors/Error_Screens.php`. Same three outcomes rendered
  INTO the live layout instead of as a new page. The bodies are whatever the running bundle
  registered through `Error_Screens.set_components({unauthorized, not_found, fatal})` from a static
  `on_app_modules_define()` - the template's set beside its components in
  `rsx/theme/components/feedback/errors/`, the panel's in `Sys/app/sys/_Sys_Error_Screens.js`. No
  default: an unregistered bundle throws naming the call.
- `Default_Layout.js` + `.jqhtml` - the `$sid="content"` passthrough `match_url_to_route()` supplies
  when an action declares no `@layout`.
- `Spa_Session_Controller.php` - `#[Auth('public')]` `#[Auth_Realm('any')]` `get_state`, returning
  `{build_key, session_hash, user, site}` for the caller's OWN session - `session_hash` is
  `Session::_client_session_hash()`, the same reader `rsxapp.session_hash` comes from (the
  session the request HOLDS, never the incoming cookie, so a session created or rotated while
  a page rendered still matches). Called only by
  `Rsx.validate_session()` (`Core/Js/Rsx.js`) after a navigation; a mismatch is a
  `window.location.reload()`. Which session it reports (staff or portal) is decided from the
  request - `Rsx_Portal::is_portal_request()` - since a portal page's Ajax arrives on the portal
  channel; the client sends nothing.

## How a dispatch runs

`Rsx`'s boot phase table calls `Spa._on_framework_modules_init` (phase `framework_modules_init`:
`discover_actions()` + `setup_browser_integration()`) and `Spa._on_spa_init` (phase `_spa_init`,
between `app_init` and `app_ready`: awaited dispatch to the initial URL). Both return immediately
unless `window.rsxapp.is_spa`.

`dispatch()` is the single choke point - link clicks, `popstate`, `redirect()` and programmatic
calls all land here. In order: the navigation guard is turned OFF when the target is another
page (`_is_fragment_only_move(location, url)` false - a dispatch is navigation from code, which
the guard never asks about; the initial load and a confirmed popstate arrive with location
already on the target, so they touch nothing); the disabled check (`Spa.disable()` degrades
everything to full page loads, `force` exempts popstate); re-entrancy, which QUEUES a nested
dispatch as `pending_redirect` and drains it in the `finally` (this is how an `on_load()` redirect
works); ORM cache reset and the `spa_dispatch_start` trigger; the four hand-offs to the server via
`_navigate_away()` (external host, same URL under `history: 'auto'`, no route match, and an action
whose `@spa` names a different bootstrap than the loaded one); history push/replace; the `@auth`
gate; `_resolve_layout_chain()`; `Rsx.validate_session()` on every navigation after the first.

The one popstate that does NOT reach `dispatch()` is a fragment-only move: when the new location
keeps the path and query of `_last_committed_url` (`_is_fragment_only_move()`), the handler
records the URL, fires `spa_hash_change {url, hash}` and returns - no prompt, no dispatch,
the action stays mounted. Fragments are not compared, because an application's own
`replaceState()` write is invisible here and can leave the recorded fragment stale.

**The navigation guard (`Rsx.set_navigation_guard()`, a boolean) is enforced in
`setup_browser_integration()`, not in `dispatch()`.** The click handler returns without
`preventDefault()` while it is on, beside the `_spa_enabled` return, so the document unloads and
`Rsx`'s `beforeunload` listener raises the native dialog. The popstate handler - after the
`_undoing_vetoed_move` swallow and the fragment-only return - raises `window.confirm(
Rsx.NAVIGATION_GUARD_PROMPT)`: Cancel calls `_undo_vetoed_move()` (`history.go()` across the
`spa_run`/`spa_index` distance, its own popstate swallowed) and returns with nothing dispatched;
OK clears the guard and falls through to the ordinary popstate dispatch. `confirm()` is
synchronous, which is what lets the answer be known before the handler returns.

`_resolve_layout_chain()` walks `#spa-root` downward comparing each container's own class against
`[...layouts, action]` - a layout or action is rendered ON its container element, not as a child of
it - reuses matching layouts, destroys from the first divergence down, and creates the rest.
Layouts are awaited to `rendered()`; the action's `ready()` is deliberately NOT awaited, and its
`.then()` is where `_action_is_loading` clears, scroll settles and `spa_dispatch_ready` fires.

Scroll restoration is ONE mechanism (`_begin_scroll_restoration()` and its siblings), decided
at dispatch start from `opts.scroll` - a popstate's recorded position, or, for the initial
dispatch of a reload / back_forward document load, `history.state.scroll` (`_on_spa_init`).
`history.scrollRestoration` is `'manual'`. It applies after `_resolve_layout_chain()`, at ready
(`_apply_scroll_position()`), then under a `ResizeObserver` until a notification reports no
height change; user input (wheel, touch, scroll keys, a scrollbar press, a scroll to a position
it did not write) ends it, and `_dispatch_is_restoration` keeps such a page off the top at
ready. Scroll anchoring is off on the scroller while it runs, so the only scrolls are its own
and the user's.

## Invariants a change here must keep

- **A SPA route is registered under its JS ACTION class, never the bootstrap controller.** The row
  carries `js_action_class` and `target` = the action, `class`/`method`/`file`/`surface` = the PHP
  bootstrap. `Rsx::Route('X_Spa_Controller::index')` therefore throws, and both URL generators
  (`Rsx::_try_spa_action_route()`, `Rsx._spa_route_patterns()`) key on the action name.
- **The row carries POINTERS to gate lists, never copies.** Dispatch-time enforcement is the
  bootstrap controller's `#[Auth]` (`auth.surfaces[<controller>::<method>]`); the action's own
  `@auth` is `auth.surfaces[<action class>]`. Client gates are UX - `_denied_auth_checks()` exists
  so the interface is honest, and the server re-runs the same named gates on every call the action
  makes. A denied action must never CONSTRUCT: that holds in `dispatch()` and in
  `load_detached_action()` alike.
- **Every layout's template needs a `$sid="content"` element** and `#spa-root` must exist; both are
  hard throws, not fallbacks.
- **The derivation is FULL, never diffed.** A `spa` row is owned by TWO files (the action and its
  bootstrap controller) - that is why the module rebuilds all of them from in-memory records rather
  than tracking ownership across a changed set.
- **Decorator metadata is the declaration.** Adding a new one means a `/** @decorator */` function
  here, a static on the class, and a reader - in `_parse_decorators()` if the BUILD needs it, in
  `Spa.js` if only the client does.
- **The portal shares this runtime; it does not fork it.** `Portal_Spa_ManifestSupport` is the
  build-time twin (`@portal_spa` -> `portal_routes`, `type => 'portal_spa'`) and is the reason
  `_record_action_routes()` returns early on `_is_portal_spa`. On the client there is one `Spa`:
  `match_url_to_route()` strips the portal prefix itself. Anything added to one support module is
  considered for the other.
- **The two millisecond constants here are not work timeouts and no new one is welcome.**
  `NAVIGATION_GRACE_PERIOD_MS` (10s) suppresses errors from the previous page's in-flight requests;
  `scroll_to_anchor`'s `timeout_ms` (500) is a look-again window for a DOM node that may still be
  rendering, whose expiry degrades to "no scroll". Nothing in a dispatch is bounded, and nothing
  should be.

## Seams outside this directory

- `Core_Bundle.php` includes `app/RSpade/Core/SPA` - this code is in every bundle.
- `BundleCompiler::_create_javascript_routes()` emits `Rsx._define_routes()` for PHP `#[Route]`s
  only; SPA patterns reach the client on the class objects. The exception is
  `config('rsx.always_published_routes')`, folded in as `Rsx._define_published_spa_routes()` so
  `Rsx.Route()` answers for an action (the `/_sys` entry point) that is not in this bundle.
- `Dispatcher.php` serves a `spa` row by calling the bootstrap controller, and separately resolves
  a `/_/<Class>/<action>` fallback URL naming a `Spa_Action` into a 302 to that action's `@route`
  pattern.
- `Auth_ManifestSupport` maps `#[SPA]` to kind `spa` in the staff realm; `Auth_Gates` treats it as a
  PAGE kind. `Manifest_Store` refuses `#[FPC]` on an `#[SPA]` method. `PHP-SPA-01`
  (`SpaAttributeMisuse_CodeQualityRule`, critical, cross-file) flags `#[Route]` beside `#[SPA]`.
- `Exception_Handler._should_show_in_layout()` reads `Spa.layout._action_is_loading` to decide
  between its in-layout debug box and `Error_Screens.fatal()`.
- `browser.js` (`scroll_anchor_into_view`, `nearest_scrollable_ancestor`) and `Rsx.HASH_ANCHOR_KEY`
  own the scroll primitives the anchor block calls.
- `@jqhtml/core` consumes the `_load_only` / `_load_render_only` args `load_detached_action()` sets.

## Tests

`app/RSpade/tests/spa/` (`rsx:test --framework --group=spa`) - one Playwright file today, covering
the title ladder; its `test_catalog.md` lists the layout-chain and history rows as holes. The
decorator transform is covered in the `js_transform` concern, the gating in `auth_gates`, and the
dispatcher's rejection surface in `dispatch`.

## See also

`rsx:man spa` - `rsx:man routing` - `rsx:man anchors` - `rsx:man auth_gates` - `rsx:man portal` -
skills `rspade:spa`, `rspade:js-decorators`, `rspade:portal-core`
