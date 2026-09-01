---
name: publishing-a-release
description: "Publishing an RSpade framework release from the monorepo with system/bin/publish - the pre-flight gates, changelog and release-subject assembly, the two published repositories (rspade_system, rspade_project) and the reference app vendored into rspade_system, what gets stripped or transformed on the way out, the .dist to .sh renames, byte fidelity, the tracked-but-ignored class assertion, and what to check when a publish fails. Use when running bin/publish, changing what a release ships, or diagnosing a refused publish."
---

# Publishing a release

```bash
bash system/bin/publish
```

**Publishing packages for production - it is NOT a way to test anything.** Every run pushes to three real remotes.

The script itself (`system/bin/publish`, ~770 lines, heavily commented) is the authority; this skill is the map. **Read the step comments before changing anything** - several of them record failures that cost real downstream boxes.

---

## Pre-flight gates

1. **`php artisan rsx:check`** must pass. A code-quality violation stops the publish.
2. **The monorepo working directory must be CLEAN** (`git status --porcelain` empty). Everything ships from committed state, so the release commit and its changelog describe the source honestly.

**Not enforced by the script, but belongs to a release:** the source spelling sweep - `docs.dev/audits/framework_internal_audit_checklist.md`, entry "Source spelling sweep (pre-release)". `/rsx/` ships as the reference app downstream reads as canonical, so a misspelled identifier in it gets copied into real applications. Run it before a release and after any epic that added vocabulary.

## Changelog and release subject

The changelog is the monorepo `git log` since `.rspade_last_commit_for_publish` (written at the end of every successful publish), **capped at the more recent of that marker or 2 days back**, agent-attribution lines filtered out, and truncated at 50,000 characters. First publish falls back to the 2-day window.

The release commit is a **clean one-line subject + the byte-faithful changelog as the BODY**:

```
RSpade framework release <range_start>..<head> (N commits)

<full changelog: every commit's subject AND body>
```

That shape is load-bearing downstream: `rsx:framework:pull` reads `%s` for a readable one-liner and `%b` for the real rationale, which is why a release squash carries every underlying monorepo message rather than one content-free line.

---

## The three repositories

| Repo | Contents | Who consumes it |
|---|---|---|
| **rspade_system** | the framework: `system/` with dev material stripped | `rsx:framework:pull` clones this |
| **rspade_project** | the end-user starter project: root files + `rsx/`, with the published `rspade_system` tree **vendored into `system/` as ordinary files** | a new app is provisioned from this |

The reference application no longer has a repository of its own. `rsx/` is rsynced
INTO `rspade_system` at `app/RSpade/resource/reference_app/` (minus
research/archive/incomplete/docs scratch), so every release carries a pristine,
release-current worked example that downstream docs can cite by path. In the
monorepo that path is a SYMLINK to `/rsx/`, which is why the Step 3 rsync excludes
it and Step 3a materializes it as real files.

Both take the same commit message. Each has a separate git dir symlinked in, so the publish worktrees stay disposable.

## What leaves the tree on the way out

- **Dev-only files**: `bin/publish` itself, `bin/CLAUDE.md`, `CLAUDE.md`, `docs/`, `.claude/`, `storage/*`, supervisord config, archives, caches, scratch dirs, `.rspade_last_commit_for_publish`.
- **Framework-developer man pages**: `ast_sourcecode_parsers`, `code_quality`, `manifest_api`, `manifest_build`, `storage_directories`, `vs_code_extension`. **Never point a shipped fragment or skill at one of these** - it would resolve to nothing downstream.
- **`.env.dist`**: the TRACKED project-root `.env.dist` is copied **verbatim** to `system/.env.dist` and to the starter project - it is an authored file, not a scrub of anybody's live `.env`, so whether a key ships is an editorial decision made in that file (and a secret in it is a secret every install shares). A missing root `.env.dist` FAILS the publish. The shipped `system/.env` is re-created as the symlink to `../.env`.
- **`.dist` renames**: every `bin/*.dist` is renamed to its real name in the release - this is how `framework-pull-upstream.sh.dist` becomes the downstream updater. `rspade.code-workspace` goes the other way (renamed TO `.dist`).

---

## Byte fidelity and the class assertion

These steps are one mechanism and their ORDER is load-bearing.

**Step 12a — `.gitattributes` with `* -text`.** A downstream checkout must materialize EXACTLY the bytes the monorepo committed. If git normalized line endings, a checkout would materialize LF where a vendored package stored CRLF, and every such file would read as modified on every pull - churn that is not a change. Written BEFORE the staging below, and it governs the `rspade_project` copy and every downstream repo automatically.

**Step 12b — stage first, then `git add --renormalize .`.** Changing attributes does not make git re-examine stat-clean files; the renormalize is what re-runs the (now identity) clean filter so previously-normalized blobs finally stage byte-identical. `git add -A` must come FIRST (it stages deletions; renormalize fatals on a tracked-but-deleted path). One-time re-commit, a no-op thereafter. Downstream boxes that pulled before this fix need one `--force` pull to join the corrected cohort.

**There is no Step 12c.** The release inventory (`system/.rspade-release.json`, `{release_id, date, files:{path:sha256}}`) is RETIRED: its only consumer was `rsx:framework:verify`, which went away when downstream `system/` became a git submodule. The tracked copy leaves `rspade_system` by construction on the first publish without it - the Step 3 `rsync --delete` removes it from the worktree and the `git add -A` above stages that deletion. Do not reintroduce it.

**Step 12d — the tracked-but-ignored class assertion.** The publish REFUSES if any tracked path is excluded by the shipped tree's own `.gitignore` (`git check-ignore --no-index`). Such a file ships fine in `rspade_system` but a downstream `git add system` DROPS it, so fresh clones silently lack it. The remedy is always to strip the offending ignore rule in publish (Step 7b does exactly that for the generated updater `.sh`), never to accept the hit.

---

## Changing what a release ships

- **A new framework file must reach downstream** - nothing to do: everything tracked under `system/` ships. But if the monorepo `.gitignore` excludes it, the Step 12d assertion or a downstream `git add system` will drop it - check both.
- **A new file or directory anywhere under `system/`** - nothing to declare. ALL of `system/` is framework property and is overwritten on every update; downstream it is a git submodule, so whatever is committed here is what arrives there.
- **New durable "make the environment correct" behavior** - a numbered script in `system/bin/environment_updates/`, never the updater. The updater is a running bash script downstream; edits to its `.dist` also lag one pull.
- **A dev-only file must NOT ship** - add it to the Step 7 removal list. A framework-developer man page goes in the man-strip list, and nothing shipped may then point at it.
- **A file needs a downstream-specific form** - ship the `.dist` (Step 8 renames `bin/*.dist` to its real name) rather than branching at runtime.

## Do not re-order the pull's commit

The downstream pull commits its own `system/` changes BEFORE the rebuild, so the commit records the PRISTINE release as synced: pristine by construction (no authorization gate needed), byte-identical across sister environments, and a failed rebuild therefore loses nothing. **The old commit-after-rebuild order deadlocked a release whose own validator rejected the unmigrated app** - the release stayed uncommitted, and the next app commit's pre-commit hook reset it away again. If you touch that sequence in `framework-pull-upstream.sh.dist`, keep commit-before-rebuild.

## When a publish fails

| Symptom | Cause / fix |
|---|---|
| "Code quality check failed" | Fix the `rsx:check` violations. Never bypass. |
| "Git working directory is not clean" | Commit (or genuinely revert) first - the release ships committed state only. |
| "Tracked-but-ignored files present" | Read the printed list; strip the ignore rule that excludes each one. |
| Push rejected | Someone else published, or the remote moved. Inspect `/var/www/publish/<repo>_git` before forcing anything. |

Publish is not idempotent-by-accident: it writes `.rspade_last_commit_for_publish` only after all three pushes succeed, so a failed run leaves the next changelog range intact.

---

## Knowledge tree

Publish ships the always-on knowledge fragments and the `skills/` tree from `system/app/RSpade/docs/` (Step 14), and wires the downstream template's `CLAUDE.md` to import the app view. `docs.dist/` is **gone** - it was retired in the knowledge restructure, along with the steps that copied it, injected a read-only header into `CLAUDE.dist.md`, and rewrote its internal paths. There is no second downstream copy of any fragment; do not reintroduce one.

`system/QUICKSTART.md` ships with the framework tree as part of the ordinary `system/` copy - no dedicated step.

---

Details: `system/bin/publish` (the step comments are the contract), `php artisan rsx:man rsx_upstream`, `rsx:man framework_pull_mechanics`. Related: `rspade:shipping-a-contract-change`.
