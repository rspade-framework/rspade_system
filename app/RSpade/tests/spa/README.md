# SPA concern

Client-side routing for authenticated areas: `Spa` (dispatch, layout chain, history),
`Spa_Layout` (persistent wrapper, `on_action`), `Spa_Action` (the page), and the
`@route`/`@layout`/`@spa`/`@auth`/`@title` decorators that declare an action.

This concern covers the **page-title ladder** - the seam where a dispatched action's
title reaches a layout - and the **navigation guard**, the toggle that has the browser
ask before a page with unsaved changes is left, and **breadcrumb origins** - the reserved
`_via` parameter, carried across navigation and kept or stripped after each breadcrumb walk
(`rsx:man breadcrumb_origins`; source `Core/SPA/Spa_Via.js`, `Core/Js/Rsx_Navigation.js` `augment()`,
`Spa.js` `_rewrite_link_via()` / `via()` / `via_push()`, `Breadcrumbs/Rsx_Breadcrumb_Resolver.js`
`settle_via()` / `_walk_chain_structure()`; test `playwright/breadcrumb_origins.js`) - and the **navigation door**, `Rsx.navigate()` /
`Rsx.redirect()` (`Core/Js/Rsx_Navigation.js`: validate, classify, carry, one transport, the
full-load hand-off read once by `Spa._on_spa_init()`; test `playwright/navigate.js`) - and
**crumbs returning to the state a page was left in** (`Core/SPA/Spa_Journal.js`, `Spa.return_url()`,
the resolver's chain `href`, the one-time `_scroll` hash key in `Spa._dispatch()`; test
`playwright/breadcrumb_return_state.js`, the template's half `rsx/tests/playwright/breadcrumb_return_state.js`). Everything else in the SPA is either covered elsewhere (decorator transform
in `js_transform`, auth gating in `auth_gates`, the dispatcher's auth-rejection surface
in `dispatch`) or catalogued below as a coverage hole.

## Source under test

- `app/RSpade/Core/SPA/Spa_Action.js`
  - `page_title()` - defaults to the class's `_spa_title` (the `@title` decorator value),
    or `'(title not set)'` when the class has no decorator.
  - `get_static_title()` - the decorator value ONLY when `page_title()` is NOT overridden;
    an override means the decorator string is generic route metadata, so painting it would
    flash a wrong title.
  - `await_loaded()` - resolves once the action's `on_load()` has completed, via the sticky
    `'load'` lifecycle event. Title/breadcrumb methods are called at dispatch time, before
    the load finishes, so any method reading `this.data` awaits this first.
- `app/RSpade/Core/SPA/Spa_Layout.js`
  - `resolve_page_title(paint, placeholder)` - the ONE way a layout obtains a title:
    synchronous static paint, then the awaited `page_title()` repaint when it differs.
- `app/RSpade/Core/SPA/Spa_Decorators.js` - `@title` stores `_spa_title` on the class.
- `app/RSpade/Core/Js/Rsx.js` - the navigation guard: `set_navigation_guard()` (no
  argument; any argument throws), `clear_navigation_guard()`, `has_navigation_guard()`,
  `NAVIGATION_GUARD_PROMPT`, and the lazily-installed `beforeunload` listener.
- `app/RSpade/Core/SPA/Spa.js` - `setup_browser_integration()`: the click handler stands
  aside while the guard is on, and the popstate handler raises `window.confirm()` and
  steps history back (`_undo_vetoed_move()`) on Cancel; `Rsx_Navigation._transport()` turns
  the guard off when a navigation leaves the page.
- Consumers in the template app: `rsx/app/frontend/Frontend_Spa_Layout.js`
  (`_update_page_title`, which adds a per-URL session cache as the `placeholder`) and
  `rsx/portal/Portal_Layout.js`.

## Behavior that defines correctness

- An action with a fixed title declares it ONCE, with `@title`, and overrides nothing.
- A layout can paint that title with zero latency: `resolve_page_title()` calls back
  synchronously, before the returned promise is ever awaited.
- An action that overrides `page_title()` contributes NO static candidate, whatever its
  class decorator says.
- A dynamic title resolves from loaded data, because the override awaits `await_loaded()`
  and the `'load'` event is sticky (a late registration fires immediately).

## Behavior that defines correctness - the navigation guard

- A TOGGLE: `set_navigation_guard()` takes no argument and throws when given one; it is
  idempotent, and one `clear_navigation_guard()` turns it off.
- Guard on, link click: not intercepted. The browser offers its `beforeunload` dialog;
  dismissed, the page, its action and the guard are untouched; accepted, the target
  arrives as a full document. Clearing the guard restores interception on the next click.
- Guard on, Back/Forward between SPA pages: a native `confirm` carrying
  `Leave site?\n\nChanges you made may not be saved.` Dismissed - for Back, Forward and a
  two-step jump - the address and the stack are as they were, nothing is dispatched and
  the guard stays on. Accepted, the guard goes off and the move is dispatched in the SPA.
- A fragment-only Back/Forward is never asked about.
- `Rsx.navigate()` / `Rsx.redirect()` are not guarded: no dialog, in-SPA, guard off
  afterwards. A fragment-only move on the page already showing leaves the guard on.
- Leaving the page for real raises `beforeunload` while the guard is on, and nothing once
  it has been cleared. Chromium raises it only after a user gesture, so each script
  clicks before it expects one.

Man page: `php artisan rsx:man spa` (PAGE TITLES, THE NAVIGATION GUARD).

## Running

```bash
node app/RSpade/tests/spa/playwright/spa_action_title.js
node app/RSpade/tests/spa/playwright/navigation_guard.js
node app/RSpade/tests/spa/playwright/history_scroll_and_veto.js
node app/RSpade/tests/spa/playwright/fragment_popstate.js
node app/RSpade/tests/spa/playwright/navigate.js
node app/RSpade/tests/spa/playwright/breadcrumb_origins.js
node app/RSpade/tests/spa/playwright/breadcrumb_return_state.js
```

Run one file at a time. `spa_action_title.js` drives the TEMPLATE app (`/contacts`,
`/contacts/view/:id`, a `[data-sid=page_title]` header) and fails in an application that
lacks them.
