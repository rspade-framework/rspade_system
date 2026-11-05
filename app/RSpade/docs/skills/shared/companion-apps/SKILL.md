---
name: companion-apps
description: "Writing a companion application - a long-running watcher, listener or bridge under rsx/companions/<name>/ (Companion_Service_Abstract) run by rsx-companiond, calling the app through signed #[Companion_Endpoint] methods. Use when something must run forever instead of a looping task, running rsx:companion_apps:start, or on contract_mismatch, site_required or 'rsx-companiond is not running on this box'."
---

# Companion applications

A companion is the third endpoint caller, beside a browser (`#[Ajax_Endpoint]`) and an end user's integration (`#[Api_Endpoint]`): a process that ships in the same commit as the application and calls it as a peer. No user, no session, no API key, no versioning. The framework runs it (`rsx-companiond`, one daemon under the container's supervisor - **supervisord is required for companions**); the application declares it, gives it endpoints, and decides who may watch it.

Reach for one when the work is CONTINUOUS - a cursor polled forever, a socket held open. Work that ends is a `#[Task]` (`rspade:background-tasks`).

## The three traps

1. **Declare the site.** The site is per request, signed. Single tenancy: absent = site 1. Multi tenancy: absent is `422 site_required` - pass it: `companion.client.<endpoint>(params, {site_id})` or `companion.call(endpoint, params, {site_id})`. A task the endpoint dispatches runs on NO site: pass `site_id` in its params and declare it in the task (`Session::set_temporary_site_id()`).
2. **Endpoints are idempotent and enqueue.** A companion killed mid-call repeats the call, and the SDK retries every temporary failure. Validate, record, `Task::dispatch()`, answer fast. The companion advances its own checkpoint only AFTER the application accepted the work - a hard kill then repeats work and never loses it.
3. **No versioning.** Change an endpoint and a running companion's next call is `409 contract_mismatch`: the SDK exits 75 and the daemon starts it again on current code. No `through:`, no shim, ever.

## Declaring one

```php
// rsx/companions/feed_watch/feed_watch_companion_service.php
class Feed_Watch_Companion_Service extends Companion_Service_Abstract
{
    public static function name(): string { return 'feed_watch'; }
    public static function description(): string { return 'Follows the supplier feed and queues each change'; }
    public static function command(): array { return ['node', 'resource/main.js']; }
    public static function shutdown_grace_seconds(): int { return 60; }   // 1..300

    // Read-only (the build checks the body), no request site. Null = may run.
    public static function ready_to_run(): ?string
    {
        return Feed_Connection_Model::without_site_scope(fn () => Feed_Connection_Model::exists())
            ? null : 'no feed connection is configured';
    }

    #[Companion_Endpoint('feed_watch')]
    public static function item_changed(Request $request, array $params = [])
    {
        // validate, record, enqueue - and safe to receive twice
        Task::dispatch('Feed_Import_Service', 'import_item', ['site_id' => Session::get_site_id(), 'item' => $params['item']]);
        return ['queued' => true];
    }
}
```

- Node source goes under `resource/` (the manifest does not scan it; elsewhere a `module.exports` breaks the build). `command()` paths are relative to the companion's directory.
- **The declaration is read AT BUILD** into `build/companions/<name>/descriptor.json`: no database, no `env()`, and `environment()` never holds a secret - fetch one through an endpoint.
- An endpoint's method name IS the endpoint name. It goes on the companion class or any class with **no class-level `#[Auth]`**, and carries **no `#[Auth]` itself** - the signature is the gate. `rsx_sdk_contract_probe` is reserved.
- `#[Task]` / `#[Schedule]` methods on the class are ordinary tasks.
- Inside an endpoint the writer is `Companion_Actor_Model` (`Companion:<name>`); revisions file under source `companion`; `#[Run_As_Dispatcher]` tasks are refused.
- An endpoint answers like an API endpoint: array/model/null (204), `Rsx_Api::error()` / `validation_error()`, `abort(404)`. **Never 503** for a failure the companion must see - the SDK retries every 503.

## The process

```js
// rsx/companions/feed_watch/resource/main.js
const companion = require('rsx-companion-sdk');   // on NODE_PATH; zero dependencies

let stopping = false;
companion.on('stop', () => { stopping = true; });           // finish the unit, then exit 0
companion.on('pause', () => { /* stop starting new units */ });
companion.on('resume', () => { /* carry on */ });

(async () => {
    await companion.ready();                               // waits as long as it takes
    while (!stopping) {
        const change = await next_change();
        await companion.client.item_changed({item: change}); // retries outages itself
        save_checkpoint(change);                           // only after the app accepted it
        companion.status('Last change ' + change.id);
        companion.state({cursor_age_s: 12});               // replaced whole; fixed shape
    }
    process.exit(0);
})().catch((err) => { companion.error('watch_failed', err.message); process.exit(1); });
```

`companion.error(code, message, {severity, context})` fires the `companion.error` action in PHP (`{name, instance_id, code, message, severity, context}`; also fired with code `crashed` on every crash) - route it in an `#[OnEvent]` handler; the framework sends it nowhere. Worked example: `system/app/RSpade/resource/reference_app/companions/hello/`.

## Running it

| Command | Effect |
|---|---|
| `rsx:companion_apps:start [name]` | desired = running (a per-box flag file; absent = stopped, so **nothing runs until this**); also releases a hold |
| `rsx:companion_apps:stop [name]` | desired = stopped, graceful stop |
| `rsx:companion_apps:restart [name]` | stop + immediate start, desired unchanged |
| `rsx:companion_apps:list [--json]` | desired, status, waiting reason, instance, uptime |
| `rsx:companion_apps:maintenance_on` / `_off` | hold everything down / release, desired untouched |

Flags first, then the daemon's socket, so they are right with the daemon down. A companion starts when: desired, no maintenance (the framework window or the overlay), a build, Redis, the election (`single_instance()`, via rsx-lockd), `Rsx_Install_State` (build, no pending migration, a site), then `ready_to_run()`. Not ready = WAITING with a reason, never counted as a restart. **Readiness gates a START only** - a running companion rides outages on the SDK's retries.

Development: `on_code_change()` - `restart_if_affected` (default: contract key or own files changed), `restart`, `pause` (**best-effort**: a companion's own call can trigger the rebuild), `ignore`. Production restarts a companion whose descriptor changed after `rsx:build --force`. Maintenance windows stop the whole `rsx-companiond` unit first and start it last; `rsx:mode:set`, `rsx:build --force` and a development migrate raise no window - the SDK absorbs the outage.

Output: `storage/logs/companions/<name>/<name>.log` is the record (rotated by the daemon, `rsx.companions.logging.*`, `days_retention` 0 = forever); a capped Redis stream feeds live consoles.

## Watching it from the application

- **Gates deny by default**: register `companion.view.authorize` `{companion, user}` and `companion.control.authorize` `{companion, user, action}` (pause/resume/stop/start/restart) in `/rsx/handlers/`. A developer passes both; the portal never does. Grant least privilege.
- `Rsx_Companion::status($name)` / `all()` / `instances()` / `output_after()` / `request_stats()` read Redis (answer with the daemon down); `pause()` / `resume()` / `stop()` (a hold) / `start()` / `restart()` go through this box's daemon and throw `Companion_Control_Exception` when none listens. Desired state is CLI-only.
- JS: `Rsx_Companion.get(name)` etc.; `this.subscribe('Companion_Changed_Topic', {name}, cb)` with a debounced `refresh()`; `<Companion_Output $companion="feed_watch" />` is the live console.
- `rsx:health`: `Companion Daemon` and `Companion: <name>` rows. `/_sys/companions` is the developer's screen.

## Gotchas

- `rspade-sizing` does not count companions: give their memory back with `RSPADE_RESERVED_MB`.
- Several web hosts need a shared Redis and rsx-lockd, and the desired flag set on each host. Restarting rsx-lockd restarts every single-instance companion.
- The daemon reads `RSX_MODE` at its own start: after `rsx:mode:set`, `supervisorctl restart rsx-companiond`.
- A child of a root daemon runs as the storage tree's owner and sees no `APP_KEY` or database password.

Contract (the wire format, every status and how_ended, the lifecycle table): `php artisan rsx:man companion_apps`.
