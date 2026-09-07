# Issues Encountered - Tasks Concern

## ISSUE-1: Task::dispatch() and Task::status() reference wrong table name [RESOLVED 2026-06-17]

**Severity:** Bug (runtime failure) - FIXED

**Resolution:** The code referenced a non-existent `_task_queue` table; the
migrated (and only) table is `_tasks`, whose columns already matched exactly what
the code reads/writes. Repointed all 25 `_task_queue` references to `_tasks`
across `Task.php`, `Task_Instance.php`, `Task_Process_Command.php`, and
`Task_Worker_Command.php` (`Cleanup_Service.php` already used `_tasks`). No
migration/schema change was needed. The man page
`database_schema_architecture.txt` and `Core/Task/CLAUDE.md` were corrected to
say `_tasks`. The previously-skipped `test_dispatch_*` / `test_status_*` tests
are now implemented and passing (Task_Dispatch_Test: 19/19, full tasks: 54/54).

**File:** `system/app/RSpade/Core/Task/Task.php`, lines 252 and 267

**Description:**
`Task::dispatch()` inserts into `_task_queue` and `Task::status()` queries from
`_task_queue`, but the migration at
`system/database/migrations/2025_11_16_200145_create_tasks_table.php` creates the
table as `_tasks`.  Neither the production DB nor the test DB has a `_task_queue`
table.  `Task::dispatch()` throws a DB error at runtime.

Similarly, `Task_Instance` (lines that reference `_task_queue`) and the man page's
TASK DATABASE SCHEMA section both document field names that do not match the actual
`_tasks` DDL (e.g., the migration has `logs TEXT` but `Task.php` reads `$row->logs`
which matches; however the man page lists a different set of columns including
`service_name`, `task_name`, `queue_name`, `execution_mode`, `status_data`,
`status_log`, etc., none of which exist in the actual table).

**Action taken:** Skipped all `test_dispatch_*` and `test_status_*` DB-exercising
tests with `__skip()` and a pointer to this file.  No code was changed.

**Resolution required:** Either rename `_task_queue` references in `Task.php` /
`Task_Instance.php` to `_tasks`, OR add a migration to create `_task_queue` with
the schema that `Task.php` expects.

---

## ISSUE-2: Man page Task_Instance API does not match actual code

**Severity:** Documentation error

**File:** `system/app/RSpade/man/tasks.txt`, TASK INSTANCE API section

**Description:**
The man page documents the following Task_Instance methods that do not exist:

- `$task->log($message)` documented as single-argument.
  Actual signature: `log(string $level, string $message)`.
  The man page should document `$task->info($message)`, `$task->error($message)`,
  and `$task->debug($message)` instead (these call `log()` with a fixed level).

- `$task->set_status($key, $value)` - does not exist.
  Closest equivalent: `Task_Instance::set_result()` (sets full result, not key-value).
  The status_data/progress tracking described does not exist in the current code.

- `$task->get_temp_directory()` - does not exist.
  Actual method: `$task->get_temp_dir()`.

- `$task->set_temp_expiration($seconds)` - does not exist.
  No equivalent method found in `Task_Instance`.

**Action taken:** No code changed.  Man page fixes for the two clear naming errors
(`log` -> `info/error/debug` and `get_temp_directory` -> `get_temp_dir`) are noted
here; the `set_status` and `set_temp_expiration` discrepancies are more ambiguous
(they might represent planned-but-not-yet-implemented API).

---

## ISSUE-3: Man page TASK DATABASE SCHEMA describes non-existent columns

**Severity:** Documentation error

**File:** `system/app/RSpade/man/tasks.txt`, TASK DATABASE SCHEMA section

**Description:**
The man page `_tasks` table schema lists columns that do not exist in the migration:
- `service_name`, `task_name`, `queue_name`, `execution_mode` - do not exist
  (actual: `class`, `method`, `queue`, no `execution_mode`)
- `status_data` - does not exist (actual: no key-value status storage)
- `status_log` - does not exist (actual: `logs`)
- `scheduled_at` - does not exist (actual: `scheduled_for`)
- `has_temp_directory`, `temp_expires_at` - do not exist

The actual `_tasks` (and `_task_queue` per code) table has: `id`, `class`, `method`,
`queue`, `status`, `params`, `result`, `logs`, `error`, `scheduled_for`,
`next_run_at`, `started_at`, `completed_at`, `last_heartbeat_at`, `timeout`,
`worker_pid`, `lock_key`, `created_at`, `updated_at`.

**Action taken:** No code changed.  This is a significant schema divergence; it is
likely the man page was written for a planned schema that was later revised during
implementation.

---

## ISSUE-4: Task::status() returns array, not Task_Status object

**Severity:** Documentation error

**File:** `system/app/RSpade/man/tasks.txt`, "Check Task Status" section

**Description:**
The man page says `Task::status()` returns a `Task_Status` object with properties
like `$status->status`, `$status->get($key, $default)`, `$status->log`,
`$status->result`, `$status->started_at`.

Actual: `Task::status()` returns `array|null`.  `Task_Status` is a value object
representing a status string constant, not a rich status container.

**Action taken:** No code changed.  This is consistent with ISSUE-2 and ISSUE-3
(the man page describes a richer planned API that was simplified in implementation).
