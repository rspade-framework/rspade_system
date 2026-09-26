---
name: sys-panel
description: "Coexisting with and operating the framework's own control panel at /_sys - its seven screens (Dashboard, Debug Flags, Tasks, Email & SMS, Logs, Sites, Users), the `_Sys_*` reserved namespace and NAME-RESERVED-01 / NAME-RESERVED-02, the is_sysadmin developer gate (Session::is_developer(), login_users.is_developer) and how an application narrows it, the one is_logged_in route GET /_sys/stop-impersonating, 'Sign in as this user' and window.rsxapp.impersonation, the per-browser console_debug override, the rsx.sys_panel.enabled switch, and linking to the panel with Rsx::Route('_Sys_Dashboard_Action') / Permission::can_access. Use when linking to or hiding the /_sys panel, turning it off, narrowing is_sysadmin, building an impersonation banner with Rsx.Route('_Sys_Impersonation_Controller::stop'), wondering why an impersonation landed on a different site or signed the developer out, setting console_debug for one browser, hitting a NAME-RESERVED-01 violation ('starts with an underscore, which is the framework-application prefix' or 'must carry the framework-application prefix') or a NAME-RESERVED-02 violation ('Application code references the framework-reserved' / 'Application code calls the framework-internal method'), wondering why a `_Sys_` name or a `/_sys` route is missing from rsx:routes / rsx:manifest:show / rsx:jqhtml:glossary output, or being tempted to extend _Sys_Layout, render a _Sys_* component, or edit anything under system/app/RSpade/Sys/ - all of which are reserved from application code."
---

# The /_sys control panel

RSpade ships its own application: a control panel at `/_sys`, living in
`system/app/RSpade/Sys/` and built exactly the way `rsx/` is built - modules,
bundles, a theme, a SPA layout, JS actions. It is the SYSTEM ADMINISTRATOR's
view of the framework, install-wide (a screen over site-scoped data reads every
site), not the application's admin area - billing, customers and everything a
tenant does inside the application stay in the application.

Contract tier: `rsx:man sys_panel` (every screen in full).

## The screens

| Screen | URL | What it does |
|---|---|---|
| Dashboard | `/_sys` | Tiles (mode + seal, build key, live workers, failed tasks in 24h, pending mail, delivery mode, site and identity counts), each linking to its screen; the `rsx:health` report run in the web request, with Re-run. |
| Debug Flags | `/_sys/debug-flags` | console_debug for THIS BROWSER only (below), plus every effective `rsx.console_debug` / `rsx.development` value and where it came from. |
| Tasks | `/_sys/tasks` | Running (Kill), Schedules (Run now) and History tabs over `_tasks`; a detail with params, result, logs, Kill and Re-dispatch. |
| Email & SMS | `/_sys/email` | Both outbound queues across every site: status tiles, delivery facts, a filtered grid, a message view (sandboxed HTML body) with Resend - the `rsx:mail:resend` rules. SMS is read-only. |
| Logs | `/_sys/logs` | The top-level files of the log directory; a viewer that tails, pages backward, filters server-side and follows. Read-only. |
| Sites | `/_sys/sites` | The tenants (never site 0): members, rename (name + slug), enable/disable. No delete. |
| Users | `/_sys/users` | Login identities: memberships, live sessions, sign-in history, second factors and SSO links; change status, enable/disable a membership, end sessions, remove factors, disconnect providers, "Sign in as this user". No delete; `is_developer` is never editable here. |

An action that changes state asks first, and one on the Sites or Users screen
that would lock the developer out of the panel they are using (their own
identity, session, membership or session's site) is refused with the reason.

## The reserved namespace

Every class, jqhtml component, JS class and Blade `@rsx_id` in the tree carries
exactly ONE leading underscore: `_Sys_Layout`, `<Define:_Sys_Section>`,
`@rsx_id('_Apidocs_App')`. Directories never do (lowercase, because the
namespace generator PascalCases directory segments).

`NAME-RESERVED-01` (critical, manifest-time) enforces the DECLARATION side in
both directions, keyed on the file's path:

- a name declared under `rsx/` may **NOT** start with an underscore;
- a name declared under `app/RSpade/Sys/` **MUST** start with one.

The `rsx/` direction is not cosmetic. A `_`-prefixed class in `rsx/` that
matches a framework one is read by the manifest as a **class override**: the
framework's file is archived as `.upstream` and your copy is served instead,
with nothing reported anywhere. The panel would look like it had changed on its
own. That silent-shadowing failure is why the rule refuses rather than warns.

Fix for either direction: rename. The violation text names the exact new name.

## What an application may and may not do

MAY - the sanctioned references, all indirections that are contracts:

```php
if (Permission::can_access('_Sys_Dashboard_Action')) {
    $url = Rsx::Route('_Sys_Dashboard_Action');
}
```

```javascript
if (Permission.can_access('_Sys_Dashboard_Action')) {
    const url = Rsx.Route('_Sys_Dashboard_Action');
}
```

And the exit link of an impersonation the panel began - the stop route is
published into every bundle the same way (see "Impersonation" below):

```javascript
if (window.rsxapp.impersonation) {
    const stop_url = Rsx.Route('_Sys_Impersonation_Controller::stop');   // GET /_sys/stop-impersonating
}
```

### `_Sys_Dashboard_Action` is the special name

That one string is addressable from `Rsx::Route()` in PHP **and** `Rsx.Route()`
in JavaScript even though the application's bundle never includes
`app/RSpade/Sys` - and must not: an `rsx/` bundle naming anything in that tree
is `CONV-BUNDLE-02`, critical. `Permission.can_access('_Sys_Dashboard_Action')`
answers in every bundle for the same reason.

- **The ACTION, not the controller.** A SPA route is registered under its JS
  action class, so `Rsx::Route('_Sys_Spa_Controller::index')` throws
  ("JavaScript class _Sys_Spa_Controller must extend Spa_Action") - true of
  every SPA bootstrap controller, yours included.
- **How it reaches a bundle that does not contain it.** The target is listed in
  `config('rsx.always_published_routes')`; the bundle compiler resolves its
  patterns from the manifest at compile time and emits them into every bundle's
  route table, and its grants ship on every page in
  `window.rsxapp.auth_routes_published` (always present, unlike the opt-in
  `auth_routes`). Move the panel and every link moves with it - which is why a
  hardcoded `/_sys` is always wrong.
- **Referencing it as a string is NOT a `NAME-RESERVED-02` violation.** Strings
  are the sanctioned carrier: the rule reads code, never string literals, so
  these calls need no exception comment.
- **This is THE canonical way to link to the /_sys dashboard.** Any other
  `_Sys_*` reference - the stop-impersonating link above aside - is refused, and
  there is no second spelling of this one.

An application may append its own targets to `always_published_routes` in
`rsx/resource/config/rsx.php` (append-only merge). A configured target with no
routes in the manifest fails the bundle compile rather than silently resolving
to a placeholder URL.

MUST NOT - everything else in the tree is a framework internal that happens to
be visible, renamable or deletable in any release:

- never extend `_Sys_Layout` or any `_Sys_*` / `_Apidocs_*` class;
- never render a `_Sys_*` or `_Apidocs_*` component from an app template;
- never call a `_Sys_*` controller method or reference a `_Sys_*` bundle;
- never hand-write a `/_sys` sub-path (or any `/_`-prefixed URL - the leading
  `/_` is the house mark for framework-owned endpoints, like `/_ajax`);
- never declare a `_`-prefixed name of your own.

Both directions are enforced. `NAME-RESERVED-01` refuses a DECLARATION;
`NAME-RESERVED-02` (critical, manifest-time) refuses a REFERENCE - a
`_`-prefixed class, component, `@rsx_id` or static method that the manifest
knows the framework declares under `app/RSpade/`. It is scoped to real
framework names, so your own `static::__helper()` calls, `__construct`,
`__DIR__` and vendor APIs are untouched, and `static::` / `self::` / `parent::`
receivers are never checked at all. The sanctioned carriers above are
STRINGS handed to a resolver, so they are legal by construction - the rule
never reads a string literal.

## The gate

Every panel surface declares `#[Auth('is_sysadmin')]` (JS actions:
`@auth('is_sysadmin')`). `is_sysadmin` is a framework `#[Auth_Check]` on
`Permission_Abstract`, staff realm, and its body is `Session::is_developer()`:
developers only (`login_users.is_developer`, set by hand); any other signed-in
identity gets a 403.

To change the audience there is exactly one documented mechanism: the check is
`#[Replaceable]`, so an application that CLASS-OVERRIDES the permission class
(a same-named class in `rsx/`, copy-and-replace - `rsx:man class_override`)
redeclares `is_sysadmin()` with its own body, and every
`#[Auth('is_sysadmin')]` surface follows with no call-site change. There is no
config key, registry or hook. If that shape does not fit, it is a framework
change request, not a local workaround.

One panel surface is NOT `is_sysadmin`: `GET /_sys/stop-impersonating` is gated
`is_logged_in` (during an impersonation the signed-in identity is never a
developer) and refuses in its body unless the IMPERSONATOR is a developer.

An anonymous visitor is sent to the login page rather than shown a refusal.

## Impersonation

"Sign in as this user" on a Users detail calls `Session::begin_impersonation()`:
FULL read/write, one level, never onto a developer or yourself, onto one of
the identity's ACTIVE memberships (the dialog asks which site when there are
several). It stores the developer's site under
`Session::put_value('sys.impersonation.return_site', ...)`, sets the chosen
site, and loads `/`. `GET /_sys/stop-impersonating` ends it, restores that site
and lands on the identity's Users detail.

**The site is the application's call.** A `Main::init()` that declares its staff
site on every request (the single-tenant template's `Session::set_site_id(1)`)
moves the session back to that site on the next request; the framework's
membership check then asks about the impersonated identity THERE - with an
active membership the impersonation continues on the app's site, without one
the whole session is signed out (the developer's own sign-in included).

**The banner is the application's.** The framework ships the state -
`window.rsxapp.impersonation` = `{impersonator_login_user_id,
impersonator_email, started_at}` or null - and the published stop route. The
template's banner is `Impersonation_Banner`
(`system/app/RSpade/resource/reference_app/theme/components/ui/impersonation_banner/`),
mounted in its staff SPA layout. Mechanism: `rsx:man session` (IMPERSONATION).

## The per-browser console_debug override

Debug Flags stores a console_debug override (enabled, filter mode and channels,
benchmark / location / backtrace prefixes) in the developer's SESSION - never in
`.env` or a config file. Every page and Ajax call that browser makes runs under
it, PHP output and `window.rsxapp.console_debug` alike; no other browser, no
other developer and no CLI process sees it. It applies only while the identity
is a developer (re-checked every request), only where console_debug is live
(never strict production), leaves `outputs.*` to the configuration, and ends
with the session or on Reset. The `rsx:debug` harness headers still win over it.
Details: `rsx:man console_debug` (PER-BROWSER OVERRIDE).

## The switch

`config('rsx.sys_panel.enabled')`, framework default `true`. Turn it off in
`rsx/resource/config/rsx.php`:

```php
'sys_panel' => ['enabled' => false],
```

A disabled panel answers 404 at every `/_sys` URL (a `pre_dispatch()` refusal -
no mechanism removes a route by config). `can_access()` hides a link when the
GATE denies, not when the SWITCH is off, so a link that must follow the switch
tests the config too.

## Never modify the tree

All of `system/` is overwritten by `rsx:framework:pull` (downstream it is a git
submodule reset to the upstream tip), so an edit inside `Sys/` survives until
the next update and then vanishes silently. The tree is modified in ONE place:
the RSpade monorepo, on a box with `IS_FRAMEWORK_DEVELOPER=true`.

Downstream, a change to the panel is a **framework change request** -
`rsx:man framework_debug_and_contrib`. Class-overriding a `_Sys_*` class is not
an alternative: it means declaring a `_`-prefixed name under `rsx/`, which
`NAME-RESERVED-01` refuses.

## Why the panel is missing from tool output

`rsx:routes`, `rsx:jqhtml:glossary`, `rsx:manifest:show`, `rsx:manifest:dump`,
`rsx:manifest:stats --detailed`, `rsx:bundle:show`, `rsx:task:list` and the IDE
completion service hide `_`-prefixed names and `app/RSpade/Sys/` files unless
`config('rsx.code_quality.is_framework_developer')` is true. That is display
only - nothing leaves the manifest, no route stops resolving, and `/_sys` is
reachable in a browser on any box.

## The API console

`Sys/app/apidocs/` is the second module in the tree: the external-API developer
console. It shares the panel's THEME and nothing else - standalone page, no
`_Sys_Layout`, no `/_sys` route - and the APPLICATION mounts it with its own
route calling `Rsx_Api_Docs::page()`, so its gate is whatever that route
carries. See `rsx:man external_api` and skill `rspade:external-api`.
