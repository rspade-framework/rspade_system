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
which names `_Apidocs_Bundle` by FQCN. `Rsx_Api_Docs::openapi_document()` is the
separate call for the OpenAPI document, so the two can be gated differently.
The template app's mount is `rsx/app/apidocs/apidocs_controller.php` - a gate
and two one-line bodies, and the only thing an application ever writes.

Render-time bundle validation would normally demand that a bundle cover the
dispatching controller's directory, which a framework bundle can never do for a
controller under `rsx/`. A FRAMEWORK VIEW waives both coverage checks
(`Rsx_Bundle_Abstract::__validate_path_coverage()`, :880 and :897-900), and that
waiver is the whole reason a framework-side bundle is legal on an app route.

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
