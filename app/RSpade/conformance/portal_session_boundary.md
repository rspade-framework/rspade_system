---
id: portal_session_boundary
title: Portal-reachable code asks the portal facade
severity: high
when: launch, change
scope: rsx/**/*.php
related: rsx:man portal, rsx:man session, skill rspade:portal-core
---

## The contract

Code a client-portal request can reach reads identity, tenant and session values through `Portal_Session`, never through the staff `Session` facade, and code both experiences reach forks on `Rsx_Portal::is_portal_request()` - never on who is signed in. One browser has one session row carrying BOTH sets of properties, routinely both populated, so a staff-facade call on a portal path does not return null: it returns a real staff user, a real staff tenant and staff-shaped authorization on a page serving a client, and a staff-facade write (`set_login_user_id()`, `set_site_id()`, `logout()`, `put_value()`) acts on the identity the person uses elsewhere.

Read `php artisan rsx:man portal` (PORTAL SESSIONS, EXPERIENCE FORKS, WHICH SITE IS THIS?) and skill `rspade:portal-core` first.

Already handled by the framework, not re-checked: `/_upload`, the realtime token, `@csrf`, `Flash_Alert`, `Rsx_Mail`, `Rsx_Sms`, `Rsx_Time`, `Rsx_Settings`, `Rsx_Throttle` and every site-scoped model resolve the experience themselves. This audit is about application code.

## Find every instance

1. Every staff-facade call:

       grep -rnE "(^|[^A-Za-z_])Session::" rsx --include='*.php' | grep -v '^rsx/resource/' | grep -v '^rsx/tests/'

   For each hit, decide whether a portal request can reach that line by tracing its CALLERS, not by its directory: the three transports (`/_ajax/<Controller>/<action>`, `/_ajax/_batch`, `/_upload`) serve both experiences through the same handlers, and event handlers, services, models, topics and shared components are not routed at all. Classify each: staff-only, portal-reachable and correct, portal-reachable and wrong.

2. The identity-as-experience test:

       grep -rnE "(Portal_)?Session::is_logged_in\(\)" rsx --include='*.php' | grep -v '^rsx/tests/'

3. The three mandatory file gates and every other gate handler that runs on both channels:

       grep -rn "OnEvent('file\.\(upload\|thumbnail\|download\)\.authorize'" rsx --include='*.php'

   Confirm each answers correctly when `'user'` is a `Portal_User_Model` and when it is null.

4. `rsx/portal_main.php`: `Portal_Main::init()` declares the portal site with `Portal_Session::set_site_id()`.

## What does not conform

- A `Session::` read (user, user id, login user, site id, value) on a portal-reachable path - use the `Portal_Session::` equivalent, or fork on `Rsx_Portal::is_portal_request()`.
- A `Session::` write on a portal-reachable path, including `Session::put_value()` / `get_value()` in portal code (`Portal_Session::put_value()` / `get_value()` / `forget_value()` is the portal's store).
- `Portal_Session::is_logged_in()` or `Session::is_logged_in()` used to decide WHICH facade or properties to read next. An anonymous portal caller falls into the staff branch and a staff user also signed into the portal falls into the portal branch.
- A gate handler that type-checks for the staff `User_Model` and so silently denies or allows portal traffic.
- A portal path that hand-scopes a query by site, or sets the staff site on a portal request, instead of relying on the declared portal site.
- No `Portal_Session::set_site_id()` in `Portal_Main::init()` when the application has a portal (`Portal_Session::get_site_id()` throws without it - and a defaulted tenant is worse).

Not findings:

- A staff-or-portal disjunction asking "is anybody signed in" (the template's `File_Upload_Handlers` upload gate is the model), so long as it does not then choose which properties to read.
- `Portal_Session::is_logged_in()` inside a portal-only file (a portal controller, a portal-only topic).
- `Main::init()` declaring the STAFF site - that is the staff app's own tenancy; nothing portal rides on it.

## Report

`PASS - <one line: N staff-facade calls classified, none portal-reachable and wrong>`, or one finding per line:

    file:line - what is wrong - the conforming fix

No other prose.
