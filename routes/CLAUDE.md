# routes/

RSX has no Laravel route files. HTTP routes are `#[Route]` / `#[SPA]` / `#[Portal_Route]` /
`#[Api_Endpoint]` attributes discovered by the manifest, and `App\Http\Kernel` hands every
request to `Rsx_Front_Controller` - Laravel's router is never consulted, so a route
registered here (or by a vendor package) would be unreachable. See `rsx:man dispatch`.

| File | Purpose |
|------|---------|
| console.php | Loaded by `App\Console\Kernel::commands()` for closure-based artisan commands (Laravel's stock `inspire`). Console only; unrelated to HTTP. |
