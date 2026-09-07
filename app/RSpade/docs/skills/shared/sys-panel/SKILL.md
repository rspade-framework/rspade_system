---
name: sys-panel
description: "Coexisting with the framework's own control panel at /_sys - the `_Sys_*` reserved namespace and NAME-RESERVED-01 / NAME-RESERVED-02, the is_sysadmin gate and how an application narrows it, the rsx.sys_panel.enabled switch, linking to the panel with Rsx::Route / Permission::can_access, and why the tree is never modified or referenced from rsx/. Use when linking to or hiding the /_sys panel, turning it off, narrowing is_sysadmin, hitting a NAME-RESERVED-01 violation ('starts with an underscore, which is the framework-application prefix' or 'must carry the framework-application prefix') or a NAME-RESERVED-02 violation ('Application code references the framework-reserved' / 'Application code calls the framework-internal method'), wondering why a `_Sys_` name or a `/_sys` route is missing from rsx:routes / rsx:manifest:show / rsx:jqhtml:glossary output, or being tempted to extend _Sys_Layout, render a _Sys_* component, or edit anything under system/app/RSpade/Sys/ - all of which are reserved from application code."
---

# The /_sys control panel

RSpade ships its own application: a control panel at `/_sys`, living in
`system/app/RSpade/Sys/` and built exactly the way `rsx/` is built - modules,
bundles, a theme, a SPA layout, JS actions. It is the SYSTEM ADMINISTRATOR's
view of the framework (dashboard, debug flags, tasks, email, logs, sites,
users), not the application's admin area - tenants, billing and customers are
application concepts and stay in the application.

The screens are placeholders today; the route, gate, nav and chrome are real
and the feature set lands over releases. What is already load-bearing is the
shape below, which every application has to coexist with.

Contract tier: `rsx:man sys_panel`.

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

MAY - the two sanctioned references, both indirections that are contracts:

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
  `_Sys_*` reference is refused, and there is no second spelling of this one.

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
receivers are never checked at all. The two sanctioned carriers above are
STRINGS handed to a resolver, so they are legal by construction - the rule
never reads a string literal.

## The gate

Every panel surface declares `#[Auth('is_sysadmin')]` (JS actions:
`@auth('is_sysadmin')`). `is_sysadmin` is a framework `#[Auth_Check]` on
`Permission_Abstract`, staff realm, and its body today is
`Session::is_logged_in()` - the loosest useful default.

To narrow it there is exactly one documented mechanism: the check is
`#[Replaceable]`, so an application that CLASS-OVERRIDES the permission class
(a same-named class in `rsx/`, copy-and-replace - `rsx:man class_override`)
redeclares `is_sysadmin()` with a tighter body, and every
`#[Auth('is_sysadmin')]` surface follows with no call-site change. There is no
config key, registry or hook. If that shape does not fit, it is a framework
change request, not a local workaround.

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
