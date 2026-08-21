<!-- bucket: framework — single-source, never duplicate. True ONLY in the RSpade monorepo — every line here is FALSE downstream (there `system/` is a git submodule). The general merge-conflict mandate lives in shared/80. -->

## GIT IN THIS MONOREPO

**Commits are SNAPSHOTS** - when the user says "commit", capture the ENTIRE working directory state. NEVER delete, exclude, or clean up files before committing. Include ALL files: temp files, drafts, notes, node_modules, .env, everything. `git add -A` means ADD ALL.

**NEVER selectively commit** - if you find yourself saying "I'll commit just the X files" or "excluding Y", you are doing it wrong. Commit everything. The working directory IS the commit. **node_modules is version-controlled in this project.**

- Git submodules, when this tree has any, live in `/internal-libs/` (none present today). `system/` is NOT one here - downstream it is, but here it is the authored source.
- npm: `php artisan rsx:dev:update_npm`.
- **`php artisan rsx:git` is inert passthrough here** — exactly git, no submodule sync, no maintenance cycle, nothing announced (detected from `IS_FRAMEWORK_DEVELOPER=true`).

**In THIS monorepo the `system/`-conflict exception does not apply** - here `system/` IS the authored framework source, so its conflicts get the same per-hunk treatment as any other code.
