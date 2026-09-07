# env

Framework tests for the `.env`-facing dev guards, the APP_URL boot resolver, the
developer-bootstrap env heal, and the RSX_MODE -> `app.env`/`app.debug` derivation. Residents: the dev-mode hostname tripwire
(`Rsx_Env_Hostname_Guard`), the APP_URL resolver (`Rsx_App_Url`), and
`Rsx_Env_Symlink::full_heal()` / `boot_heal()`. The SYMLINK invariant on its own
(`Rsx_Env_Symlink::heal()`) is tested under `prod_mode/` where it was born and is
left there.

## Applicability

APP_URL is the SINGLE source of the application hostname (`RSX_HOSTNAME` and
`REALTIME_PUBLIC_URL` were collapsed onto it). Two facts flow from that:

- A copy-pasted `.env` can declare an APP_URL host belonging to a DIFFERENT
  instance than the one the site is browsed under. When both names resolve to
  the same outer reverse proxy, every observable signal looks healthy while
  generated URLs (and the derived realtime relay URL `wss://{host}/ws`) point at
  the wrong box. The hostname guard fails loud on that mismatch in development.

- APP_URL carries a `$HOSTNAME` token (`APP_URL=https://$HOSTNAME`) resolved to
  the OS hostname at boot, and MUST be https (RSpade assumes upstream SSL
  termination). `Rsx_App_Url` performs both transforms.

The guard is a DEVELOPMENT-mode web tripwire only: it is gated on `RSX_MODE`
(not `is_dev_site()`), bails on CLI, and exempts loopback REQUESTS (the
curl / `rsx:debug` testing channel). A loopback-VALUED APP_URL is NOT exempt.

## Source under test

- `app/RSpade/Core/Env/Rsx_Env_Hostname_Guard.php`
  - `check()` - per-request entry (bails on CLI / non-dev / no HTTP host /
    loopback request), collects the declared host, throws on a mismatch.
  - `build_declared(array $env)` - pure: raw APP_URL -> normalized {var, host}
    entry (exact match); empty APP_URL yields none; a hostless APP_URL fatals.
  - `find_mismatch(string $request_host, array $declared)` - pure EXACT
    comparison (the old suffix rule is gone with RSX_HOSTNAME).
  - `is_loopback_host()` / `normalize_request_host()` - pure helpers.

- `app/RSpade/Core/Prod/Rsx_Env_Symlink.php` (the heal, not the symlink half)
  - `full_heal()` - .env.dist required, .env created from the ROOT dist, symlink
    invariant, development-only key sync, credential validation, APP_KEY.
  - `boot_heal()` - the pre-boot entry: the storage/rsx-tmp stamp short-circuit,
    and the "development runs every boot, other modes only when .env is absent"
    rule. Called from `bootstrap/rsx_env_heal.php` (both entrypoints + the
    container entrypoint).

- `bootstrap/app.php` (the env path anchor, not a class)
  - `useEnvironmentPath(<project root>)` - Laravel loads the root .env directly, so
    `env:encrypt`, `key:generate` and `environmentFilePath()` all address the root
    rather than `base_path()` (system/, which the framework pull resets and cleans).

- `app/RSpade/Commands/Rsx/Env_Decrypt_Command.php`
  - `outputFilePath()` - the remaining half of that: stock `env:decrypt` defaults its
    OUTPUT directory to `base_path()` independently of where it read the ciphertext.

- `config/app.php` (the mode derivation, not a class)
  - `app.env` / `app.debug` derived from RSX_MODE, the single mode switch. The
    APP_ENV / APP_DEBUG env keys are read nowhere in the framework. The same
    derivation is repeated in `config/rsx.php` and `config/jqhtml.php`, because a
    config file cannot call `config()`.

- `app/RSpade/Core/Env/Rsx_App_Url.php`
  - `resolve(string $raw, string $os_hostname)` - pure: substitute `$HOSTNAME` /
    `${HOSTNAME}` and strip a trailing slash.
  - `enforce_https(string $app_url)` - pure: throw unless the scheme is https.
  - `patch_environment()` / `enforce_https_from_env()` - impure boot seams
    (read/write env); proven E2E in the ticket, not unit-tested.

Call sites: guard at `Dispatcher::dispatch()` (after `__validate_route_attributes()`,
before the Portal_Dispatcher delegation); resolver substitution at
`bootstrap/app.php` (afterLoadingEnvironment); resolver https enforcement at
`Rsx_Framework_Provider::boot()`.

## Man pages that define behavior

- `man/realtime.txt` (SETUP / environment variables; the guard paragraph).
- `man/email.txt` (HOSTNAME DETECTION).
- Root `CLAUDE.md` "Hostname & Site Environment" section.

## Testable surface

| Area | Type | Notes |
|------|------|-------|
| full_heal: .env recreated byte-identical from the ROOT .env.dist | php | implemented |
| full_heal: no root .env.dist -> throws, never seeds from system/.env.dist | php | implemented |
| full_heal: APP_KEY populated is kept; blank is minted once, in development only | php | implemented |
| full_heal: key sync reaches .env.dist and .env, never overwrites, precedes validation | php | implemented |
| full_heal: credential validation (missing named; empty DB_PASSWORD legal) | php | implemented |
| full_heal: .env.encrypted with no .env is refused in every mode; both present is a normal heal | php | implemented |
| environmentPath()/environmentFilePath() anchored at the project root; env:decrypt override and its --path/--filename semantics | php | implemented |
| boot_heal: stamp short-circuit and stale-stamp re-run | php | implemented |
| pre-boot wiring (artisan / index.php / container entrypoint invoke the same heal) | http | deferred - the heal runs before the framework exists, so it is proven by direct invocation of bootstrap/rsx_env_heal.php rather than a persistent test |
| find_mismatch exact comparison (match / mismatch / suffix-no-longer-applies) | php | implemented |
| build_declared APP_URL normalization + empty-skip + loopback-value-not-skipped + hostless fatal | php | implemented |
| is_loopback_host / normalize_request_host | php | implemented |
| Rsx_App_Url::resolve token spellings / trailing slash / passthrough / idempotent | php | implemented |
| config/app.php: app.env / app.debug derive from RSX_MODE; APP_ENV / APP_DEBUG ignored; booted container agrees | php | implemented |
| Rsx_App_Url::enforce_https https-pass / http-throw / http-localhost-throw / empty-throw | php | implemented |
| check() web path (loopback-request exempt + throw on mismatch) | http | deferred - PHP runner is CLI, so check() always bails there; proven by E2E curl in the ticket verification, not a persistent test |
| patch_environment / enforce_https_from_env boot seams (env patched before config, readable https error) | http | deferred - boot-phase behavior; proven by E2E curl + boot script in the ticket verification |

## Notes

- All tests are pure logic (`$use_database_transactions = false`); no DB.
- The PHP test runner is CLI, where `check()` bails immediately, so the tests
  drive the pure cores directly rather than the request/boot wrappers.
