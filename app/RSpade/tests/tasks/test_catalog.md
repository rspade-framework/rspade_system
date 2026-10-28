# Test catalog: tasks

Status legend: `implemented` | `deferred` (reason) | `blocked` (reason) | `planned`.
Type: php / cli (given per section). Last updated: 2026-10-11.

## Task_Definition_Test (php, default isolation) - vocabulary, cron, discovery, the instance handle

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-def-01 | the seven run statuses and their constants | Task_Run_Model::$enums['status_id'] | PENDING..CANCELLED = 1..7 | implemented |
| task-def-02 | only pending and running are live; the enum's terminal flag agrees | each status | is_live / is_terminal / 'terminal' | implemented |
| task-def-03 | origin, pool constants and the pool roster | constants | 1/2/3, 1/2, on_demand/scheduled/kill | implemented |
| task-def-04 | cron parser accepts a daily expression | "0 3 * * *" | expression kept | implemented |
| task-def-05..06 | cron rejects an invalid expression / too few parts | bad cron | throws | implemented |
| task-def-07..08 | cron is_valid true/false | cron | correct bool | implemented |
| task-def-09..10 | next run is an integer, in the future | cron | int > now | implemented |
| task-def-11..14 | human phrases: accepted, match their cron, phrase kept, out-of-range rejected | phrases | equal next runs; throws | implemented |
| task-def-15..18 | get_scheduled_tasks(): array, includes session cleanup, class/method/cron keys, valid crons | manifest | as described | implemented |
| task-def-19 | Task_Instance has no public constructor; a missing run has no instance | reflection; find(missing) | private; null | implemented |
| task-def-20 | the instance reads its row | inline row | class, method, params, id; for_row() agrees | implemented |
| task-def-21..22 | temp dir created per run, stable, removed by cleanup | get_temp_dir() twice | same dir, task_<id>, gone after cleanup | implemented |
| task-def-23..24 | internal() throws for an unknown service / a method without #[Task] | bad names | throws | implemented |

## Task_Dispatch_Test (php, $requires_db_reset + no-tx) - the pending row, and internal()

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-disp-01..02 | dispatch throws for an unknown service / a method without #[Task] | bad names | throws | implemented |
| task-disp-03 | an unknown option is refused and nothing is enqueued | ['queue' => ...] | throws naming the options; row count unchanged | implemented |
| task-disp-03b | scheduled_for on an #[Exclusive]/#[Debounce] task is refused and nothing is enqueued | exclusive fixture, scheduled_for 2030 | throws; row count unchanged | implemented |
| task-disp-04 | dispatch returns the id of exactly one new row | echo task | int id, +1 row | implemented |
| task-disp-05 | the row: PENDING, Dispatched, FQCN, params + params_hash, due now, NULL timeout (no hard limit unless asked for), no worker | params | each column | implemented |
| task-disp-06 | timeout and a future scheduled_for are stored | options | 45; the given moment | implemented |
| task-disp-07 | the dispatcher identity and site are recorded | signed out; acting as user 1 | null pair; actor id + type-ref id, site 1 | implemented |
| task-disp-08 | internal() returns the settled inline run | echo task | COMPLETED, Inline, code 0, this pid, no worker_id, state on the run | implemented |
| task-disp-09 | a throwing task is settled FAILED before internal() rethrows | always_fail | throws; FAILED, code 1, "Exception: ..." | implemented |
| task-disp-10 | a returned failure code is returned, not thrown | exit_with code 4 | FAILED, code 4 | implemented |

## Task_Return_Contract_Test (php, default isolation) - the return value is the return code

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-ret-01 | null, true, 0 are success | returns_kind | COMPLETED, 0, no error | implemented |
| task-ret-02 | false is failure code 1 | false | FAILED 1 "returned false"; "Task failed: ..." stderr line | implemented |
| task-ret-03 | any other integer is its own failure code | 7, -3, 300 | FAILED with that code | implemented |
| task-ret-04 | any other type is failure code 1 naming the type | array, string, '0', float, object | FAILED 1 "returned <type>" | implemented |
| task-ret-05 | a throw is failure code 1 with "Class: message" | Exception, TypeError | rethrown; FAILED 1 | implemented |
| task-ret-06 | the console exit code clamps to 1..255 | Task_Run_Outcome::exit_code() | 0, 7, 255; 256/-3/array/throw -> 1 | implemented |

## Task_Concurrency_Test (php, default isolation) - the coalescing primitives

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-conc-01 | policy read from #[Exclusive] / #[Debounce(30)] / none | fixture methods | mode + delay; is_managed | implemented |
| task-conc-02 | params_hash is canonical | key orders, list orders | key order ignored, list order kept, sha256 | implemented |
| task-conc-03 | identity: class::method (exclusive), + params_hash (debounce), null (unmanaged); lock name | hashes | as described | implemented |
| task-conc-04 | enqueue_coalesced keeps one pending row per identity | three enqueues | same id; one pending row | implemented |
| task-conc-05 | an unmanaged enqueue is an impossible call | plain_task | throws | implemented |
| task-conc-06 | an exclusive enqueue is due now | exclusive | scheduled_for <= now | implemented |
| task-conc-07 | a debounce enqueue waits delay since the identity's last completion | completed run now | ~30 s ahead; another parameter set due now | implemented |
| task-conc-08 | a completion re-anchors the pending run to now + delay | pending in the past | ~30 s ahead | implemented |
| task-conc-09 | run lock acquire / release; refused for an unmanaged task | try_acquire_run_lock | in use while held, free after; throws | implemented |

## Task_Debounce_Identity_Test (php, default isolation) - one running + one pending per identity, however a run starts

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-ident-01 | #[Exclusive] coalesces across params | two dispatches, different params | same id, first params kept | implemented |
| task-ident-02 | #[Debounce] coalesces per parameter set | same set in two key orders; another set | same id; different id | implemented |
| task-ident-03 | an unmanaged task never coalesces | two dispatches | two rows | implemented |
| task-ident-04 | at most one running + one pending | pending set RUNNING; dispatch twice | a new follow-up, then that follow-up again | implemented |
| task-ident-05 | the run lock is per identity | another process holds parameter set A | A not acquirable; B acquirable | implemented |
| task-ident-06 | an inline run waits for the running instance | Task_Lock_Holder releases when it sees a waiter; Task::internal() | the run completed and started after the release | implemented |
| task-ident-07 | a worker sets aside an identity running elsewhere, and claims it once free | holder holds; worker; release; worker | PENDING, then COMPLETED | implemented |

## Task_Exclusive_Group_Test (php, default isolation) - #[Exclusive('group')]: one running across several methods

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-grp-01 | the group is read from the attribute | the two grouped fixture methods, a bare #[Exclusive], #[Debounce], a plain task | group name for the two; null for the rest | implemented |
| task-grp-02 | every member shares the group's run lock | another process holding the group lock | `is_group_running()` true without acquiring; neither member can take the run lock; a task outside the group can; free again after release | implemented |
| task-grp-03 | each member keeps its own pending run | dispatch alpha, beta, alpha again | two distinct pending rows; the repeat returns alpha's id | implemented |
| task-grp-04 | a worker passes over a busy group and runs everything else, then runs the members one after another | pending alpha, beta and a plain task; the group held elsewhere, then released | first pass: plain completed, alpha and beta still pending; second pass: both completed, beta started after alpha finished | implemented |
| task-grp-05 | a schedule tick held back by another member is queued, not dropped | a due schedule for beta while the group is held elsewhere | one PENDING run with the schedule id and Scheduled origin; next_run_at advanced; it completes once the group is free | implemented |

## Task_Debounce_Exclusive_Test (php, default isolation, live rsx-lockd) - #[Debounce] and #[Exclusive] declared together

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-combo-01 | a bare #[Exclusive] beside #[Debounce] adds nothing to the policy | debounce_exclusive_task vs debounce_task | identical policy (debounce, 30, no group); pending identity and run-lock identity both class::method::params_hash | implemented |
| task-combo-02 | ... and coalesces per parameter set | same params twice; other params | same id; a second pending run | implemented |
| task-combo-03 | ... and runs different params concurrently | another process holds parameter set A; pending A and B; worker | A's lock not acquirable, B's is; A stays PENDING, B COMPLETED | implemented |
| task-combo-04 | a debounced group member: params pending identity, the group's run lock | combo_debounced, combo_plain | policy (debounce, 30, group); identity_key per params; run_lock_identity = the group's for any params and for the plain member; group_members lists both | implemented |
| task-combo-05 | the group's lock excludes plain and debounced members alike; a debounced member takes no lock of its own identity | group lock held elsewhere; then taken through the debounced member | neither member acquirable (any params), an outside task is; while the member holds the group, its identity's lock name is free to another process | implemented |
| task-combo-06 | one pending run per debounce identity within a group | dispatch A, A, B; plain member twice | A coalesces; B separate; plain one per method; pending_ids_on_run_lock = all three from either member | implemented |
| task-combo-07 | one running + one pending per debounce identity | A set RUNNING; dispatch A twice, then B | is_identity_running true for A only; one new follow-up, returned again; B its own pending run | implemented |
| task-combo-08 | the delay is honoured and the pending run re-anchored on settle | pending A, B and plain in the past; Task::internal(A); fresh dispatches | A's pending run ~30 s ahead, B's and plain's untouched; group released; a fresh A enqueue ~30 s ahead; a never-run parameter set due now | implemented |
| task-combo-09 | a worker passes over debounced members while the group is held, then runs them in queue order | pending A, B, plain member, unrelated task; group held elsewhere, then released | first pass: members PENDING with no worker_id / started_at, unrelated COMPLETED; second pass: all COMPLETED, each started after the one before it finished | implemented |
| task-combo-10 | an inline run of a debounced member waits for the group | Task_Lock_Holder on the group lock releases when it sees a waiter; Task::internal() | the run completed and started after the release | implemented |
| task-combo-11 | a blocked debounced member's wait line names the group and the holder; scheduled comes first | group held; RUNNING plain member; a due and a not-yet-due pending debounced run | `waiting for exclusive lock "fixture_combo_group" (held by task #N)`; the other `scheduled for ...` | implemented |
| task-combo-12 | a running debounced member is named as the group's holder | group held; RUNNING combo_debounced A; pending plain member and combo_debounced B | both name the group and A's run id | implemented |
| task-combo-13 | a schedule tick blocked by another identity of the group is queued | due schedule for combo_debounced; RUNNING run of the same method with other params; group held | one PENDING Scheduled run with the empty parameter set; next_run_at advanced | implemented |
| task-combo-14 | a schedule tick whose own identity is running is queued as its follow-up and debounced | due schedule; RUNNING run with the empty parameter set (on demand); group held | one PENDING run with the schedule_id; next_run_at advanced; a dispatch joins it; the running run's settle re-anchors it to now + the delay | implemented |
| task-combo-15 | TASK-CONCURRENCY-01 accepts the combination and refuses malformed arguments | the fixture methods' manifest attributes; hand-built bad arguments | no problem for any fixture; one each for a negative / non-integer / missing delay and an empty / non-string / second group name | implemented |

## Task_Pools_Test (php, default isolation) - the pools and what each claims

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-pools-01 | each pool's cap is rsx.tasks.pools.<pool>.max_workers; no global cap | config | 4 / '2' -> 2 / 1; global key absent | implemented |
| task-pools-02 | a cap below 1 or not an integer throws; an unknown pool throws | 0, -1, 1.5, null, 'three'; 'nightly' | RuntimeException | implemented |
| task-pools-03 | a worker joins only a task pool | --pool=kill | exit 1 | implemented |
| task-pools-04 | the scheduled pool runs due dispatched work before a due schedule; the schedule's run is its own row | due schedule + pending run; scheduled worker | B then A; pool_id Scheduled; origin Scheduled run; next_run_at = next cadence; last_task_id / last_success_at | implemented |
| task-pools-05 | the on_demand pool never runs a schedule | due schedule + pending run; on_demand worker | B only; schedule still due | implemented |
| task-pools-06 | a worker claims each due pending run once; not-yet-due and cancelled runs wait | due, future, cancelled rows; both pools' workers | A ran once; future PENDING; cancelled CANCELLED | implemented |
| task-pools-07 | a due schedule whose identity is running is queued as its follow-up | holder holds the exclusive identity; scheduled worker; then again with it free | one PENDING Scheduled run, next_run_at advanced; it COMPLETES once the identity is free | implemented |
| task-pools-08 | a claim lost before the run is handed back | release_unrun_claim() via reflection | dispatched -> PENDING, worker columns cleared; scheduled -> deleted; settled -> untouched | implemented |
| task-pools-09 | two workers racing the same pending row: the guarded claim lets exactly one win | concurrent claims | one claim affects the row | deferred (the read and the guarded write cannot be interleaved in-process; the guard is the WHERE status_id = PENDING of claim_pending_run) |

## Task_Worker_Execution_Test (php, $requires_db_reset + no-tx) - the worker loop and abandoned-run verdicts

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-exec-01 | the oldest due run is claimed first | two pending rows | B (due longer) then A | implemented |
| task-exec-02 | a claim records pool, wid, generation, host and pid; the worker leaves the pool | pending row | COMPLETED with state; on_demand pool identity; member not alive after | implemented |
| task-exec-03 | a failing run is FAILED and the worker goes on | always_throws then marker_a | FAILED with error; next COMPLETED | implemented |
| task-exec-04 | a current-generation worker that left the pool is abandoned at once, live pid or not | departed wid, this pid | PENDING retry, abandon_count 1, reason, operator line | implemented |
| task-exec-05 | a live member's run is left alone | this process joined; dead pid | RUNNING | implemented |
| task-exec-06 | an older generation on this host is judged by pid, held to a task worker's command line | dead pid; worker-shaped pid; a pid an unrelated process holds | abandoned; RUNNING; abandoned ("is not a task worker"), that process untouched | implemented |
| task-exec-07 | an older generation on another host is left alone and counted by health | three other-host rows + one local | RUNNING; previous_generation_rows 3 on 2 hosts; WARN | implemented |
| task-exec-08 | a run with no worker_id is judged by pid on its own host | inline dead pid; dispatched dead pid; other host | inline FAILED; dispatched PENDING; other RUNNING | implemented |

## Task_Abandonment_Retry_Test (php, $requires_db_reset + no-tx) - what the reaper does with an abandoned run

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-retry-01 | an abandoned dispatched run retries after base * 2^(n-1); waits; then completes | twice abandoned; workers before and after due | PENDING, abandon_count 1 then 2, scheduled_for windows, cleared columns, reason; then COMPLETED | implemented |
| task-retry-02 | the attempts-th abandonment fails it for good | abandon_count attempts-1 | FAILED, code 1, error names the limit | implemented |
| task-retry-03 | an abandoned scheduled run is FAILED and counted on its schedule | real manifest schedule; departed scheduled-pool wid | FAILED; consecutive_failures 1, last_task_id, last_error_at | implemented |
| task-retry-04 | a run that throws is never retried | always_throws; worker; tick | FAILED, abandon_count 0, ran once | implemented |

## Task_Schedule_Run_Test (php, default isolation) - a schedule's runs and statistics

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-sched-run-01 | a failing scheduled run is FAILED and counted | schedule on always_throws | run FAILED; consecutive_failures 1, last_error, last_error_at, last_task_id; next cadence | implemented |
| task-sched-run-02 | a TypeError is recorded like any failure | raises_type_error | "TypeError: ..."; streak 1 | implemented |
| task-sched-run-03 | failures accumulate; a success clears the streak | two failing runs, then a success | 2; then 0, last_error null, last_success_at set | implemented |
| task-sched-run-04 | each run of a schedule is its own row | two runs | two COMPLETED rows; last_task_id the latest | implemented |

## Task_Schedule_Reconcile_Test (php, $requires_db_reset + no-tx) - _task_schedules against the manifest

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-recon-01 | every declared schedule is registered | empty table; tick | one row per declaration, future next_run_at | implemented |
| task-recon-02 | a changed expression is re-registered in place, statistics kept | corrupted expression + streak | same id, manifest expression, recomputed next_run_at, streak kept | implemented |
| task-recon-03 | a matching schedule is untouched | two ticks | same id and next_run_at | implemented |
| task-recon-04 | an undeclared schedule is removed; its runs are kept | ghost schedule + run | schedule gone; run kept, schedule_id null | implemented |
| task-recon-05 | --force-scheduled makes every schedule due now | tick with the flag | every next_run_at <= now | implemented |
| task-recon-06 | a changed server timezone is re-registered in place | config zone switched; tick | same id, timezone recorded, next_run_at on the new wall clock | implemented |
| task-recon-07 | a moved maintenance hour is re-registered in place | rsx.tasks.maintenance_hour switched; tick | same id, cron_resolved '0 H * * *', next run at H:00 | implemented |
| task-recon-08 | a row with no resolution recorded has it recorded, next run kept | cron_resolved nulled; tick | cron_resolved filled, next_run_at unchanged | implemented |

## Maintenance_Window_Test (php, no DB) - the maintenance window

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| maint-01 | the window opens at 2am by default | 'maintenance' | '0 2 * * *' | implemented |
| maint-02 | the phrases resolve through the configured hour | hour 4; daily / weekly on a day / monthly | '0 4 * * *', '0 4 * * 0', '0 4 * * 3', '0 4 1 * *' | implemented |
| maint-03 | an invalid hour throws naming the setting | 24, -1, '2', null | InvalidArgumentException naming rsx.tasks.maintenance_hour | implemented |
| maint-04 | an unknown maintenance phrase throws | 'maintenance fortnightly' | exception | implemented |
| maint-05 | every framework task running daily or less is in the window | the manifest's framework schedules | 'maintenance...' phrase and #[Exclusive('maintenance')] | implemented |

## Cron_Parser_Timezone_Test (php, no DB) - the wall clock and daylight saving

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| cron-tz-01 | an expression is read on the zone's wall clock | 'daily at 2am' in Chicago and in UTC | 02:00 CDT; 02:00 UTC | implemented |
| cron-tz-02 | the zone defaults to the server timezone; an explicit zone wins | config zone set / blank | get_timezone() answers each | implemented |
| cron-tz-03 | an unknown zone throws | 'Mars/Olympus_Mons' | exception | implemented |
| cron-tz-04 | a fixed time skipped by spring-forward runs right after the jump | 2am / 2:30am on 2026-03-08 | 03:00 CDT; next day 02:00 | implemented |
| cron-tz-05 | a fixed time repeated by fall-back runs once | 1:30am on 2026-11-01 | 01:30 CDT, then 2026-11-02 01:30 CST | implemented |
| cron-tz-06 | a wildcard job runs on real elapsed time | every 5 minutes / hourly across both jumps | 01:00 CST three minutes on; no extra 3am run | implemented |

## Task_Schedule_Visibility_Test (php, default isolation) - a failing schedule must be visible

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-vis-01 | rsx:health WARNs at the threshold, naming the offender and its error | schedule at threshold | WARN with identity, count, excerpt | implemented |
| task-vis-02 | below the threshold is OK | threshold - 1 | OK | implemented |
| task-vis-03 | rsx:tasks:list renders pools and the Schedules table | failing + healthy schedule | streak and first error line; dashes for healthy | implemented |

## Task_Timeout_Reaper_Test (php, $requires_db_reset + no-tx) - the silence limit and a requested timeout, in rsx:task:process

A live worker is a real `sleep` child; the run's row is written with the start time and signs of life each case needs.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-timeout-01 | a live worker past its own timeout gets a force kill, carried out | live sleep pid, timeout 60, started 120 s ago | request (cap 60s), [TASK TIMEOUT]; after carry_out: process dead, KILLED "timed out after" | implemented |
| task-timeout-02 | inside its cap: untouched | timeout 600, 10 s | no request, RUNNING | implemented |
| task-timeout-03 | a SILENT run is killed at the silence limit | NULL timeout, silent_timeout 30, started 90 s ago, no report | request "no report for Ns (cap 30s)", [TASK TIMEOUT]; carried out: KILLED, reason "no report for", process dead | implemented |
| task-timeout-04 | silent_timeout 0 is no silence limit | NULL, 0, a day silent | no request, RUNNING | implemented |
| task-timeout-05 | a run with a live request is not requested twice | two ticks | one request; second tick silent | implemented |
| task-timeout-06 | an INLINE run is never killed for time | inline row (no worker_id), live pid, cap 60, an hour in | no request, RUNNING, process alive, nothing reported | implemented |
| task-timeout-07 | a silent run inside the limit is untouched | NULL, 30, started 10 s ago | no request | implemented |
| task-timeout-08 | a REPORTING run outlives the limit | a day in; last_report_at 5 s ago / last_heartbeat_at 5 s ago / stale heartbeat + fresh report | no request for any, nothing reported, worker alive | implemented |
| task-timeout-09 | the limit runs from the LAST sign of life | a day in, heartbeat 500 s ago, report 90 s ago, limit 30 | "no report for 9Xs (cap 30s)" - not a day | implemented |
| task-timeout-10 | an invalid silence limit throws | silent_timeout 'soon' | RuntimeException naming the key | implemented |
| task-timeout-11 | an explicit timeout is HARD even for a reporting run | timeout 60, 120 s in, report 1 s ago | "timed out after" | implemented |
| task-timeout-12 | an explicit timeout replaces the silence limit | timeout 7200, 600 s silent, limit 30 | no request | implemented |

## Task_Sign_Of_Life_Test (php, default isolation) - every report stamps the run alive

The row's stamps are pushed into the past first, so "stamped" is a moved value; the instance's last write time is pinned through reflection.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-life-01 | every reporting call stamps last_report_at by itself | heartbeat, status, progress, progress_count, eta, state, queue_push, queue_push_many (also []), queue_pop / queue_remove / queue_clear on an empty queue, message, stdout, stderr, summary - one fresh run each, no flush | stamped | implemented |
| task-life-02 | a heartbeat stamps its own column too | heartbeat() | last_heartbeat_at and last_report_at | implemented |
| task-life-03 | the queue calls on a queue holding items stamp | pop, remove, clear | stamped | implemented |
| task-life-04 | an unchanged status is still a sign of life | status('copying') twice | stamped; no second stderr line | implemented |
| task-life-05 | captured echo is a sign of life | the runner executes a task that only echoes, then throws | stamped while it ran | implemented |
| task-life-06 | a held report is stamped by the next reporting call after the interval | heartbeat inside the interval; then a call after it | held; then both columns stamped | implemented |
| task-life-07 | a held report is stamped at the settle | held status; settle | stamped | implemented |
| task-life-08 | a stop check alone is not a report | is_stop_requested() | nothing stamped | implemented |

## Task_Next_Run_Test (php, $requires_db_reset + no-tx) - setting a schedule's next run

The subject is a real declared schedule (Session_Values_Cleanup_Service, hourly), because an undeclared one is what the API refuses.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-next-01 | set_next_run writes the moment and answers it | an ISO moment 2 h ahead | returned as ISO UTC; next_run_at equals it | implemented |
| task-next-02 | the moment may carry any offset | the same instant at -06:00 | stored as the UTC instant | implemented |
| task-next-03 | a task without a #[Schedule] throws | fixture marker_a | the exact message; no row written | implemented |
| task-next-04 | not a task / not a moment throws | unknown method; 'next tuesday'; a date; '' | throws each time | implemented |
| task-next-05 | an unregistered declaration is registered by the call | _task_schedules emptied | one row, the declared expression, the moment | implemented |
| task-next-06 | reconciliation leaves an explicit next run alone | set; two ticks | same row, same moment | implemented |
| task-next-07 | the cadence resumes after the moved run | set due now; scheduled-pool worker | one COMPLETED Scheduled run; next_run_at = the cron expression's next cadence | implemented |
| task-next-08 | run_again_in works from a run of any origin | instances of a scheduled, a dispatched and an inline run | next_run_at = now + N each time; the moment answered | implemented |
| task-next-09 | run_again_in throws without a #[Schedule], and for a negative wait | fixture run; -1 | throws | implemented |

## Task_Waiting_Test (php, default isolation, live rsx-lockd) - what a pending run is waiting for

A held lock is another process (Task_Lock_Holder); the run holding it is a RUNNING row the test writes.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-wait-01 | a run that is not pending waits for nothing | running; each terminal status | null; to_status_array()['waiting'] null | implemented |
| task-wait-02 | not due yet | scheduled_for an hour ahead | {scheduled, "scheduled for <time>", null, null}; status carries the ISO scheduled_for | implemented |
| task-wait-03 | a grouped lock names the group and the holder | group lock held; RUNNING group_alpha; pending group_beta | `waiting for exclusive lock "fixture_group" (held by task #N)`, lock = group, id | implemented |
| task-wait-04 | an ungrouped lock names only the holder | identity lock held; RUNNING + pending exclusive_task | `waiting for exclusive lock (held by task #N)`, lock null | implemented |
| task-wait-05 | a held lock with no running row leaves the holder off | both locks held, no RUNNING rows | the two texts without the parenthesis; id null | implemented |
| task-wait-06 | a #[Debounce] run waits only on its own parameter set | lock of {client: 1} held | the same set: lock wait; another set: queued | implemented |
| task-wait-07 | a running row without a held lock is not a lock wait | RUNNING group_alpha, lock free | queued | implemented |
| task-wait-08 | every claiming worker busy | caps 2 + 1; three worker runs and one inline run | "waiting for a free worker (3 of 3 busy)" | implemented |
| task-wait-09 | a free worker | caps 2 + 1; two worker runs | {queued, "waiting for a worker to start"} | implemented |
| task-wait-10 | precedence | not due + locked; locked + no free worker; no free worker | scheduled; lock (with holder); workers | implemented |
| task-wait-11 | derived, never stored: a changed holder is a different answer | holder settles, another member runs; then the lock is freed | the new holder's id; then queued; the pending row byte-identical | implemented |
| task-wait-12 | only the holder's run id is exposed | a holder with params | four keys; no param value, no task name | implemented |
| task-wait-13 | many pending runs cost two queries | 24 pending runs, one Task_Waiting | exactly two queries (the switch, the running rows); to_status_arrays() shares the resolver | implemented |
| task-wait-15 | the task system being off is the first reason | switch off; a due run, a not-yet-due run, a running run | {disabled, "task system disabled - php artisan rsx:tasks:enable"} for both pending runs; null for the running one; one query; on again -> queued | implemented |
| task-wait-14 | the helpers: run_lock_identity, group_members, pending_ids_on_run_lock | fixture methods and pending rows | group shared by members; members from the manifest; waiters per group / identity / parameter set; none for an unmanaged task | implemented |

## Task_Kill_Worker_Test (php, $requires_db_reset + no-tx) - force stops and kills carried out

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-kill-01 | a request binds host, pid, mode and due time; one live request per run and mode; another host's run | RUNNING row | fields; duplicate null; host = the worker's | implemented |
| task-kill-02 | only a running run with a worker pid can be requested | pending; no pid | null | implemented |
| task-kill-03 | a force kill signals the worker and settles KILLED | worker-shaped child (Task_Worker_Victim), pool row, force_kill() | process gone; KILLED, reason, two operator lines; request DONE 'killed' | implemented |
| task-kill-04 | a run that ended first makes the request moot | stopped during the grace | MOOT; nothing signalled; verdict kept | implemented |
| task-kill-05 | a request whose worker changed is moot | worker_pid changed | MOOT; new worker not signalled | implemented |
| task-kill-06 | an inline run in a web request is never signalled | no worker_id, non-artisan pid | MOOT; alive; operator line | implemented |
| task-kill-07 | a worker already gone is settled KILLED without a signal | dead pid | killed_no_process | implemented |
| task-kill-08 | a killed scheduled run counts on its schedule | schedule + run | consecutive_failures 1, "killed: ..." | implemented |
| task-kill-09 | a pool run whose pid an unrelated process now holds is never signalled | plain `sleep` on a pool row, force kill | killed_no_process; the process still running; run KILLED, "the worker process was already gone" | implemented |
| task-kill-11 | the kill worker loop (rsx:task:killer) admits itself to the kill pool and drains this host's requests | spawned killer | requests DONE; pool left | planned |
| task-kill-12 | rsx:task:process hands a request whose kill worker died back to the queue | CLAIMED request, departed kill-pool wid | PENDING again | planned |

## Task_Lifecycle_Test (php, default isolation) - operations on a run, and their commands

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-life-01 | request_stop flags a pending or running run; the task sees it | each status | stop_requested_at; is_stop_requested(); operator line | implemented |
| task-life-02 | a second request keeps the first moment | two requests | same stop_requested_at | implemented |
| task-life-03 | a finished run cannot be asked to stop | completed, failed, cancelled | false, nothing recorded | implemented |
| task-life-04 | a task that checks stops early and settles STOPPED | stoppable_batches, request after batch 2 | batches_done 2; STOPPED, code 0 | implemented |
| task-life-05 | after a stop the verdict follows the return | stop + false; stop + null | FAILED; STOPPED | implemented |
| task-life-06 | cancel takes a pending run off the queue | pending | CANCELLED, reason, completed_at, operator line | implemented |
| task-life-07 | cancel refuses a run that started or ended | running, completed | false, unchanged | implemented |
| task-life-08 | force stop / force kill of a pending run cancel it | pending | CANCELLED; no kill request | implemented |
| task-life-09 | force stop flags the stop and requests a kill after the grace | running, grace 30 | stop flagged; FORCE_STOP request due +30 s; operator line; negative grace throws | implemented |
| task-life-10 | force kill requests a kill now | running | FORCE_KILL request due now; finished -> false | implemented |
| task-life-11 | rerun dispatches the same task and params | failed run | new PENDING run; operator line | implemented |
| task-life-12 | rerun refuses a live run | running | throws | implemented |
| task-life-13 | rsx:tasks:stop graceful / --force --grace / --kill; refuses finished and missing | runs | exit codes and the right request | implemented |
| task-life-14 | rsx:tasks:cancel | pending; running | 0 + CANCELLED; 1 | implemented |

## Task_Reports_Test (php, default isolation) - what a task reports, read back

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-rep-01 | the first report after a quiet spell is written at once | status() with no prior write | on the row; last_report_at | implemented |
| task-rep-02 | a report within the interval is held until flush | last write pinned to now | nothing on the row until flush() | implemented |
| task-rep-03 | is_stop_requested() writes held reports at the write rate, not per call | held summary; check within the interval, then after it | not written; then written | implemented |
| task-rep-04 | the settle writes held reports | held state; settle | state on the row | implemented |
| task-rep-05 | each report persists | heartbeat, status, progress, count, eta, state, queue, summary, messages | each reader; messages in order and by cursor | implemented |
| task-rep-06 | a report replaces the last; one row per kind | two states, two summaries | the latest; 2 report rows | implemented |
| task-rep-07 | progress is clamped | 150, -5 | 100, 0 | implemented |
| task-rep-08 | a report refuses what it cannot record | negative count / eta, keyed or non-string/array queue items, NAN | InvalidArgumentException | implemented |
| task-rep-09 | status() writes a stderr line only when it changes | a, a, b (with newline), a | three lines, newline folded | implemented |
| task-rep-10 | a long status is cut to fit | 1500 chars | 1000 ending '...' | implemented |
| task-rep-11 | progress_percent is derived from a count; a reported percentage wins | 1 of 4; 0 of 0; 10% + count | 25; null; 10 | implemented |
| task-rep-12 | available_reports lists what was set, in a stable order | every report + settle | the ten names in order | implemented |
| task-rep-13 | output_after reads by cursor, stream and page | stdout, stderr, operator | order, streams, cursor, limit; unknown stream throws | implemented |
| task-rep-14 | to_status_array shape | running run with status + count | exact key list (with `waiting`, null for a run that is not pending) and values | implemented |
| task-rep-15 | a run has no ETA until something sets one | a status only | eta_at null | implemented |
| task-rep-16 | a percentage estimates the ETA from the pace so far | a run started 100 s ago; 50%, then 25% | about 100 s left, then about 300 s | implemented |
| task-rep-17 | a count estimates the ETA the same way | a run started 60 s ago; 3 of 12 | about 180 s left | implemented |
| task-rep-18 | progress that says nothing clears the ETA instead of guessing | an ETA set by hand, then 0%, 0 of 40, 0 of 0 | null each time | implemented |
| task-rep-19 | finished progress puts the ETA at now | 100%; 9 of 5 | now, within 2 s | implemented |
| task-rep-20 | a progress report overwrites an ETA set by hand | eta(5000) then 50% at 100 s | about 100 s left | implemented |

## Task_Output_Test (php, default isolation) - output lines, console streams, echo capture

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-out-01 | each line is a row on its stream | multi-line stdout/stderr, CRLF | one row per line, in order | implemented |
| task-out-02 | console streams echo each stream live; operator lines never | stdout, stderr, status twice, operator | stdout sink: stdout; stderr sink: stderr + one status | implemented |
| task-out-03 | a quiet runner (no stderr stream) still echoes stdout and records everything | null stderr | stdout echoed; both rows | implemented |
| task-out-04 | internal() with streams narrates the run and its failure | echo_params; always_fail | JSON on stdout, lines on stderr; failure line after the task's | implemented |
| task-out-05 | printed output is captured in order, passes through, is not echoed twice | prints_output | rows interleaved with the task's own; passthrough text; buffer level restored | implemented |
| task-out-06 | printed output survives a throw | prints_then_throws | FAILED; printed line recorded; buffer closed | implemented |

## Task_Attachments_Test (php, $requires_db_reset + no-tx) - named files a run attaches (temp files)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-att-00 | an attachment stamps the run alive | attach_bytes, attach_file on a run whose last_report_at was aged | last_report_at stamped | implemented |
| task-att-01 | attach_bytes records the file as a temp file | bytes | row fields, sniffed mime, temp file under uploads/_temp keyed at random, nothing in _file_storage, read_bytes/read_stream, download response | implemented |
| task-att-02 | attach_file copies a file and leaves the source | temp file | source name, given mime, bytes; source kept | implemented |
| task-att-03 | attachments are listed by name; a missing name is null | two names | alpha, zeta | implemented |
| task-att-04 | a bad name or unreadable file is refused | '', 256 chars, missing path | throws; nothing recorded | implemented |
| task-att-05 | replacing a name deletes the earlier temp file | two attaches | one row; earlier temp row and bytes gone | implemented |
| task-att-06 | identical bytes are two temp files, never shared | same bytes on two runs | different temp files; replacing one leaves the other | implemented |
| task-att-08 | deleting the temp file deletes the attachment; no blob reference is declared | declarations; Rsx_Temp_Files::delete | none declared; row gone | implemented |

## Task_Retention_Test (php, $requires_db_reset + no-tx) - history kept to rsx.tasks.retention

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-retain-01 | truncation keeps the last N lines, unlinks attachments, stamps the run | old finished run | last 3 lines; blob released; reports/messages kept; never revisited | implemented |
| task-retain-02 | recent and live runs are not truncated | recent failed, running | untouched | implemented |
| task-retain-03 | keep_lines 0 deletes all output | old killed run | no lines | implemented |
| task-retain-04 | purge deletes old finished runs and everything beside them | old cancelled, recent, pending | old gone with reports/output/messages/attachments, blob released; others kept | implemented |
| task-retain-05 | temp dirs of finished or missing runs are removed | finished, running, missing, foreign dirs | 2 removed; running and foreign kept | implemented |
| task-retain-06 | the scheduled sweep runs every pass and summarizes | run past both windows; Task::internal sweep | "Truncated 1 run(s), purged 1 run(s)" | implemented |
| task-retain-07 | the sweep refuses an invalid configuration | keep_lines -1; minutes as a string | throws naming the key | implemented |
| task-retain-08 | a stop requested before the sweep ends it before any run is touched | run past both windows; sweep row with stop_requested_at | "Stopped after truncating 0 run(s), ..." summary; old run not truncated | implemented |

## Task_Gates_Test (php, default isolation) - who may see and act on a run

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-gate-01 | no handler denies view, control, scope and subscriptions (staff) | no handlers, signed out | all false; scope empty | implemented |
| task-gate-02 | the same in the portal realm | set_portal_request(true) | all false | implemented |
| task-gate-03 | a developer passes every staff gate without a handler; the topics ask the gate | user 1 | all true; missing run refused | implemented |
| task-gate-04 | the developer bypass is staff only | user 1 on a portal request | denied | implemented |
| task-gate-05 | view and control handlers decide, seeing run, user and action | test handlers | true/true/false; arguments recorded; unknown action throws | implemented |
| task-gate-06 | the portal realm asks the portal handlers | staff handler only, then portal handler | false, then true | implemented |
| task-gate-07 | the view scope handler narrows the list | scope to one run | that run only | implemented |

## Task_Notify_Test (php, no transactions, live Redis registry) - the frames watchers follow

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-notify-01 | a change reaches each site watching that run, once | registry with several sites and topics | Task_Changed_Topic to sites 1 and 7 | implemented |
| task-notify-02 | output has its own topic | changed(output only), changed(both) | Task_Output_Topic; both | implemented |
| task-notify-03 | a lifecycle move reaches the run and every list watcher whose filter matches its task | lifecycle() on a real run; list watchers unfiltered, {class}, {class, method}, another class, another method | run topic + list topic to the unfiltered, class and class+method sites only; frame data {class: simple name, method} | implemented |
| task-notify-07 | a lifecycle move tells the PENDING runs on the same group lock | RUNNING group_alpha; pending group_beta + group_alpha; pending exclusive_task and plain_task; a finished group_beta - all watched | Task_Changed_Topic for the run and the two pending group runs only | implemented |
| task-notify-08 | an ungrouped lock's waiters are its own identity | #[Exclusive] and #[Debounce] holders with pending runs (same / other parameter set) | the method's pending run; the same parameter set's only | implemented |
| task-notify-09 | an unmanaged run's move tells no other run | RUNNING + pending plain_task | the moved run only | implemented |
| task-notify-10 | a waiter frame goes only where that waiter is watched | one waiter watched on two sites, one unwatched | two frames, both for the watched waiter | implemented |
| task-notify-11 | waiter frames under a pool lock wait for flush_deferred() | lifecycle() under the on_demand lock | nothing; then the waiter's frame | implemented |
| task-notify-12 | a real run tells its group's waiters when it starts and when it settles | Task::internal() of group_alpha with a watched pending group_beta | two frames for the waiter; the group lock free and the waiter "queued" at the next read | implemented |
| task-notify-13 | a cancelled run tells the waiters on its lock | cancel() of a pending group_alpha | the pending group_beta's frame | implemented |
| task-notify-04 | nobody watching publishes nothing | registry for another run | nothing | implemented |
| task-notify-05 | under a pool lock frames wait for flush_deferred(), once | two changes under the lock | nothing; one frame; second flush nothing | implemented |
| task-notify-06 | a run's own writes announce themselves | report, output line, operator line | changed; output; output | implemented |

## Task_Spawn_Admission_Test (php, per-test transaction, live rsx-lockd) - who enters a pool, who is never started

Other members are real members on a second daemon connection (the RsxLocks one, raw pool.* frames).

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-spawn-01 | a worker that finds its pool full exits without joining | cap 1, one other member; in-process worker | exit 0, "The on_demand pool is full"; no membership; lock free | implemented |
| task-spawn-02 | a worker below the cap joins, claims nothing, and leaves before returning | cap 2, one other member | "Joined the on_demand pool (wid N, generation G)"; members back to 1 | implemented |
| task-spawn-03 | spawn_worker() reads the pool count before starting anything | cap 1: member present, then gone | false + nothing registered; then true, child registered | implemented |
| task-spawn-04 | a member process counts itself | this process joined; cap 1 | false; nothing started | implemented |
| task-spawn-05 | spawn_worker() under a pool lock refuses instead of parking behind itself | lock held | RuntimeException "holds a task pool lock" | implemented |
| task-spawn-06 | maintenance mode starts nothing | forced maintenance; dispatch | false; nothing registered; PENDING | implemented |
| task-spawn-07 | one rsx:task:process tick tries exactly one spawn | due row; cap 3; spawn_workers(true) | "spawned a worker"; one process registered | implemented |
| task-spawn-08 | this process's own live spawns fill the cap first | fixture worker recorded as our on_demand spawn; cap 1 | false; nothing started; exited fixture not a worker | implemented |
| task-spawn-09 | under the suite dispatch() enqueues only | dispatch | pending row; nothing registered | implemented |
| task-spawn-10 | Task::spawn_workers(false) enqueues without spawning | toggles; dispatch | spawning_workers() follows; pending row | implemented |
| task-spawn-11 | the class boundary turns spawning back off | __restore_class_boundary() | false | implemented |

## Task_Pool_Test (php, no transactions, live rsx-lockd) - the worker pool client

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-pool-01 | every call is applied before it returns; join answers wid + generation; member_pool and per-pool holds_lock follow; each pool has its own name | lock/join/member_alive/members_alive/leave/unlock, observed over the RsxLocks connection | holder/members move with each call; stats() agrees | implemented |
| task-pool-02 | locked ops are refused without the lock; second join / leave refused | calls without the lock, doubled calls | RuntimeException "refused pool.<op>" | implemented |
| task-pool-03 | count() excludes the caller | count before/after join/leave | equal; stats() counts the caller | implemented |
| task-pool-04 | a SIGKILLed member child is no longer counted or alive | child joins, SIGKILL | not alive; count back | implemented |
| task-pool-05 | a member child that dies holding the lock hands it to the waiter | child holds, SIGKILLs itself on a waiter | lock granted; exit 137 | implemented |
| task-pool-06 | the pool connection is independent of RsxLocks | both locks; dump; close each | two conn_ids; group only on RsxLocks; each close leaves the other | implemented |
| task-pool-07 | the open pool socket is one of this process's lock descriptors | open, then close | one more fd, named in the detached-spawn prefix; gone after | implemented |
| task-pool-08 | a member's detached child does not carry its membership | child joins, spawns tinker on a FIFO, SIGKILLed | grandchild holds no member socket; membership gone; grandchild alive | implemented |

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
| task-cmd-09 | FATAL: collision with a framework command | 'db:wipe' | RuntimeException "already a framework command" | implemented |
| task-cmd-10..11 | FATAL: missing / empty description | one arg; '   ' | RuntimeException "the description and is required" | implemented |
| task-cmd-12 | FATAL: missing name | no arguments | RuntimeException "the command name and is required" | implemented |
| task-cmd-13 | the REAL baked table names the fixture tasks | this build's manifest | rsx_test:echo, rsx_test:fail, rsx_test:exit -> their methods | implemented |
| task-cmd-14 | an alias carries the name and description; service and task are fixed, every option is a task parameter | new Task_Alias_Command(...) | getName/getDescription; no service/task arguments; no declared options | implemented |
| task-cmd-15 | an ABSENT table registers nothing and never fatals | task_commands unset | registrar adds no command | implemented |
| task-cmd-16 | the fixture aliases really are registered with artisan | the running console application | names present | implemented |

## Task_Command_Cli_Test (cli, $requires_db_reset + no-tx) - the alias driven for real

Spawns artisan with stdout and stderr opened onto separate files (the two streams being kept
apart IS the property under test - hence the file-level @ARTISAN-SPAWN-01-EXCEPTION), each
child pointed at the test database.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-cmd-cli-01 | stdout is the task's stdout and nothing else | rsx_test:echo --a=1 | exactly {"echo":{"a":"1"}}, exit 0 | implemented |
| task-cmd-cli-02 | the alias IS rsx:task:run - identical stdout, byte for byte | alias vs rsx:task:run | equal | implemented |
| task-cmd-cli-03 | every option is a task parameter (no value envelope) | --debug --data={json} | debug true; data decoded | implemented |
| task-cmd-cli-04 | stderr carries the task's stderr | rsx_test:echo --a=1 | both lines on stderr, not stdout | implemented |
| task-cmd-cli-05 | -q empties stderr and never touches stdout | rsx_test:echo -q | stderr ''; stdout unchanged | implemented |
| task-cmd-cli-06 | a throwing task exits 1 with the failure line on stderr | rsx_test:fail | exit 1, stdout '', "Task failed: Exception: ..." after the task's line | implemented |
| task-cmd-cli-07 | the exit code is the return code clamped to 1..255 | rsx_test:exit --code=0/3/255/300/-2 | 0, 3, 255, 1, 1; failure line on stderr | implemented |
| task-cmd-cli-08 | an unknown task exits 1 naming it | rsx:task:run ... no_such_task | exit 1, "[ERROR] Task no_such_task not found" | implemented |
| task-cmd-cli-09 | a command run is a recorded inline run | rsx_test:echo --marker=... | Inline, COMPLETED, stderr lines on the run | implemented |
| task-cmd-cli-10 | rsx:task:list shows the COMMAND column, '-' for a task with none | rsx:task:list | header; rsx_test:echo; dash for cleanup_request_log | implemented |

## Task_Report_Endpoint_Test (php, default isolation) - one report's value, and a queue's limit

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-rpt-01 | a queue limit answers the first (oldest) items and the queue's depth | 5 items; limit 2, none, 50 | first 2 + total 5; all 5; all 5 | implemented |
| task-rpt-02 | a limit below 1 or not a whole number is refused | limit 0, -3, 'many' | Error_Response | implemented |
| task-rpt-03 | total is null for every other kind; a limit does not touch it | summary with limit 1 | the summary; total null | implemented |

## Task_Queue_Test (php, default isolation) - the queue report as rows

| ID | Purpose (what it proves) | Input | Expected | Status |
|----|--------------------------|-------|----------|--------|
| task-queue-01 | pushes append in order and items keep their type | push, push_many (string + array) | queue() in order; depth 3; queue(2) is the head | implemented |
| task-queue-02 | pop removes the head | 3 stored, pop | the last two | implemented |
| task-queue-03 | held calls are applied in call order | pop, pop (empty), push x, push y, pop, push z | y, z; depth counts held calls | implemented |
| task-queue-04 | sliding the window leaves the rows between untouched | 5 stored; pop + push | one DELETE and one INSERT; the four rows between keep their ids | implemented |
| task-queue-05 | remove takes one exact match, the earliest | duplicates, a case variant, a superstring | one row gone, the earliest; no match removes nothing | implemented |
| task-queue-06 | remove sees calls still held | push_many held, remove an array item | held pushes written first, then one removed | implemented |
| task-queue-07 | clear then push_many declares the queue | stored items, pop, clear, push_many | exactly the new items | implemented |
| task-queue-08 | the report exists once the queue has held an item | clear / pop / push_many([]) on a new run; then push, clear | no report, queue() null; then reported and empty | implemented |
| task-queue-09 | unheld calls write as they are made | first call after a quiet spell | stored at once | implemented |

## Task_System_Switch_Test (php, $requires_db_reset + no-tx, live rsx-lockd, child artisan processes) - the task system's off switch

Children are real `php artisan` processes on the test database: a task being stopped, an inline caller waiting for the switch.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-switch-01 | the switch round-trips and records who threw it | disable(reason) twice, enable twice | is_enabled false; {disabled_at now, "the command line", reason, host}; second disable false and the record kept; enable true then false; blank reason = null | implemented |
| task-switch-02 | the commands are idempotent and rsx:tasks:list states the switch | enable; disable x2; --grace=soon; list; enable; list | "already on"; "OFF"; "already off" with the first reason kept; non-zero; "Task system: DISABLED since ... (first)"; "Task system: enabled" | implemented |
| task-switch-03 | a worker claims nothing while off | pending run; a worker per pool | exit 0, "The task system is disabled", pool empty, run PENDING; COMPLETED after enable | implemented |
| task-switch-04 | a busy worker finishes its run and leaves | a run that throws the switch, then a second pending run | first COMPLETED, second PENDING, worker out of its pool | implemented |
| task-switch-05 | dispatch enqueues and starts no task worker; the kill pool is not switched off | spawning on, switch off | spawn_worker false for on_demand and scheduled, registry unchanged; true for kill; row PENDING | implemented |
| task-switch-06 | the tick recovers and starts no task worker | abandoned run + due pending run, spawning on | [ABANDONED TASK], the dead run back to PENDING; no "spawned a worker"; --once runs nothing | implemented |
| task-switch-07 | schedules wait and catch up with one run | schedule 30 days overdue, every minute | no run and next_run_at untouched while off; one run after enable (two workers), next_run_at in the future | implemented |
| task-switch-08 | an inline caller queues, waits and runs after enable | child rsx:task:run while off; a worker while off; enable | PENDING inline row, "disabled" wait line, body not entered, child alive; worker claims nothing; after enable exit 0, COMPLETED with no worker_id, the body entered once, the stderr line | implemented |
| task-switch-09 | a waiter and a worker never both run the queued run | child #[Command] `rsx_test:exit --code=7` while off; enable; in-process worker | child exits 7; one row, FAILED return_code 7 | implemented |
| task-switch-10 | a waiter that dies leaves its run for a worker | child SIGKILLed while waiting; a tick; enable; worker | row PENDING through the tick; COMPLETED under a worker_id, the body entered once | implemented |
| task-switch-11 | the health row | on; off with a reason | OK; FAIL naming since/by/reason with `php artisan rsx:tasks:enable`; Task Scheduler Liveness INFO | implemented |
| task-switch-12 | disable stops a cooperative run gracefully | child running runs_until_stopped; pending run; --grace=3600 | exit 0 at once, run STOPPED, its kill request MOOT, the pending run untouched | implemented |
| task-switch-13 | disable kills a run that ignores the stop after the grace; another host's run is left | worker-shaped victim + other-host row; --grace=1 | victim dead, run KILLED with stop_requested_at and the reason; remote RUNNING with a PENDING request, named in the output; "No task is running on this host" | implemented |
| task-switch-14 | disable reports a web-request run it cannot kill | inline row on a non-artisan pid; --grace=1 | exit 2; process alive; run RUNNING with stop_requested_at; listed | implemented |

## Task_Dead_Run_Recovery_Test (php, $requires_db_reset + no-tx, live rsx-lockd) - what an outage leaves, and the first tick after it

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-dead-01 | one tick settles every run a rebooted host left RUNNING | previous-generation pool runs (dispatched, scheduled) and an inline run, all with dead pids | dispatched PENDING with a paced retry; scheduled FAILED, counted, next_run_at kept; inline FAILED | implemented |
| task-dead-02 | a reused pid neither keeps a dead run alive nor gets killed | previous-generation pool run two hours old on a plain `sleep` pid | abandoned on the first tick ("is not a task worker"); no kill request, no [TASK TIMEOUT]; the process untouched | implemented |
| task-dead-03 | another host's unsettled run does not hold its #[Exclusive] identity | other-host previous-generation RUNNING exclusive_task; dispatch + worker | stuck run RUNNING; the new run reads "queued" and COMPLETES | implemented |
| task-dead-04 | a schedule's queued run that was never claimed is claimed by the next worker | PENDING scheduled-origin row with schedule_id, a day overdue | COMPLETED; schedule last_task_id + last_success_at | implemented |
| task-dead-05 | a long-overdue schedule runs once | every-minute schedule 30 days overdue; two scheduled workers | one run row; next_run_at in the future | implemented |
| task-dead-06 | long-overdue pending runs each run once, oldest due first | three pending runs 10/30/20 days overdue | run order by due time; three rows, each COMPLETED | implemented |

## Deferred / planned

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| task-d-01 | an inline run whose process died mid-task is settled FAILED by its shutdown function | php | needs a child process that fatals inside a task (Task_Runner::settle_abandoned_inline) | planned |
| task-d-02 | a dispatch on this host spawns a kill worker for a force kill (Task::spawn_worker('kill')) | php | spawn path; under the suite spawns are off by default | planned |
| task-d-03 | rsx:tasks:list running table (worker PID, progress, status text) | cli | Schedules table covered by task-vis-03 | planned |
| task-d-04 | orphaned temp directory removal by Task_Retention_Service::remove_orphaned_temp_directories() | php | the run's own cleanup is covered; the sweep of a directory left by a killed run is not | planned |
| task-d-10 | an inline run whose pid was reused stays RUNNING (the reaper has no command line to hold an inline pid to) | php | pins a known gap; awaiting an owner decision on the evidence (docs.dev/audits/task_dead_run_recovery_audit_2026_10_11.md, scenario 2) | blocked |
| task-d-11 | a RUNNING run on a host that no longer exists is settled | php | no mechanism declares a host gone; awaiting an owner decision (same audit, scenario 3) | blocked |
| task-d-12 | rsx-lockd restarting under live workers: the run locks are released while their tasks still run | php | cannot restart the daemon from inside a test run | deferred |
| task-d-13 | the Task System health row reads INFO under the maintenance flag | php | needs the real flag raised; the branch is one read of Framework_Maintenance::is_active_on_disk() | deferred |
| task-d-14 | a web request blocked in Task::internal() while the system is off, ended by the web server, leaves its run queued | http | needs a served fixture route and a php-fpm request limit; the dead-waiter case is task-switch-10 | deferred |
| task-debounce-nodelay-01 | the debounce delay is optional: a bare `#[Debounce]` has delay 0, coalesces repeat dispatches, and its pending run is due at once after a completion | php | `debounce_no_delay_task` dispatched twice straight after a completed run of the same identity | policy delay 0; one pending run, same id both times; `scheduled_for` not in the future | implemented | 2026-10-12 |

## Task_Multiple_Locks_Test (php, default isolation, live rsx-lockd) - several #[Exclusive('group')] on one task

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-multi-01 | every group is read from the repeated attribute | `lock_a_and_b` | groups `[fixture_lock_a, fixture_lock_b]`; two run-lock identities | implemented |
| task-multi-02 | the locks are taken all at once or not at all | a held elsewhere; then both free | pair refused and b NOT left held; a b-only run can take b; with both free both are held and both released together | implemented |
| task-multi-03 | a younger run with a free lock goes ahead; the older runs in the next window | overdue pair + younger b-only; a held; worker twice | b-only COMPLETED while the pair waits; pair COMPLETED once both are free | implemented |
| task-multi-04 | a waiting run names every held lock | a and b held, then only a | `waiting for exclusive locks "fixture_lock_a", "fixture_lock_b"`, then `waiting for exclusive lock "fixture_lock_a"` | implemented |
| task-multi-05 | an inline run takes every lock | `Task::internal()` of the pair | COMPLETED; both locks free after | implemented |
| task-multi-06 | a due debounced run waits for its lock and a call does not move it | overdue debounced run; group held; second dispatch | same id, scheduled_for unchanged; COMPLETED once the group is free | implemented |
| task-multi-07 | TASK-CONCURRENCY-01 accepts repeated groups and refuses a repeated #[Debounce] | attribute maps | none / none / one / one problems | implemented |
| task-runas-01 | a #[Run_As_Dispatcher] dispatch with no sign-in behind it is refused at dispatch - nobody signed in, or a declared CLI identity with no user session (a worker rebuilds from the sign-in alone) | php | `Task::dispatch()`, session reset; acting as user 1 unbound | RuntimeException "a dispatched run can only be started from a signed-in request" | implemented (`Task_Run_As_Dispatcher_Test`) | 2026-10-21 |
| task-runas-02 | dispatch stamps the requester's user session and audit session on every run, and the claim tier: 0 for #[Run_As_Dispatcher], 1 other on-demand, 2 scheduled-origin | php | dispatch from a bound virtual session; insert_row ORIGIN_SCHEDULED | user_session_id + audit_session_id stamped; priorities 0 / 1 / 2; a scheduled run has no user session | implemented | 2026-10-21 |
| task-runas-03 | the run is bound to the dispatching sign-in: dispatched by A, A signs out and B signs in on that browser, the worker runs it as A on A's site with get_session_id() = the browser, get_user_session_id() = A's, A's audit session and timezone; identity, site, logout, portal site and session values throw; the worker hands its caller's context back | php | browser fixture + A's user session, dispatch, sign A out, B's user session pointed, in-process worker | COMPLETED; state names A throughout; six refusal messages naming Run_As_Dispatcher; caller blank and unfrozen after | implemented | 2026-10-21 |
| task-runas-04 | refusals - FAILED, nothing executed: membership disabled, login identity deleted, user session gone, portal user disabled (can_login() false) | php | users.is_enabled 0 / login_users.deleted_at / _user_sessions row deleted / portal status SUSPENDED, after dispatch | FAILED with "may no longer act" / "was deleted since" / "no longer exists" / "disabled or deleted"; empty state | implemented | 2026-10-21 |
| task-runas-05 | a signed-out sign-in is NOT a refusal | php | A's user session signed out (idle) after dispatch | COMPLETED | implemented | 2026-10-21 |
| task-runas-06 | the impersonator recorded on the user session is declared during the run | php | user session with impersonator_login_user_id | `get_impersonator_login_user_id()` answers it | implemented | 2026-10-21 |
| task-runas-07 | a portal sign-in's run executes in the portal realm as that portal user, and the realm is cleared after | php | portal user session fixture dispatch | portal true, portal user id, site 1, actor Portal_User_Model | implemented | 2026-10-21 |
| task-runas-08 | every worker run ends blank: after a dispatcher run, a plain run in the same worker sees no identity, site, actor, browser, sign-in, audit session or portal realm, and the server timezone rather than the dispatcher's | php | dispatcher run (A's timezone set) + plain run, one in-process worker | run order dispatcher then plain; plain state blank; timezone = server's | implemented | 2026-10-21 |
| task-runas-09 | Task::internal() runs in its caller's context and restores it after a task that rewrote identity, site, declared tenant, realm and audit session | php | bound virtual session as A; internal as_dispatcher, then rewrites_context | state names A; caller's identity, site, sign-in, audit session and staff realm back; no declared tenant | implemented | 2026-10-21 |
| task-runas-10 | an inline run with a declared identity and no sign-in runs as it: identity, membership, site, actor; no browser, sign-in or audit session; the row's dispatched_by names it, user_session_id null; the caller keeps its identity | php | `__acting_as_user(1)`; `Task::internal()` | COMPLETED; state names user 1; row Inline, dispatched_by User_Model 1 | implemented | 2026-10-21 |
| task-runas-10b | the test seam: `__run_task_method()` after `__acting_as_user()` runs as that user | php | `__run_task_method(as_dispatcher)` | state user 1, actor User_Model 1 | implemented | 2026-10-21 |
| task-runas-10c | a portal user declared in the portal realm is an actor for an inline run | php | portal realm, `Portal_Session::set_site_id(1)` + `set_portal_user_id()`; `Task::internal()` | portal true, portal user, actor Portal_User_Model, dispatched_by the portal user | implemented | 2026-10-21 |
| task-runas-10d | an inline run with nobody acting is refused before a row is written, naming the CLI flags; a site alone is nobody | php | session reset; `Task::internal()`, `__run_task_method()`; then `__acting_as_site(1)` | "nobody is acting here", "rsx:task:run Task_Run_As_Fixture_Service as_dispatcher --user=<id|email> --site=<id>"; no row; nothing ran | implemented | 2026-10-21 |
| task-runas-11 | rsx:task:run without --user refuses a dispatcher task naming the flags, recording nothing; --site alone is not an actor | cli | `rsx:task:run Task_Run_As_Fixture_Service as_dispatcher [--site=1]` | exit 1; "[ERROR] ... runs as the user who dispatched it (#[Run_As_Dispatcher]). From the command line, say who it acts as: --user=<id|email> --site=<id>" | implemented (`Task_Run_As_Dispatcher_Cli_Test`) | 2026-10-21 |
| task-runas-11b | rsx:task:run --user=<email> --site=<id> runs a dispatcher task inline as that user, and --site may be omitted for a one-site identity | cli | user 1's email + site 1; then --user=1 | exit 0; Inline COMPLETED; state user 1 / site 1 / actor; no browser, sign-in, audit session; dispatched_by 1; params empty | implemented (`Task_Run_As_Dispatcher_Cli_Test`) | 2026-10-21 |
| task-runas-11c | the flags resolve exactly as rsx:ajax's (Rsx_Cli_Actor): unknown user, unknown site, --site required across several sites, the chosen site's users row | cli | --user=nobody@...; --site=987654; an identity on two new sites, without then with --site | "[ERROR] User not found", "[ERROR] Site ID not found", "has users rows on sites A, B; pass --site=<id> to choose one"; site B's users row | implemented (`Task_Run_As_Dispatcher_Cli_Test`) | 2026-10-21 |
| task-runas-11e | a disabled membership, then a disabled site, is refused before anything runs - as a sign-in and a worker refuse it | cli | an identity with a disabled users row on a new site; then the row enabled and the site disabled | exit 1; "membership on site N is disabled", then "Site N is disabled"; no `_tasks` row | implemented (`Task_Run_As_Dispatcher_Cli_Test`) | 2026-10-21 |
| task-runas-11d | the flags work for an ordinary task and are never parameters; a #[Command] alias passes them to the task and acts as nobody | cli | `rsx:task:run Test_Echo_Service echo_params --user=1 --site=1 --a=1`; `rsx_test:echo --user=someone --site=2` | echo {a:1}, dispatched_by 1; echo {site, user}, dispatched_by null | implemented (`Task_Run_As_Dispatcher_Cli_Test`) | 2026-10-21 |
| task-runas-12 | claim order: a dispatcher run beats an older plain on-demand run, which beats a due schedule; within a tier the oldest due run goes first whatever its id | php | due schedule; two plain runs (the later id older); a dispatcher run; one scheduled-pool worker | order dispatcher, first, second, schedule | implemented | 2026-10-21 |
| task-runas-13 | TASK-RUN-AS-01 names #[Run_As_Dispatcher] without #[Task], beside #[Schedule], beside #[Command] | php | attribute name lists | one problem each; none for a plain dispatched task | implemented | 2026-10-19 |
| task-report-bar-01 | `<Task_Report $bar=true>` on a LIVE run with no progress reported draws the empty bar reading "0%" (progress and progress_count); without $bar, and on a finished run that never reported, it shows "(Not reported)" | playwright | a pending run of the sample task (scheduled a day out), mounted on /_sys | "0%" with a bar / "(Not reported)" | planned - verified by hand 2026-10-19 through rsx:debug --eval; an automated version needs a browser-reachable way to stage a pending run | 2026-10-19 |
| task-errlog-01 | a PHP warning in a run (raised as an ErrorException) fails it and writes `[TASK #<id>] <Service>::<method>: ErrorException: ...` to the Laravel log | cli | `cli/Task_Error_Log_Cli_Test.php`, `rsx:task:run` of `raises_warning` | run FAILED with the warning; the log gains the prefixed line | implemented | 2026-10-27 |
| task-errlog-02 | a FATAL error mid-run (memory exhausted) is recorded on the run as `PHP Fatal error: ...` and logged under the run's id | cli | `rsx:task:run` of `dies_of_a_fatal_error` | inline run settled FAILED with the fatal in its error; the log gains the prefixed fatal line | implemented | 2026-10-27 |
| task-errlog-03 | a failure the task RETURNS writes no log line | cli | `rsx:task:run` of `returns_kind --kind=false` | run FAILED; no `[TASK #<id>]` line | implemented | 2026-10-27 |

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| TASK-LIST-01 | `rsx:task:list` lists a concrete service at any depth below `Rsx_Service_Abstract` (one under an abstract intermediate) and never the abstract | php | `Task_List_Fixture_Service` extends `Task_List_Fixture_Base_Abstract` | the service and its task listed, the base not | implemented | 2026-10-28 |

