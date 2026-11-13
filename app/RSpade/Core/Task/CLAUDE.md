# Core/Task — the task system's implementation

**Writing a task? Do not read this file.** How to author, dispatch, schedule, report from,
stop and show a `#[Task]` is `rsx:man tasks` plus the `rspade:background-tasks` skill (the
reporting API, the return contract, `#[Exclusive]`/`#[Debounce]`, the `#[Schedule]` phrases
and the block-comment caution, the widgets, the abandonment rules) and `rspade:task-access` (who
the browser shows a run to). This file only
says what is in this DIRECTORY and what must stay true when editing it.

## What is here

- `Task.php` — the facade: THE SWITCH (`is_enabled()` / `disabled_state()` read
  `_task_system_off` on every call, never memoized across a loop iteration or a tick;
  `disable($reason)` / `enable()` are the only writers and publish
  `Task_Notify::system_switched()`), `set_next_run()` (one upsert of `_task_schedules.next_run_at` for a
  DECLARED schedule; throws for a task with no `#[Schedule]`), `dispatch()` (coalescing through `Task_Concurrency` for a managed
  task, then one `spawn_worker()` try per task pool), `internal()` (an INLINE run: row,
  identity run lock, shutdown-function settle, revision unit, console streams; while the
  system is off, `__queue_and_wait()` - a PENDING row, a poll every `DISABLED_POLL_SECONDS`,
  then the guarded claim of its own row),
  `get_scheduled_tasks()`, `resolve_task_class()`, `spawn_worker($pool)` (refuse when
  spawning is off, under maintenance mode, while the task system is off (the two task
  pools; a kill worker is still started), while this process's own spawns into the pool
  fill its cap (`is_worker_process()`, `/proc`), then read the pool count under the pool
  lock, unlock, and spawn only below the cap - `rsx:task:killer` for the kill pool,
  `rsx:task:worker --pool=` otherwise), the process switch `spawn_workers(bool)` (OFF by
  default under the test suite), `is_run_process()`, and the vanished-host pair
  `vanished_host_timeout()` (the validated config) and `sql_seconds_since_evidence()` (the
  one SQL measure of a run's latest evidence of life, on the database clock), read by the
  tick and the health row alike.
- `Task_Daemon.php` — the application's side of `rspade-taskd`, the container's scheduler
  (`resource/docker/rspade-taskd/`): `poke()` (a line on its socket, only where
  `/.rspade_container` exists and this process spawns workers; never waits, never fails),
  `next_wake_ms()` (what the tick prints under `--_report-next-wake`: next schedule at its own
  time - never early, next deferred pending run, `BUSY_RECHECK_SECONDS` while anything runs or a kill is
  owed here, null when nothing waits), and THE DUE RULES every claim and spawn check uses:
  `schedule_due_cutoff()` (a tick claims schedules due within the next `SCHEDULE_LEAD_SECONDS`), `where_pending_due()` (a dispatched
  run at its time, a scheduled-origin run early) and `next_run_after()` (advance from the later
  of now and the due time). Pokes come from `dispatch()`, `set_next_run()`, a worker that ran
  something exiting, a manifest rebuild and `rsx:tasks:enable`.
- `Task_On_Build.php` — `#[On_Build]`: `declarations()` (the manifest attribute index),
  `recorded_build_key()` / `dispatch_for_build($build_key)` (the scheduler tick's last step:
  when the key differs from `_task_on_build` row 1, dispatch every declaration once with no
  params, THEN record the key - a throw leaves it unrecorded; nothing while the task system is
  off; skipped by the tick under the test suite) and `declaration_problems()`, the one
  judgement of a declaration, asked by `TASK-ON-BUILD-01` (a manifest-build FATAL, so the tick
  never re-judges a declaration). The build never dispatches - it only pokes the daemon.
- `Task_Dispatcher_Context.php` — `#[Run_As_Dispatcher]`: `begin($task_id)` loads the run's
  `_tasks.user_session_id` (the dispatching sign-in - staff, portal or an API key's) and
  starts a FROZEN, BOUND virtual session from it (`Session::_begin_virtual()`: identity, site,
  realm, impersonator, `get_session_id()` = that browser, `get_user_session_id()` = that user
  session, the dispatch's audit session) - even when that sign-in has ended since. It refuses
  first (a throw = a failed run, nothing executed): no user session, the login identity
  deleted, the membership or site not active, the portal user unable to sign in or its site
  not active. `Task_Runner::execute()` calls it only for a run executing outside its caller's
  process (the worker, `rsx:task:process --once`); `Task::internal()` does not.
- `Task_Runner.php` — the one path every run takes: `insert_row()` (stamps `dispatched_by`
  from `Rsx_Model_Abstract::_resolve_context_actor()`, the current `site_id`,
  `Session::get_user_session_id()`, `audit_session_id` and the claim tier `priority` -
  `PRIORITY_RUN_AS_DISPATCHER` 0, `PRIORITY_ON_DEMAND` 1, `PRIORITY_SCHEDULED` 2 for a
  scheduled-origin run; a `#[Run_As_Dispatcher]` task with no user session behind it is
  refused unless the run is INLINE and somebody is acting - a declared identity, staff or
  portal (`_resolve_context_actor()` non-null) - in which case it runs in the caller's
  unfrozen context with `user_session_id`/`audit_session_id` null; a dispatched run always
  needs the user session, because `Task_Dispatcher_Context::begin()` rebuilds from it alone),
  `execute()` (`pre_task()`, the method, echo captured line by line as stdout, a throw is an
  outcome, a worker's leaked locks released and named; the run in progress is held in
  `$_executing` for `_log_fatal_on_shutdown()`, registered once per process: a fatal that ends
  the process mid-run is written to the run's stderr and to the Laravel log under
  `log_prefix()` (`[TASK #<id>] <Service>::<method>: `); with `$own_context` - worker and
  `--once` - a fresh revision unit and virtual session before, `Session::_end_virtual()`
  after), `settle()` (a failure that was THROWN also goes to the Laravel log under
  `log_prefix()`; a returned failure does not; then flush, guarded
  RUNNING -> COMPLETED/STOPPED/FAILED under the instance's claim (`_claimed_row()`), a
  refusal of a run taken from this process logged and noted on the run, schedule
  statistics, run lock release + re-anchor of a coalesced pending run, THEN the lifecycle
  frame - the waiters it tells must read the lock free; answers whether it recorded the
  outcome), `settle_abandoned_inline()`, `running_fields()`.
- `Task_Run_Outcome.php` — THE RETURN CONTRACT, in one place (`from_return()`,
  `from_throwable()`, `exit_code()`).
- `Task_Instance.php` — the `$task` handle: the reporting calls, held and written together
  at `FLUSH_INTERVAL` (a write rate; every reporting call ends in `__reported()`, so the next
  write stamps `last_report_at` even when nothing else changed), `run_again_in()`,
  `is_stop_requested()`, attachments (inside the blob
  store's reference scope; a replaced blob released if orphaned), the console streams a
  runner attaches (`set_console_streams()`), `record_operator_line()`, and THE CLAIM it
  writes under (`_claimed_row()`: the row's worker_host, worker_pid, worker_pid_start_time
  and abandon_count as `for_row()` read them) with `_mark_superseded()` /
  `is_superseded()` for a run taken from this process.
- `Task_Run_Model(_Abstract).php` — `_tasks`, one row per run: enums, report readers,
  cursor reads of output and messages, `to_status_array()` / `to_status_arrays()` (with
  `waiting`), `search_query()` /
  `find_first_1000()` / `find_page()`, and the lifecycle operations (`request_stop`,
  `force_stop`, `force_kill`, `cancel`, `rerun`), each writing an operator line.
  `Task_Schedule_Model`, `Task_Attachment_Model` (a `#[Blob_Reference]` + `Blob_Referencing`)
  and `Task_Kill_Request_Model` are the side-table models; all are base + shell.
- `Task_Concurrency.php` — the policy of a task method, as TWO identities decided
  separately. The PENDING identity (`identity_key()`: the pending row, the enqueue lock, the
  delay, the re-anchor) is `class::method` for `#[Exclusive]` and
  `class::method::params_hash` whenever `#[Debounce]` is declared; the RUN-LOCK identities
  (`run_lock_identities()`) are every group's whenever one or more `#[Exclusive('group')]`
  are declared (the attribute is repeatable) and the pending identity alone otherwise.
  `get_policy()` answers `mode` `debounce` whenever `#[Debounce]` is present (a bare
  `#[Exclusive]` beside it changes nothing) and `groups` (sorted) from every
  `#[Exclusive('group')]`. `groups_of()`, `group_identity()`, `is_group_running()` (the
  group's lock, asked of the daemon), `group_members()` (from the manifest's attribute
  index, any instance naming the group), `pending_ids_on_run_lock()` (the waiters of any of
  a run's locks), `params_hash()`, the coalescing enqueue under the identity's enqueue lock
  (`enqueue_coalesced()`, and `enqueue_coalesced_without_waiting()` for the schedule tick a
  worker enqueues under its pool lock; both take the due time a schedule passes),
  `try_acquire_run_lock()` (a worker's non-blocking all-or-none try) and
  `acquire_run_lock()` (an inline run waits), both answering a `Task_Lock_Set`,
  `reschedule_pending_after_completion()`.
- `Task_Lock_Set.php` — every run lock one run needs, held from claim to settle:
  `try_acquire()` takes all or none (releasing what it took the moment one is held
  elsewhere), `acquire()` waits for each in NAME ORDER (no two inline runs can each hold one
  and wait on the other), `release()`.
- `Task_Waiting.php` — what a PENDING run is waiting for (`disabled` / `scheduled` / `lock` /
  `workers` / `queued`), derived per read: one instance memoizes the switch (one read), the
  RUNNING rows (one query: holders by
  run lock identity + busy pool workers), the pools' caps and each lock's `is_in_use()`.
- `Task_Lock.php` — one named `RsxLocks` lock held across control flow (the enqueue and run
  locks). A claim is guarded by its pool lock and a guarded write, never by a `Task_Lock`.
- `Task_Pool.php` — the client of rsx-lockd's worker-pool accountant (`pool.*` ops,
  `system/app/RSpade/resource/docker/rsx-lockd/README.md` "Worker pools") for the THREE pools (`ON_DEMAND`,
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
  SIGKILL - a pool run's pid only while `Task::is_worker_process()` - settle KILLED),
  `claim_for_this_process()` (`rsx:tasks:disable`). Every claim records the claimer's
  `worker_pid` + `worker_pid_start_time` together; `rsx:task:process`
  (`recover_kill_requests()`) re-queues a CLAIMED request whose claimer is gone - a kill
  worker of the daemon's generation by `members_alive()`, anyone else by
  `Process_Identity::is_running()` (plus `Task::is_worker_process()` for an older-generation
  kill worker), never by bare pid existence.
- `Task_Gates.php` — the browser task API's one access seam, decided PER TASK TYPE by
  `#[Task_Access]`: `decide($run, $action)` (ALLOWED / DENIED / UNDECLARED / WRONG_REALM; a
  staff developer passes without a check being asked; a caller not signed in in the realm
  is DENIED; a run stamped with another site is DENIED before the check), `can_view()`,
  `can_control()`, `declaration()`, `refusal_metadata()` (the marked `unauthorized` payload
  Rsx_Task.js recognises), `list_refusal()`, `visible_page()` (declared types only;
  `dispatcher` types narrowed in SQL, custom checks asked per row while walking by id until
  the page fills) and `can_subscribe_to_task()` / `can_subscribe_to_list($filter)` for the
  topics.
- `Task_Access_ManifestSupport.php` — bakes the `#[Task_Access]` table
  (`data['task_access']`, read by `Manifest::get_task_access()`) and enforces its build-time
  FATALs (no `#[Task]`, on a class, repeated, no check, unknown argument or realm, a check
  method missing / not static / not three-parameter).
- `Task_Notify.php` + `Task_Changed_Topic.php`, `Task_Output_Topic.php`,
  `Task_List_Changed_Topic.php` (data `{class, method}`, so a filter can name one task) —
  the realtime frames: published once per site that holds a subscription the frame matches
  (shallow: every filter key equal) in the relay's registry, nothing when nobody watches;
  each topic's `can_subscribe()` is `Task_Gates`. `lifecycle()` also sends
  `Task_Changed_Topic` for every PENDING run on the moved run's run lock
  (`pending_ids_on_run_lock()`). Called inside a database transaction (a dispatch from a
  `#[Writer]` endpoint or a `Site_Lock::write()` section), `lifecycle()` waits for the
  outermost commit (`DB::afterCommit()`) and sends nothing on a rollback.
- `Rsx_Task_Controller.php` — the browser's endpoints (both realms, `#[Auth('public')]`,
  `Task_Gates` decides; it can read and control runs, never start one; reads `#[Portal_Impersonation_Readable]`) and the `/_task/xterm.mjs` +
  `/_task/xterm-fit.mjs` module routes. `BundleCompiler` adds its JS stub to every bundle.
- `ui/` — `Rsx_Task.js` and the widgets `Task_Status_Badge`, `Task_Output` (xterm.js,
  imperative and append-only; its source is five overridable hooks, which is how
  `Core/Companion/ui/Companion_Output` reuses the whole terminal), `Task_Report`, `Task_Report_Browser`; in `Core_Bundle` with
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
- `Cron_Parser.php` — normalizes both 5-field cron and the plain-English `#[Schedule]` phrases, and reads them on the server timezone's wall clock (`Rsx_Time::get_server_timezone()`), walking REAL minutes with cron's DST rule for fixed-time jobs. `_task_schedules.timezone` records the zone a row's `next_run_at` was computed in and `cron_resolved` the five-field expression it resolved to (`'maintenance'` resolves through `rsx.tasks.maintenance_hour`, `Cron_Parser::maintenance_hour()`); reconciliation re-registers a row whose zone or resolution differs, and only records a NULL resolution. Every framework task running daily or less is `#[Schedule('maintenance...')]` + `#[Exclusive('maintenance')]` - `Maintenance_Window_Test` holds that line.
- `Task_Health_Checks.php` — `rsx:health` rows: Task System (FAIL while off; INFO under the
  maintenance flag), Task Worker Pools (WARN naming the other hosts' runs rsx-lockd cannot
  answer for - `unanswered_foreign_rows()` - and when the next settles), Task Scheduler
  Liveness (INFO while off), Task Schedule Failures.
- The switch's commands are `Commands/Rsx/Tasks_Disable_Command.php` (switch first, then the
  synchronous stop pass: `force_stop()` on every RUNNING run, this host's kill requests
  carried out in-process, re-read until none is owed) and `Tasks_Enable_Command.php`;
  `system/bin/maintenance-mode.sh` calls both.

## Invariants to keep when editing here

- **Every worker run ends blank.** A run executed outside its caller's process starts in a
  virtual session of its own (plain, or frozen and bound for `#[Run_As_Dispatcher]`) and ends
  with `Session::_end_virtual()` - the ONE blanking entry point (Session + Portal_Session
  identity, binding and freeze, declared audit session, request realm, `Rsx_Time` timezone
  cache, `Rsx_Dark_Mode`, second factors passed). The worker and `--once` snapshot their
  launch context and restore it on exit, so an in-process caller (`Artisan::call`) gets its
  context back; `Task::internal()` snapshots and restores its caller's context
  (`Session::_snapshot_context()`) and never blanks it. Anything new that memoizes on the
  acting identity is cleared in `_end_virtual()`.
- **One set of due rules.** Every "is it due" in this system - the worker's two claims, the
  tick's spawn check, `--once` - goes through `Task_Daemon`'s due rules, and
  `next_wake_ms()` must agree with them (it starts from what they leave undue). A schedule
  claimed early ADVANCES from its due time, never from now, or it runs that minute twice.
  The daemon ticks only when told: a new path that writes a due time (`scheduled_for`,
  `next_run_at`) or frees a blocked run outside a worker pokes.
- **Claim order is tier, then age.** `claim_pending_run()` orders by `priority`,
  `scheduled_for`, `id` (served by `idx_tasks_claim_priority`, no sort); `claim_due_schedule()`
  ticks nothing while a due on-demand run (priority < 2) is pending and not set aside this
  pass - a run set aside because its lock is held never holds the schedules up.
- **Every execution is a run row.** Dispatched, scheduled (created by the claiming
  scheduled-pool worker in the same transaction that advances `next_run_at` - RUNNING for an
  unmanaged task, a coalesced PENDING run due at the schedule's due time for a managed one,
  which every worker then claims like any pending run) and inline alike; every path settles through `Task_Runner::settle()` or a guarded write of its own
  (kill, abandonment, cancel). A schedule is a `_task_schedules` row and is never a run.
- **Every settle and every claim is GUARDED** on the status it expects (and, for a
  kill or an abandonment, on the worker that held it), so two writers can never both win:
  a run a kill worker settled KILLED keeps that verdict when the task returns, and two
  pools can never both claim one pending row. The run's own writes - `Task_Runner::settle()`
  and every `Task_Instance::flush()` - are guarded on the CLAIM the instance was made under
  (`_claimed_row()`: host, pid, pid start time, abandon count), so a process whose run was
  settled as abandoned can neither overwrite the reaper's verdict nor write onto the retry
  another worker claimed: its first refused write logs and leaves an operator line, it drops
  every later report, and `is_stop_requested()` answers true. An instance is always made
  AFTER its process claimed the row (`Task_Instance::find()` follows every claim); a new run
  path must keep that order.
- **A graceful stop is cooperative.** `request_stop()` only stamps `stop_requested_at`;
  nothing here may act on it for the task. Killing happens only through a kill request,
  carried out by a kill worker, never inside the request that asked.
- **Kills are host-affine and never signal a web request.** A pid names a process on one
  machine, so a kill request carries the target's `worker_host` and only that host's kill
  workers claim it. An inline run inside php-fpm is settled moot, never signalled.
- **Tasks run concurrently, with no automatic lock.** Nothing here may reintroduce a global
  application lock or an automatic per-task site lock: a task body is an enforcement window
  and takes its own `Site_Lock::write()` section per item (below), and serializes any other
  critical section with `RsxLocks`.
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
- **Abandoned-worker recovery is evidence wherever a party can answer; silence only where
  none can.** A claim writes `worker_id` +
  `worker_generation`, `worker_host` and `worker_pid` + `worker_pid_start_time` together
  (`Task_Runner::running_fields()`); wherever they are cleared, all of them are, and
  `worker_seen_at` with them. `rsx:task:process` asks the daemon about every RUNNING run with a
  `worker_id` in ONE `members_alive()` per pool, under that pool's lock: the daemon's own
  generation and not alive -> abandoned at once; an older generation (`known: false`) ->
  this host judges its own runs by pid - `Task::is_run_process()`: the pid must still be
  the process the run recorded (`worker_pid_start_time`, `Lib/Support/Process/Process_Identity`)
  and a task worker of this project (`Task::is_worker_process()`, the command line); never
  bare pid existence, which a reused pid satisfies - and leaves other hosts' to them. A run
  with no `worker_id` (inline) is the same pid + start-time probe on its own host, with no
  command line to hold it to. The kill worker and the /_sys worker evidence ask
  `is_run_process()` too, so a reused pid is never signalled. Every run the tick found alive
  on its own host is stamped `worker_seen_at = NOW(3)` (THE VOUCH). The ONE age-based
  verdict is THE VANISHED HOST: another host's run that rsx-lockd cannot answer for (an
  older generation, or inline), whose latest evidence - `started_at`, `last_heartbeat_at`,
  `last_report_at`, `worker_seen_at`, measured in SQL against `NOW(3)` - is older than
  `rsx.tasks.vanished_host_timeout`, is settled through `settle_abandoned()` like any
  abandonment. A row rsx-lockd answers for (`known: true`) is never judged by age, and no
  other grace period or age rule may be added. The silence limit (and a run's own requested
  timeout) is enforced as a force-kill REQUEST for a live local run.
- **Abandonment is retried; a failure never is.** An abandoned DISPATCHED run goes back to
  PENDING at now + `rsx.tasks.retry.base_seconds` * 2^(n-1) (`abandon_count`) and FAILS on
  the `retry.attempts`-th; an abandoned scheduled or inline run is FAILED. A run that
  returned a failure or threw is settled FAILED and nothing re-runs it. `rsx.tasks.retry` is
  owner-set pacing, not a timeout.
- **Maintenance spawns nothing.** `spawn_worker()` returns false while
  `Framework_Maintenance::is_active()`, for every pool. `rsx:tasks:disable` therefore carries
  out this host's kills in its own process.
- **OFF means nothing starts executing, by any route, and everything queues.** Every path
  that would begin a task body asks `Task::is_enabled()` first, from the database, at the
  moment it would begin: the worker before it joins and before every claim (under its pool
  lock - a task-table read), `spawn_worker()` for the two task pools, `--once`, and
  `internal()`, which queues its run PENDING and waits. There is no bypass flag and no
  internal allowance; a new execution entry must ask too. Recovery is NOT execution and
  never asks: the reaper, `recover_kill_requests()`, the kill pool and `Task_Kill_Worker`
  run while the system is off - `rsx:tasks:disable` on one host depends on the other
  hosts' ticks carrying out their kills.
- **A queued inline run is an ordinary PENDING row.** Its waiter claims it with the same
  guarded PENDING -> RUNNING write a worker uses (after the identity's run lock), so
  whichever of the two wins, the body is entered once; a waiter that died leaves nothing to
  recover. Nothing may mark such a row as owned by its waiter.
- **A kill signals only its target.** `carry_out()` SIGKILLs a pool run's pid only while
  that pid is a task worker of this project by its command line, and an inline run's only
  when it is an artisan process of this project; anything else settles without a signal.
- **The console streams are the RUNNER's.** `Task_Instance::set_console_streams()` is called
  by `Task::internal()`'s fourth argument, which only `Task_Run_Command` (and therefore every
  alias) passes - so an application calling `internal()` prints to nobody's console. The
  streams are display only: every line is recorded on the run either way.
- **Reports are written at a rate, never dropped** - except by a process whose run was taken
  from it (the claim guard, above), which records nothing more. A held report is written by the first
  reporting call or `is_stop_requested()` after `FLUSH_INTERVAL`, by `flush()` or by the
  settle; `is_stop_requested()` READS the row on every call (a stop is seen at once) but
  writes only at the rate, so a per-item stop check is not a per-item write.
  `FLUSH_INTERVAL` is a write rate and must never become a timeout.
- **Task access fails closed.** A task with no `#[Task_Access]` for the caller's realm is
  refused (a named error to a signed-in non-developer, not-found to anyone else); only a
  staff developer bypasses. Every new task surface asks `Task_Gates`, and a list never
  truncates silently - it walks until the page is full. There is no generic way to START a
  task from the browser or the external API, and none may be added.
- **A task is limited in SILENCE, never in run time.** The reaper measures a worker-run task
  from its last sign of life - the latest of `started_at`, `last_heartbeat_at`,
  `last_report_at` - against `rsx.tasks.silent_timeout`; a run that keeps reporting is never
  killed for time. `_tasks.timeout` is NULL unless a dispatch named one, and a named one is
  the only hard limit (from `started_at`, replacing the silence rule for that run). So:
  every NEW reporting call on `Task_Instance` must end in `__reported()`, including its
  early returns; `insert_row()` must never write a default into `timeout`; and none of this
  is licence to add a timeout anywhere else (the no-timeout mandate).
- **A run takes ALL its run locks or none.** `Task_Lock_Set` is the only way a run lock is
  taken: a worker's claim makes non-blocking tries (THE RULE) and lets go of every one the
  moment one is refused, so a waiting run never holds part of its locks; an inline run waits
  for each in name order. A grouped member takes no lock of its own identity - one run in
  the group is implied by the group's lock. Nothing reserves a lock for a waiting run: the
  claim walks due pending runs most overdue first and starts the first whose locks are all
  free, and skipped runs are looked at again after every settle and at every tick. Every
  branch that asks "which rows are this identity's" goes through the pending identity
  (`__identity_query()`), and every branch that asks "what excludes this run" through
  `run_lock_identities()`; neither is inferred from `mode` alone.
- **A managed schedule tick is a call.** It enqueues through the same coalescing path as
  `dispatch()`, so every marker applies to it: a tick while the identity runs is its one
  pending follow-up, never folded into the running run and never a second running instance.
- **What a pending run waits for is DERIVED, never stored.** `Task_Waiting` reads the row,
  the lock daemon and the running rows when asked; no column may be added to cache it, and no
  path may write "blocked by" state onto a run - a dead holder must leave nothing to clean
  up. A list presents its runs through ONE `Task_Waiting` (`to_status_arrays()`), never one
  per row. Only the holder's run id reaches a viewer.
- **An explicit next run survives everything but its own claim.** `set_next_run()` writes
  `next_run_at`; reconciliation touches that column only for a changed expression, resolution or timezone, or
  `--force-scheduled`, and the worker write that advances it (the tick, whether it creates a
  RUNNING run or enqueues a pending one) is guarded on the `next_run_at` the worker read. A new write to
  that column must be guarded the same way.
- **A build never dispatches.** `#[On_Build]` work is dispatched only by the tick
  (`Task_On_Build::dispatch_for_build()`), which a build pokes; nothing in `rsx.rebuilt` or the
  manifest may write a run - a build may have no database, run inside a request, or run while
  the task system is off.
- Attributes are reflection-only — never define `#[Task]`/`#[Schedule]`/`#[Command]`/`#[Task_Access]`/`#[On_Build]` classes.

## Site locks in a task body

Every task body runs inside a site lock ENFORCEMENT WINDOW (`Task_Runner::_run_body()`, which
every runner path and the test harness's `__run_task_method()` go through): a write to a
site-scoped model fatals unless the task holds that site's write lock - one
`Site_Lock::write($site_id, fn () => ...)` section per item, re-reading the record inside - or
is in a `Site_Lock::bypass()` / `bypass_all_sites()` with a reason. A task takes no automatic
lock and no automatic transaction, and `#[Writer]` / `#[Reader]` / `#[Manual_Lock]` on a
`#[Task]` fail the manifest build (SITE-LOCK-01). A transaction the body leaves open is rolled back there and
reported on the run. The `Rsx_Artisan` spawn mandate stands regardless.

## See also

`rsx:man tasks` · `rsx:man task_commands` · `rsx:man site_locks` · `rsx:man locks` · skills `rspade:background-tasks`, `rspade:task-access`, `rspade:locks-and-subprocesses`
