---
name: framework-updates
description: "Updating the RSpade framework in a downstream application - what rsx:framework:pull actually does end to end, why all of system/ is framework property that every update overwrites, the flags that exist and the ones that no longer do, the one-time conversion from the vendored tree to a git submodule and why it only happens inside the container, cloning a project so system/ is populated, the environment updates that self-apply after a pull, consuming breaking_changes documents, and rsx:framework:status. Use when running or recovering a pull, reading its output, cloning a project with an empty system/, or reacting to a breaking-change notice."
---

# Framework updates

```bash
php artisan rsx:framework:pull     # the update. User-initiated ONLY.
php artisan rsx:framework:status   # installed release + is an update available?
```

**`system/` is a git submodule** tracking `https://github.com/rspade-framework/rspade_system.git`.

## The one rule

**ALL of `system/` is framework property and will be overwritten on every update.**

No owned zones, no protected sub-paths, no merge, nothing preserved. The update runs `git reset --hard` inside the submodule, `git clean -fdx` to remove untracked files, and checks out the upstream tip. Local changes there are discarded without being reported, because there is nothing worth reporting: the tree is a checkout of somebody else's repository, and a modification to it is drift, not work.

**Customize the framework with a class override in `rsx/`** - copy the class into `rsx/` under the same name (`rsx:man class_override`). That is the supported path, it survives every update, and it is the reason none of this needs a merge.

**Set a 10-minute (600000ms) command timeout.** The pull fetches the distribution and runs the whole rebuild chain; a 2-minute tool default is not enough, and killing it mid-run leaves a partial update. This is a tool-call budget, not a timeout in code - the framework's no-timeout mandate is untouched.

---

## What a pull does, in order

1. **Resolve context and paths**, then **copy itself to `/tmp` and re-exec.** The updater lives at `system/bin/framework-pull-upstream.sh`, inside the tree it is about to replace; `git checkout` would rewrite the file bash is still reading.
2. **Raise the maintenance window** - the same one `rsx:maintenance:enable` raises (web 503, automated task runners refused). Lifted on every exit path, Ctrl-C included. `--no-service-control` opts out.
3. **Establish that `system/` is a submodule.** If it is not, see *Conversion* below.
4. **`git reset --hard` + `git clean -fdx`** inside the submodule. Unconditional.
5. **Fetch and check out the upstream tip.**
6. **Commit the new submodule pointer** in your repository - one commit changing one gitlink, with the concatenated breaking changelog as its body. The full changelog is also appended to `rsx/resource/framework_update_history.dat`.
7. **Rebuild**: `rsx:env:heal`, `rsx:clean`, `rsx:manifest:build --force`, `migrate --framework-only`, `rsx:bundle:compile`, `rsx:framework:post_update`.
8. **Lower the maintenance window.**
9. **Report what needs a human**: pending migrations, pending `breaking_changes` documents.

The pointer is committed **before** the rebuild. A rebuild that fails therefore costs only the build - fix what it reports and rebuild. **Never re-pull to fix a failed rebuild.**

---

## Flags

| Flag | What it does |
|---|---|
| `--diff` | Preview the incoming changelog. Changes nothing, raises no window. |
| `--no-rebuild` | Sync only; prints the rebuild commands to run yourself. |
| `--no-commit` | Update the submodule, leave the pointer uncommitted. |
| `--no-service-control` | Do not raise the maintenance window (you manage it). |
| `--upstream-url=<url>` | Pull from somewhere other than the public distribution. |
| `--branch=<name>` | Track a branch other than `master`. |

### There is no `--force`, and nothing left to force

`--force` overrode the tamper gate. The tamper gate, the per-file release inventory, the mutation ledger, the owned-zone rsync and the three-way merge are all gone - the update overwrites everything under `system/` unconditionally, so "override the protection" has nothing to refer to. `--diff-system-changes`, `--resync` and `--check-foreign-changes` are gone for the same reason: they all answered "what did you change under `system/`", a question with one permanent answer now.

**A missing or modified framework file is fixed by running the pull.** No flag, no ceremony.

---

## Conversion from the vendored tree

Projects created before the submodule model carry `system/` as ordinary tracked files. The pull converts them, in two commits: remove the vendored tree, then add the submodule.

**Inside the RSpade container this happens automatically.** Outside it, the pull **refuses** and prints the exact commands - converting rewrites your repository's history, and that is not something to do to somebody's machine unasked.

Reachability is proven before anything is deleted, and a failed submodule add rolls the removal commit back, so an interrupted conversion cannot leave you with an application and no framework.

---

## Cloning a project

The submodule is not populated by a plain `git clone`:

```bash
git clone --recurse-submodules <your-app>
# or, after the fact:
git submodule update --init --recursive
```

**An empty `system/` is a missing submodule, not a broken framework.** That is the first thing to check when a fresh clone cannot find `artisan`.

---

## After the pull

**Environment updates self-apply, silently.** A successful pull runs `system/bin/post-update.sh`, which executes every self-detecting, idempotent script in `system/bin/environment_updates/` - the Claude Code status line and git guard, the knowledge-tree wiring, the storage relocation. They are silent when already applied and can never fail your build.

**Run `php artisan migrate` after every completed pull.** A pull can bring migrations; until `migrate` runs, the code and the schema disagree. The full sequence when a user asks for a pull, done without being asked separately:

```bash
php artisan rsx:git pull
php artisan migrate
```

**Pending breaking changes are surfaced automatically** - these are the manual steps an update cannot perform for you:

```bash
php artisan rsx:framework:breaking_changes                    # list
php artisan rsx:framework:breaking_changes:show <name>        # read one
php artisan rsx:framework:breaking_changes:mark <name> --fulfilled
```

---

## Things not to do

- **Do not edit anything under `system/`.** It is overwritten on the next update, silently. Use a class override.
- **Do not commit inside the submodule.** Your commit is not in the distribution; the next update checks out the upstream tip over it.
- **Do not add `system/` to `.gitignore`.** The gitlink must be tracked or the framework version is not recorded.
- **Do not re-pull to fix a failed rebuild.** The release is already committed; rebuild instead.

Details: `php artisan rsx:man rsx_upstream`, `rsx:ma breaking_changes`, `rsx:man class_override`.
