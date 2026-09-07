<!-- bucket: framework — single-source, never duplicate. True ONLY in this monorepo. -->

## THE FRAMEWORK'S OWN APPLICATION (`Sys/`)

`system/app/RSpade/Sys/` is an RSX APPLICATION that ships inside the framework: the `/_sys`
control panel plus the `/apidocs` API console beside it. It is built the way `rsx/` is built
(`app/sys`, `app/apidocs`, `theme`, `lib`, `handlers`; modules, bundles, a SPA layout, JS
actions) and is scanned by the manifest like any other application tree — **it is not a
runtime special case**, so a framework feature that is awkward to use here is a framework
defect, not a `Sys/` exemption.

**The `_` prefix is the whole isolation mechanism, both directions** (`NAME-RESERVED-01`,
critical, manifest-time, keyed on path): every class, JS class, jqhtml component and
`@rsx_id` declared here carries exactly ONE leading underscore, and `rsx/` may declare none —
because a `_`-prefixed name in `rsx/` matching one here is read as a CLASS OVERRIDE, which
silently archives the framework file as `.upstream` and serves the app's copy. Directories
stay lowercase and bare (the namespace generator PascalCases them).

**A `Sys/` bundle names no `rsx/` path and no app-defined class** (`CONV-BUNDLE-04`,
critical): the panel runs beside somebody else's application, which may restyle its theme or
delete a component at will, so the tree ships its own Bootstrap build, palette and components
(`theme/`, shared by the API console — the two shipped surfaces are one product). **The
mirror rule is `CONV-BUNDLE-02`** (critical): an `rsx/` bundle may name nothing under
`app/RSpade/Sys` either, by path, by `_`-prefixed bundle class or in `include_routes` — the
tree is shared in NEITHER direction.

**The panel is reached from an application bundle WITHOUT being in it.** Its entry target
`_Sys_Dashboard_Action` (the INDEX ACTION — a SPA route is registered under its JS action
class, never under the `#[SPA]` bootstrap controller, so `Rsx::Route('_Sys_Spa_Controller::index')`
throws) is listed in `config('rsx.always_published_routes')`; `BundleCompiler` resolves its
patterns from the manifest at compile time into EVERY bundle's route table, and
`Auth_Gates::export_published_route_grants()` ships its grants on every page as
`window.rsxapp.auth_routes_published` (always present, explicit `0`s, unlike the opt-in
`auth_routes`). So `Rsx.Route()` and `Permission.can_access()` both answer for it anywhere. A
configured target with no manifest routes FAILS THE COMPILE. **The published map is attached
AFTER `__filter_underscore_keys()`** in `Rsx_Bundle_Abstract` — that filter strips
`_`-prefixed PAYLOAD FIELDS, and these keys are route targets whose reserved prefix is the
point.

**Modified ONLY here**, on a box with `IS_FRAMEWORK_DEVELOPER=true`; downstream all of
`system/` is reset by `rsx:framework:pull`, and a panel change is a framework change request.
The panel's own gate is `is_sysadmin` on `Permission_Abstract` (`#[Replaceable]`, body =
`is_logged_in` today); its switch is `rsx.sys_panel.enabled`.

Deep docs live beside the code: `Sys/CLAUDE.md`, `Sys/app/sys/CLAUDE.md`,
`Sys/app/apidocs/CLAUDE.md`. Contract tier: `rsx:man sys_panel`; skill `rspade:sys-panel`.
Tests: `rsx:test --framework --group=sys_panel`.
