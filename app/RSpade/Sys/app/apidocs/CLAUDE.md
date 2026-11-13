# app/apidocs - the API reference console

The developer console for the external API: browse endpoints, read parameters,
copy a code sample, and send a real request. It is a module of the framework's
own application tree (`app/RSpade/Sys/`), built the way any RSX module is -
`_Apidocs_Bundle`, a blade shell, one Ajax controller, and `components/`.

```
apidocs/
  _Apidocs_Bundle.php       every asset the page serves
  _Apidocs_App.blade.php    the page shell, @rsx_id('_Apidocs_App')
  _Apidocs_Controller.php   the three Ajax endpoints the page calls
  components/               the console itself, and _Apidocs_Vendor_Bundle
```

## The mount contract - the APPLICATION owns the route and the gate

The framework declares NO route for the console and has no visibility setting.
An application routes to it and calls `Rsx_Api_Docs::page()` (`Core/Api/`, which
is the ENGINE's home and stays there); that one call renders `_Apidocs_App`,
which names `_Apidocs_Bundle` by FQCN. The console links to `<mount>/openapi.json`,
the application's browser download of the document (`Rsx_Api_Docs::openapi_document()`,
headers chosen by that route); a program reads the framework's
`GET /api/vN/openapi.json` (`Core/Api/Openapi_Api_Controller`) with its key.
The template app's mount is `rsx/app/apidocs/apidocs_controller.php` - a gate
and two one-line bodies, and the only thing an application ever writes.

Render-time bundle validation would normally demand that a bundle cover the
dispatching controller's directory, which a framework bundle can never do for a
controller under `rsx/`. A FRAMEWORK VIEW waives both coverage checks
(`Rsx_Bundle_Abstract::__validate_path_coverage()`, :880 and :897-900), and that
waiver is the whole reason a framework-side bundle is legal on an app route.

## Application sections - content in, never markup

`Rsx_Api_Docs::page()`'s `$sections` is the one thing an application hands the
console: a list of `['title' => ..., 'markdown' => ...]`. `page()` validates it
and renders each through `Markdown_Rendition::render()` (raw HTML discarded, the
GFM element allowlist on the output), so what reaches `page_data.api_catalog.sections`
is `{slug, title, html}` already safe to place unescaped - and `_Apidocs_Section`
is the one place on this page that does. `_Apidocs_Catalog.sections()` /
`find_section()` / `section_url()` read it; the sidebar lists them under "Guides"
ahead of the resources, and the console shows one at `?section=<slug>` through the
same pushState/redraw path an endpoint uses (a section wins over `?endpoint=` and is
shown even when no endpoint is defined). `_Apidocs_Section.scss` styles exactly the
element set the renderer can produce, from the `--rsx-*` tokens.

## Why it is standalone

No sidebar, no `_Sys_Layout`, no `/_sys` route. `_Apidocs_Console` owns the
entire page and does its own history handling - a sidebar click pushes state and
redraws only the `$redrawable` body. It is mounted by an ordinary `#[Route]`, so
Spa's link interception does not apply, and an SPA split would rebuild the
header, key bar and sidebar on every click and lose the filter text with them.

Sharing the panel's THEME is not sharing its CHROME or its ACCESS CONTROL. The
panel's surfaces are `#[Auth('is_sysadmin')]`; `_Apidocs_Controller` stays
`#[Auth('public')]`, because the console's real gate is whatever the application
put on its own route and the framework cannot know what that is. Adopting a key
affects only the caller's own session, the key is validated before it is
accepted, and `Api_Dispatcher` gates every request it is later used for.

## The theme it consumes

Everything visual comes from `Sys/theme/`: `_Sys_Theme_Bundle`'s Bootstrap
build (variable-overridden in `theme/variables.scss`) and the `--rsx-*` custom
properties in `theme/theme.scss`. `_Apidocs_Page.scss` declares NO palette of
its own - it reads `--rsx-bg`, `--rsx-surface`, `--rsx-raised`, `--rsx-border`,
`--rsx-text`, `--rsx-muted`, `--rsx-accent`, `--rsx-accent-hover`,
`--rsx-accent-soft`, `--rsx-danger`, `--rsx-warn`, `--rsx-mono`, and the verb
pair `--rsx-get` / `--rsx-post` that the method pills use. A colour this module
needs and the theme lacks is ADDED TO `theme/theme.scss`, never kept here.

## The temporary key is bound to this browser session

"Temporary Key" mints through `mint_temporary_key()` ->
`Api_Key_Model::generate_tester_key()`: a one-hour key for the signed-in user whose
`tester_session_id` is THIS browser's session row. The bearer check
(`Rsx_Api_Bearer::authenticate()`) admits it only on a request carrying this browser's session
cookie while it is signed in as the key's user, so every request the console sends with a key -
`_Apidocs_Tester`, `_Apidocs_File_Tester`, the key bar's `/me` check - is a `fetch()` with
`credentials: 'same-origin'`. From a script, another browser, or this one signed out or signed
in as somebody else, the key is a 401 like an invalid one; `adopt_tester_key` refuses a tester
key minted elsewhere the same way. It expires after its hour as before. The cookie only ever
REFUSES: it is never the identity and never stands in for a key, and a pasted ordinary key
ignores it. **No created/revoked notice is sent for it, deliberately**: the key cannot be used
outside the session that minted it, so a hijacked copy gains nothing that session lacks and a
notice would warn of nothing (the events still fire, flagged; the application's handler skips
it). `Core/Api/CLAUDE.md`, Tester keys.

The console's one dialog (the temporary-key confirm) is the theme's `_Sys_Modal.confirm()`,
and its response pane pretty-prints through `_Sys_Code_Pane.pretty()`.

Bootstrap's reboot supplies what the console used to normalise for itself (body
margin, border-box, form-control type inheritance, link colour), so there is no
page-reset stylesheet in this module.

`_Apidocs_Vendor_Bundle` (in `components/`) adds highlight.js and its
github-dark theme by CDN - the syntax highlighting for samples and responses -
and is auto-discovered by `_Apidocs_Bundle`'s `__DIR__` include, so it loads on
this page and nowhere else.

## Living documentation

This file describes the module as it is today. When its contents or conventions
change, updating it is part of that change. Contract tier: `rsx:man external_api`
(DOCS AND TESTER PAGE) and `rsx:man bundle_api` (the two waivers).
