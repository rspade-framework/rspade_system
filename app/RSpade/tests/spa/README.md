# SPA concern

Client-side routing for authenticated areas: `Spa` (dispatch, layout chain, history),
`Spa_Layout` (persistent wrapper, `on_action`), `Spa_Action` (the page), and the
`@route`/`@layout`/`@spa`/`@auth`/`@title` decorators that declare an action.

This concern currently covers the **page-title ladder** - the seam where a dispatched
action's title reaches a layout. Everything else in the SPA is either covered elsewhere
(decorator transform in `js_transform`, auth gating in `auth_gates`, the dispatcher's
auth-rejection surface in `dispatch`) or catalogued below as a coverage hole.

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

Man page: `php artisan rsx:man spa` (PAGE TITLES).

## Running

```bash
node app/RSpade/tests/spa/playwright/spa_action_title.js
```
