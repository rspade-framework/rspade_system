# Core/Task — the task system's implementation

**Writing a task? Do not read this file.** How to author, dispatch, schedule and guard a
`#[Task]` is `rsx:man tasks` plus the `rspade:background-tasks` skill (the `$task` API,
`#[Exclusive]`/`#[Debounce]`, the human-readable `#[Schedule]` phrases and the block-comment
caution, the `_tasks` schema, and the failure/recycle rules all live there). This file only
says what is in this DIRECTORY.

## What is here

- `Task.php` — the public facade: `dispatch()`, `status()`, coalescing enqueue, prompt
  detached-worker spawn (`spawn_worker()`: refuse while this process's own spawned workers
  fill the cap, reserve a slot, refuse while this host's workers fill the cap, then spawn),
  and the process-level switch `spawn_workers(bool)` (OFF by default under the test suite).
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
- `Task_Lock.php` — MySQL advisory `GET_LOCK` mutex; the cluster-safe primitive under the
  queue itself. NOT the app-facing lock: that is `RsxLocks` (`Core/Locks/`, `rsx:man locks`).
- `Cron_Parser.php` — normalizes both 5-field cron and the plain-English `#[Schedule]` phrases.
- `Task_Worker_Registry.php` — the Redis worker-slot registry enforcing
  `rsx.tasks.global_max_workers` across the pool: a ZSET of live slots (heartbeat-scored) and
  a HASH of spawn reservations (`token => host:pid`, no TTL) that `admit($token)` converts.
  Also the two flush-proof counts read from `/proc` (`is_worker_process()`,
  `host_worker_count()`), recognising a worker by its command-line text.
- `Task_Killer.php`, `Task_Status.php`, `Task_Health_Checks.php`, `Cleanup_Service.php` —
  kill paths, status vocabulary, `rsx:health` probes, retention pruning.

## Invariants to keep when editing here

- **A cron tracker row is never permanently terminal.** One row IS the schedule; parking it in
  `failed`/`killed` stops that schedule forever and silently. Every settle path recycles it to
  `pending`, and `rsx:task:process` loudly revives any tracker found terminal.
- **Tasks run concurrently and unguarded.** Nothing here may reintroduce a global application
  lock; a task serializes its own critical section with `RsxLocks`.
- **Admission precedes the spawn, and a reservation is released deterministically** - by
  the child converting it, by the spawner on a spawn that produced no pid, or by
  `reclaim_orphaned_reservations()` (every `rsx:task:process` tick) when its owner pid is gone
  from this host. Never give a reservation a TTL, and never spawn a worker without one - a
  pre-spawn check that is not the admission itself lets concurrent dispatches all pass it.
- **The registry is never the only count.** It lives in Redis, and a flush empties it under
  running workers. The process-local and host counts in `spawn_worker()` only REFUSE, never
  admit, and neither may be replaced by a time window or a spawn-rate budget: they are counts
  of processes that exist, so they are right at any speed.
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
