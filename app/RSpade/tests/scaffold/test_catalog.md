# Scaffold - Test Catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| SCAFFOLD-01 | SPA module (the default) generates the full file set: module bundle, `#[SPA]` bootstrap, layout `.js`/`.jqhtml`, index feature Action pair + controller | php | `rsx:app:module:create zzz_scaffold_spa` | 7 files, exit 0 | implemented | 2026-08-18 |
| SCAFFOLD-02 | The generated bundle extends `Rsx_Module_Bundle_Abstract` and includes `__DIR__` | php | same run | both present | implemented | 2026-08-18 |
| SCAFFOLD-03 | The bootstrap is gated (`#[Auth('is_logged_in')]`), marked `#[SPA]`, and passes the bundle to the SPA shell | php | same run | three lines present | implemented | 2026-08-18 |
| SCAFFOLD-04 | The layout extends `Spa_Layout` and its template declares `$sid="content"` | php | same run | both present | implemented | 2026-08-18 |
| SCAFFOLD-05 | The Action carries all four decorators (`@route`, `@layout`, `@spa`, `@auth`) and extends `Spa_Action` | php | same run | all present | implemented | 2026-08-18 |
| SCAFFOLD-06 | The feature controller is gated and route-free (Ajax endpoints only) | php | same run | `#[Auth]` present, no `#[Route(` | implemented | 2026-08-18 |
| SCAFFOLD-07 | An SPA module generates no Blade views | php | same run | zero `*.blade.php` at module root | implemented | 2026-08-18 |
| SCAFFOLD-08 | `--blade` generates the server-rendered set (bundle, layout, controller, view, js, scss) | php | `rsx:app:module:create zzz_scaffold_blade --blade` | 6 files, exit 0 | implemented | 2026-08-18 |
| SCAFFOLD-09 | The repaired Blade bundle extends `Rsx_Module_Bundle_Abstract` and the layout still renders it | php | same run | base class + `::render()` present | implemented | 2026-08-18 |
| SCAFFOLD-10 | The repaired Blade controller ships gated, with a route, and no `pre_dispatch` stub | php | same run | `#[Auth]` + `#[Route]` present, `pre_dispatch` absent | implemented | 2026-08-18 |
| SCAFFOLD-11 | Invalid module names are refused and leave no directory | php | `Bad_Name`, `bad-name`, `bad9name` | exit 1, no dir | implemented | 2026-08-18 |
| SCAFFOLD-12 | An existing module is never overwritten | php | `rsx:app:module:create frontend` | exit 1 | implemented | 2026-08-18 |
| SCAFFOLD-13 | A feature in a missing module is refused | php | `feature:create zzz_scaffold_missing widgets` | exit 1 | implemented | 2026-08-18 |
| SCAFFOLD-16 | The identifier derivation carries a leading framework-application underscore through: `_sys_card` names `_Sys_Card`, and the human title drops the empty leading segment | php | `StubProcessor::to_class_name()` / `::to_title()` | `_Sys_Card`, `Sys_Card`, `Sys Card` | implemented | 2026-09-07 |
| SCAFFOLD-14 | A scaffolded SPA module renders end to end (JIT bundle compile, action in the layout, no console errors) | playwright | `rsx:debug /<module> --user=1` | 200, action markup, no console errors | deferred (verified by hand at change time; needs a live server + a throwaway module in the tree) | 2026-08-18 |
| SCAFFOLD-15 | A scaffolded Blade module renders end to end | playwright | `rsx:debug /<module> --user=1` | 200, view markup, no console errors | deferred (same reason) | 2026-08-18 |
