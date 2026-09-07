# Concern: git_proxy

Covers `php artisan rsx:git` - the transparent git proxy that owns `system/` safety and
service quiescing on a downstream app - plus the two pieces wired to it: the
`rsx:maintenance:disable` conflict guard, and the Claude Code git guard that redirects a
bare `git` to the proxy.

## Domain overview

On a live downstream box, three processes mutate the working tree during any git
operation, and plain git is defended against none of them:

1. the container's `fixperms` loop `chmod`/`chown`s every newly created file, including
   files inside `.git/` (objects, index, lockfiles), racing git's own writes;
2. the JIT manifest rebuild re-applies the class-override pass (`.php` <->
   `.php.upstream` renames + autoloader rewrite) on any web request, re-dirtying
   `system/` within ~1s of any clean;
3. `.git/index.lock` contention from concurrent status/build processes. This is ONE of
   the two causes of the field `fatal: stash failed`: a non-fast-forward merge carrying
   a tracked modified file makes git run an internal `git stash create`, which fails
   silently when it loses the lock race and kills the merge having done no work.
   Measured 29/40 under a concurrent index-refreshing reader, 0/40 with
   `--no-optional-locks`. The proxy flags its own reads (it was a contender) and retries
   that one classified failure (`cli/t13`, `cli/t14`).

   The OTHER cause wears the same wording and is deterministic: a stat-stale index (or,
   on some git versions, dirt only the merge machinery counts) makes save_state and its
   child stash disagree with no lock involved at all. Retrying it is pure delay, and the
   first classifier could not tell the two apart. So the proxy now (a) refreshes the
   index once, deliberately, on the tree-rewriting path - nothing else refreshed it once
   every read carried `--no-optional-locks` - (b) classifies `stash failed` on EVIDENCE
   (does `.git/index.lock` exist right now?), and (c) when it is deterministic, escapes
   for `pull`/`merge` by merging in a linked scratch worktree and fast-forwarding the
   result home, which never calls save_state at all (`cli/t15`, `cli/t16`).

The consequence is that `system/` cannot be held clean for the instant a `pull`/`merge`
needs it, so a pull carrying framework-update commits races and aborts. `rsx:git` owns
the invariant instead: **the app never sees or commits `system/` churn, and
tree-rewriting ops run quiesced.**

The pull/merge path resets `system/` to pristine for the instant of the merge, and
"pristine" includes UNTRACKED churn: `reset` and `checkout -- <path>` act on tracked
paths only, so a class-override sidecar survived the reset and collided with a peer
box's incoming tracked blob, aborting the pull deterministically on every re-run
(2026-08-18). The reset therefore ends with `clean -fdq -- system` - never `-x`, since
gitignored state (the `.env` symlink, ignored build trees) is not churn.

A pull is otherwise SILENT about `system/` file changes (owner ruling, 2026-08-18). The
framework SYNCS `system/`; files appearing, changing and disappearing is what that looks
like, so the read-side monitoring that used to classify every deleted path and name every
untrailered incoming commit was removed - it spoke on the routine case (one peer's
correct update arrived as 617 deletions under a remedy that was a no-op), and protection
belongs on the WRITE side regardless - and under the submodule model git itself is that
write side: `system/` contributes one gitlink to the app's index, so framework files
cannot enter an app commit at all.

The backwards-release invariant that used to sit on this path is retired with the
per-release marker it read (`.rspade-release.json`, removed from the distribution
entirely). Under the submodule model the recorded gitlink IS the installed release, and
`bootstrap/rsx_submodule_sync.php` refuses to boot while the recorded revision and the
checkout disagree - a structural guard rather than a monitored one.

Everything the proxy does automatically is announced on stderr with the flag that
overrides it, so its output teaches the tool.

One rider sits at the bottom of the script and is not about `system/` at all: a
pull/merge that SUCCEEDED and moved HEAD runs `post-update.sh --quiet`. `rsx/resource/`
is manifest-ignored, so a pull whose only change is a teammate's new application skill
triggers no rebuild - and the rebuild is what normally applies the environment updates.
It is non-fatal and never touches the operation's exit code (`cli/t23`); the environment
updates themselves are the `environment_updates` concern.

The proxied command itself runs with recursion scoped off
(`git -c fetch.recurseSubmodules=no -c submodule.recurse=false ...`, 2026-09-06). Git's
default is `on-demand`, which fetches the submodule once per INTERMEDIATE gitlink across
the pulled range - strictly more data than the reconciliation needs (one object, the
revision recorded at the new HEAD), and after a framework history rewrite those
intermediate gitlinks name revisions the remote no longer has, so `upload-pack: not our
ref` aborted the entire pull and `sync_submodule` never ran. A container several commits
behind could not pull at all, on any retry. An explicit `--recurse-submodules` still
overrides `-c`, and `clone` is not wrapped (`cli/t24`).

## Source files under test

- `bin/rsx-git.sh` - the proxy
- `bin/maintenance-mode.sh` - `do_disable()`'s conflict guard + `--force`
- `bin/claude-git-guard.sh` - the PreToolUse redirect
- `bin/environment_updates/040_claude_git_guard.sh` - its installer (and the removal of
  the retired `claude-pull-guard.sh` registration)
- `artisan` - the pre-boot `rsx:git` interception

## Fixture

`cli/_lib_fixture` builds a synthetic DOWNSTREAM app under `mktemp`: a git repo with a
`system/` tree, an app file, an upstream bare repo, and an **artisan shim** answering the
calls the proxy makes. The shim delegates `rsx:maintenance:enable|disable` to the
fixture's copy of the REAL `maintenance-mode.sh` (with `--no-services`, since a test box
has no supervisord), so the conflict guard under test is the real implementation rather
than a stub. Seam files (`rebuild_fail`, `maint_fail`) drive the refusal
and fail-open paths.

`rsx-git.sh` derives its project root from its own location, so the fixture COPIES it
rather than symlinking - a symlink would resolve back to the real repository.

No fixture project ships a release marker. The proxy reads none: release reconciliation
was retired with the vendored model, and `.rspade-release.json` no longer ships at all.
The proxy reconciles on the RECORDED gitlink versus the CHECKED-OUT revision, which is
what `fx_upstream_commit` and the submodule fixtures exercise.

Two helpers exist for the stash-failure work: `fx_stale_index` (makes every tracked file
stat-stale without changing content, a fresh date per call) and `fx_git_shim` (puts a
`git` shim early in the PATH `fx_run` uses, which fails the CALLER'S OWN bare
`pull`/`merge` with `fatal: stash failed` and passes everything else to the real git).
The shim's discrimination is structural rather than heuristic: every invocation the proxy
makes internally leads with a git GLOBAL option (`-C <dir>`, `--no-optional-locks`), so
the only one whose first argument is a bare subcommand is the user's own operation - and
the scratch worktree's own `git -C <scratch> merge` therefore runs for real.

## Running

    bash system/app/RSpade/tests/git_proxy/cli/t1_staging_exclusion.sh

or the whole suite via `system/app/RSpade/tests/run_all_tests.sh` (which globs
`*/cli/*.sh`; `_lib_fixture` deliberately has no `.sh` extension so it is never run as a
test).

## Man page(s)

`man/rsx_git.txt` (the proxy), `man/maintenance_mode.txt` (the disable guard).
