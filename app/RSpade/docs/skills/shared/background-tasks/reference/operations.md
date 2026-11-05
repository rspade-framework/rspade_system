# Background tasks - worker pools and the task-system switch

Operational depth for skill `rspade:background-tasks`: which pool runs what, the claim order, abandonment retry, retention, and turning the whole task system off and on.

## The worker pools

| Pool | Runs | Cap (`rsx.tasks.pools.<pool>.max_workers`) |
|---|---|---|
| `on_demand` | dispatched work | automatic: two thirds of the container's task workers (task workers about a quarter of php-fpm's), else 2; `RSX_TASK_MAX_WORKERS` sets it |
| `scheduled` | queued on-demand work FIRST, then due schedules | automatic: a third of the container's task workers (at least 1), else 1; `RSX_TASK_SCHEDULED_WORKERS` sets it |
| `kill` | force stops and kills | 2 |

**Claim order**: `#[Run_As_Dispatcher]` runs first (somebody is waiting), then every other on-demand run, then scheduled-origin runs - oldest first within each tier; no schedule is ticked while an on-demand run is due. A run whose lock is held is passed over, whatever its tier.

**rsx-lockd is the one count of workers** (`Task_Pool`): a worker's pool membership IS its lifelong daemon connection - no heartbeat, lease or TTL. A worker admits itself under its pool's lock, claims with a guarded write, runs the task unlocked, settles under the lock. The cron tick settles an ABANDONED run (its worker gone) with no grace period: a dispatched run goes back to PENDING after `rsx.tasks.retry.base_seconds` (600) * 2^(n-1) and FAILS on the `attempts`-th (5) abandonment; a scheduled or inline run is FAILED. **So a dispatched task's body may run more than once when a worker dies under it - write it to be safe to re-run from the start.** Full contract: `rsx:man tasks`.

Retention: finished runs' output is cut to 150 lines and their attachments unlinked after 7 days; runs are purged after 21 days (`rsx.tasks.retention`, minutes).

---

## Turning the task system off and on

```bash
php artisan rsx:tasks:disable --reason="replacing the server" [--grace=30]
php artisan rsx:tasks:enable
```

One switch, **stored in the database** (one fact for every host sharing it). **While it is off, every task - dispatched or inline - QUEUES; nothing runs until `rsx:tasks:enable`; an inline caller waits for its answer.**

- `Task::dispatch()` writes its PENDING row as always; no worker is started, a worker claims nothing and exits, and no `#[Schedule]` produces a run. A schedule that came due meanwhile runs **once** after enable, then follows its cadence.
- **`Task::internal()`, `rsx:task:run` and a `#[Command]` queue the run PENDING and WAIT** - no limit, re-reading every 2 s; a command says once on stderr that it is waiting for `php artisan rsx:tasks:enable`. After enable the waiting process claims and runs its own run in-process and the caller gets its ordinary answer; if a worker claimed it first the caller returns that settled run. It executes exactly once. **A waiter that goes away loses nothing**: the process dies with the server, or a web server ends a request that outlived its limit, and the run stays queued for a worker - that caller just never sees the answer. So an endpoint that calls `Task::internal()` blocks while the system is off; where the answer is not needed in the request, `dispatch()`.
- `rsx:task:process` keeps settling dead runs and starting kill workers. No flag makes anything run.
- **`rsx:tasks:disable` is synchronous**: switch first, then every RUNNING run gets a force stop - asked to stop, killed after the grace (`--grace`, default `rsx.tasks.stop_grace_seconds`) - and it returns once this host's runs have settled. **Exit 0 = off and nothing running here**; 2 = off, with the inline web-request runs it lists still running (never signalled); another host's runs keep their kill request for that host's tick. Idempotent both ways.
- It is seen everywhere: every pending run's wait line reads `task system disabled - php artisan rsx:tasks:enable`, `rsx:tasks:list` leads with it, `rsx:health` FAILs its **Task System** row, `/_sys` Tasks and Task Workers show an alert. In PHP: `Task::is_enabled()`, `Task::disabled_state()`.
- `rsx:maintenance:enable` runs the disable and `rsx:maintenance:disable` the enable - so a task system you had turned off on purpose is ON after a maintenance window.

**Replacing a server**: `rsx:tasks:disable` -> wait for exit 0 -> replace it -> `rsx:tasks:enable` on the new one. A host lost without that leaves its RUNNING runs unsettled when it returns under another hostname (a recreated container's hostname is its id unless pinned): only the host that ran a worker can judge its pid.
