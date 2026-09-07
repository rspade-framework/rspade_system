# Concern: tasks

## Domain overview & applicability

The task system: services extend `Rsx_Service_Abstract` and expose `#[Task]`
methods (optionally `#[Schedule]`'d with cron) that are dispatched to a queue,
executed by a worker/cron processor, and tracked through status transitions
(pending -> running -> completed/failed/stuck) with logs and a captured return
value. `Task::dispatch()` enqueues, `Task::internal()` runs synchronously,
`Task::status()` reads back state, `Task_Instance` is the per-run handle. Backs
all background/scheduled work in the framework.

## Source files

- `app/RSpade/Core/Task/Task.php` - dispatch/internal/status, scheduled-task discovery
- `app/RSpade/Core/Task/Task_Instance.php` - per-run lifecycle, logs, temp dir
- `app/RSpade/Core/Task/Task_Status.php` (status constants/validation), `Cron_Parser`
- `app/RSpade/Core/Task/Rsx_Service_Abstract.php`, the `#[Task]`/`#[Schedule]` attributes
- `_tasks` table migration; `rsx:task:*` commands; `Cleanup_Service`
- `app/RSpade/Core/Task/Task_Command_ManifestSupport.php` - `#[Command]` discovery and the
  five build-time FATALs; `Task_Command_Registrar.php` + `Task_Alias_Command.php` - the
  registration hook in `app/Console/Kernel.php`, and the one command class every alias is
  an instance of
- Test-only fixture: `tasks/php/Test_Echo_Service.php` (2 side-effect-free `#[Task]`
  methods, no `#[Schedule]` so the cron processor never picks it up). Both carry a
  `#[Command]` (`rsx_test:echo`, `rsx_test:fail`) so the cli tests have real registered
  aliases to drive - one returning a value, one throwing - without touching a database.
  They exist only in the monorepo: `bin/publish` ships no framework tests.

## Man page(s)

- `man/tasks.txt` (reconciled - several fixes; see issues_encountered.md)
- `man/task_commands.txt` - `#[Command]` and the console output contract

## Testable surface

- Definition/metadata: `Task_Status` constants + validation, `Cron_Parser`
  validity/next-run, scheduled-task discovery via `#[Task]`/`#[Schedule]`,
  `Task_Instance` lifecycle transitions + logging + temp dir, dispatch/internal
  guard errors (unknown service, missing attribute). (php - default isolation)
- Dispatch/status persistence: enqueue creates a row, stores class/method/params/
  status/queue, `status()` reads it back. (php - **commits**, so
  `$requires_db_reset = true` + `$use_database_transactions = false`)
  (Resolved 2026-06-17: the code referenced a non-existent `_task_queue` table;
  fixed to `_tasks`. The dispatch/status tests are now implemented and passing.
  See issues_encountered.md ISSUE-1.)
- `#[Command]` declaration: the five manifest-build FATALs, the baked `task_commands`
  table, alias construction, and registration from a present/absent table. (php - synthetic
  manifest data, default isolation)
- The console sink: `Task_Instance::set_console_sink()` echoing every log line live while
  the in-memory log is unchanged, the `[NN%]` `update_progress` format, and
  `Task::internal()`'s runner-only fourth argument. (php - default isolation)
- The alias driven for real: stdout the value, stderr the narration, `-q`, `--debug`, a
  throwing task's exit 1, and the `rsx:task:list` COMMAND column. (cli - spawns artisan
  with the two streams redirected apart)
- Full lifecycle execution by the worker/cron processor (`rsx:task:process`),
  stuck-task detection, CLI output of `rsx:task:list`/`run`. (cli/integration - deferred)

## Documents

- `test_catalog.md` - full catalog (implemented + blocked + deferred).
- `issues_encountered.md` - the `_task_queue` bug + man-page divergences.
