# Core Framework Systems

## Main_Abstract hooks

An application's `/rsx/main.php` (and the portal's `/rsx/portal_main.php`) extends
`Main_Abstract` with static hooks the dispatcher calls: `init()` (bootstrap),
`pre_dispatch()` (before every dispatch, every Ajax call and every bearer-key call; non-null
halts, and it is never an authorization hook) and `unhandled_route()`. The contract - order,
arguments per channel, what a non-null return does - is `rsx:man dispatch`, MAIN HOOKS.

Every one of them - and a controller's own `pre_dispatch()` - runs through
`Locks/Site_Lock::_run_hook()`: NO automatic site lock, inside an enforcement window (a
site-scoped write needs a `Site_Lock::write()` section, a bypass, or a `#[Site_Lock_Exempt]`
model), and it must hand over holding no lock or transaction it took. The request's own lock
is taken after the pre_dispatch hooks, around the handler only.

## Core JavaScript and dispatch

Every class in `Js/` is in every bundle - never check for its existence; the boot order and the
runtime's contents are `Js/CLAUDE.md`. Every request enters through
`Dispatch/Rsx_Front_Controller` (Laravel's router is never consulted), classified once into a
channel whose pipeline and error policy own it: `Dispatch/CLAUDE.md`, `rsx:man dispatch`.

## Autoloader and Manifest

Classes are found by NAME, not path. The manifest indexes every file under the configured
`rsx.manifest.scan_directories` (`rsx/` plus `app/RSpade/Core`, `Integrations`, `Bundles`,
`CodeQuality`, `Lib`, `Sys`), and the test trees only while `rsx:test` runs.
`Manifest/CLAUDE.md`.

## Core vs Lib

`Core/` is the ENGINE: boot, build, request handling, the browser runtime, data and identity,
background work, live updates and the test harness. A supplementary feature - one an
application could run without - lives in `app/RSpade/Lib/`, grouped by domain
(`Lib/CLAUDE.md`). Both trees are always present: both are scanned, and `Core_Bundle` includes
the browser-facing parts of each.

## Subsystem documents

Subsystems with a colocated `CLAUDE.md`: `Ajax/`, `Api/`, `Bundle/`, `Database/`,
`Dispatch/`, `Files/`, `Js/`, `JsParsers/`, `Manifest/`, `Models/`, `Realtime/`, `Session/`,
`SPA/`, `Task/`. The `Lib/` features carrying one are listed in `Lib/CLAUDE.md`. jqhtml
template syntax (slots, inheritance, `<Define>` attributes) is skill `rspade:jqhtml`.

## CLI Invocation Parameters vs Environment Variables

**OWNER RULING (do not reintroduce):** the parameters of a program invocation are
ALWAYS `--flags`, NEVER `KEY=VALUE` env prefixes. An environment variable describes
the ENVIRONMENT — a PATH-like, deployment-pertaining, `.env`-worthy fact about the
system the program runs in (`RSX_MODE`, `APP_URL`, `DB_*`, `REDIS_HOST`). It is
NOT a channel for telling a single invocation what to do. The owner has expressed
explicit displeasure at env-as-params; it must not reappear.

- WRONG: `RSX_FORCE_BUILD=1 php artisan rsx:build`
- RIGHT: `php artisan rsx:build --force`

**Passing state to a subprocess:** write the deployment fact to `.env` FIRST, then
invoke — the child boots reading `.env` (e.g. the mode commands set `RSX_MODE` before
spawning `rsx:build`, so no `RSX_MODE=` prefix is needed). Per-invocation intent rides
as `--flags`; to reach a grandchild, forward the flag explicitly (`rsx:build` passes
`--_build-context` on to its `rsx:clean` and `optimize:cache` children) — never rely on
env inheritance.

**Boot-time flag detection:** pre-handler code (Manifest boot, the build-tree guard)
runs before any command's `handle()`, so it cannot use `$this->option()`. The
sanctioned channel is argv inspection — `Manifest::__cli_has_flag('--flag')` for a
flag, `Rsx_Internal_Flags` for the `--_` ones the pre-boot strip lifted, and
`$_SERVER['argv'][1]` for the command name (which is how `Rsx_Build_Context` recognises
`rsx:build` from the first line of boot). Programmatic in-process declaration is
`Rsx_Build_Context::begin()`. None of them ever reads an env var for invocation intent.