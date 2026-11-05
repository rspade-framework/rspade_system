<!-- bucket: framework — single-source, never duplicate. True ONLY in this monorepo. -->

## MONOREPO ENVIRONMENT

**URL**: https://rspade.framework.dev.hanson.xyz/ (user URLs) | http://localhost/ (curl/testing) | **DB**: MySQL (rspade/rspadepass) | **Dir**: `/var/www/html` | **PHP**: 8.5. `base_path()` = `/var/www/html/system`; the tree links `system/rsx -> ../rsx`, `system/.env -> ../.env`, `system/build -> ../build` and `system/app/RSpade/resource/reference_app` -> `rsx/`, so `/rsx` in framework code is `/var/www/html/rsx`.

**Dependencies**: framework packages install with plain `composer require` / `npm install` INSIDE `system/` (`system/vendor` + `system/node_modules` committed, shipped by `rsx:framework:pull`; the framework wins every overlap) - **the ONLY place this template gets a package; the template keeps ZERO app-layer deps.** Exposing one (`dependencies.exposed_composer`/`exposed_npm`) is a standing commitment whose breaking change or retirement ships a Category 2 `breaking_changes` doc; **never hand-edit the machine-generated `"replace"` block in the root `composer.json`.** Skill `rspade:framework-dependencies`.

**`.env.dist` is authored here** (hand-curated, copied verbatim into every release) - **never put a secret in it.** Framework-internal `--_` flags (no `InputOption`, stripped pre-boot in `bootstrap/rsx_preboot.php`, read via `Rsx_Internal_Flags`) and `RSPADE_MAINT_MODE`: `Commands/CLAUDE.md`, skill `rspade:maintenance-mode`.
