# Concern: maintenance mode

Maintenance mode is ONE flag file (`storage/rsx-framework/.maintenance.mode.framework.update`)
with two ways in:

- `php artisan rsx:maintenance:enable [--reason=<text>] [--no-services]` / `rsx:maintenance:disable`
  - the operator feature, intercepted PRE-BOOT in `system/artisan` and shelled to
  `system/bin/maintenance-mode.sh`.
- `rsx:framework:pull` - the same script with the reason `framework update in progress`.

While the flag is up: every WEB request answers 503 (quoting the reason), and the CLI gate is
**allow-most-deny-some** - it exists to keep AUTOMATED processes out of a stopped environment,
not to babysit humans.

| Command | Under maintenance |
|---|---|
| `rsx:task:process`, `rsx:task:worker` | BLOCKED (exit 75) |
| `rsx:task:run` | BLOCKED unless `--force` |
| everything else (`migrate`, `rsx:health`, `rsx:clean`, builds, ...) | ALLOWED |
| anything carrying `--_framework-update-override` | bypasses the classification |

Enable also kills running background tasks, QUIESCES the node RPC helper daemons (unsupervised
PID-1 orphans matched by the socket path in their argv - no supervisorctl stop reaches them;
disable deliberately does not restart them, they respawn on demand), and stops realtime,
fpc-proxy, php-fpm, rsx-lockd and redis (in that order - rsx-lockd after its PHP consumers,
redis LAST, after the flag is already up). Disable starts redis FIRST, then rsx-lockd,
php-fpm, realtime, fpc-proxy, and clears the flag last.

Because rsx-lockd and redis are stopped, two subsystems change behavior for the window:

- `RsxLocks` selects an **flock backend** (`storage/flock/<domain>__<db-scope-md5>__<name>.lock`, all locks
  exclusive) chosen from the per-process `RSPADE_MAINT_MODE` snapshot at ACQUISITION time. A
  degraded CLUSTER lock and a SYSTEM lock keep separate files, since the domain names the file.
- `RsxCache` reads miss silently and writes are dropped with ONE log warning per process;
  `Realtime` drops frames and reports an empty subscriber registry.

Each tolerance ALSO engages on a redis CONNECT FAILURE when the flag is on disk (the straggler
case: a process that booted before enable). A connect failure with no flag stays as loud as ever.

Laravel's own `down` / `up` are REFUSED (`Commands/Restricted/Down_Command.php`,
`Up_Command.php`): hidden stubs that print the `rsx:maintenance:*` equivalent and exit 1.
The mechanism they drove is deleted with them - nothing writes `storage/framework/maintenance.php`
and nothing reads it (the `PreventRequestsDuringMaintenance` middleware and the pre-render
require in `public/index.php` are both gone). That deletion is the point: a stock `down` would
otherwise have exited 0, written its file, gated nothing, and told an operator the site was down.

## Source files under test

- `system/artisan` (internal-flag strip, maintenance interception, the gate), `system/public/index.php` (web 503)
- `app/RSpade/Core/Framework/Framework_Maintenance.php` (choke point, reason, test seam)
- `app/RSpade/Core/Console/Rsx_Internal_Flags.php` (the `--_` convention)
- `app/RSpade/Core/Locks/RsxLocks.php` (flock backend)
- `app/RSpade/Core/Cache/RsxCache.php`, `app/RSpade/Core/Realtime/Realtime.php` (tolerance)
- `system/bin/maintenance-mode.sh`, `system/bin/framework-pull-upstream.sh.dist` (delegation)
- `app/RSpade/Commands/Restricted/Down_Command.php`, `Up_Command.php` (the refusals)
- `app/RSpade/Core/JsParsers/Rsx_Node_Service.php` (`quiesce_all()`, the PHP twin of the
  enable-time bash quiesce; the service lifecycle itself is the `js_transform` concern)

## Man pages

- `rsx:man maintenance_mode` (primary)
- `rsx:man framework_pull_mechanics` (THE MAINTENANCE WINDOW)
- `rsx:man tasks` (gate behavior of process/worker/run)

## Testing notes

PHP tests force the maintenance answer with `Framework_Maintenance::$force_active_for_tests`
so the box under test never enters 503. The CLI gate is pre-boot, so its tests must raise the
REAL flag - they always pass `--no-services` (no supervisord unit is touched), wrap every case
in try/finally, and `teardown()` clears the flag unconditionally.

The RPC quiesce cannot be tested through a live `enable`: it lives inside the SERVICES half,
and running that half on a development box stops php-fpm, redis and the lock daemon. It is
covered instead by asserting its PLACEMENT in the script and by EXTRACTING its lines from the
script and running them, unmodified, against planted fake daemons in a scratch directory
(M-30..M-32). Nothing about the reaper is retyped in the test, so the test cannot drift from
the script.

"Was this refused?" is asserted on the gate's ACTUAL refusal - its exact message
(`503 - System is in maintenance mode`), its exit hint, and exit code 75 - never on the phrase
"maintenance mode" appearing somewhere in the output. An allowed command is entitled to discuss
maintenance mode (`rsx:health` has a lock-server row that does), and a looser check would both
false-positive and quietly pressure unrelated code into avoiding the words.

The full service quiesce (supervisorctl stop/start of four units) is NOT covered by an
automated test: it needs an isolated supervisor. It is verified by the end-to-end smoke
documented in the maintenance man page.
