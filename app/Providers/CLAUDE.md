# app/Providers/

| File | Purpose |
|------|---------|
| AppServiceProvider.php | Runs `Rsx_Preboot_Service::init()` at boot and owns the query-logging modes (`set_query_log_mode()`, `QUERY_LOG_*`). |

The framework's own providers live in `app/RSpade/Core/Providers/` and are registered in
`config/app.php`. There is no RouteServiceProvider: RSX does not use Laravel's router
(`rsx:man dispatch`).
