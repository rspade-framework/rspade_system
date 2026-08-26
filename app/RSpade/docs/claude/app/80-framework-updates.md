<!-- bucket: app — single-source, never duplicate. True ONLY in a downstream application. THIS FRAGMENT IS THE CANONICAL HOME of the pull contract and its recovery paths; the git-proxy rules live in app/81 and the maintenance window in shared/73. -->

## FRAMEWORK UPDATES

```bash
php artisan rsx:framework:pull    # User-initiated only
php artisan rsx:framework:status  # Installed release + is an update available?
```

**`system/` is a GIT SUBMODULE** tracking `https://github.com/rspade-framework/rspade_system.git`.

**ALL OF `system/` IS FRAMEWORK PROPERTY AND WILL BE OVERWRITTEN ON EVERY UPDATE.** There are no owned zones, no protected sub-paths and no merge: an update does `git reset --hard` inside the submodule, discards untracked files, and checks out the upstream tip. Local changes there are not preserved, not reported and not asked about — the tree is a checkout of somebody else's repository. **Customize the framework with a class override in `rsx/`** (`rsx:man class_override`), never by editing `system/`.

The pull then **commits the new submodule pointer** in your repo — one commit changing one gitlink, carrying the concatenated upstream changelog as its body, so `git log` explains what arrived without reading another repository's history. Each update is also appended in full to `rsx/resource/framework_update_history.dat`. The pull raises the SAME maintenance window the operator commands do (see the maintenance fragment).

**Cloning your app needs the submodule**: `git clone --recurse-submodules`, or `git submodule update --init --recursive` after the fact. A checkout with an empty `system/` is a missing submodule, not a broken framework.

**Run `php artisan migrate` AFTER every completed pull.** A pull can bring migrations from other environments; until `migrate` runs, the code and the schema disagree. The full user-requested pull sequence is: `php artisan rsx:git pull` -> `php artisan migrate` (safe when nothing is pending — it simply reports nothing to migrate; in the dev container, against a local database, the automatic snapshot protects it). **Do this whenever the user asks for a pull, without being asked separately.**

**Set a LONG command timeout (10 minutes / 600000ms) when running `rsx:framework:pull`** — it fetches the distribution and runs the whole rebuild chain, and killing it mid-run leaves a partial update. This is a TOOL-CALL budget, not a timeout in code; the no-timeout mandate is unaffected.

### Flags

`--diff` (preview the incoming changelog, changes nothing), `--no-rebuild`, `--no-commit`, `--no-service-control` (you manage the maintenance window), `--upstream-url=`, `--branch=`.

**There is no `--force`, and nothing to force.** The tamper gate, the release inventory and the owned-zone reconciliation it used to override are all gone: the update already overwrites everything under `system/` unconditionally, so a "restore my missing framework file" flag has nothing left to mean. A missing or modified file is simply corrected by running the pull.

**`system/app/Http/Kernel.php` is framework-owned like everything else under `system/`.** Declare your own HTTP middleware in `config('rsx.middleware')` (append-only; `rsx:man config_rsx`).

### Migration from the vendored model

Older projects carry `system/` as ordinary tracked files. The pull converts them: **inside the RSpade container it does this automatically** (removing the vendored tree and adding the submodule, in two commits); **outside the container it refuses** and prints the exact commands, because converting rewrites your repository's history and that is not something to do to somebody's machine unasked.

### After the pull

- **Environment updates self-apply, silently** — a successful pull runs `system/bin/post-update.sh`, executing self-detecting idempotent scripts (the Claude Code status line and git guard, the knowledge-tree wiring, the storage relocation). Never able to fail your build.
- **Pending mandatory upstream changes are surfaced automatically**: `rsx:framework:upstream_changes` (list) / `:show {name}` / `:mark {name} --fulfilled`.

Skill: `rspade:framework-updates`. Details: `php artisan rsx:man rsx_upstream`, `rsx:man upstream_changes`.
