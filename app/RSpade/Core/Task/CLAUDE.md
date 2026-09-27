# Core/Task — the task system's implementation

**Writing a task? Do not read this file.** How to author, dispatch, schedule and guard a
`#[Task]` is `rsx:man tasks` plus the `rspade:background-tasks` skill (the `$task` API,
`#[Exclusive]`/`#[Debounce]`, the human-readable `#[Schedule]` phrases and the block-comment
caution, the `_tasks` schema, and the failure/recycle rules all live there). This file only
says what is in this DIRECTORY.

## What is here

- `Task.php` — the public facade: `dispatch()`, `status()`, coalescing enqueue, prompt
  detached-worker spawn (`spawn_worker()`: refuse under maintenance mode, refuse while this
  process's own spawned workers
  fill the cap (`is_worker_process()`, `/proc`), then read the pool count under the pool lock,
  unlock, and spawn only below the cap), and the process-level switch `spawn_workers(bool)`
  (OFF by default under the test suite).
- `Task_Instance.php` — the `$task` handle passed to every task method (`info`/`error`/`debug`,
  `update_progress`, `set_result`, `heartbeat`).
- `Task_Command_ManifestSupport.php` — bakes the `#[Command]` table (`data['task_commands']`)
  and enforces its five build-time FATALs. `Task_Command_Registrar.php` — the one call
  `app/Console/Kernel.php` makes, turning each baked row into a `Task_Alias_Command.php`,
  which is `Task_Run_Command` with the service and method already decided. The alias lives
  HERE rather than in `Commands/` because Laravel's `load()` instantiates every command
  class in that directory through the container, and this one takes constructor arguments.
  Contract: `rsx:man task_commands`.
- `Task_Concurrency.php` — `#[Exclusive]` / `#[Debounce]` resolution (at most one running +
  one pending per `class::method` identity).
- `Task_Lock.php` — an object holding one named `RsxLocks` lock across control flow: the
  `#[Exclusive]`/`#[Debounce]` enqueue and identity run locks (`Task_Concurrency`). The
  claim itself is guarded by the task pool lock, never by a `Task_Lock`.
- `Cron_Parser.php` — normalizes both 5-field cron and the plain-English `#[Schedule]` phrases.
- `Task_Pool.php` — the client of rsx-lockd's worker-pool accountant (`pool.*` ops,
  `system/bin/rsx-lockd/README.md` "Worker pools"): ONE lifelong connection per process, no
  lock group, every call acknowledged. The pool lock, membership (`join()` answers the
  member's `wid` + `generation`, `leave`), `count()` (excludes the caller),
  `member_alive(wid, generation)` and the batch `members_alive()` (each answering
  `alive` + `known`), `stats()` (with the daemon's current generation), `max_workers()`,
  `host()` (the `worker_host` value), and the `holds_lock()`/`wid()`/`generation()`
  bookkeeping call sites assert with. The worker loop itself is
  `Commands/Rsx/Task_Worker_Command.php`; the reaper is `Task_Process_Command.php`.
- `Task_Killer.php`, `Task_Status.php`, `Task_Health_Checks.php`, `Cleanup_Service.php` —
  kill paths, status vocabulary, `rsx:health` probes (Task Worker Pool, scheduler liveness,
  schedule failures), retention pruning.

## Invariants to keep when editing here

- **A cron tracker row is never permanently terminal.** One row IS the schedule; parking it in
  `failed`/`killed` stops that schedule forever and silently. Every settle path recycles it to
  `pending`, and `rsx:task:process` loudly revives any tracker found terminal.
- **Tasks run concurrently and unguarded.** Nothing here may reintroduce a global application
  lock; a task serializes its own critical section with `RsxLocks`.
- **rsx-lockd is the ONE count of workers.** A worker's membership is its pool connection:
  it ends when the process does, however it ends, so there is no heartbeat, lease, TTL or
  reservation anywhere, and nothing here may add one. The worker ADMITS ITSELF under the
  pool lock (count of others < cap, then join); `spawn_worker()`'s count is only a pre-check
  that avoids starting a process doomed to exit, and its process-local count only ever
  REFUSES - it is a count of processes that exist, never a time window or a spawn-rate budget.
- **THE RULE: under the pool lock, only pool ops and `_tasks` row reads/writes** (plus a
  non-blocking try or a release of the identity run lock). No other blocking lock, no
  subprocess, no outbound call - pool waits are invisible to the daemon's deadlock detector.
  A process never calls `Task_Pool::lock()` while holding it (it would queue behind itself);
  `spawn_worker()` asserts that, `claim_next_task()` asserts it HOLDS the lock.
- **A pool socket must never reach a child.** PHP sockets are inherited, and a child holding
  the worker's socket keeps its membership alive after it dies. Every spawn seam closes the
  daemon sockets `Lockd_Connection::open_socket_inodes()` names
  (`RsxLocks::inherited_lock_fds()`); a new spawn path of a long-lived child must use one.
- **Abandoned-worker recovery is evidence, never age.** A worker's claim writes
  `worker_id` + `worker_generation` (its pool identity), `worker_host` and `worker_pid`.
  `rsx:task:process` asks the daemon about every RUNNING row with a `worker_id` in ONE
  `members_alive()` under the pool lock: the daemon's own generation and not alive ->
  abandoned at once; an older generation (`known: false`)
  -> this host judges its own rows by pid and leaves other hosts' rows alone (the Task Worker
  Pool health row counts those). A row with no `worker_id` (`--once`, `Task::internal()`) is
  the local pid probe, on its own host only. No grace period exists and none may be added.
  Timeout kills are pid-based and local, and `Task_Killer` signals only a row whose
  `worker_host` is this machine. Wherever a row's `worker_pid` is cleared, `worker_id`,
  `worker_generation` and `worker_host` are cleared with it.
- **Abandonment is retried; a throw never is.** `settle_abandoned()` puts an abandoned
  one-shot back to PENDING with `scheduled_for` = now + `rsx.tasks.retry.base_seconds` *
  2^(n-1), n counted in `consecutive_failures`, and FAILS it on the `retry.attempts`-th
  abandonment; an abandoned tracker counts the run as a failed run and waits for the next
  cadence strictly after now (`Cron_Parser`), never due at once. A task that throws settles
  through `Task_Instance::mark_failed()` exactly as before - no retry, no opt-in. A success
  (`mark_completed()`) clears `consecutive_failures` and `status_reason` on every row.
  `rsx.tasks.retry` is owner-set pacing, not a timeout.
- **Maintenance spawns nothing.** `spawn_worker()` returns false while
  `Framework_Maintenance::is_active()`; the row stays PENDING for the first tick after the
  window. The tick tries ONE `spawn_worker()` when work is due - a worker drains until
  nothing is claimable, so the tick never loops spawns.
- **The reaper's stuck-task cap is framework infrastructure, not licence to add timeouts.**
  See the no-timeout mandate.
- Attributes are reflection-only — never define `#[Task]`/`#[Schedule]`/`#[Command]` classes.
- **The console sink is the RUNNER's.** `Task_Instance::set_console_sink()` is set by
  `Task_Run_Command` (and therefore by every alias) through `Task::internal()`'s fourth
  argument, and by nothing else — so an application calling `internal()` from a web request
  prints to nobody's console. It is display only: the in-memory log array and the queued DB
  writes never consult it.

## See also

`rsx:man tasks` · `rsx:man task_commands` · `rsx:man locks` · skills `rspade:background-tasks`, `rspade:locks-and-subprocesses`
