# app/RSpade/Sys/app/sys - the control panel module

The `/_sys` control panel: one SPA bootstrap controller, one route controller, one
layout, and one directory per screen. Namespace is `App\RSpade\Sys\App\Sys` for
every PHP file here (the page directories hold JS and jqhtml only).

| File | What it is |
|---|---|
| `_Sys_Bundle.php` | the panel's only bundle. Includes `jquery`, `lodash`, `_Sys_Theme_Bundle`, `app/RSpade/Sys/theme`, `app/RSpade/Sys/lib`, `__DIR__`. Names no `rsx/` path and no app class (`CONV-BUNDLE-04`). |
| `_Sys_Spa_Controller.php` | `#[SPA] index()` -> `rsx_view(SPA, ['bundle' => '_Sys_Bundle'])`. Its `pre_dispatch()` is where `rsx.sys_panel.enabled` is enforced for every SPA screen. |
| `_Sys_Controller.php` | the panel's server-rendered routes. Today: `GET /_sys/logout` -> `RsxAuth::logout()` + redirect to `/`. Same `pre_dispatch()` refusal. |
| `_Sys_Layout.{jqhtml,js,scss}` | the persistent chrome: fixed sidebar, top bar with the page title and the sign-out link, `<main $sid="content">`. `on_create()` builds the nav and filters it through `Permission.can_access()`; `on_action()` re-marks the active item and repaints the title. |

## The screens

`dashboard/`, `debug_flags/`, `tasks/`, `email/`, `logs/`, `sites/`, `users/` -
each `_Sys_<Name>_Action.js` + `.jqhtml`. All seven are PLACEHOLDERS today: the
route, the gate, the nav entry and the chrome are real; the body is one sentence
naming what will live there. Each composes
`<_Sys_Page_Scaffold><Slot:main><_Sys_Section>...</_Sys_Section></Slot:main></_Sys_Page_Scaffold>`.

The dashboard additionally carries a stock `<button class="btn btn-primary">`, on
purpose: it is the visible proof that the panel's own Bootstrap build is doing the
theming rather than a hand-written colour rule.

## Rules that bite here

- Every class, component and `@rsx_id` starts with exactly ONE underscore
  (`NAME-RESERVED-01`); directories never do.
- Filenames under `app/RSpade` are case-exact matches of the class they declare.
- `#[Auth('is_sysadmin')]` / `@auth('is_sysadmin')` on every surface - a surface with no
  gate does not deploy.
- URLs come from `Rsx.Route()` / `Rsx::Route()`; the layout's sign-out link is
  `Rsx.Route('_Sys_Controller::logout')`.
