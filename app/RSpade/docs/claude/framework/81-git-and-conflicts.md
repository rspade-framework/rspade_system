<!-- bucket: framework — single-source, never duplicate. True ONLY in this monorepo. -->

## GIT IN THIS MONOREPO

**Commits are SNAPSHOTS**: "commit" means `git add -A` - the ENTIRE working directory (temp files, drafts, notes, `.env`, `node_modules` - all version-controlled here). Never delete, exclude or clean up files first, and never commit selectively ("just the X files" is wrong). `php artisan rsx:git` is plain git here (no submodule sync, no maintenance window).
