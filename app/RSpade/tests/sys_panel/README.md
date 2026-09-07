# sys_panel

The framework's own application at `app/RSpade/Sys/`: the control panel served
at `/_sys`, and the API reference console beside it in `app/apidocs/`.

## Applicability

This concern covers what the PANEL DECLARES and how it is REACHED - the surfaces
the manifest builds from `app/RSpade/Sys/`, the `rsx.sys_panel.enabled` switch
enforced at `pre_dispatch`, the `is_sysadmin` gate at the dispatch seam, logout, and
the bundle invariant that keeps the tree independent of the application it ships
beside.

It also covers what the INVENTORY COMMANDS show: the panel's names and files are
hidden from `rsx:routes`, `rsx:manifest:*`, `rsx:bundle:show` and `rsx:task:list`
unless the box is a framework developer. That is display only, and it belongs here
because the reason is the reservation rule rather than anything about the commands.

The API console's rows here are about WHERE IT LIVES and what the manifest holds
for it - the console is mounted by an application route, so nothing downstream
would notice it moving trees and the suite has to say so. Its behaviour (scope
narrowing, the adopted key, the catalog) is `api`, which is engine-only and
untouched by the move.

It does NOT cover the machinery those things ride on: gate indexing and evaluation
are `auth_gates`, bundle compilation and CONV-BUNDLE-04 are `bundles`, dispatch
resolution is `dispatch`, and the `_`-prefix name rule (NAME-RESERVED-01) is
`code_quality`. The `is_sysadmin` check itself is declared on `Permission_Abstract`
and is proved in `auth_gates` (AG-ROOT-*), beside the other built-ins.

The panel's pages are placeholders today: the route, the gate, the chrome and the
nav are real, the bodies are one sentence each. Rows for a page's own behaviour
arrive with that page.

## Sources under test

| File | Role |
|------|------|
| `system/config/rsx.php` `always_published_routes` | `['_Sys_Dashboard_Action']` - the entry target whose route patterns the bundle compiler emits into EVERY bundle, and whose auth grants ship on every page as `window.rsxapp.auth_routes_published`. This is what makes `Rsx.Route()`/`Permission.can_access()` answer for the panel from an application page that never includes `app/RSpade/Sys` (CONV-BUNDLE-02 forbids including it). |
| `Sys/app/sys/_Sys_Spa_Controller.php` | `#[SPA] index()` -> `rsx_view(SPA, ['bundle' => '_Sys_Bundle'])`; `pre_dispatch()` enforces `rsx.sys_panel.enabled` for every SPA screen. |
| `Sys/app/sys/_Sys_Controller.php` | `GET /_sys/logout` -> `RsxAuth::logout()` + redirect to `/`; same refusal. |
| `Sys/app/sys/_Sys_Bundle.php` | the panel's only bundle; names no `rsx/` path and no app class. |
| `Sys/theme/_Sys_Theme_Bundle.php` | Bootstrap compiled from `system/node_modules/bootstrap` through `theme/vendor/bootstrap.scss`, plus the `--rsx-*` tokens. |
| `Sys/app/sys/*/_Sys_*_Action.js` | the seven screens and their decorators. |
| `Sys/app/sys/_Sys_Layout.js` | the chrome, and the nav filtered through `Permission.can_access()`. |
| `system/config/rsx.php` | `manifest.scan_directories` (`app/RSpade/Sys`) and `sys_panel.enabled`. |
| `Core/Naming/Rsx_Identifier.php` | `is_visible_to_developer()` / `is_path_visible_to_developer()` - the one predicate the inventory commands filter on. |
| `Commands/Rsx/*` | `rsx:routes`, `rsx:manifest:show`, `rsx:manifest:dump`, `rsx:manifest:stats`, `rsx:bundle:show`, `rsx:task:list`, `rsx:jqhtml:glossary` - each applies that predicate at one point. |
| `Sys/app/apidocs/_Apidocs_Bundle.php` | the console's only bundle; names no `rsx/` path and reaches the tree's theme. |
| `Sys/app/apidocs/_Apidocs_App.blade.php` | `@rsx_id('_Apidocs_App')`, the view `Rsx_Api_Docs::page()` returns. |
| `Sys/app/apidocs/components/_Apidocs_Console.jqhtml` | the console's root component, the whole page. |
| `app/RSpade/Core/Api/` | the API ENGINE - asserted to hold no view layer, so the console cannot grow back into it. |

Behavior of record: `Sys/CLAUDE.md`, `Sys/app/sys/CLAUDE.md` and
`Sys/app/apidocs/CLAUDE.md`.

## Not automated here

The browser half - the sidebar's active item moving on SPA navigation, the
midnight palette actually painting, Bootstrap components wearing it - is verified
with `rsx:debug /_sys --user=<id> --screenshot-path=...` pending playwright rows.
The console's rendering is verified the same way, at
`rsx:debug /apidocs --user=<a user with API access>`.
