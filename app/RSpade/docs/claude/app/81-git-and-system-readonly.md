<!-- bucket: app — single-source, never duplicate. True ONLY in a downstream application. THIS FRAGMENT IS THE CANONICAL HOME of the rsx:git rule and the system/-conflict default; the general merge-conflict mandate lives in shared/80. -->

## GIT WORKFLOW — THE FRAMEWORK IS READ-ONLY

**NEVER modify `system/`** - It's like node_modules or the Linux kernel.

- **App repo**: your project root `.git` (you control)
- **Framework**: `system/` (a git submodule - ALL of it is overwritten on every update; don't touch)
- **Your code**: `rsx/` (all changes here)

**USE `php artisan rsx:git <any git command>` INSTEAD OF BARE `git`.** It is a transparent proxy — same subcommands, flags, exit codes, stdin and editor/pager behavior — that owns what plain git cannot see on an RSpade box: the build regenerates class-override churn as uncommitted `system/` modifications within a second of any clean, so **bare git loses a race it cannot see** and refuses a pull with "your local changes would be overwritten by merge". A Claude Code PreToolUse hook blocks a bare `git` and names the `rsx:git` equivalent.

What the proxy does: it runs your git command unchanged, then — if the operation MOVED the recorded revision of the `system/` submodule and the checkout did not follow — it raises a maintenance window, runs `rsx:clean` (which resets the submodule hard), checks the recorded revision out, rebuilds, and lowers the window. That is the whole of it; `push`/`fetch`/`log`/`show`/`commit` and everything else is plain git, and it fails open. A CONFLICTED gitlink is never settled for you: both sides chose a framework revision, so the proxy prints the `--ours`/`--theirs` + `rsx:framework:pull` recipe and stops. A successful `pull`/`merge` that moved HEAD also re-applies the environment updates quietly (`rsx/resource/` is manifest-ignored, so a teammate's new application skill triggers no rebuild). **NEVER resolve a pull refusal with `git stash`** or by committing `system/` into an app commit.

**The `system/` reset is UNCONDITIONAL**: `rsx:clean` runs `git reset --hard` + `git clean -fdx` INSIDE the submodule, discarding any local drift — there is no integrity gate and nothing to override (`--force` is accepted and does nothing). It is skipped only in a framework-developer tree, when `system/` is not a submodule, or during a framework update in flight. So **never edit `system/`**: the next clean, build or `rsx:git` reconcile erases it without asking.

### `system/` is one gitlink, never merged file by file

**`system/` is a submodule, so it contributes ONE gitlink to your index and a conflict there is a one-line disagreement about which framework revision to run — never a file-by-file merge.** That is a CHOICE, not a merge, and `rsx:git` deliberately does not make it for you: it prints the recipe (`git checkout --ours|--theirs -- system`, `git add system`) and stops, and you then run `php artisan rsx:framework:pull` to bring the checkout into line. Pick the NEWER revision unless you have a reason not to. Afterward confirm the installed release with `rsx:framework:status`. (`bootstrap/rsx_submodule_sync.php` refuses to boot while the recorded revision and the checkout disagree, whatever caused the drift, so a wrong choice is loud rather than silent.)

**The framework pointer bump IS visible** in `status`, `diff` and `show --stat` — `.gitmodules` carries `ignore = dirty`, which hides the build's permanent churn inside `system/` and nothing else. **Never add a repo-wide `[diff] ignoreSubmodules` to `.git/config`**: `all` hides the recorded pointer as well, so a framework update lands with no trace anywhere you would review it (and the setting applies to every other gitlink you keep). The environment update removes it and `rsx:health`'s "Submodule Visibility" row reports both halves; `php artisan rsx:heal submodule-ignore-dirty` fixes them.

**If a box has already been moved BACKWARDS off its release** (system/ regressed, rebuilds failing, thousands of untracked paths under `system/`): do NOT run `rsx:git pull` — its `clean -fdq -- system` deletes the untracked new-release files. Recover with `php artisan rsx:framework:pull --resync`, and read `breaking_changes/backwards_merge_recovery_08_18.txt` first: a regressed box is running the OLD updater and needs a one-line bootstrap before `--resync` exists.

Skill: `rspade:git-operations`. Details: `php artisan rsx:man rsx_git`.
