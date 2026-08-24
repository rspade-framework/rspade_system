# Environment Updates

Self-detecting, idempotent scripts that configure/repair the target environment AFTER a
framework update. `system/bin/post-update.sh` (invoked by `framework-pull-upstream.sh` at the
end of a successful pull) runs every `*.sh` in this directory, in sorted order, on every pull.
It accepts `--quiet`, which exports `RSPADE_ENV_UPDATE_QUIET=true` for the scripts it runs
(see contract rule 2).

Because `post-update.sh` runs these as a subprocess reading the FRESHLY-SYNCED copies, a new
or changed environment update takes effect on the SAME pull that ships it — no two-pull lag.
This is the home for durable "make the environment correct" behavior (git hooks, the Claude
Code status line, one-time migrations), so that `framework-pull-upstream.sh` itself stays
minimal (editing a running bash script is unsafe, and its edits lag one pull).

## Triggers

`system/` is vendored, so a plain `git pull` DELIVERS a new script here but executes nothing.
Five triggers converge on `post-update.sh` — it is the single entry point, and every script
must tolerate being run by any of them:

1. **`rsx:framework:pull`** — the updater's `run_post_update()` at the end of a successful pull.
2. **A successful `rsx:manifest:build`** (`Manifest_Build_Command`) — DEVELOPMENT mode only
   (sealed debug/production builds must never self-modify), skipped while maintenance mode is
   up (the pull raises that window and runs post-update itself, so a pull applies the updates
   exactly once), serialized on the `ENVIRONMENT_UPDATES` named write lock, and non-fatal. This
   is the BOOTSTRAP-SAFE trigger: a build runs in every environment regardless of hooks, clone
   age, or how the code arrived. It is also the only trigger the framework monorepo has.
3. **The `post-commit` hook** installed by `050_post_commit_env_update.sh` — a latency
   shortener, detached and non-blocking. Git never distributes `.git/hooks`, so this one only
   exists on an environment where trigger 1 or 2 has already run once.
4. **Container start** — the docker entrypoint's step 4b, before supervisor. This is the
   FIRST-BOOT trigger, and it exists because the other three all presuppose an environment
   that has already been used: a freshly cloned project has had no pull, no build and no
   commit, so it ran with none of these applied (most visibly with storage never relocated,
   which leaves the blob store and logs inside the framework-owned `system/` tree). It runs
   before supervisor because `030` MOVES the tree the helper daemons hold sockets in, and
   takes no `ENVIRONMENT_UPDATES` lock because nothing else in the container is alive yet.
5. **`rsx:git pull` / `merge`** — after an operation that succeeded and moved HEAD, the git
   proxy runs `post-update.sh --quiet` (downstream only; the monorepo path is inert). It
   exists because `rsx/resource/` is manifest-ignored: a pull whose only change is a
   teammate's new application skill triggers no rebuild, so trigger 2 never fires. Non-fatal
   and quiet — the developer asked for a pull, not for an environment report.

## The contract every script here MUST follow

0. **Container-gated. FIRST LINE OF EXECUTABLE CODE, no exceptions.** Every script here
   begins with:

   ```bash
   [ -f /.rspade_container ] || exit 0
   ```

   These scripts modify the environment AROUND the project — git hooks, editor and agent
   settings, the on-disk storage layout — and that environment is the RSpade container's,
   not the host's. Run on somebody's own machine they install container assumptions into
   it silently, with nothing to tell them it happened. The marker is written by the
   framework's Dockerfile (`Rsx::is_rspade_container()` is the PHP reader); its absence is
   a normal state, not a failure, so this exits 0 and prints nothing, exactly as the
   silent-when-not-applicable rule below requires. Place it immediately after `set -u...`
   and BEFORE any path resolution or filesystem work.

1. **Self-detecting.** First, determine whether the change is already applied. If so, do
   NOTHING and print NOTHING — exit 0 silently. The pull output stays quiet on a healthy env.
2. **Speak only when acting.** Print to stdout ONLY when you detect an unapplied change and
   apply it (one concise line or short block: what you applied). Errors go to stderr.
   **INFORMATIONAL lines gate on `RSPADE_ENV_UPDATE_QUIET`; problems never do.** When that
   variable is `true` (exported by `post-update.sh --quiet`, which the `rsx:git` post-pull
   hook passes), suppress the "created / repaired / retargeted / pruned" lines and keep every
   stderr report. A quiet run is a quiet SUCCESS, never a silent failure. The house spelling
   is one helper at the top: `info() { [ "$QUIET" = true ] || echo "[env] $*"; }`.
3. **Idempotent.** Running twice is a no-op the second time. These run on EVERY pull.
4. **Non-fatal.** A failure is reported by `post-update.sh` but never fails the pull (the
   framework update already succeeded). Do not `exit` non-zero for a benign "nothing to do".
5. **Context-aware.** `post-update.sh` exports `PROJECT_ROOT`, `SYSTEM_DIR`, and
   `IS_FRAMEWORK_DEVELOPER` (`true` only in the framework monorepo). Gate any step that would
   be wrong in the monorepo (e.g. anything touching `system/` as if it were app-owned) behind
   `[ "$IS_FRAMEWORK_DEVELOPER" != true ]`.
6. **Ordering.** Prefix filenames with a number (`010_`, `020_`, ...) to make the run order
   explicit and deterministic. Lower numbers run first.
7. **Never clobber developer state.** When writing a file the developer may own or have
   customized (`.claude/settings.json`, `.git/hooks/pre-commit`), AUGMENT — never replace —
   and refuse to overwrite a non-framework customization (detect via a marker), reporting the
   skip instead.
8. **Bash, explicitly - never `sh`, never an implicit shell, never a bare path.** A script this
   directory WRITES (a `.git/hooks/*` entry file) gets a `#!/bin/bash` shebang, and any inline
   shell string goes through `bash -c`: `/bin/sh` is dash on our platforms, and dash allows only
   single-digit fds in a redirection, so the lock-fd-close prefix (`exec 11>&-`) parses there as
   a command named 11 and kills the spawn (field failure, 2026-08-13). And never rely on a repo
   script's exec bit: any command registered into a config that will
   later exec one of our scripts (Claude Code `statusLine` / `hooks`, and anything similar
   added later) MUST be spelled `bash "<path>"`, never the bare path — downstream,
   `core.fileMode false` plus the pull's rsync make exec bits unreliable, and a bare path dies
   with "Permission denied". Corollary: never gate an invocation on `[ -x ... ]`; gate on
   `[ -f ... ]` and invoke through `bash`. The one genuine exception is a `.git/hooks/*` file,
   which git execs directly — those installers own the `chmod +x` AND must heal a lost bit on
   an already-installed hook. **And whenever a registered command's spelling changes, the
   installer must recognize and rewrite its OWN prior spelling** (exact-string match, one
   report line): the "is this ours?" test is an exact compare, so without the heal the
   installer reads its own older command as a developer customization and skips forever.

## How "applied" is detected

Each script owns its own detection signal — a marker string inside a file it installed, a
config key it set, the presence of a relocation marker, etc. Prefer an explicit, testable
signal over inferring from side effects.

## Current scripts

- `010_claude_statusline.sh` — installs the RSpade Claude Code status line into
  `.claude/settings.json` (augment-only) as `bash "$CLAUDE_PROJECT_DIR/system/bin/statusline.sh"`;
  heals an environment still carrying the earlier bare-path spelling. ALSO seeds RSpade
  preference DEFAULTS into the same file — `attribution.commit`/`attribution.pr` (empty, which
  is how the documented `attribution` object suppresses the Claude commit trailer and PR
  footer; supersedes the deprecated `includeCoAuthoredBy`), `effortLevel: medium`,
  `spinnerTipsEnabled: false`, `cleanupPeriodDays: 3650` (the 30-day default deletes session
  transcripts at startup), and `env.CLAUDE_CODE_ENABLE_TELEMETRY: "0"`. Each key is written
  ONLY when absent (`array_key_exists`, so an explicit `false`/`null` counts as a developer
  decision), tested per sub-key, and never overwritten — `/effort high` writes `effortLevel`
  and that value then survives every future pull. Delete a key to have its default restored.
- `020_remove_precommit_hook.sh` — REMOVES the pre-commit hook this slot used to install
  (the one that ran `rsx:clean` and unstaged `system/` to keep the vendored framework tree
  out of app commits). That guard was a consequence of `system/` being vendored — one index
  holding both the framework's files and the application's, with nothing but a hook to tell
  them apart. The slot now does the inverse job rather than being deleted, because git does
  not distribute `.git/hooks`: every environment that ever received the hook still has it,
  and nothing else would ever take it away. Only a hook carrying the `RSPADE-PRECOMMIT-V1`
  marker is removed; a developer's own pre-commit hook is left alone, silently. Retire this
  slot once the fleet has turned over.

- `040_claude_git_guard.sh` — registers `system/bin/claude-git-guard.sh` as a Claude Code
  PreToolUse hook (augment-only, downstream only) as
  `bash "$CLAUDE_PROJECT_DIR/system/bin/claude-git-guard.sh"`: blocks a bare `git` from the
  Bash tool and redirects to `php artisan rsx:git`. It also REMOVES both spellings of the
  retired pre-pull guard it used to install — that guard told the agent to run `rsx:clean`
  then pull, a sequence that deadlocks on a live box (the class-override churn returns before
  the pull's index write lands), which is the problem `rsx:git` actually fixes.
- `050_post_commit_env_update.sh` — installs the `post-commit` hook (RSPADE-POSTCOMMIT-V1,
  augment-only, downstream only) that spawns `post-update.sh` detached after a commit, so the
  environment updates land without waiting for the next build or pull. Never blocks or fails a
  commit; a foreign `post-commit` hook is reported and left alone; a lost exec bit on our own
  hook is restored.
- `060_claude_docs.sh` — wires the framework knowledge tree (`system/app/RSpade/docs/`) into
  the environment. BOTH contexts: ensures `.claude/skills/rspade -> ../../system/app/RSpade/docs`
  (that directory carries `.claude-plugin/plugin.json`, so Claude Code loads it as the namespaced
  `rspade` plugin) — reconciling the ENTRY only (absent -> create; dead or wrong-but-inside-the-
  rspade-tree -> retarget; a real file/dir or a foreign target -> report + skip), and NEVER
  removing/recreating the `.claude/skills` parent (recreating a watched top-level dir mid-session
  breaks Claude Code's file watcher). DOWNSTREAM ONLY: prepends the always-on memory import
  `@../../system/app/RSpade/docs/claude/app.md` to the app developer's own
  `rsx/resource/CLAUDE.md` (created with a stub if absent; the path is relative to the REAL file
  because a relative `@import` resolves against the real file, not the symlink it was reached
  through), and removes the retired container-era `/root/CLAUDE.md` / `/root/.claude/CLAUDE.md`
  symlink when — and only when — it points into the rspade tree. Prints the one-time
  workspace-trust note only on the run that first creates the symlink.
  Test seam: `RSPADE_CLAUDE_HOME_DIR` overrides the `/root` home for simulations.
- `070_app_skills.sh` — wires the APPLICATION's own Claude Code skills into the
  environment. BOTH contexts (the template app carries skills exactly as a downstream app
  does). For every `rsx/resource/skills/<name>/SKILL.md` it ensures
  `.claude/skills/<name> -> ../../rsx/resource/skills/<name>` (RELATIVE, never absolute — the
  link is committable and must be correct in every clone); a directory without a `SKILL.md`
  is not a skill and is ignored. `rspade` is RESERVED (060 owns that entry, the framework
  plugin) and is refused with a rename instruction. It also PRUNES a symlink directly under
  `.claude/skills/` whose LITERAL target starts `../../rsx/resource/skills/` or
  `../../system/app/RSpade/` and which no longer resolves — nothing else is ever removed, and
  a foreign entry blocking a skill's name is reported and left in place. The parent directory
  is never removed/recreated (Claude Code's file watcher). It exists as an environment update
  rather than a committed link because `rsx/resource/` is manifest-ignored: a pull carrying
  only a teammate's new skill triggers no rebuild, which is why `rsx:git` runs
  `post-update.sh --quiet` after a pull/merge that moved HEAD. `rsx:health` reports the same
  state ("Claude Skills"); `rsx:heal claude-skills` re-runs this script.
- `061_system_rsx_symlink.sh` — ensures the `system/rsx -> ../rsx` app-tree symlink exists
  (every manifest build depends on it via `base_path('rsx')`). Heals boxes broken by the
  2026-08-18 foreign-path-untracking incident: the symlink is tracked but structurally
  uninventoriable (the publish inventory drops non-regular-files), so the first cut untracked
  it and the proxy clean deleted it. Recreates when missing, retargets a wrong symlink; a real
  file/dir squatting on the path is reported and left alone.
- `080_submodule_ignore_dirty.sh` — makes the framework's submodule POINTER visible in
  ordinary git output (downstream only; the monorepo has no `system` submodule). Two
  fixes, both self-detecting: sets `submodule.system.ignore = dirty` in the TRACKED
  `.gitmodules` when it is anything else, and UNSETS a repo-wide
  `diff.ignoreSubmodules` from `.git/config` whatever its value. `dirty` is the only
  value that shows a moved pointer (a framework update) while hiding the build's
  permanent `.php` <-> `.php.upstream` churn inside `system/`; `none` reports ` M system`
  forever, and the blanket `all` operators reach for in response hides the pointer too —
  the framework version then lands with no trace in `status`, `diff` or the commit
  summary (field report, 2026-08-22). `bin/publish` already writes `ignore = dirty` into
  the starter and `framework-pull-upstream.sh` sets it during the vendored -> submodule
  conversion, so this slot serves boxes converted BEFORE that landed (the generated
  updater lags one pull) plus anyone who added the blanket setting by hand. A
  `.gitmodules` with no `system` entry is not a submodule project and is skipped
  silently. The `.gitmodules` edit is a TRACKED-file change, so the informational line
  asks the developer to commit it. `rsx:health` reports the same state ("Submodule
  Visibility"); `rsx:heal submodule-ignore-dirty` re-runs this script.
- `090_readme_clone_url.sh` — personalizes the starter README's quick-start clone line
  (downstream only). The shipped `README.md` tells the reader to
  `git clone --depth 1 --recurse-submodules https://github.com/rspade-framework/rspade my-app`,
  which is right on the framework's own GitHub page and wrong in a project made from it
  ("Use this template" gives a new repo with one squashed commit and the developer's own
  origin). It rewrites that one line to `git clone --recurse-submodules <origin> my-app`
  (`--depth 1` dropped — a teammate joining a project wants the history) and adjusts the
  one sentence in the requirements `<details>` block that recommended `--depth 1`. THE ONLY
  AUTHORIZATION IS BYTE IDENTITY: the project's `README.md` must `cmp -s` equal to the
  pristine copy shipped in the release at
  `system/app/RSpade/resource/starter/README.md` (placed by `bin/publish`; a monorepo
  symlink back to `bin/publish_assets/README.rspade_starter.md`). Anything else — an edited
  README, an already-personalized one, an older release with no pristine copy — is a silent
  no-op forever; there are no git-history heuristics. No origin is a no-op that keeps the
  pristine window open until a remote exists; an origin that IS the starter
  (`rspade-framework/rspade`, or the internal `rspade_project`) is a no-op, since a checkout
  of the starter is not a project yet. The write is atomic (temp + `mv`) and verified.
  `README.md` is TRACKED, so the informational line asks for the commit.
