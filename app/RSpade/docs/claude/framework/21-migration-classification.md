<!-- bucket: framework — single-source, never duplicate. True ONLY in this monorepo. -->

## MIGRATION CLASSIFICATION (CORE-SYSTEM VS TEMPLATE-APP)

**MANDATE: classify every new migration as core-system vs template-app.** After creating ANY migration, decide whether the table belongs to the framework (a table framework `/system/` code needs to function) or to the reference/template app.

- **Framework-core** migrations live in `system/database/migrations/` and ship with `rspade_system`.
- **Template-app** migrations (clients, contacts, projects, memberships, shared_items, announcements, request threads - anything whose model lives in `rsx/models/`) MUST live in `rsx/resource/migrations/`, shipping with the template app and NOT the framework.

**The trap**: `make:migration:safe` writes to the FRAMEWORK directory under `is_framework_developer=true`. A template-app migration is created with `php artisan make:migration:safe <name> --path=rsx/resource/migrations`, which writes the file there and whitelists it in that directory's `.migration_whitelist` in one step, **so it never leaks into the framework and breaks framework-only installs that lack the app tables.**

**Template-flavored references** (this repo's worked examples, not downstream instructions):
- Detail tables: the `Party` entity - `/rsx/models/party_model.php` plus the staff module under `/rsx/app/frontend/party/`. Its `Rsx_Detail_Table::create(...)` migration belongs in the template-app directory.
- The trashed-record escape from the model-fetch policy: `Frontend_Clients_Controller::fetch_deleted`.
