# app/RSpade/Sys - the framework's own application

This tree is an RSpade APPLICATION that happens to ship inside the framework: the
control panel served at `/_sys`. It is built exactly the way `rsx/` is built - a
module with a bundle, a SPA bootstrap controller, JS actions, a theme directory of
components - and it is scanned by the manifest like any other application tree
(`config('rsx.manifest.scan_directories')` lists `app/RSpade/Sys`).

```
Sys/
  theme/            _Sys_Theme_Bundle (Bootstrap + tokens), variables.scss,
                    theme.scss, vendor/bootstrap.scss, components/
  lib/              shared panel code (classes only) - empty today
  handlers/         #[OnEvent] handlers - empty today
  app/sys/         the panel module: bundle, controllers, layout, one dir per page
  app/apidocs/      the API reference console: bundle, blade shell, Ajax
                    controller, components/ - standalone, mounted by the
                    application's own route (see app/apidocs/CLAUDE.md)
```

## Every name here carries one leading underscore

`_Sys_Layout`, `<Define:_Sys_Section>`, `@rsx_id('_Sys_App')`. The single leading
underscore is the FRAMEWORK-APPLICATION PREFIX, reserved from `rsx/` exactly as a
`_`-prefixed table or column is reserved in the schema: it is what keeps the
framework's application out of the namespace an application is free to fill. The
shape lives in `App\RSpade\Core\Naming\Rsx_Identifier`; `NAME-RESERVED-01` enforces
both DECLARATION directions at manifest-build time (bare name here -> violation;
`_`-prefixed name under `rsx/` -> violation, because the manifest would read it as
a class override and silently serve the application's copy), and
`NAME-RESERVED-02` refuses a REFERENCE to any of these names from `rsx/`.

DIRECTORIES are the exception: they are lowercase and never `_`-prefixed, because
the namespace generator PascalCases directory segments. `Sys/app/sys/X.php` is
`App\RSpade\Sys\App\Sys`, `Sys/theme/X.php` is `App\RSpade\Sys\Theme`, and a
namespace mismatch anywhere under `app/RSpade` is a fatal that is never auto-fixed.
Filenames are CASE-EXACT: the filename equals the class or component name.

## The bundle invariant

`_Sys_Bundle` and `_Sys_Theme_Bundle` NAME NO `rsx/` PATH AND NO APP-DEFINED
CLASS - `CONV-BUNDLE-04` (critical) enforces it. The panel is framework code
running beside somebody else's application; that application may restyle its
theme, redefine its Bootstrap build or delete a component at will, and the panel
must still come up. So the panel ships its own everything: its own Bootstrap build
(compiled from the framework's `system/node_modules/bootstrap`), its own palette,
its own components. The API console beside it (`app/apidocs/_Apidocs_Bundle.php`)
obeys the same invariant for the same reason, and reaches the SAME theme -
`_Sys_Theme_Bundle` plus `app/RSpade/Sys/theme` - so the framework's two
shipped surfaces are one product. `theme/theme.scss` is therefore the only
place a palette token is declared; a module never keeps a private copy.

Two mechanical consequences: SCSS `@import` is legal only in a file whose path
contains `/vendor/`, and `vendor/` is a never-recursed directory basename - so
`theme/vendor/bootstrap.scss` must be an EXPLICIT FILE include, never picked up
from a directory include. Bootstrap's JS arrives through the bundle's `npm` key
and its icons through `cdn_assets` (mirrored into the git-tracked `.cdn-cache`
store and served same-origin from `/_vendor/`).

## The gate and the switch

Every dispatchable surface in the PANEL declares `#[Auth('is_sysadmin')]` (JS actions:
`@auth('is_sysadmin')`). `is_sysadmin` is a framework check on `Permission_Abstract`,
staff realm only; its body is `is_logged_in()` today and narrowing it is a pending
owner decision that lands there, not at any call site here.

`app/apidocs/` is the exception, and deliberately: the console is not a panel
screen. It has no route of its own - the APPLICATION mounts it - so its gate is
whatever that route carries, and `_Apidocs_Controller`'s Ajax endpoints stay
`#[Auth('public')]` because the framework cannot know what the app chose.

`config('rsx.sys_panel.enabled')` (default true) switches the panel off. No
manifest or dispatcher mechanism removes a route by config, so the refusal is a
`pre_dispatch()` returning `Error_Screens::not_found($request)` - stated on both
`_Sys_Spa_Controller` (which covers every SPA screen) and `_Sys_Controller`.

## Adding a page

1. `mkdir app/sys/<feature>` (lowercase, no underscore prefix).
2. `_Sys_<Feature>_Action.js`: `@route('/_sys/<path>') @layout('_Sys_Layout')
   @spa('_Sys_Spa_Controller::index') @auth('is_sysadmin') @title('<Title>')`,
   `class _Sys_<Feature>_Action extends Spa_Action`.
3. `_Sys_<Feature>_Action.jqhtml`: `<_Sys_Page_Scaffold><Slot:main>...`.
4. Add the nav entry to `_Sys_Layout.js` `on_create()`. Items are filtered
   through `Permission.can_access(item.route)`, so a tighter gate removes the link
   with no edit here.

Nothing needs registering: `__DIR__` is already in `_Sys_Bundle`'s include list.

## Framework property: modified here, referenced nowhere

This tree is modified in ONE place - the RSpade monorepo, on a box with
`IS_FRAMEWORK_DEVELOPER=true`. Downstream all of `system/` is reset to the
upstream tip by `rsx:framework:pull`, so an edit here survives until the next
update and then vanishes with nothing reported; a downstream change to the panel
is a framework change request (`rsx:man framework_debug_and_contrib`).

And the rule pointing the other way: **an application must not USE the `_Sys_*`
or `_Apidocs_*` classes, components, templates or routes.** Not extend
`_Sys_Layout`, not render `<_Sys_Section>`, not call a `_Sys_*` controller
method, not hand-write a `/_sys` sub-path. Everything here may be renamed,
restructured or deleted in any release. The ONE sanctioned reference is a link
to the panel through `Rsx::Route('_Sys_Dashboard_Action')` guarded by
`Permission::can_access(...)`.

`NAME-RESERVED-01` checks DECLARATIONS; `NAME-RESERVED-02` checks REFERENCES,
and both are manifest-build fatals. NAME-RESERVED-02 fires when application code
under `rsx/` names a `_`-prefixed class, component, `@rsx_id` or static method
that the MANIFEST knows is declared under `app/RSpade/` - so it covers this whole
tree plus every other `_`-prefixed framework class, and it covers a framework `_`/`__`-prefixed STATIC on an
ordinarily-named class (`Ajax::_is_internal_call()`, `Rsx._escape_html(...)`).
The two sanctioned carriers stay legal because they are STRINGS and the rule
never reads a string literal. Details: `rsx:man sys_panel`,
`rsx:man code_quality`, skill `rspade:sys-panel`.

The framework's inventory commands hide this tree's names and files unless the
box is a framework developer (`Rsx_Identifier::is_visible_to_developer()` /
`is_path_visible_to_developer()`) - display only, nothing leaves the manifest.

## Living documentation

This file describes the tree as it is today. When the tree's contents or
conventions change, updating it is part of that change.
