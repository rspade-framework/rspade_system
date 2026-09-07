# Concern: environment_updates

Covers `system/bin/post-update.sh` and the numbered scripts under
`system/bin/environment_updates/` - the self-detecting, idempotent scripts that
configure and repair the environment AROUND a project after code arrives.

## Domain overview

`system/` is vendored downstream, so delivering a script is not running one. Five
triggers converge on `post-update.sh`, the single entry point: the framework pull, a
successful development `rsx:manifest:build`, the `post-commit` hook, container start,
and (since 2026-08-23) `rsx:git pull`/`merge` after an operation that moved HEAD. Every
script must therefore tolerate being run by any of them, at any moment, repeatedly.

The contract each script obeys - container-gated first line, self-detecting, silent when
already applied, idempotent, non-fatal, augment-never-clobber - is stated in full in
`system/bin/environment_updates/CLAUDE.md`. These tests exercise it as BEHAVIOR rather
than as prose: a healthy re-run must print nothing, a developer's own file must survive,
and a link the framework did not make must never be touched.

Two properties are easy to get wrong and are therefore covered directly:

1. **Authorship is decided on the LITERAL symlink target**, never a resolved path. A
   resolved path says nothing about who wrote the link, and pruning on one would delete a
   developer's own link that happens to point into the tree.
2. **Quiet suppresses INFORMATIONAL lines only.** `post-update.sh --quiet` exports
   `RSPADE_ENV_UPDATE_QUIET=true` for the scripts it runs (the `rsx:git` post-pull hook
   passes it, the framework pull deliberately does not). A quiet run is a quiet SUCCESS -
   stderr problems and the failure count are never suppressed.

## Source files under test

- `system/bin/post-update.sh` - the entry point and its `--quiet` flag.
- `system/bin/environment_updates/060_claude_docs.sh` - the framework knowledge tree:
  the `.claude/skills/rspade` PLUGIN entry, plus the downstream memory import.
- `system/bin/environment_updates/070_app_skills.sh` - the application's own skills:
  `.claude/skills/<name> -> ../../rsx/resource/skills/<name>`, plus the prune rule.
- `system/bin/environment_updates/080_submodule_ignore_dirty.sh` - submodule POINTER
  visibility: `submodule.system.ignore = dirty` in the tracked `.gitmodules`, and the
  removal of a repo-wide `diff.ignoreSubmodules` from `.git/config`. Downstream only.
- `system/bin/environment_updates/090_readme_clone_url.sh` - the starter README's
  quick-start clone line, rewritten to the project's own `origin`. Downstream only, and
  authorized ONLY by byte identity with the pristine copy shipped at
  `system/app/RSpade/resource/starter/README.md`.

`040_claude_git_guard.sh` is covered by the `git_proxy` concern (it installs the guard
that redirects a bare `git` to the proxy), and the manifest-build trigger by the
`maintenance` concern's `Environment_Updates_Trigger_Test`.

## Documentation that defines behavior

- `system/bin/environment_updates/CLAUDE.md` (the contract)
- `system/bin/CLAUDE.md` (the post-update mechanism and its triggers)
- `rsx:man template_app` - "APPLICATION SKILLS" (the 070 contract, operator-facing)
- shared fragment `docs/claude/shared/85-project-skills.md`
- `rsx:man template_app` - "THE STARTER README'S CLONE LINE" (the 090 contract)

## Testable surface

| Area | Type | Covered |
|------|------|---------|
| Register / ignore / idempotent-silent / quiet (070) | cli | yes (t1) |
| Prune rule and its limits (070) | cli | yes (t2) |
| Augment-never-clobber + the reserved `rspade` name (070) | cli | yes (t3) |
| The framework plugin entry: create, repair, leave-foreign-alone (060) | cli | yes (t4) |
| `post-update.sh --quiet`: informational gone, problems kept | cli | yes (t5) |
| Submodule pointer visibility: .gitmodules ignore + the blanket unset (080) | cli | yes (t6) |
| README clone-line personalization: pristine gate, origin/placeholder, starter no-op (090) | cli | yes (t7) |
| The downstream memory import 060 prepends to `rsx/resource/CLAUDE.md` | cli | no (planned) |
| The other installers (010 statusline, 050 post-commit, 061 rsx symlink) | cli | no (planned) |

## How the fixtures work

`cli/_lib_fixture` builds a synthetic project under a mktemp root and runs the REAL
script against it with `PROJECT_ROOT` / `SYSTEM_DIR` overridden - nothing is copied, so
the script on disk is always the subject. stdout and stderr are captured SEPARATELY,
because the quiet contract is about which stream a line lands on. The container gate is
satisfied by `/.rspade_container`; a test that finds it absent SKIPs rather than
asserting a no-op.
