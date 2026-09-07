# Test catalog: tasks

Status legend: `implemented` | `deferred` (reason) | `blocked` (see issues_encountered.md) | `planned`.
Type: php / cli. Last updated: 2026-08-31.

## Task_Definition_Test (php, default isolation) - metadata, no commits

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-def-01..04 | Task_Status PENDING/RUNNING/COMPLETED/FAILED constants defined | - | constants exist | implemented |
| task-def-06 | status value() returns string | status | string | implemented |
| task-def-07..10 | is_pending/running/completed/failed predicates | status | correct bool | implemented |
| task-def-12 | status to-string | status | label | implemented |
| task-def-13 | status rejects invalid value | bad value | throws | implemented |
| task-def-14 | cron parser accepts daily expr | "0 2 * * *" | valid | implemented |
| task-def-15..16 | cron rejects invalid expr / too few parts | bad cron | throws | implemented |
| task-def-17..18 | cron is_valid true/false | cron | correct bool | implemented |
| task-def-19 | cron next-run returns integer | cron | int timestamp | implemented |
| task-def-20 | cron next-run >= 1 minute ahead | cron | future | implemented |
| task-def-21 | get_scheduled_tasks returns array | - | array | implemented |
| task-def-22 | scheduled tasks include session cleanup | - | present | implemented |
| task-def-23 | each scheduled task has required keys | - | keys present | implemented |
| task-def-24 | scheduled cron expressions all valid | - | all valid | implemented |
| task-def-25 | Task_Instance getters (immediate mode) | new instance | correct | implemented |
| task-def-26 | Task_Instance initial status pending | new | pending | implemented |
| task-def-27..29 | mark_started/completed/failed change status | transitions | running/completed/failed | implemented |
| task-def-30 | info() appends log entry | info(msg) | log grows | implemented |
| task-def-31 | error() log includes level | error(msg) | level recorded | implemented |
| task-def-32..33 | get_temp_dir creates dir; stable on 2nd call | - | dir exists, same | implemented |
| task-def-34 | internal() throws for unknown service | bad service | throws | implemented |
| task-def-35 | internal() throws for missing #[Task] | non-task method | throws | implemented |

## Task_Dispatch_Test (php, $requires_db_reset + no-tx) - enqueue/status persistence

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-disp-01 | dispatch throws for unknown service | bad service | throws | implemented |
| task-disp-02 | dispatch throws for method w/o #[Task] | non-task | throws | implemented |
| task-disp-03 | dispatch returns integer id | valid task | int id | implemented |
| task-disp-04 | dispatch creates row in _tasks table | valid | row exists | implemented |
| task-disp-05 | dispatch stores pending status | valid | status pending | implemented |
| task-disp-06 | dispatch stores class + method | valid | columns set | implemented |
| task-disp-07 | dispatch stores params as JSON | params | json column | implemented |
| task-disp-08 | dispatch uses default queue | valid | default queue | implemented |
| task-disp-09 | dispatch respects custom queue option | queue opt | honored | implemented |
| task-disp-10 | status returns array for known id | id | array | implemented |
| task-disp-11 | status returns null for unknown id | bad id | null | implemented |
| task-disp-12 | status contains expected keys | id | keys | implemented |
| task-disp-13 | status field pending after dispatch | id | pending | implemented |
| task-disp-14 | status id matches dispatched task | id | match | implemented |
| task-disp-15 | status result null for pending task | id | null result | implemented |
| task-disp-16 | status logs empty array for pending | id | [] | implemented |
| task-disp-17 | internal() returns task return value | echo task | value | implemented |
| task-disp-18 | internal() re-throws task exception | failing task | throws | implemented |
| task-disp-19 | internal() with empty params returns empty echo | empty | [] | implemented |

## Deferred / planned

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| task-d-01 | full pending->running->completed lifecycle persisted in DB | php | now unblocked (ISSUE-1 fixed); worker-driven lifecycle test not yet authored | planned |
| task-d-02 | scheduled processor `rsx:task:process` runs due tasks | cli | needs cron processor invocation + time control | deferred |
| task-d-03 | stuck-task detection (DEAD worker arm, cleanup_stuck_after) | php | needs time manipulation + a committed running row; the TIMEOUT arm is now covered by Task_Timeout_Reaper_Test | deferred |
| task-d-04 | `rsx:task:list` / `rsx:task:run` CLI output | cli | command-output test; lower priority | planned |
| task-d-05 | queue concurrency enforcement | php | needs worker concurrency simulation | deferred |
| task-d-06 | temp directory auto-cleanup timing | php | time-dependent | deferred |
| task-d-07 | `set_status()` key-value tracking, `set_temp_expiration()` | php | methods documented but absent (see issues) | deferred |

## Task_Killer_Test (php) - force-kill running tasks (rsx:tasks:kill / kill-all)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-kill-01 | on-demand RUNNING task: SIGTERM->SIGKILL the worker, row -> KILLED + status_reason, worker_pid cleared | real detached victim pid on a RUNNING row | outcome 'killed', process dead, status KILLED, reason recorded | implemented |
| task-kill-02 | cron tracker (next_run_at set) recycles to PENDING (schedule survives), not terminal | RUNNING cron tracker row | outcome 'recycled', status PENDING, next_run_at kept, reason recorded | implemented |
| task-kill-03 | rsx:tasks:kill-all requires --explanation | Artisan::call with no --explanation | non-zero exit | implemented |

## Task_Timeout_Reaper_Test (php) - execution-timeout arm of rsx:task:process

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-timeout-01 | a LIVE worker past the row's own timeout is killed and settled KILLED | RUNNING row, live victim pid, timeout 60, started 120s ago | process dead, status KILLED, status_reason "timed out ... cap 60s", worker_pid cleared, [TASK TIMEOUT] logged | implemented |
| task-timeout-02 | a LIVE worker inside its cap is untouched | RUNNING row, timeout 600, started 10s ago | process alive, status RUNNING, no status_reason, nothing logged | implemented |
| task-timeout-03 | a row with no timeout inherits rsx.tasks.default_timeout | timeout NULL, default 30, started 90s ago | killed, status_reason names cap 30s | implemented |
| task-timeout-04 | no row timeout AND no configured default = uncapped | timeout NULL, default 0, started 86400s ago | process alive, status RUNNING | implemented |
| task-timeout-05 | a timed-out cron TRACKER recycles to PENDING, schedule survives | RUNNING tracker (real manifest schedule identity), timeout 60, started 300s ago | status PENDING, next_run_at kept, status_reason recorded | implemented |

## Task_Failure_Recycle_Test (php) - a cron tracker is never permanently terminal

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-recycle-01 | a tracker whose task THROWS is recycled to PENDING, never FAILED, with the failure recorded | due tracker on a fixture task that throws, driven by rsx:task:worker | status PENDING, worker_pid null, error text, status_reason "failed (recycled): ...", consecutive_failures 1, last_error_at set, completed_at still null, next_run_at advanced | implemented |
| task-recycle-02 | a task raising an \Error (TypeError) records its message - only the worker's Throwable catch makes it visible | due tracker on a fixture task that throws TypeError | status PENDING, error contains the TypeError message, consecutive_failures 1, last_error_at set | implemented |
| task-recycle-03 | the one-shot contract is unchanged: an on-demand row that throws stays terminal | on-demand row on a throwing fixture task | status FAILED, completed_at set, last_error_at set, consecutive_failures 1, status_reason null | implemented |
| task-recycle-04 | a success clears the failure streak and stamps the last successful run | due tracker with consecutive_failures 3 on a succeeding fixture task | status PENDING, consecutive_failures 0, completed_at set, result recorded | implemented |
| task-recycle-05 | the rsx:task:process backstop revives a tracker already stranded terminal, preserving its failure record | FAILED + KILLED trackers on real manifest schedule identities | both PENDING, worker_pid null, error + consecutive_failures preserved, [STRANDED SCHEDULE] reported | implemented |

## Task_Schedule_Visibility_Test (php, default isolation) - a failing schedule must be visible

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-vis-01 | rsx:health WARNs at rsx.tasks.failing_schedule_warn_after, naming the offender and its reason | tracker planted at the configured threshold | status WARN, detail contains the identity, the streak count and the failure excerpt | implemented |
| task-vis-02 | a streak below the threshold is still just retrying, not a health finding | tracker planted at threshold - 1 | status OK, "no schedule failing repeatedly" | implemented |
| task-vis-03 | rsx:tasks:list renders the Schedules table with next run / last success / failures / last error | trackers with and without a failure streak | table lists every tracker; failure columns blank when the streak is 0 | planned |

## Task_Command_Definition_Test (php, default isolation) - #[Command] discovery and registration

Drives `Task_Command_ManifestSupport::process()` over SYNTHETIC manifest data: a bad
declaration is proved to break the build without one ever existing in the tree.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-cmd-01 | a valid declaration is baked into data['task_commands'] with class/method/description | synthetic #[Task]+#[Command] | one row, correct target | implemented |
| task-cmd-02 | named attribute arguments (name:, description:) are accepted | synthetic named args | same row | implemented |
| task-cmd-03 | a #[Task] with no #[Command] produces no row | synthetic #[Task] only | empty table | implemented |
| task-cmd-04 | FATAL: #[Command] without #[Task] | synthetic #[Command] alone | RuntimeException "may only annotate a #[Task] method" | implemented |
| task-cmd-05 | FATAL: name with no prefix segment | 'import' | RuntimeException "needs a 'prefix:name' segment" | implemented |
| task-cmd-06 | FATAL: empty prefix or empty suffix | ':import', 'myapp:' | same RuntimeException | implemented |
| task-cmd-07 | FATAL: name in the framework's namespace | 'rsx:import' | RuntimeException "The 'rsx:' prefix belongs to the framework" | implemented |
| task-cmd-08 | FATAL: collision with another #[Command] | two declarations, one name | RuntimeException "One command name names exactly one task" | implemented |
| task-cmd-09 | FATAL: collision with a framework command | 'db:wipe' (a real Commands/Restricted signature) | RuntimeException "already a framework command" | implemented |
| task-cmd-10 | FATAL: missing / empty description | one arg; '   ' | RuntimeException "the description and is required" | implemented |
| task-cmd-11 | FATAL: missing name | no arguments | RuntimeException "the command name and is required" | implemented |
| task-cmd-12 | the REAL baked table names the fixture tasks | this build's manifest | rsx_test:echo -> Test_Echo_Service::echo_params, rsx_test:fail -> always_fail | implemented |
| task-cmd-13 | an alias carries the name, description and --debug of its declaration | new Task_Alias_Command(...) | getName/getDescription/definition | implemented |
| task-cmd-14 | an ABSENT table registers nothing and never fatals | task_commands unset from the live manifest | registrar adds no command | implemented |
| task-cmd-15 | the fixture aliases really are registered with artisan | the running console application | both names present | implemented |

## Task_Console_Sink_Test (php, default isolation) - the live console sink

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-sink-01 | no sink means nothing is written anywhere but the in-memory log | info() with no sink | log grows, nothing written | implemented |
| task-sink-02 | every line reaches the sink and the log identically | info/error/debug into php://memory | stream == implode("\n", logs) . "\n" | implemented |
| task-sink-03 | lines arrive LIVE, not as one dump at the end | two info() calls, read between | first visible before second exists | implemented |
| task-sink-04 | detaching the sink stops the echo without dropping a log line | set_console_sink(null) | second line logged, not written | implemented |
| task-sink-05 | update_progress formats as "[45%] message" on both channels | update_progress(45, 'Halfway') | "[info] [45%] Halfway" | implemented |
| task-sink-06 | update_progress with no message is the percent alone | update_progress(7) | "[info] [7%]" | implemented |
| task-sink-07 | update_progress clamps to 0..100 | -5, 250 | "[0%]", "[100%]" | implemented |
| task-sink-08 | Task::internal() WITHOUT a sink writes nothing - the application-caller default | internal(3 args) | value returned, stream empty | implemented |
| task-sink-09 | Task::internal() WITH a sink narrates the run | internal(..., $sink) | info lines on the stream | implemented |
| task-sink-10 | a failure narrates its [error] line before internal() rethrows | always_fail with a sink | "[error] Task failed: ..." then throw | implemented |

## Task_Command_Cli_Test (cli, no transactions) - the alias driven for real

Spawns artisan with stdout and stderr redirected to separate files (the two streams being
kept apart IS the property under test - hence the file-level @ARTISAN-SPAWN-01-EXCEPTION).

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-cmd-cli-01 | stdout is the return value as JSON and nothing else | rsx_test:echo --a=1 | parses as JSON, equals the task's value, exit 0 | implemented |
| task-cmd-cli-02 | the alias IS rsx:task:run - identical stdout, byte for byte | alias vs rsx:task:run | equal | implemented |
| task-cmd-cli-03 | --debug wraps the value | rsx_test:echo --debug | {success:true, result:...} | implemented |
| task-cmd-cli-04 | stderr carries the [info] narration | rsx_test:echo --a=1 | both fixture info lines | implemented |
| task-cmd-cli-05 | -q empties stderr and never touches stdout | rsx_test:echo -q | stderr '', stdout unchanged | implemented |
| task-cmd-cli-06 | a throwing task exits 1 with the JSON error on stdout and [error] on stderr | rsx_test:fail | exit 1, {success:false,error:...}, "[error] Task failed: ..." | implemented |
| task-cmd-cli-07 | rsx:task:list shows the COMMAND column, '-' for a task with none | rsx:task:list | header + rsx_test:echo beside echo_params; dash beside cleanup_request_log | implemented |
