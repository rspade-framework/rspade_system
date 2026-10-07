# Core/Task — the task system's implementation

**Writing a task? Do not read this file.** How to author, dispatch, schedule, report from,
stop and show a `#[Task]` is `rsx:man tasks` plus the `rspade:background-tasks` skill (the
reporting API, the return contract, `#[Exclusive]`/`#[Debounce]`, the `#[Schedule]` phrases
and the block-comment caution, the gates and widgets, the abandonment rules). This file only
says what is in this DIRECTORY and what must stay true when editing it.

## What is here

- `Task.php` — the facade: `dispatch()` (coalescing through `Task_Concurrency` for a managed
  task, then one `spawn_worker()` try per task pool), `internal()` (an INLINE run: row,
  identity run lock, shutdown-function settle, revision unit, console streams),
  `get_scheduled_tasks()`, `resolve_task_class()`, `spawn_worker($pool)` (refuse when
  spawning is off, under maintenance mode, while this process's own spawns into the pool
  fill its cap (`is_worker_process()`, `/proc`), then read the pool count under the pool
  lock, unlock, and spawn only below the cap - `rsx:task:killer` for the kill pool,
  `rsx:task:worker --pool=` otherwise) and the process switch `spawn_workers(bool)` (OFF by
  default under the test suite).
- `Task_Runner.php` — the one path every run takes: `insert_row()` (stamps `dispatched_by`
  from `Rsx_Model_Abstract::_resolve_context_actor()` and the current `site_id`),
  `execute()` (`pre_task()`, the method, echo captured line by line as stdout, a throw is an
  outcome, a worker's leaked locks released and named), `settle()` (flush, guarded
  RUNNING -> COMPLETED/STOPPED/FAILED, schedule statistics, run lock release + re-anchor of a
  coalesced pending run), `settle_abandoned_inline()`, `running_fields()`.
- `Task_Run_Outcome.php` — THE RETURN CONTRACT, in one place (`from_return()`,
  `from_throwable()`, `exit_code()`).
- `Task_Instance.php` — the `$task` handle: the reporting calls, held and written together
  at `FLUSH_INTERVAL` (a write rate), `is_stop_requested()`, attachments (inside the blob
  store's reference scope; a replaced blob released if orphaned), the console streams a
  runner attaches (`set_console_streams()`), and `record_operator_line()`.
- `Task_Run_Model(_Abstract).php` — `_tasks`, one row per run: enums, report readers,
  cursor reads of output and messages, `to_status_array()`, `search_query()` /
  `find_first_1000()` / `find_page()`, and the lifecycle operations (`request_stop`,
  `force_stop`, `force_kill`, `cancel`, `rerun`), each writing an operator line.
  `Task_Schedule_Model`, `Task_Attachment_Model` (a `#[Blob_Reference]` + `Blob_Referencing`)
  and `Task_Kill_Request_Model` are the side-table models; all are base + shell.
- `Task_Concurrency.php` — `#[Exclusive]` (identity `class::method`) / `#[Debounce]`
  (`class::method::params_hash`): `params_hash()`, the coalescing enqueue under the identity's
  enqueue lock, `try_acquire_run_lock()` (a worker's non-blocking try), `acquire_run_lock()`
  (an inline run waits), `reschedule_pending_after_completion()`.
- `Task_Lock.php` — one named `RsxLocks` lock held across control flow (the enqueue and run
  locks). A claim is guarded by its pool lock and a guarded write, never by a `Task_Lock`.
- `Task_Pool.php` — the client of rsx-lockd's worker-pool accountant (`pool.*` ops,
  `system/bin/rsx-lockd/README.md` "Worker pools") for the THREE pools (`ON_DEMAND`,
  `SCHEDULED`, `KILL`; daemon name `tasks:<pool>:<scope>`): ONE lifelong connection per
  process, no lock group, every call acknowledged. Lock/unlock, `join()` (answers `wid` +
  `generation`), `leave`, `count()` (excludes the caller), `member_alive()` /
  `members_alive()` (`alive` + `known`), `stats()`, `max_workers($pool)` (throws below 1),
  `host()`, and the `holds_lock()` / `member_pool()` / `wid()` / `generation()` bookkeeping.
  The worker loop is `Commands/Rsx/Task_Worker_Command.php`; the reaper and scheduler tick
  is `Task_Process_Command.php`.
- `Task_Kill_Worker.php` — `request()` (records a `_task_kill_requests` row bound to the
  target's `worker_host`; spawns a kill worker when that is this host), `run()` (the
  `rsx:task:killer` loop under the kill pool), `carry_out()` (poll until due or moot,
  SIGKILL, settle KILLED), `claim_for_this_process()` (`rsx:tasks:kill-all`).
- `Task_Gates.php` — the view gate, view scope and control gate per realm (deny by default,
  a staff developer passes); `can_subscribe_to_task()` / `can_subscribe_to_list()` for the
  topics.
- `Task_Notify.php` + `Task_Changed_Topic.php`, `Task_Output_Topic.php`,
  `Task_List_Changed_Topic.php` (data `{class, method}`, so a filter can name one task) —
  the realtime frames: published once per site that holds a subscription the frame matches
  (shallow: every filter key equal) in the relay's registry, nothing when nobody watches;
  each topic's `can_subscribe()` is `Task_Gates`.
- `Rsx_Task_Controller.php` — the browser's endpoints (both realms, `#[Auth('public')]`,
  the gates decide; reads `#[Portal_Impersonation_Readable]`) and the `/_task/xterm.mjs` +
  `/_task/xterm-fit.mjs` module routes. `BundleCompiler` adds its JS stub to every bundle.
- `ui/` — `Rsx_Task.js` and the widgets `Task_Status_Badge`, `Task_Output` (xterm.js,
  imperative and append-only), `Task_Report`, `Task_Report_Browser`; in `Core_Bundle` with
  `ui/vendor/xterm.scss`.
- `Task_Retention_Service.php` — the 30-minute sweep: truncate output + unlink attachments,
  purge, orphaned temp directories.
- `Task_Command_ManifestSupport.php` — bakes the `#[Command]` table (`data['task_commands']`)
  and enforces its five build-time FATALs. `Task_Command_Registrar.php` — the one call
  `app/Console/Kernel.php` makes, turning each baked row into a `Task_Alias_Command.php`,
  which is `Task_Run_Command` with the service and method already decided. The alias lives
  HERE rather than in `Commands/` because Laravel's `load()` instantiates every command
  class in that directory through the container, and this one takes constructor arguments.
  Contract: `rsx:man task_commands`.
- `Cron_Parser.php` — normalizes both 5-field cron and the plain-English `#[Schedule]` phrases.
- `Task_Health_Checks.php` — `rsx:health` rows: Task Worker Pools, Task Scheduler Liveness,
  Task Schedule Failures.

## Invariants to keep when editing here

- **Every execution is a run row.** Dispatched, scheduled (created by the claiming
  scheduled-pool worker in the same transaction that advances `next_run_at`) and inline
  alike; every path settles through `Task_Runner::settle()` or a guarded write of its own
  (kill, abandonment, cancel). A schedule is a `_task_schedules` row and is never a run.
- **Every settle and every claim is GUARDED** on the status it expects (and, for a
  kill or an abandonment, on the worker that held it), so two writers can never both win:
  a run a kill worker settled KILLED keeps that verdict when the task returns, and two
  pools can never both claim one pending row.
- **A graceful stop is cooperative.** `request_stop()` only stamps `stop_requested_at`;
  nothing here may act on it for the task. Killing happens only through a kill request,
  carried out by a kill worker, never inside the request that asked.
- **Kills are host-affine and never signal a web request.** A pid names a process on one
  machine, so a kill request carries the target's `worker_host` and only that host's kill
  workers claim it. An inline run inside php-fpm is settled moot, never signalled.
- **Tasks run concurrently and unguarded.** Nothing here may reintroduce a global
  application lock; a task serializes its own critical section with `RsxLocks`.
- **rsx-lockd is the ONE count of workers.** A worker's membership is its pool connection:
  it ends when the process does, however it ends, so there is no heartbeat, lease, TTL or
  reservation anywhere, and nothing here may add one. A worker ADMITS ITSELF under its pool
  lock (count of others < cap, then join); `spawn_worker()`'s count is only a pre-check, and
  its process-local count only ever REFUSES.
- **THE RULE: under a pool lock, only pool ops and task-table reads/writes** (plus a
  non-blocking try or a release of an identity run lock). No other blocking lock, no
  subprocess, no outbound call - pool waits are invisible to the daemon's deadlock detector.
  A process holds AT MOST ONE pool lock at a time and never calls `Task_Pool::lock()` while
  holding one (`lock()` asserts it; `spawn_worker()` asserts it holds none; a worker's claim
  asserts it holds its own).
- **No frame under a pool lock.** A Redis publish is an outbound call, so `Task_Notify` only
  RECORDS a frame while this process holds a pool lock, and whoever releases the lock calls
  `Task_Notify::flush_deferred()` - the worker after each unlock, the reaper after each
  pool's pass. A new path that writes a run under a lock and unlocks must flush.
- **A pool socket must never reach a child.** PHP sockets are inherited, and a child holding
  a worker's socket keeps its membership alive after it dies. Every spawn seam closes the
  daemon sockets `Lockd_Connection::open_socket_inodes()` names
  (`RsxLocks::inherited_lock_fds()`); a new spawn path of a long-lived child must use one.
- **Abandoned-worker recovery is evidence, never age.** A claim writes `worker_id` +
  `worker_generation`, `worker_host` and `worker_pid` together; wherever they are cleared,
  all four are. `rsx:task:process` asks the daemon about every RUNNING run with a
  `worker_id` in ONE `members_alive()` per pool, under that pool's lock: the daemon's own
  generation and not alive -> abandoned at once; an older generation (`known: false`) ->
  this host judges its own runs by pid and leaves other hosts' alone. A run with no
  `worker_id` (inline) is the local pid probe on its own host. No grace period exists and
  none may be added. Timeout enforcement is a force-kill REQUEST for a live local run.
- **Abandonment is retried; a failure never is.** An abandoned DISPATCHED run goes back to
  PENDING at now + `rsx.tasks.retry.base_seconds` * 2^(n-1) (`abandon_count`) and FAILS on
  the `retry.attempts`-th; an abandoned scheduled or inline run is FAILED. A run that
  returned a failure or threw is settled FAILED and nothing re-runs it. `rsx.tasks.retry` is
  owner-set pacing, not a timeout.
- **Maintenance spawns nothing.** `spawn_worker()` returns false while
  `Framework_Maintenance::is_active()`, for every pool. `rsx:tasks:kill-all` therefore carries
  out this host's kills in its own process.
- **The console streams are the RUNNER's.** `Task_Instance::set_console_streams()` is called
  by `Task::internal()`'s fourth argument, which only `Task_Run_Command` (and therefore every
  alias) passes - so an application calling `internal()` prints to nobody's console. The
  streams are display only: every line is recorded on the run either way.
- **Reports are written at a rate, never dropped.** A held report is written by the first
  reporting call or `is_stop_requested()` after `FLUSH_INTERVAL`, by `flush()` or by the
  settle; `is_stop_requested()` READS the row on every call (a stop is seen at once) but
  writes only at the rate, so a per-item stop check is not a per-item write.
  `FLUSH_INTERVAL` is a write rate and must never become a timeout.
- **The gates fail closed.** With no handler for a realm's event, `Task_Gates` denies (the
  scope answers `0 = 1`); only a staff developer bypasses. Every new task surface asks it.
- **The reaper's execution cap is framework infrastructure, not licence to add timeouts.**
  See the no-timeout mandate.
- Attributes are reflection-only — never define `#[Task]`/`#[Schedule]`/`#[Command]` classes.

## See also

`rsx:man tasks` · `rsx:man task_commands` · `rsx:man locks` · skills `rspade:background-tasks`, `rspade:locks-and-subprocesses`
