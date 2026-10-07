# Test catalog: tasks

Status legend: `implemented` | `deferred` (reason) | `blocked` (reason) | `planned`.
Type: php / cli (given per section). Last updated: 2026-10-07.

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
| task-disp-05 | the row: PENDING, Dispatched, FQCN, params + params_hash, due now, default timeout, no worker | params | each column | implemented |
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

## Task_Pools_Test (php, default isolation) - the pools and what each claims

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-pools-01 | each pool's cap is rsx.tasks.pools.<pool>.max_workers; no global cap | config | 4 / '2' -> 2 / 1; global key absent | implemented |
| task-pools-02 | a cap below 1 or not an integer throws; an unknown pool throws | 0, -1, 1.5, null, 'three'; 'nightly' | RuntimeException | implemented |
| task-pools-03 | a worker joins only a task pool | --pool=kill | exit 1 | implemented |
| task-pools-04 | the scheduled pool runs due dispatched work before a due schedule; the schedule's run is its own row | due schedule + pending run; scheduled worker | B then A; pool_id Scheduled; origin Scheduled run; next_run_at = next cadence; last_task_id / last_success_at | implemented |
| task-pools-05 | the on_demand pool never runs a schedule | due schedule + pending run; on_demand worker | B only; schedule still due | implemented |
| task-pools-06 | a worker claims each due pending run once; not-yet-due and cancelled runs wait | due, future, cancelled rows; both pools' workers | A ran once; future PENDING; cancelled CANCELLED | implemented |
| task-pools-07 | a due schedule whose identity is running is coalesced | holder holds the exclusive identity; scheduled worker | no run row; next_run_at advanced | implemented |
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
| task-exec-06 | an older generation on this host is judged by pid | dead pid; live pid | abandoned; RUNNING | implemented |
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

## Task_Schedule_Visibility_Test (php, default isolation) - a failing schedule must be visible

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-vis-01 | rsx:health WARNs at the threshold, naming the offender and its error | schedule at threshold | WARN with identity, count, excerpt | implemented |
| task-vis-02 | below the threshold is OK | threshold - 1 | OK | implemented |
| task-vis-03 | rsx:tasks:list renders pools and the Schedules table | failing + healthy schedule | streak and first error line; dashes for healthy | implemented |

## Task_Timeout_Reaper_Test (php, $requires_db_reset + no-tx) - the timeout arm of rsx:task:process

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-timeout-01 | a live worker past its own timeout gets a force kill, carried out | live sleep pid, timeout 60, started 120 s ago | request (cap 60s), [TASK TIMEOUT]; after carry_out: process dead, KILLED | implemented |
| task-timeout-02 | inside its cap: untouched | timeout 600, 10 s | no request, RUNNING | implemented |
| task-timeout-03 | no run timeout inherits rsx.tasks.default_timeout | NULL, default 30, 90 s | request names cap 30s | implemented |
| task-timeout-04 | no cap anywhere: unbounded | NULL, default 0, a day | no request | implemented |
| task-timeout-05 | a run with a live request is not requested twice | two ticks | one request; second tick silent | implemented |
| task-timeout-06 | an INLINE run is never killed for time | inline row (no worker_id), live pid, cap 60, an hour in | no request, RUNNING, process alive, nothing reported | implemented |

## Task_Kill_Worker_Test (php, $requires_db_reset + no-tx) - force stops and kills carried out

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-kill-01 | a request binds host, pid, mode and due time; one live request per run and mode; another host's run | RUNNING row | fields; duplicate null; host = the worker's | implemented |
| task-kill-02 | only a running run with a worker pid can be requested | pending; no pid | null | implemented |
| task-kill-03 | a force kill signals the worker and settles KILLED | real sleep child, pool row, force_kill() | process gone; KILLED, reason, two operator lines; request DONE 'killed' | implemented |
| task-kill-04 | a run that ended first makes the request moot | stopped during the grace | MOOT; nothing signalled; verdict kept | implemented |
| task-kill-05 | a request whose worker changed is moot | worker_pid changed | MOOT; new worker not signalled | implemented |
| task-kill-06 | an inline run in a web request is never signalled | no worker_id, non-artisan pid | MOOT; alive; operator line | implemented |
| task-kill-07 | a worker already gone is settled KILLED without a signal | dead pid | killed_no_process | implemented |
| task-kill-08 | a killed scheduled run counts on its schedule | schedule + run | consecutive_failures 1, "killed: ..." | implemented |
| task-kill-09 | rsx:tasks:kill-all requires --explanation | no option | non-zero | implemented |
| task-kill-10 | kill-all kills this host's runs synchronously, queues another host's | local sleep run + remote run | local KILLED; remote PENDING request; "queued for host" | implemented |
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
| task-rep-05 | each report persists | heartbeat, status, progress, count, eta, state, state_list, summary, messages | each reader; messages in order and by cursor | implemented |
| task-rep-06 | a report replaces the last; one row per kind | two states, two summaries | the latest; 2 report rows | implemented |
| task-rep-07 | progress is clamped | 150, -5 | 100, 0 | implemented |
| task-rep-08 | a report refuses what it cannot record | negative count / eta, keyed state_list, NAN | InvalidArgumentException | implemented |
| task-rep-09 | status() writes a stderr line only when it changes | a, a, b (with newline), a | three lines, newline folded | implemented |
| task-rep-10 | a long status is cut to fit | 1500 chars | 1000 ending '...' | implemented |
| task-rep-11 | progress_percent is derived from a count; a reported percentage wins | 1 of 4; 0 of 0; 10% + count | 25; null; 10 | implemented |
| task-rep-12 | available_reports lists what was set, in a stable order | every report + settle | the ten names in order | implemented |
| task-rep-13 | output_after reads by cursor, stream and page | stdout, stderr, operator | order, streams, cursor, limit; unknown stream throws | implemented |
| task-rep-14 | to_status_array shape | running run with status + count | exact key list and values | implemented |
| task-rep-15 | a queue report exists once it has held an item | state_list([]); then ['a'], then [] | not reported; then reported, holding [] | implemented |

## Task_Output_Test (php, default isolation) - output lines, console streams, echo capture

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-out-01 | each line is a row on its stream | multi-line stdout/stderr, CRLF | one row per line, in order | implemented |
| task-out-02 | console streams echo each stream live; operator lines never | stdout, stderr, status twice, operator | stdout sink: stdout; stderr sink: stderr + one status | implemented |
| task-out-03 | a quiet runner (no stderr stream) still echoes stdout and records everything | null stderr | stdout echoed; both rows | implemented |
| task-out-04 | internal() with streams narrates the run and its failure | echo_params; always_fail | JSON on stdout, lines on stderr; failure line after the task's | implemented |
| task-out-05 | printed output is captured in order, passes through, is not echoed twice | prints_output | rows interleaved with the task's own; passthrough text; buffer level restored | implemented |
| task-out-06 | printed output survives a throw | prints_then_throws | FAILED; printed line recorded; buffer closed | implemented |

## Task_Attachments_Test (php, $requires_db_reset + no-tx) - named files a run attaches

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| task-att-01 | attach_bytes records the file and stores the blob | bytes | row fields, sniffed mime, blob by sha256, read_bytes/read_stream, download response | implemented |
| task-att-02 | attach_file copies a file and leaves the source | temp file | source name, given mime, bytes; source kept | implemented |
| task-att-03 | attachments are listed by name; a missing name is null | two names | alpha, zeta | implemented |
| task-att-04 | a bad name or unreadable file is refused | '', 256 chars, missing path | throws; nothing recorded | implemented |
| task-att-05 | replacing a name releases the orphaned blob | two attaches | one row; old blob gone | implemented |
| task-att-06 | replacing keeps a blob something else references | same bytes on another run | blob kept | implemented |
| task-att-07 | re-attaching the same bytes keeps the blob | same bytes twice | same storage; row updated | implemented |
| task-att-08 | an attachment is a declared blob reference | declarations; disposal | declared; is_referenced; release refused, then allowed once unlinked | implemented |

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
| task-rpt-01 | a state_list limit answers the first (oldest) items and the list's total | 5 items; limit 2, none, 50 | first 2 + total 5; all 5; all 5 | implemented |
| task-rpt-02 | a limit below 1 or not a whole number is refused | limit 0, -3, 'many' | Error_Response | implemented |
| task-rpt-03 | total is null for every other kind; a limit does not touch it | summary with limit 1 | the summary; total null | implemented |

## Deferred / planned

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| task-d-01 | an inline run whose process died mid-task is settled FAILED by its shutdown function | php | needs a child process that fatals inside a task (Task_Runner::settle_abandoned_inline) | planned |
| task-d-02 | a dispatch on this host spawns a kill worker for a force kill (Task::spawn_worker('kill')) | php | spawn path; under the suite spawns are off by default | planned |
| task-d-03 | rsx:tasks:list running table (worker PID, progress, status text) | cli | Schedules table covered by task-vis-03 | planned |
| task-d-04 | orphaned temp directory removal by Task_Retention_Service::remove_orphaned_temp_directories() | php | the run's own cleanup is covered; the sweep of a directory left by a killed run is not | planned |
