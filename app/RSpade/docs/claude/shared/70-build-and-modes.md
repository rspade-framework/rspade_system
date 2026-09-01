<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL HOME of the three application modes and the sealed-build lifecycle. Build INVISIBILITY lives in 03; the manifest/bundle one-liners live in 03 + 31. -->

## APPLICATION MODES & SEALED BUILDS

Three modes: `development`, `debug`, `production` — switched with `php artisan rsx:mode:set dev|debug|prod`. **Development** is production-worthy for low traffic: auto-rebuild on file change, JIT compile, unminified, full debugging.

**A DEV BOX IS AS IMPORTANT AS A PROD BOX (owner ruling).** A primary goal of RSpade is that development mode runs on a production environment and still behaves reliably and securely. The ONLY major differences in a sealed build: it skips the environment checks and dynamic source rebuilds (slightly faster), minifies JS, and suppresses diagnostic dumps on errors. Everything else — CSP enforcement, auth, delivery, every feature — works identically in every mode, so **a feature that "only breaks in dev" is a broken feature**, never a dev-mode allowance; and a mode-conditional weakening of a security control is a defect.

**`debug` and `production` are SEALED builds — compiled ONCE by an explicit command, then IMMUTABLE.** No auto-rebuild; a missing artifact fails loud. `debug` is the sealed production-LIKE local build (unminified, sourcemaps and `console_debug()` survive) for reproducing a prod-only issue; `production` is minified with sourcemaps gone and `console_debug()` stripped.

**`RSX_MODE` is the ONLY mode source: Laravel's `app.env` and `app.debug` DERIVE from it (`config/app.php`), and the `APP_ENV`/`APP_DEBUG` env keys are not read anywhere** — so there is no second switch to set, and no way for the two to disagree.

**Lifecycle**: `rsx:prod:enable [--debug]`, `rsx:prod:refresh` (rebuild + re-seal in place — **run this after pulling code onto a prod host**, or nothing served changes), `rsx:prod:disable`, `rsx:prod:verify` (asset hashes vs the seal — the cluster/CI drift check), `rsx:prod:export` (deployable package).

**External assets are mirrored in EVERY mode — there is no development exception.** Every bundle `cdn_assets` entry, every `mirror:true` external and every remote reference inside compiled CSS (a Google Fonts `@import` in SCSS and the woff2 files it names included) is downloaded into `rsx/resource/.cdn-cache/` on first compile and served from `/_vendor/`, so a mirrored asset is same-origin and contributes nothing to the CSP in any mode. **That store is git-tracked source — commit what a compile adds**, and `rsx:cdn_externals:refresh` is its one expiry (refused while sealed). An unfetchable URL or a failed `integrity` check FAILS the compile. **A sealed build is self-contained**: it never downloads at request time, and a missing mirror file fails loud naming `rsx:prod:refresh`.

**Guards are guardrails, NOT security**: `rsx:clean` refuses while sealed and write choke points reject unauthorized writes under `rsx-build`, but raw `rm` and hand-edits bypass them — `verify` detects the drift afterwards.

**In DEVELOPMENT mode `artisan` runs inside the RSpade container or not at all** — a pre-boot gate refuses when `/.rspade_container` is absent, printing the `docker compose exec` line for the command that was typed. CLI only, development only: debug and production run outside a container normally, and the web entrypoint is never gated. `migrate` separately reads `/.rspade_container_dev` and snapshots ONLY there, against a LOCAL database host; elsewhere there is nothing to snapshot and it runs bare.

**`RSX_MODE` in `.env` is the mode, and you never edit it by hand.** **Per-invocation intent is a `--flag`, never an env prefix: do NOT prefix an invocation with `KEY=VALUE`** (owner ruling).

**Before launch**, review `rsx:man prelaunch_checklist` AND your own `rsx/resource/audits/prelaunch_checklist.md`. Both lists, every launch.

Skill `rspade:application-modes-deployment` (per-mode behavior table, what the seal pins, the determinism contract, verify-drift diagnosis, export packaging). Details: `rsx:man app_mode`.
