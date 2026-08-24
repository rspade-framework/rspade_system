<!-- bucket: framework — single-source, never duplicate. True ONLY in the RSpade
monorepo. CANONICAL HOME of the publish and distribution AUTHORING rules; the
downstream operator contract lives in the app framework-updates fragment. -->

## DISTRIBUTION & PUBLISHING

**`bin/publish` packages for production — NOT for testing.** It publishes the `system/` tree to `rspade_system` (one commit = one release) and wires it into `rspade_project` as a git SUBMODULE. **Byte fidelity is a release requirement** — the shipped `.gitattributes` sets `* -text`, so a downstream checkout materializes exactly the bytes the monorepo committed; if git normalized line endings, every CRLF-bearing vendored file (node_modules ships them) would read as modified on every pull, churn that is not a change.

**We AUTHOR the consumer.** Downstream, `system/` is a git submodule moved only by `rsx:framework:pull` — every rule that tree obeys is a rule written here.

**ALL of `system/` is framework property and is overwritten on every update.** Downstream it is a GIT SUBMODULE tracking `rspade_system`, and the pull is `reset --hard` + `clean -fdx` + checkout of the upstream tip. **Owned zones no longer exist** — no protected sub-paths, no three-way merge, no per-file release inventory, no tamper gate. Everything a downstream developer wants to change is changed in `rsx/`, via a class override.

**The updater self-delivers; edit the `.dist`.** The downstream pull logic lives in `system/bin/framework-pull-upstream.sh`, generated from the AUTHORITATIVE `system/bin/framework-pull-upstream.sh.dist`. It ships in the submodule like everything else, so improvements land automatically — **on the pull AFTER the one that delivers them (one-pull lag)**. The running updater **copies itself to `/tmp` and re-execs** before touching the tree, because `git checkout` rewrites the very file bash is reading.

**New durable "make the environment correct" behavior goes in `system/bin/environment_updates/`, NOT in the updater** — editing a running bash script is unsafe and `.dist` edits lag one pull. Each script is self-detecting, idempotent, silent when applied and non-fatal; `post-update.sh` is the single entry point, and in THIS repo its only trigger is a successful `rsx:manifest:build` in development mode. Contract: `system/bin/environment_updates/CLAUDE.md`.

**The pull commits the submodule pointer BEFORE the rebuild**, so a rebuild that fails costs only the build — fix what it reports and rebuild, never re-pull. **Framework-developer trees never run any of this** — here `system/` is authored source, not a checkout.

**The starter app's own CLAUDE.md is `rsx/resource/CLAUDE.dist.md`** — publish renames it to `rsx/resource/CLAUDE.md`, symlinks the project-root `CLAUDE.md` to it and prepends the `@../../system/app/RSpade/docs/claude/app.md` import; it is NOT loaded in this monorepo. It is a TEMPLATE EXAMPLE of how an end developer uses their own local CLAUDE.md, not an index of the application, so **edits to it are minimal**: it may record how a change to the shipped template application works, but the template app is kept deliberately simple and obvious in function precisely so that specific usage documentation is unnecessary — its behavior is inferred from RSpade conventions and from the comments in the template code itself. **`rsx/resource/skills/` ships too** (reference app and starter alike), so a skill seeded there reaches every install.

Skill `rspade:publishing-a-release` (pre-flight gates, changelog assembly, the three repositories, strip/transform rules, failure triage). Details: `rsx:man rsx_upstream`, `rsx:man framework_pull_mechanics`.
