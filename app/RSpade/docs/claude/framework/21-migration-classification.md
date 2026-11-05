<!-- bucket: framework — single-source, never duplicate. True ONLY in this monorepo. -->

## MIGRATION CLASSIFICATION (CORE-SYSTEM VS TEMPLATE-APP)

**MANDATE: classify every new migration.** A table framework `/system/` code needs to function -> `system/database/migrations/` (ships with `rspade_system`). A template-app table (anything whose model lives in `rsx/models/` - clients, contacts, projects, request threads, the `Party` detail tables) -> `rsx/resource/migrations/`. **The trap: under `is_framework_developer=true`, `make:migration:safe` writes to the FRAMEWORK directory**; a template migration is `php artisan make:migration:safe <name> --path=rsx/resource/migrations` (writes and whitelists it there in one step), or it leaks into the framework and breaks framework-only installs that lack the app tables.
