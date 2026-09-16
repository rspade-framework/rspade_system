---
name: application-modes-deployment
description: "Building, sealing and deploying an RSpade app - the three application modes, rsx:build as the one build path, the rsx:prod:enable/disable/verify lifecycle, the build/ tmp/ storage/ split and its read-only production posture, what the seal pins, strict production vs the --debug variant, the determinism contract that makes a build cluster-shareable, and diagnosing a refusal or verify drift. Use when taking an app to production, reproducing a prod-only bug locally, deploying new code onto a sealed host, or debugging \"This production build is unsealed\", \"A sealed production build is already present\", \"Refusing to clean a production build tree without --force\", or \"is a build artifact, and only a build may write it\"."
---

# Application modes and deployment

Three modes: `development`, `debug`, `production`. **Development is production-worthy for low-traffic use** - it auto-rebuilds and JIT-compiles, and an app can legitimately run that way forever. `debug` and `production` (together, "a prod mode") are **SEALED builds**: compiled ONCE by an explicit command, then IMMUTABLE. No auto-rebuild; a missing artifact fails loud instead of quietly rebuilding.

`RSX_MODE` in `.env` is the authoritative mode (default `development`). You never edit it by hand - the transition commands write it.

**The contract in one sentence: one command (`rsx:build`) produces what the site serves, and everything else only reads it.** Every refusal below is that sentence enforcing itself.

The full deployment guide - the three recipes, the read-only posture, the administration levers and the error catalog - is `php artisan rsx:man prod`. This skill is when-to-reach-for-what and what to do when something refuses.

---

## Which command, when

```bash
php artisan rsx:build                  # DEVELOPMENT: manifest + every bundle
php artisan rsx:build --force          # PROD MODE: clean, build, seal (the whole artifact)
php artisan rsx:clean --force          # discard build/ and tmp/ (prod mode needs --force)
php artisan rsx:prod:enable            # enter strict production and build
php artisan rsx:prod:enable --debug    # enter the DEBUG variant and build
php artisan rsx:prod:disable           # unseal, return to development, build dev assets
php artisan rsx:prod:verify            # recompute hashes vs the seal (exit 1 on drift)
php artisan rsx:mode:set dev|debug|prod   # thin delegator, kept for muscle memory
```

`rsx:mode:set` is a delegator so there is exactly ONE implementation of each transition: `dev` -> `prod:disable`, `debug` -> `prod:enable --debug`, `prod` -> `prod:enable`.

**The one that trips people up: after pulling new code onto a host that is already sealed, run `rsx:build --force`** (or `rsx:mode:set prod`, which does the mode write and then exactly that). There is no separate "refresh" command - the build IS the refresh. Without it, the host keeps serving the artifact it already has: the seal says "a build completed here", never "this build matches the code on disk".

### What `rsx:prod:enable` actually does

1. Heal the `.env` symlink invariant.
2. Write `RSX_MODE` to `.env` **and sync it into the running process** (see the gotcha below).
3. Spawn `rsx:build --force`, which boots on the mode just written.

That is all. The build is what produces and seals the assets, so `rsx:build --force` on a box already in a prod mode is the same operation without the mode write.

**A failed build leaves the box unsealed**, and it says so: "RSX_MODE is production and this box has nothing to serve until the build succeeds." Fix the error and run the build, or `rsx:prod:disable` to get back to a working development box.

`rsx:prod:disable` removes the seal FIRST, sets `RSX_MODE=development`, runs `rsx:clean`, **restores the DEVELOPMENT composer autoloader** - a strict-prod build leaves a classmap-authoritative autoloader that must be undone or new/renamed classes stop resolving - and runs `rsx:build`. It is idempotent.

---

## The three trees, and why they exist

```
build/     what rsx:build produced - the deployment artifact
tmp/       derived caches and runtime scratch; wholly regenerable
storage/   uploads, storage/app, logs, storage/state (the maintenance flag, flock files)
```

**`tmp/` IS Laravel's storage path.** `useStoragePath()` is handed the tmp root, so `storage_path()` and every package that composes a location from it land in the disposable tree. The persistent exceptions are pinned: the logging channels at `<storage>/logs`, the local and public disks at `<storage>/app`, and the `tmp/logs` and `tmp/app` symlinks that send those two lazy spellings back into storage. **A package that persists through `storage_path()` loses its files on the next `rsx:clean`** - point it at a `Storage` disk, at `Rsx_Project_Paths::app_dir('<name>')` for durable data, or at `tmp_path('<name>')` for a cache. `TMPDIR` is the tmp root in every process the framework starts; set it yourself in a php-fpm pool, supervisor program or cron line you wrote, and read the `PHP temp dir` row of `rsx:health` to confirm.

**Reach for a path through `App\RSpade\Core\Paths\Rsx_Project_Paths` and never a literal.** `PATH-OWNER-01` flags a literal, a `storage_path()` with a literal argument, or a raw write aimed at the build root. `tmp/` and `storage/` are relocatable (`RSX_TMP_PATH`, `RSX_STORAGE_PATH`, absolute or project-root-relative), so a literal does not merely read badly - on a box that moved one, it addresses nothing. `build/` is fixed at `<project>/build` and has no key: it ships and goes read-only beside `system/` and `rsx/`.

**An application artifact that belongs with the build** is written from an `#[OnEvent('rsx.rebuilt')]` handler into `Rsx_Project_Paths::build_path('app/<name>')` - inside `rsx:build` in a prod mode, on the first request after a change in development. Worked example: `rsx:man event_hooks`.

**The split is what makes the production read-only posture possible**: the web user writes `storage/` and `tmp/` and only READS `system/`, `rsx/` and `build/`. Own the first three with the deploy user. `rsx:health` reports where the box stands as INFO rows in a prod mode; run it AS THE SERVING USER or it reports root's access and tells you nothing.

Entry-by-entry: `rsx:man storage_directories`.

---

## Deploying: the order that matters

```bash
php artisan rsx:maintenance:enable --reason="deploying <version>"
git pull
php artisan migrate
php artisan rsx:mode:set prod          # = a reseal on an already-sealed box
php artisan rsx:prod:verify
php artisan rsx:maintenance:disable
```

**Migrate BEFORE the build.** The manifest bakes every model's column map in at BUILD time; the database moves at MIGRATE time. Build-then-migrate leaves the served code believing in columns the tables do not have yet, for the length of the migration. After a prod-mode migrate, `rsx:migrate:check_consistency` runs automatically and **migrate propagates its exit code**, so a mismatch is a failed migrate rather than a footnote.

**First install needs the build twice** - the first one has no schema to compile against:

```bash
php artisan rsx:build --force && php artisan migrate && php artisan rsx:build --force
```

**Do not run `composer install` by hand on an unsealed prod box.** Composer's post-autoload-dump hook spawns a bare `php artisan package:discover`, which is not a build context, and the seal gate refuses it - composer reports that as a failed dump. `rsx:build` dumps the optimized autoloader itself, inside the build context.

Building elsewhere (a CI artifact, a container image) works because of determinism: `rsx:man prod` carries both recipes.

---

## Strict production vs `--debug`

**Debug is a sealed, production-like LOCAL test build.** Unminified, inline sourcemaps survive, `console_debug()` still works. Its whole reason to exist is reproducing a prod-specific issue with readable code.

**Strict production** adds, relative to debug:

- Minification (Terser for JS, cssnano for CSS, via the minify RPC server).
- Inline sourcemaps are GONE - minification strips the `sourceMappingURL` comments.
- `console_debug()` is neutralized **two ways at once**: call sites are STRIPPED from the compiled output (Terser treats `console_debug` and `Debugger.console_debug` as `pure_funcs`), AND the `console_debug` config block is omitted from `window.rsxapp`, so even a surviving call site no-ops at runtime.
- The composer autoloader is dumped `--classmap-authoritative`.

**External mirroring is NOT a sealed-build difference** - every mode mirrors external assets into `rsx/resource/.cdn-cache/` (git-tracked) and serves them from `/_vendor/`, never inlined into bundles. The sealed difference is only WHO MAY DOWNLOAD: **in a prod mode only the build phase may**, and a missing mirror file THROWS naming the file and `rsx:build --force`. (A development miss is a 404 naming `rsx:cdn_externals:refresh`, which itself refuses on a sealed host.) The build runs its own explicit "Mirroring external assets" step over `*.externals.php` declarations, because nothing renders those at build time - one line per download, silence on a verified hit, and anything unfetchable FAILS the build. See skill `rspade:external-resources`.

### The environment gate

**There is no environment to validate.** `RSX_MODE` is the single mode switch and Laravel's `app.env` / `app.debug` derive from it (`config/app.php`); the `APP_ENV` / `APP_DEBUG` env keys are not read anywhere. Setting `RSX_MODE=production` IS the production environment with debug off, so `rsx:prod:enable` has no environment pre-check and `rsx:prod:verify` carries no environment rows - a mode that disagrees with the seal shows up as seal drift.

---

## The seal

`build/prod_seal.json` pins: **build_key, mode, git commit, and a per-asset sha256**. It is written LAST and only on success, so it means "a build completed here". `is_sealed()` is true only when a seal exists AND the current `RSX_MODE` is a prod mode - which is what makes a hand-edited `.env` unable to half-seal a box.

`rsx:prod:verify` is the cluster/CI tool: it recomputes the sha256 of every sealed asset and checks the on-disk build_key against the seal, and reports OPcache posture (advisory). Table out, **exit 0 when the build matches, 1 otherwise** - put it in the deploy health check next to `rsx:health`.

---

## Diagnosing a refusal

| Message | Meaning | Fix |
|---|---|---|
| `This production build is unsealed.` | The manifest index or the seal is missing in a prod mode. Web requests 500; nearly every command refuses | `rsx:build --force`. If the build itself is failing, `rsx:prod:disable` to read the failure without the gate in the way |
| `A sealed production build is already present.` | `rsx:build` with no `--force` on a sealed box | `rsx:build --force` - say deliberately that you are replacing what is being served |
| `Refusing to clean a production build tree without --force.` | `rsx:clean` on a sealed box | Almost always you wanted `rsx:build --force`, which cleans as its first step |
| `... is a build artifact, and only a build may write it.` | Something outside a build context tried to write under `build/` in a prod mode | The guard doing its job. Run the operation through `rsx:build` |
| `Cannot build - these directories are not writable by this user:` | Preflight, nothing changed | Run the build as the deploy user, or fix the tree's ownership |
| `[ERROR] N column(s) the manifest declares are missing from the database.` | The build and the schema disagree | `rsx:build --force`; migrate before the build next time |
| `[ERROR] rsx:migrate:check_consistency runs in a production mode only.` | Ran in development, where the manifest rebuilds from the live schema anyway | Nothing to fix - the check has no meaning there |
| verify: asset hash mismatch | Something wrote under `build/` outside the framework (raw `rm`, `cp`, a hand-edited bundle) | `rsx:build --force` |
| verify: build_key mismatch | The code on disk is not the code that was sealed (a pull without a rebuild) | `rsx:build --force` |
| `Bundle 'X' not compiled for production mode` | A bundle is missing while sealed | `rsx:build --force` |

---

## Living with a sealed build

The single behavior change that surprises people: **on a sealed box, editing a file does nothing.** No auto-rebuild, no JIT compile - the served assets are the ones the seal describes, and they stay that way until the next build. That is the point (a production box must not recompile under load, and a missing artifact must fail loud rather than silently rebuild something unreviewed), but it means the normal RSpade edit-and-refresh loop is OFF.

Consequences worth internalizing:

- **Debugging on a sealed box is a rebuild cycle**, not an edit cycle. If you are iterating, `rsx:prod:disable`, work in development, then re-enable.
- **A `git pull` on a sealed host changes nothing that is served** until you rebuild. A build_key mismatch in `rsx:prod:verify` is exactly this state, and it is why deploy scripts pair pull with build.
- **`LOG_LEVEL=info`**. Debug logging on a production build writes every query and framework notice to disk forever; `rsx:health` WARNs when the default channel resolves to `debug` in a prod mode.
- **OPcache posture is advisory only** in verify - it reports, it never fails. Whether a host runs OPcache is a host decision, not a framework one.

---

## Guards: guardrails, not security

Permission to write the build tree is a property of the PROCESS - the **build context** - not of a flag anybody can pass and never of an environment variable. `rsx:build` declares itself one from the first line of boot (recognised from argv) and carries it to its subprocesses as a framework-internal flag; `rsx:clean` declares one too. A web request, a plain artisan command or a tinker session is never a build context.

The guard keys on the MODE first, so a seal is not required for it to refuse: an unsealed production box is a broken deployment, and letting arbitrary commands write into it is how it stays broken. A seal on disk refuses the write as well, whatever mode the process read at boot - a web request that began under development and is still running after `rsx:mode:set prod` wrote the seal would otherwise compile with development semantics into the sealed tree.

They stop the framework's OWN write paths, and they do not (and cannot) stop somebody who goes around them: `rm -rf build/`, editing a bundle by hand, `cp` over `manifest_index.php`, `DB::table()->update()`. **The analogy is the realtime model layer**: `save()`/`delete()` emit change frames, a raw `DB::table()` write emits nothing, by design. `rsx:prod:verify` is the backstop that DETECTS such drift afterwards - it never prevents it. The OS-level answer is the read-only posture.

---

## Determinism: why a build travels

**Two byte-identical codebases, checked out at DIFFERENT absolute paths, produce an IDENTICAL build_key and IDENTICAL bundle filenames, byte-for-byte.** That is what lets one compile be trusted across a cluster, cached by CI keyed on build_key, or baked into a container image.

1. **File hashing branches on `RSX_MODE`**, the single mode switch. In a prod mode the hash covers the file's PROJECT-RELATIVE path plus its CONTENT (sha512) - never the absolute path, never disk timestamps. (Development still uses a fast relative-path + size + mtime hash; it only needs to notice local edits and is deliberately not portable.)
2. **The manifest hash excludes per-file mtime/size.** A file contributes its path and its sha1 to the key and nothing else; mtime and size stay in the index's `file_index` for dev change-detection. The prod index carries no `generated` timestamp, so it is byte-stable and build_key is content-derived.
3. **Generated-file keys are LOGICAL.** A generated stub is recorded as `tmp/js-stubs/<name>.js` whatever `RSX_TMP_PATH` resolves to, so two boxes with different overrides produce identical indexes and identical build keys.
4. **Bundle filenames are `{Bundle}__{app|vendor}.{hash8}.{ext}`**, the hash8 deriving from the same relative-path + content inputs plus the committed lockfile hashes and npm declarations. Minified output is reproducible given the pinned, committed `node_modules`.

**Bonus, and it is a real one**: because build_key is content-derived and stable across checkouts, full-page-cache keys (`fpc:{build_key}:...`) are **cluster-shareable** - two nodes on the same build hit the same FPC entries. (`rsx:man fpc`.)

---

## The mode-transition env-sync gotcha

`RSX_MODE` is a deployment ENVIRONMENT fact living in `.env`, **not** an invocation parameter. When a transition command spawns the build subprocess it writes `RSX_MODE` to `.env` FIRST **and also syncs the value into the running process** (`putenv` + `$_ENV` + `$_SERVER` + the mode cache), because the child inherits the parent's environment and its Dotenv will not override an already-set var.

Without that sync, an `enable` launched from a development parent spawns a build that runs **unminified and unstripped** - silently wrong output. If you ever add a command that shells out across a mode change, this is the trap.

**Per-invocation intent is a `--flag`, never an env prefix.** Do NOT prefix invocations with `KEY=VALUE` (owner ruling).

---

## Before launch

`php artisan rsx:man prelaunch_checklist` is a curated audit of framework-required requirements that are impractical to enforce with a lint rule - whole-flow wiring obligations, patterns a build cannot detect. The read-only posture is one of its entries.

Work it as a checklist, not a skim: each entry says what to audit, why, and what a correct implementation looks like. Then work your OWN list at `rsx/resource/audits/prelaunch_checklist.md` - app-specific items (permission gates you added, email templates, seed-data cleanup, throwaway accounts). **Review BOTH before going live**; neither replaces the other.

---

Details: `php artisan rsx:man prod`, `rsx:man app_mode`, `rsx:man storage_directories`, `rsx:man prelaunch_checklist`, `rsx:man fpc`. Related: `rspade:maintenance-mode`, `rspade:environment-config`.
