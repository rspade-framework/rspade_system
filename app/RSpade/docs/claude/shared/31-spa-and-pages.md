<!-- single-source: never duplicate into another fragment. -->

## SPA, PAGES & URLS

**Authenticated areas are SPAs**: ONE `#[SPA]` bootstrap controller per module (its feature controllers are Ajax endpoints only) plus MANY JS actions that navigate without reloads:

```javascript
@route('/contacts') @layout('Frontend_Layout') @spa('Frontend_Spa_Controller::index') @auth('is_logged_in') @title('Contacts')
class Contacts_Index_Action extends Spa_Action { async on_load() { ... } }
```

**A fixed title is `@title`; override `page_title()` only for a data-dependent one, starting with `await this.await_loaded()`.** A layout extends `Spa_Layout` and MUST hold a `$sid="content"` element. `Spa.dispatch()` pushes history; **`Spa.redirect()` replaces it - the one for a redirect in `on_load()`**. Link interception is automatic for known routes - never invent a `data-no-spa`. **`Rsx.set_navigation_guard()`/`clear_navigation_guard()` is a TOGGLE: an argument throws** (skill `rspade:navigation-guard`). **SPA is the standard; Blade only for SEO pages and auth flows** (skill `rspade:blade-views`).

**A page reached from another record crumbs to its home section** ("New Invoice from the engagement crumbs to Billing"): a breadcrumb ORIGIN - the link carries `_via` (`Spa.via_push()`), the page where the trail splits declares `static breadcrumb_via` + `breadcrumb_parent_via()` and VERIFIES the claim. Never hand-roll `from=`/`return_to=`/`parent_*` for a crumb, read `this.args._via`, or put a URL in a link. Skill `rspade:breadcrumb-origins`.

**Routes are GET and POST only** - no PUT/PATCH/DELETE, no resource routes; signature `(Request $request, array $params = [])`, both verbs in one method (`$request->is_post()`). **Every URL is `Rsx::Route()`/`Rsx.Route()` (portal `Rsx_Portal::Route()`)** - a hardcoded or interpolated path fails `URL-HARDCODE-01`; a third argument is the `#fragment` state, never append `'#…'` by hand. `rsx:man routing`.

**A bundle served to signed-out visitors (login, signup, reset, error pages, a portal's sign-in pages) NEVER includes a shared directory** (`rsx/theme`, `rsx/lib`, `rsx/models`) - that publishes every component, model and Ajax stub. It names each file it needs (both halves of every component AND of its parents) plus its own `__DIR__`; a missing one fails silently with a clean console, so verify by LOOKING. Skill `rspade:bundles`.

**`/_sys` is the framework's own panel**: never declare, extend, render or call a `_`-prefixed framework name (`NAME-RESERVED-01`/`-02`, FATAL); link only by string, `Rsx::Route('_Sys_Dashboard_Action')`. Skill `rspade:sys-panel`. **Error pages are routes under the reserved `/error/` prefix** (`#[Route('/error/404')]`, `/error/generic`; GET, no `:param`, `#[Auth('public')]`, else `ROUTE-ERROR-01`). Skill `rspade:error-pages`.

Skills `rspade:spa` (modules, scaffolding, layouts), `rspade:breadcrumb-origins`, app skill `crud-patterns`. `rsx:man spa`.
