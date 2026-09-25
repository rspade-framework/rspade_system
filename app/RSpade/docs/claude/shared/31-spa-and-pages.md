<!-- single-source: never duplicate into another fragment. -->

## SPA, PAGES & URLS

Client-side routing for authenticated areas: **ONE PHP bootstrap controller per module** (`#[SPA]`, returning `rsx_view(SPA, ['bundle' => 'Frontend_Bundle'])`) plus MANY JavaScript actions that navigate without page reloads. Feature controllers inside an SPA module are **Ajax endpoints only**.

```javascript
@route('/contacts')                     // repeat for a dual-route add/edit action
@layout('Frontend_Layout')              // outermost first; repeat for sublayouts
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')                   // MANDATORY on every @route action
@title('Contacts')                      // the whole title for a fixed-title page
class Contacts_Index_Action extends Spa_Action { async on_load() { ... } }
```

**A fixed title is `@title` and nothing else** — `page_title()` returns it, painted synchronously at dispatch. **Override `page_title()` only for a data-dependent title, and start the override with `await this.await_loaded()`** before reading `this.data` (it is called before the load finishes); an override means the class's `@title`, if any, is never painted.

A **layout** extends `Spa_Layout` and its template MUST contain a `$sid="content"` element — that is where actions render, and it persists across navigation. **Navigation**: `Spa.dispatch('/path')` pushes history; **`Spa.redirect('/path')` REPLACES it and is the one for a programmatic redirect in `on_load()`**. Route and query params both land in `this.args`. **Link interception is automatic** — only known SPA routes are intercepted, so no attribute is needed to bypass it; do not invent a `data-no-spa`. **`Rsx.set_navigation_guard(async fn)` / `Rsx.clear_navigation_guard()`** warns before unsaved work is lost — one slot, last wins; `dispatch()` awaits it and clears it when the answer is `true`; a real page exit gets the browser's native dialog instead; the app owns the dialog (skill `rspade:navigation-guard`).

**SPA pages are the preferred standard.** Use Blade only for SEO-critical public pages and authentication flows (jqhtml works in Blade but is not server-rendered). Blade page JS is a static `on_app_ready()` and **needs a page guard** (`if (!$('.My_Page').exists()) return;`) because it fires for every page in the bundle. A CRUD feature splits into `list/` (Index + DataGrid), `view/` and `edit/` with one dual-`@route` add/edit action and a three-state loading pattern.

**Routing rules**: **only GET and POST** (no PUT/PATCH/DELETE), no resource routes, `:param` path params, signature always `(Request $request, array $params = [])` with both verbs in the SAME method (`$request->is_post()`); file responses use `Response::download()`/`file()`.

**All URLs are generated with `Rsx::Route()` / `Rsx.Route()` (portal: `Rsx_Portal::Route()` / `Rsx_Portal.Route()`) — hardcoded URLs are forbidden** and `rsx:check` flags them (`URL-HARDCODE-01`, high; an interpolated path counts, and a portal file gets the portal spelling): `Rsx::Route('Frontend_Controller::view', $id)`, with extra array keys becoming a query string and an optional THIRD argument (`['tab' => 'x']`) becoming the `#fragment` state `Rsx.url_hash_get()` reads - never append `'#…'` to a Route() result by hand.

**Modules and bundles**: scaffold with `rsx:app:module:create` / `:module:feature:create` (SPA by default, `--blade` for the server-rendered ladder) / `:submodule:create` / `rsx:app:component:create`. **One bundle per module**, compiled JIT on web request — never a manual build step.

**The framework ships its own SPA control panel at `/_sys`** (`rsx:man sys_panel`, skill `rspade:sys-panel`) — its `_Sys_*` classes and components are framework property that an app never declares (`NAME-RESERVED-01`) and never extends, renders, calls or routes to by hand (`NAME-RESERVED-02` — a REFERENCE to any framework-declared `_`-prefixed name, or to a framework `_`/`__`-prefixed static, is fatal at manifest build; only the STRING carriers `Rsx::Route('_Sys_Dashboard_Action')` / `Permission::can_access('_Sys_Dashboard_Action')` link to it - the INDEX ACTION, since a SPA route is registered under its JS action class and never under the bootstrap controller, and that one target is published into every bundle so `Rsx.Route()`/`Permission.can_access()` answer client-side too), and a `/_`-prefixed URL path is framework-owned in general.

**Error pages are ROUTES the application declares under the reserved `/error/` prefix** — `#[Route('/error/404')]` / `#[Route('/error/generic')]` (portal: `#[Portal_Route('/error/404')]`), GET only, no `:param`, `#[Auth('public')]`, or `ROUTE-ERROR-01` fails the manifest build. The framework calls the method itself with `$params['error']` (an `Error_Context`: status, title, message, path, method, realm, home_url, preview, detail, error_id - `detail` only for a developer caller, `Rsx_Diagnostics::caller_sees_detail()`) and forces the status; `/error/generic` answers every status with no exact page, and the framework's own page answers when nothing is declared or a page throws. Nothing links to one — browsing the URL is a DEVELOPMENT-ONLY preview (a sealed build answers 404). `rsx:man error_pages`.

Skills: `rspade:spa`, `rspade:blade-views`, `rspade:bundles`, `rspade:error-pages`, and the app skill `crud-patterns`. Details: `rsx:man spa`, `rsx:man crud`, `rsx:man routing`, `rsx:man error_pages`.
