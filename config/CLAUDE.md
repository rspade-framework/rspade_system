# system/config — the framework's configuration

## WHAT IS HERE

Laravel's configuration directory, loaded at bootstrap. **`rsx.php` is the framework's own
configuration** - every `config('rsx.*')` key, with its default and the reasoning beside it.
The rest are Laravel's (and a few packages') files, most of them stock.

**Two tiers, deep-merged.** An application never edits a file here (all of `system/` is
replaced by every framework update): it overrides `rsx.*` keys in
`rsx/resource/config/rsx.php`, and a Laravel file in a same-named file under
`rsx/resource/config/` (`mail.php` is the worked case). `rsx:man config_rsx`.

**`env()` only for deployment-specific values** (`DB_PASSWORD`, `REDIS_HOST`); application
behaviour belongs in a config file under version control. The mode is `RSX_MODE`, and
`app.php` derives Laravel's `app.env` / `app.debug` from it - `APP_ENV` / `APP_DEBUG` are
read nowhere.

| File | What it is |
|---|---|
| `rsx.php` | The framework's configuration: sessions, tasks, files, documents, mail, realtime, tenancy, portal, metrics, the production build and the rest. |
| `app.php` | Laravel's application config; `env` and `debug` derived from `RSX_MODE`. |
| `database.php` | Connections (MySQL, the test database `DB_TEST_DATABASE`) and Redis. |
| `mail.php` | Laravel's mail transports (`MAIL_MAILER`, `MAIL_*`) - HOW a message leaves under `rsx.mail.delivery = live`. |
| `logging.php` | Log channels, pinned to the persistent logs directory (`Rsx_Project_Paths::logs_dir()`), never `storage_path()`. |
| `jqhtml.php` | The jqhtml compiler: source-map location (tmp), error context by mode. |
| `cors.php` | CORS for the external API (`api/*`) only. |
| `cache.php`, `filesystems.php`, `hashing.php`, `queue.php`, `view.php`, `broadcasting.php`, `services.php`, `auth.php` | Laravel's stock files, kept for the Laravel components the framework still boots. |
| `ide-helper.php`, `ignition.php` | Package configuration (the IDE helper generator, the error page). |
| `api.php`, `authentication.php`, `cms.php`, `geocoding.php`, `models.php`, `sitemap.php` | No `config()` reader exists for these anywhere in the framework or the template. |
