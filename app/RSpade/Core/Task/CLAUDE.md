# Core/Task — the task system's implementation

**Writing a task? Do not read this file.** How to author, dispatch, schedule and guard a
`#[Task]` is `rsx:man tasks` plus the `rspade:background-tasks` skill (the `$task` API,
`#[Exclusive]`/`#[Debounce]`, the human-readable `#[Schedule]` phrases and the block-comment
caution, the `_tasks` schema, and the failure/recycle rules all live there). This file only
says what is in this DIRECTORY.

## What is here

- `Task.php` — the public facade: `dispatch()`, `status()`, coalescing enqueue, prompt
  detached-worker spawn (`spawn_worker()`: refuse while this process's own spawned workers
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
  lock group, every call acknowledged. The pool lock, membership (`join`/`leave`),
  `count()` (excludes the caller), `member_alive()`, `stats()`, `max_workers()`, and the
  `holds_lock()`/`member_id()` bookkeeping call sites assert with. The worker loop itself is
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
- **Dead-worker recovery asks the daemon.** `rsx:task:process` settles a RUNNING row whose
  `worker_member_key` is no longer a member (after `cleanup_stuck_after`, so a daemon restart
  never reaps live work). A row with a NULL `worker_member_key` - claimed before the pool
  existed, or run inline by `rsx:task:process --once`, which is not a member - falls back to
  the local `posix_kill(worker_pid, 0)` probe. Timeout kills stay pid-based and local.
  Wherever a row's `worker_pid` is cleared, `worker_member_key` is cleared with it.
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
