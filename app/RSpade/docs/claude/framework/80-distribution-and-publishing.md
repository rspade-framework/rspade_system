<!-- bucket: framework — single-source, never duplicate. True ONLY in the RSpade
monorepo. CANONICAL HOME of the publish and distribution AUTHORING rules; the
downstream operator contract lives in the app framework-updates fragment. -->

## DISTRIBUTION & PUBLISHING

**`bin/publish` packages for production — NOT for testing.** It writes each release's `system/.rspade-release.json` inventory (`{release_id, date, files:{path:sha256}}`) and vendors the published `system/` tree into `rspade_project` as ordinary files. **Byte fidelity is a release requirement**: the inventory hashes exact bytes and `rsx:framework:verify` compares a downstream checkout against them, so any line-ending normalization would false-flag files as tampered.

**We AUTHOR the consumer.** Downstream, `system/` is a vendored tracked tree written only by `rsx:framework:pull` — every rule that tree obeys is a rule written here.

**ALL of `system/` is framework property and is overwritten on every update.** Downstream it is a GIT SUBMODULE tracking `rspade_system`, and the pull is `reset --hard` + `clean -fdx` + checkout of the upstream tip. **Owned zones no longer exist** — no protected sub-paths, no three-way merge, no per-file release inventory, no tamper gate. Everything a downstream developer wants to change is changed in `rsx/`, via a class override.

**The updater self-delivers; edit the `.dist`.** The downstream pull logic lives in `system/bin/framework-pull-upstream.sh`, generated from the AUTHORITATIVE `system/bin/framework-pull-upstream.sh.dist`. It ships in the submodule like everything else, so improvements land automatically — **on the pull AFTER the one that delivers them (one-pull lag)**. The running updater **copies itself to `/tmp` and re-execs** before touching the tree, because `git checkout` rewrites the very file bash is reading.

**New durable "make the environment correct" behavior goes in `system/bin/environment_updates/`, NOT in the updater** — editing a running bash script is unsafe and `.dist` edits lag one pull. Each script is self-detecting, idempotent, silent when applied and non-fatal; `post-update.sh` is the single entry point, and in THIS repo its only trigger is a successful `rsx:manifest:build` in development mode. Contract: `system/bin/environment_updates/CLAUDE.md`.

**The pull commits the submodule pointer BEFORE the rebuild**, so a rebuild that fails costs only the build — fix what it reports and rebuild, never re-pull. **Framework-developer trees never run any of this** — here `system/` is authored source, not a checkout.

Skill `rspade:publishing-a-release` (pre-flight gates, changelog assembly, the three repositories, strip/transform rules, inventory generation, failure triage). Details: `rsx:man rsx_upstream`, `rsx:man framework_pull_mechanics`.
