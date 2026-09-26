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

All seven screens are functional and their endpoints are covered here (RP-DASH-*,
RP-DEBUG-*, RP-TASKS-*, RP-EMAIL-*, RP-SMS-*, RP-LOGS-*, RP-SITES-*, RP-USERS-*,
RP-IMP-*). The Debug Flags rows also cover the framework-core engine the screen drives -
the per-browser console_debug override and where it is applied - because the panel is
its only writer.

## Sources under test

| File | Role |
|------|------|
| `system/config/rsx.php` `always_published_routes` | `['_Sys_Dashboard_Action', '_Sys_Impersonation_Controller::stop']` - the entry target (and the stop-impersonating route an application's banner links to) whose route patterns the bundle compiler emits into EVERY bundle, and whose auth grants ship on every page as `window.rsxapp.auth_routes_published`. This is what makes `Rsx.Route()`/`Permission.can_access()` answer for the panel from an application page that never includes `app/RSpade/Sys` (CONV-BUNDLE-02 forbids including it). |
| `Sys/app/sys/_Sys_Spa_Controller.php` | `#[SPA] index()` -> `rsx_view(SPA, ['bundle' => '_Sys_Bundle'])`; inherits the `rsx.sys_panel.enabled` refusal from `_Sys_Endpoint_Controller_Abstract`, covering every SPA screen. |
| `Sys/app/sys/_Sys_Controller.php` | `GET /_sys/logout` -> `RsxAuth::logout()` + redirect to `/`; same inherited refusal. |
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
| `Sys/theme/components/` | the panel toolkit; `_Sys_Modal` is proved in the browser (RP-MODAL-*). |
| `Sys/lib/_Sys_Endpoint_Controller_Abstract.php` | the base every panel controller extends: the ONE `rsx.sys_panel.enabled` refusal in `pre_dispatch()` (RP-GATE-03, RP-GATE-06). `Sys_Panel_Gate_Test` also enumerates every panel surface for `is_sysadmin` and refuses a non-developer (RP-GATE-*). |
| `Sys/theme/components/_Sys_DataGrid_Abstract.php` | the panel grid's server half - `fetch()` over an Eloquent, Query\Builder or row-list source (RP-GRID-*). |
| `Sys/app/sys/_Sys_Error_Screens.js` + `Core/SPA/Error_Screens.js` | the panel's registration with the SPA error-screen registry, and the registry's fail-loud paths (RP-ERR-*). |
| `Sys/app/sys/tasks/_Sys_Tasks_Controller.php` + `_Sys_Tasks_DataGrid.php` | the Tasks screen's endpoints over `_tasks`: `running`, the history grid and its filters, `detail`, `kill` (explanation required, `Task_Killer`), `redispatch`, `schedules`, `run_schedule_now` (RP-TASKS-*; fixture `Sys_Tasks_Probe_Service`). `kill` is also the real form endpoint RP-MODAL-07 drives in the browser. |
| `Sys/app/sys/email/_Sys_Email_Controller.php` + `_Sys_Email_DataGrid.php` | the Email screen's endpoints over `_email_queue`, every site: `summary`, the queue grid and its option endpoints, `detail` (preview document, catcher lookup via `Rsx_Mail_Transport::catcher_files_for()`), `resend` (`Rsx_Mail::resend()`, the rules shared with `rsx:mail:resend`) (RP-EMAIL-*). |
| `Sys/app/sys/email/_Sys_Sms_Controller.php` + `_Sys_Sms_DataGrid.php` | the SMS tab's read-only endpoints over `_sms_queue`, every site: `summary` (`Sms_Queue_Model::status_counts()` / `oldest_pending()`, `Rsx_Sms::delivery_mode()`), the grid and its option endpoints, `detail` (RP-SMS-*). Both queues' words and site labels come from `_Sys_Enum_Words`. |
| `Sys/app/sys/logs/_Sys_Log_Reader.php` + `_Sys_Logs_Controller.php` + `_Sys_Logs_DataGrid.php` | the Logs screen: the listing and the one name-to-path resolution (never a path join), windowed reads by byte offset (tail, backward pages, Follow with rotation detection), the .gz forward read, laravel entry grouping, csp pretty-printing, the server-side filter (RP-LOGS-*; a fixture directory through `_Sys_Log_Reader::$directory_for_tests`, never the install's own logs). |
| `Sys/app/sys/sites/_Sys_Sites_Controller.php` + `_Sys_Sites_DataGrid.php` + `_Sys_Site_Members_DataGrid.php` | the Sites screen: the tenant grid (site 0 and soft-deleted sites excluded, `member_counts()` in one grouped query), `detail` and `members_fetch` (not_found for site 0, a deleted or a missing id; `is_active` from `User_Model::active()`), `rename` (required, `Site_Model::field_length()`, slug unique across every site, deleted ones included) and `set_enabled` (the membership goes inactive; refused for the acting session's own site) (RP-SITES-*). |
| `Sys/app/sys/users/_Sys_Users_Controller.php` + `_Sys_Users_DataGrid.php` + `_Sys_User_Signins_DataGrid.php` | the Users screen: the login-identity grid (soft-deleted excluded, `membership_counts()` in one grouped query, status and developer filters), `detail` (every membership across every site, `User_Model::is_active()`, invite state), `sessions` (`Session::get_sessions_for_user()` plus site, type and impersonator), the paged sign-in grid (`Login_History::present_record()`), `security` (`Rsx_Two_Factor` / `Rsx_Sso` metadata, never a secret); not_found for a missing or soft-deleted identity; the actions `set_status`, `set_membership_enabled`, `terminate_session` / `terminate_all_sessions` (`Session::developer_terminate_sessions()`, whose own contract is `session` sess-termfu-18..21), `remove_two_factor`, `unlink_sso`, each with its refusal on the acting developer's identity, current membership or current session (RP-USERS-*). |
| `Sys/app/sys/users/_Sys_Users_Controller.php` `sign_in_as` + `Sys/app/sys/_Sys_Impersonation_Controller.php` | developer impersonation: `sign_in_as` (refusals, active-membership site, return site stored, `Session::begin_impersonation()`) and `GET /_sys/stop-impersonating` (the one `is_logged_in` panel surface; impersonator must be a developer; restores identity and site) (RP-IMP-*). |
| `Sys/app/sys/debug_flags/_Sys_Debug_Flags_Controller.php` + `Core/Debug/Console_Debug_Override.php` + `Core/Debug/Console_Debug_Channels.php` | the Debug Flags screen: a developer's per-browser console_debug override stored in the session (`validate()`/`normalize()`, `store()`/`clear()`), resolved per request by `Debugger::apply_session_override()` from `Rsx_Front_Controller` and read by `Rsx_Bundle_Abstract::console_debug_payload()`; only for a developer, creating no session; the endpoints `state` (values in force with their source), `channels` (the one scanner, shared with `rsx:console_debug:list_channels`), `save`, `reset` (RP-DEBUG-*). |
| `Sys/app/sys/dashboard/_Sys_Dashboard_Controller.php` | the Dashboard's two endpoints: `summary` (install-wide counts, RP-DASH-*) and `health` (`Health_Check_Runner::report()`). |

Behavior of record: `Sys/CLAUDE.md`, `Sys/app/sys/CLAUDE.md` and
`Sys/app/apidocs/CLAUDE.md`.

## Browser rows

`playwright/sys_panel_toolkit.js` (RP-ERR-*, RP-MODAL-*) runs on `/_sys` as user 1 with
self-minted dev-auth headers: `node system/app/RSpade/tests/sys_panel/playwright/sys_panel_toolkit.js`.
It depends on no application code. RP-MODAL-07 plants one RUNNING `_tasks` row with no
worker_pid in the site's database (`php artisan db:query`), kills it through the Tasks
screen's Kill dialog, and deletes it afterwards.

## Not automated here

The browser half - the sidebar's active item moving on SPA navigation, the
midnight palette actually painting, Bootstrap components wearing it - is verified
with `rsx:debug /_sys --user=<id> --screenshot-path=...` pending playwright rows.
The console's rendering is verified the same way, at
`rsx:debug /apidocs --user=<a user with API access>`.
