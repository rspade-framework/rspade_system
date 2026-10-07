# Concern: tasks

## Domain overview & applicability

The task system: services extend `Rsx_Service_Abstract` and expose `#[Task]` methods
(optionally `#[Schedule]`d with cron, `#[Exclusive]` / `#[Debounce]` for a single-instance
identity, `#[Command]` for an artisan name). Every RUN is one `_tasks` row
(`Task_Run_Model`) - dispatched (`Task::dispatch()`, claimed by a pool worker), scheduled (a
`_task_schedules` row coming due, claimed by the scheduled pool) or inline (`Task::internal()`,
`rsx:task:run`, a `#[Command]`). A run moves pending -> running -> completed / failed /
stopped / killed / cancelled; its return value is its return code. While it runs the task
REPORTS through its `Task_Instance` (status, progress, ETA, state, messages, output lines,
attachments, summary), and outside code reads those reports, and stops, cancels, kills or
reruns the run, through `Task_Run_Model`. Backs all background and scheduled work in the
framework.

## Source files

- `app/RSpade/Core/Task/Task.php` - dispatch/internal, scheduled-task discovery,
  `spawn_worker($pool)` and the process-level spawn switch `spawn_workers()`
- `Task_Run_Model(_Abstract).php` - the run: lifecycle vocabulary, report readers,
  `request_stop()` / `force_stop()` / `force_kill()` / `cancel()` / `rerun()`
- `Task_Instance.php` - the per-run handle a task reports through (coalesced writes)
- `Task_Runner.php` + `Task_Run_Outcome.php` - insert, execute (echo capture), settle; the
  return contract
- `Task_Concurrency.php` + `Task_Lock.php` - identities, coalescing enqueue, run locks
- `Task_Pool.php` - the three pools' rsx-lockd connection (lock, membership, count, liveness,
  stats), over `Core/Locks/Lockd_Connection.php`
- `Task_Kill_Worker.php` + `Task_Kill_Request_Model(_Abstract).php` - force stops and kills
- `Task_Schedule_Model(_Abstract).php`, `Task_Attachment_Model(_Abstract).php`, `Cron_Parser.php`
- `Task_Notify.php` + the three topics (`Task_Changed_Topic`, `Task_Output_Topic`,
  `Task_List_Changed_Topic`); `Task_Gates.php` (deny-by-default view/control gates)
- `Task_Retention_Service.php`, `Task_Health_Checks.php`
- Commands: `rsx:task:worker`, `rsx:task:process`, `rsx:task:run`, `rsx:task:killer`,
  `rsx:task:list`, `rsx:tasks:list`, `rsx:tasks:stop`, `rsx:tasks:cancel`,
  `rsx:tasks:kill-all`
- `Task_Command_ManifestSupport.php` - `#[Command]` discovery and the build-time FATALs;
  `Task_Command_Registrar.php` + `Task_Alias_Command.php` - registration, and the one command
  class every alias is an instance of
- Migration `system/database/migrations/2026_10_07_070855_rebuild_task_tables_for_runs_reports_and_pools.php`

Test-only fixtures in `tasks/php/`:

- `Test_Echo_Service` - side-effect-free tasks with `#[Command]`s (`rsx_test:echo` writes its
  params to stdout and narrates on stderr, `rsx_test:fail` throws, `rsx_test:exit` returns the
  `--code` given) so the cli tests have real registered aliases to drive.
- `Task_Exec_Fixture_Service` - markers recording execution order, every return shape, a
  stoppable loop, throwing and printing tasks.
- `Task_Concurrency_Fixture_Service` - `#[Exclusive]`, `#[Debounce(30)]` and plain tasks.
- `Task_Lock_Holder` - another PROCESS holding a named run lock (RsxLocks is re-entrant within a
  process, so "running elsewhere" needs a second one): holds until released, or releases the
  moment a writer queues behind it.

None carries a `#[Schedule]`; a test that needs a schedule writes a `_task_schedules` row.
They exist only while the suite runs (the test trees enter the manifest only then).

## Man page(s)

- `man/tasks.txt`
- `man/task_commands.txt` - `#[Command]` and the console output contract

## Testable surface

- Definition/metadata: the run model's status/origin/pool vocabulary, `Cron_Parser`,
  scheduled-task discovery, the `Task_Instance` handle and temp dir, internal() guard errors.
  (php - default isolation; `Task_Definition_Test`)
- Dispatch and internal(): the pending row's columns and options, the dispatcher identity, the
  settled inline row, rethrow after settle. (php - commits; `Task_Dispatch_Test`)
- The return contract and the console exit code. (php; `Task_Return_Contract_Test`)
- Single-instance identities: coalescing primitives, dispatch coalescing, the run lock held by
  another process for an inline run and a worker claim. (php; `Task_Concurrency_Test`,
  `Task_Debounce_Identity_Test`)
- Pools: caps, what each pool claims (scheduled takes dispatched work first), schedule claims
  creating their run rows, coalesced schedule ticks, a claim handed back. (php;
  `Task_Pools_Test`) Admission and spawning (`Task_Spawn_Admission_Test`); the rsx-lockd client
  itself (`Task_Pool_Test`).
- The worker loop and the abandoned-run verdicts (`Task_Worker_Execution_Test`); retry pacing of
  an abandoned run (`Task_Abandonment_Retry_Test`); the timeout arm requesting a force kill
  (`Task_Timeout_Reaper_Test`).
- Schedules: reconcile against the manifest (`Task_Schedule_Reconcile_Test`), run rows and
  statistics (`Task_Schedule_Run_Test`), visibility in rsx:health and rsx:tasks:list
  (`Task_Schedule_Visibility_Test`).
- Reports and output: every report, coalescing, readers, `to_status_array()`
  (`Task_Reports_Test`); output rows, console streams, echo capture (`Task_Output_Test`);
  attachments and their blob references (`Task_Attachments_Test`).
- Lifecycle operations and their commands (`Task_Lifecycle_Test`); kill requests carried out
  against a real process (`Task_Kill_Worker_Test`).
- Gates (`Task_Gates_Test`), realtime frames (`Task_Notify_Test`), retention
  (`Task_Retention_Test`).
- The `#[Command]` declaration and registration (`Task_Command_Definition_Test`) and the alias
  driven for real - stdout, stderr, -q, exit codes, the recorded run (cli;
  `Task_Command_Cli_Test`).

Tests that call a framework `#[Task]` method directly elsewhere in the suite use
`Rsx_Test_Abstract::__run_task_method()`, which runs it as a real inline run and returns the
state it reported.

## Documents

- `test_catalog.md` - full catalog (implemented + planned + deferred).
