---
name: background-tasks
description: "Writing, running and watching RSpade background tasks - #[Task] service methods, the Task_Instance reporting API (status(), progress(), progress_count(), eta(), state(), state_list(), message(), stdout()/stderr(), attach_file()/attach_bytes(), summary(), heartbeat(), flush()), the return contract (null/true/0 = success, an integer = return code), Task::dispatch / Task::internal and the Task_Run_Model run row, #[Schedule] recurrence, #[Exclusive]/#[Debounce] identities, the on_demand/scheduled/kill worker pools, lifecycle control (request_stop, force_stop, force_kill, cancel, rerun, rsx:tasks:stop), the deny-by-default task gates (Task_Gates, task.view.authorize, task.view.scope, task.control.authorize), Rsx_Task in JS, and the widgets Task_Status_Badge, Task_Report, Task_Report_Browser and Task_Output. Use when adding a scheduled job, a queued background job, a cleanup/import/report task, making a long task stoppable (is_stop_requested), reporting progress, showing a user the progress or output of a task they started, letting users stop or rerun their runs, or debugging a task that 'returned an array' and failed, a widget that says Unavailable, or a run stuck Pending."
---

# Background Tasks

A task is a `public static` method on a Service class (`Rsx_Service_Abstract`, in `/rsx/services/`) marked `#[Task]`. There is no job class, no queue driver, no `queue:work` daemon - the framework owns the durable queue, the worker pools, the schedule and the recovery of work whose worker died.

```php
// /rsx/services/contact_export_service.php - dispatched by an "Export to CSV" button
class Contact_Export_Service extends Rsx_Service_Abstract
{
    #[Task('Export contacts to CSV')]
    public static function export(Task_Instance $task, array $params = [])
    {
        $task->status('Loading contacts');
        $contacts = Contact_Model::where('client_id', (int) $params['client_id'])->result_set();
        $total = $contacts->count();

        $task->status('Writing the file');
        $csv = Writer::createFromString();              // League\Csv\Writer
        $done = 0;
        foreach ($contacts as $contact) {
            if ($task->is_stop_requested()) {           // answer a graceful stop between rows
                $task->summary("Stopped after {$done} of {$total} contacts.");
                return null;                            // settles STOPPED
            }
            $task->heartbeat();                         // still alive, for the watchers
            $csv->insertOne([$contact->full_name(), $contact->email]);
            $done++;
            $task->progress_count($done, $total);       // the ONE progress indicator
        }

        // The initiator is waiting for this file: an attachment is the right delivery.
        $task->attach_bytes('export', $csv->toString(), 'contacts.csv', 'text/csv');
        $task->summary("{$total} contacts exported.");
        return null;                    // null, true or 0 = success
    }
}
```

The signature is fixed: `(Task_Instance $task, array $params = [])`.

**Every execution is a run, and every run is a `_tasks` row** (`Task_Run_Model`): a dispatched run, each run of a `#[Schedule]`, and every inline run (`Task::internal()`, `rsx:task:run`, a `#[Command]`). The task WRITES its reports through `$task`; outside code READS them through `Task_Run_Model` and acts on the run through its lifecycle methods.

---

**A complete example to read:** `system/app/RSpade/Sys/app/sys/tasks/_Sys_Oregon_Trail_Service.php` - a scripted three-minute Oregon Trail game that uses every report and answers a graceful stop (start it from `/_sys/tasks`, "Play the sample task", or `php artisan rsx:task:run _Sys_Oregon_Trail_Service travel`).

## The return contract

The return value is the **return code**, compared with `===`:

| Return | Outcome |
|---|---|
| `null`, `true`, `0` | success - COMPLETED (STOPPED when a stop had been requested) |
| `false` | FAILED, return code 1 |
| any other int | FAILED, that return code |
| anything else (an array, a string) | FAILED, return code 1 - **a task never returns data** |
| a throw (any `Throwable`) | FAILED, return code 1, the exception recorded as the error |

Data goes in `summary()`, `state()`, `state_list()` or an attachment. A failure is **never retried** - only work a dead worker ABANDONED is (below).

---

## Reporting - the whole `Task_Instance` API

```php
$task->heartbeat();                         // "still alive" (plays no part in worker liveness)
$task->status('Importing page 3');          // one line, replacing the last; a CHANGE also goes to stderr
$task->progress(45.5);                      // 0-100, two decimals
$task->progress_count(3, 257);              // "3 of 257" (percentage derived from it)
$task->eta(120);                            // seconds from now; stored as a moment
$task->state(['done' => 3, 'last' => 'x']); // a JSON state object, replacing the last
$task->state_list(['item 4', 'item 5']);    // a LIST (sequential keys), replacing the last; exists once it has held an item
$task->message('Halfway');                  // kept, in order, for watchers
$task->stdout('line');  $task->stderr('line');   // output lines; echo/print are stdout too
$task->attach_file('report', $path, 'report.csv');          // named file -> blob store
$task->attach_bytes('report', $bytes, 'report.csv', 'text/csv');   // same name again replaces
$task->summary('480 rows exported.');       // the completion summary
$task->flush();                             // write held reports now
$task->is_stop_requested();                 // the cooperative stop check
$dir = $task->get_temp_dir();               // removed when the run ends
$task->get_id(); $task->get_class(); $task->get_method(); $task->get_params();
```

**Reports are written at a capped rate, not per call** (`Task_Instance::FLUSH_INTERVAL`, 0.25 s): the first after a quiet spell goes at once, later ones wait for the next report call, `is_stop_requested()`, `flush()` or the end of the run. Report on every item of a loop freely; call `flush()` before a long silent step. That is a write rate, not a timeout.

**Echo is captured as stdout** wherever the run executes, line by line, and passed through unchanged. A console runner echoes the task's stdout/stderr live to its own streams; nothing else does - a task run from a web request prints to nobody's console.


## Designing a task's reporting

**Write every task for its watchers.** A run is shown live in `/_sys/tasks` and, through the app's gates, to the user who started it - its reports are all anyone sees of it. Use every report that describes the task's work, and none that does not:

- `status()` - what it is doing now; nearly every multi-step task has one (each change is also a stderr line, so the output reads as a narrative).
- `stdout()` / `stderr()` - the narrative: what was done, and what went wrong without stopping the run (a skipped record, a retried call).
- **ONE progress indicator, only if the task has measurable progress.** `progress_count($done, $total)` when it counts items, `progress($percent)` when its measure is not a count - never both. A task whose only honest values are 0% and 100% (one document converted, one remote call) reports NO progress: `status()` and the lifecycle already say working / done.
- `eta()` when it can estimate; `state()` / `state_list()` for working state worth inspecting (counters, the queue ahead); `message()` for milestones; `summary()` at the end.

**A task that loops - over a queue, records or steps - calls `is_stop_requested()` and `heartbeat()` on every item** (see the example above). Without the stop check a graceful stop is never answered and only a kill ends the run; check between items, where stopping leaves the data coherent, and return `null` so the run settles STOPPED. The heartbeat lets a watcher tell a slow item from a stuck one. Both are cheap (reports are written at a rate). A single long step with no loop needs neither, beyond a `flush()` before it goes quiet.

**Attachments are for ONE shape of task:** a one-time run started by a user action that produces a FILE the initiator is waiting for - they poll or watch the run and take the file when it completes (the CSV export above). The attachment spares that run a storage table, a download endpoint and a cleanup of its own. A run may also attach an advanced DEBUG artifact of itself. **Never use one to deliver results that belong to the application** - converted documents, imported records, a report everyone reads: write those into the app's own tables and files, where they are kept and authorized. Attachments expire with the run's output (`rsx.tasks.retention`), are readable only through the task gates, and belong to no record.

---

## Starting a run

```php
$id  = Task::dispatch('Report_Service', 'generate', ['month' => 12]);   // run id
$run = Task_Run_Model::find($id);
$run = Task::internal('Report_Service', 'generate', ['month' => 12]);   // settled Task_Run_Model
```

```bash
php artisan rsx:task:run Report_Service generate --month=12   # inline; stdout/stderr/exit code
php artisan rsx:task:list                                     # discovered tasks
php artisan rsx:tasks:list                                    # pools, running runs, schedules
```

- **`dispatch()`** writes a PENDING row and, when due now, spawns a detached worker if a pool has room. Options: `scheduled_for` (a FUTURE value is the only thing that defers a run; refused - it throws - for an `#[Exclusive]`/`#[Debounce]` task, which times its own runs) and `timeout`. Any other option throws - there is no `queue`.
- **`Task::spawn_workers(false)`** makes dispatch enqueue ONLY for the rest of the process - the cron tick drains it. A long-running script that writes many rows calls it first (`rsx:man scripting`). **Under the test suite it is OFF by default**: a test drives queued work with `Task::internal()` or `Artisan::call('rsx:task:worker')`; a test whose subject is the spawn opts in with `Task::spawn_workers(true)`.
- **`Task::internal()`** runs in this process and returns the settled row. A throw settles FAILED and is rethrown; a failure return comes back as the row (read `status_id`, `return_code`).
- **A task worth invoking by hand gets its own artisan command**: `#[Command('myapp:report', 'Generate the monthly report')]` beside the `#[Task]`. Skill `rspade:task-commands`.

The row records `dispatched_by` (the signed-in identity, a type-ref pair) and `site_id` - what a view gate keys on.

---

## Reading a run

```php
$run->status_id;  $run->status_id__label;  $run->is_live();  $run->is_terminal();
$run->status_text();  $run->progress_percent();  $run->progress_count();  $run->eta_at();
$run->state();  $run->state_list();  $run->summary();  $run->return_code;  $run->error;
$run->output_after($after_id, ['stdout', 'stderr', 'operator']);   // cursor page
$run->messages_after($after_id);
$run->attachment('report')?->download_response();                  // or read_bytes()
$run->available_reports();  $run->to_status_array();

Task_Run_Model::find_first_1000(['class' => 'Report_Service', 'live' => true]);
Task_Run_Model::find_page($filter, $cursor);    // ['tasks' => [...], 'next_cursor' => ?string]
```

Statuses: Pending, Running, Completed, Failed, Stopped, Killed, Cancelled (`Task_Run_Model::STATUS_*`). Search keys and every reader: `rsx:man tasks` (READING A RUN). These are ungated model reads - an endpoint showing runs to a user goes through the gates.

---

## Stopping, killing, cancelling, rerunning

```php
$run->request_stop($why);         // GRACEFUL: sets stop_requested_at; nothing is ever killed
$run->force_stop(30, $why);       // ask, then kill the worker if still running after 30 s
                                  //   (default rsx.tasks.stop_grace_seconds, 60)
$run->force_kill($why);           // kill now (a pending run is cancelled instead)
$run->cancel($why);               // pending only: never runs
$new_id = $run->rerun();          // finished only: the same task + params, dispatched again
```

```bash
php artisan rsx:tasks:stop 327                       # graceful
php artisan rsx:tasks:stop 327 --force --grace=30    # force stop
php artisan rsx:tasks:stop 327 --kill                # force kill
php artisan rsx:tasks:cancel 327
php artisan rsx:tasks:kill-all --explanation="..."
```

**A stop is COOPERATIVE.** It interrupts nothing: the task stops early only if it calls `$task->is_stop_requested()` between units of work and returns when it answers true - leaving its data consistent and saying what it did in `summary()`. The run then settles STOPPED. Only a force stop or kill ends a task that does not check.

**Kills run in kill workers** (`rsx:task:killer`, the kill pool), on the target's host, never inside the request that asked: the request is recorded, a kill worker waits out the grace re-reading the run (a run that ended on its own makes it moot), then SIGKILLs the worker and settles the run KILLED. **A run inside a web request is never signalled** - only a graceful stop applies to it. Every operation is written to the run's output as an OPERATOR line naming who did it.

---

## Showing a run to users - gates, `Rsx_Task`, widgets

**The task gates DENY BY DEFAULT.** With no handler, nobody but a developer (`Session::is_developer()`, which passes every gate) may see a run or act on one. The application decides, in `/rsx/handlers/`:

| Event (staff / portal `portal.`-prefixed) | Kind | Data |
|---|---|---|
| `task.view.authorize` | gate (return `true`) | `{task, user}` |
| `task.view.scope` | filter (return the narrowed builder) | the `Task_Run_Model` query |
| `task.control.authorize` | gate | `{task, user, action}` - stop, force_stop, force_kill, cancel, rerun |

Keep the gate and the scope the same rule. Worked example: `system/app/RSpade/resource/reference_app/handlers/Task_Gate_Handlers.php` (a user sees, stops, cancels and reruns the runs THEY started; never force-kills). PHP asks `Task_Gates::can_view()`, `scope_viewable()`, `can_control()`.

The browser reads through `Rsx_Task_Controller` (public surface; the gates decide; a run the viewer may not see is "not found"):

```javascript
const status = await Rsx_Task.get(id);          // to_status_array() + can: {stop, cancel, ...}
const {lines, last_id} = await Rsx_Task.output_after(id, null);
const {tasks, next_cursor} = await Rsx_Task.page({live: true}, null, 50);   // gated by the scope
await Rsx_Task.stop(id);  await Rsx_Task.force_stop(id, 30);  await Rsx_Task.rerun(id);
this.subscribe('Task_Changed_Topic', {id}, () => this.refresh());   // lifecycle + reports
this.subscribe('Task_List_Changed_Topic', {}, () => this.refresh()); // runs start/finish
this.subscribe('Task_List_Changed_Topic', {class: 'Import_Service', method: 'run'}, cb);  // ONE task's runs
```

**Is a task running?** Watch it BY NAME and ask on each frame - the resync on subscribe gives the first answer:

```javascript
await Rsx_Task.watch_task('Import_Service', 'run', async () => {        // method null = whole service
    const runs = await Rsx_Task.live_runs('Import_Service', 'run');     // pending + running, gated
    show_import_busy(runs.length > 0);
});
```

The list frame carries `{class, method}` (the simple service name) and filters match shallowly, so `{}` hears every run, `{class}` one service, `{class, method}` one task. The frame is only "a run of this task changed"; `live_runs()` is the gated search.

`Task_Output_Topic {id}` carries output lines. Frames are "go look" only - refetch with `refresh()`.

**Widgets** (Core, every bundle, each live and gated):

```html
<Task_Status_Badge $task_id=id />                      <%-- or $task=row from find()/page() --%>
<Task_Report $task_id=id $kind="progress_text" $bar=true />
<Task_Report_Browser $task_id=id />                    <%-- every report the run set --%>
<Task_Output $task_id=id />                            <%-- xterm.js console, stdout/stderr filter --%>
```

`Task_Report` kinds: status_text, progress, progress_count, progress_text, eta, heartbeat, state_json, state_list, messages, summary, return_code. Size widgets with CSS on the host. Reference screen: `system/app/RSpade/resource/reference_app/app/frontend/system/tasks/`.

---

## Recurrence: `#[Schedule]`

```php
#[Task('Drain the outgoing email queue')]
#[Exclusive]
#[Schedule('every 5 minutes')]
public static function drain(Task_Instance $task, array $params = []) { ... }
```

Standard cron works (`'0 3 * * *'`), but **prefer the phrases**: `'every minute'`, `'every 5 minutes'`, `'hourly'`, `'daily at 2am'`, `'weekly on monday at 9:30am'`, `'monthly'`. **Why: a cron step token is `*` followed by `/`, which terminates a block comment and corrupts the file.**

Each declaration is a `_task_schedules` row (cadence + statistics); **each run of it is its own run row** (origin Scheduled). Edits take effect within one cron tick. A failing run does not stop the schedule: it runs again at its next cadence, the failure streak is counted, and `rsx:health` WARNs at `rsx.tasks.failing_schedule_warn_after` (3). Cron installs the tick:

```
* * * * * php artisan rsx:task:process
```

---

## Concurrency - the one thing you must get right

**Tasks run concurrently and UNGUARDED.** Nothing takes a global application lock. Multiple workers run at once and your task shares the database with web requests and other tasks. **A `#[Task]` must not assume it is the only writer.**

| Marker | Identity | Meaning |
|---|---|---|
| `#[Exclusive]` | `class::method` | at most one running + one pending, whatever the params |
| `#[Debounce(30)]` | `class::method` + the params | same, and the coalesced follow-up waits 30 s after the previous run COMPLETED |

Declaring both fails `rsx:check` (`TASK-CONCURRENCY-01`). `dispatch()` of a managed task returns THE pending run's id (existing or new); an inline run waits for a running instance. **They guard one identity against ITSELF**, never a shared table against other writers - take a lock for that:

```php
$token = RsxLocks::named_write_lock('rebuild_report_cache');
try { /* critical section - however long it takes */ }
finally { RsxLocks::release_lock($token); }
```

A worker releases any lock a task left held at the task boundary - and names it on the run's stderr and in a `[WORKER]` warning, because that is a defect. **Never spawn artisan with `passthru`/`exec_safe`/`shell_exec` from a task** - use `Rsx_Artisan`. Skill `rspade:locks-and-subprocesses`.

---

## Timeouts

**You never add a timeout to your own task.** The framework reaper caps a run at its `timeout` (a `dispatch()` option) or `rsx.tasks.default_timeout` (1800 s) and force-kills it through a kill worker. It caps POOL-WORKER runs only: an inline run (`Task::internal()`, `rsx:task:run`, a `#[Command]`) belongs to the process that started it and is never killed for time. That cap is framework infrastructure, and it is not a `#[Task]` argument.

---

## The worker pools

| Pool | Runs | Cap (`rsx.tasks.pools.<pool>.max_workers`) |
|---|---|---|
| `on_demand` | dispatched work | 3 (`RSX_TASK_MAX_WORKERS`) |
| `scheduled` | queued dispatched work FIRST, then due schedules | 1 |
| `kill` | force stops and kills | 10 |

**rsx-lockd is the one count of workers** (`Task_Pool`): a worker's pool membership IS its lifelong daemon connection - no heartbeat, lease or TTL. A worker admits itself under its pool's lock, claims with a guarded write, runs the task unlocked, settles under the lock. The cron tick settles an ABANDONED run (its worker gone) with no grace period: a dispatched run goes back to PENDING after `rsx.tasks.retry.base_seconds` (600) * 2^(n-1) and FAILS on the `attempts`-th (5) abandonment; a scheduled or inline run is FAILED. **So a dispatched task's body may run more than once when a worker dies under it - write it to be safe to re-run from the start.** Full contract: `rsx:man tasks`.

Retention: finished runs' output is cut to 150 lines and their attachments unlinked after 7 days; runs are purged after 21 days (`rsx.tasks.retention`, minutes).

---

## Troubleshooting

- **The run FAILED with "The task returned array".** A task returns a return code, not data. Move the data to `summary()`/`state()` and return `null`.
- **Dispatched, stays Pending.** Pools full (`rsx:health` "Task Worker Pools", `/_sys/workers`), a future `scheduled_for`, a retry backoff (`status_reason` says), a busy `#[Exclusive]` identity, `Task::spawn_workers(false)`, or inside a test. The cron tick picks it up.
- **A widget says "Unavailable" / `Rsx_Task.get()` answers not found.** The viewer fails `task.view.authorize` - with no handler nobody but a developer passes.
- **A stop did nothing.** The task never calls `is_stop_requested()`; force-stop it. A run inside a web request is never killed.
- **`Cannot reach rsx-lockd ...`.** The daemon is down (`rsx:health` "Lock Server"); a dispatch's row IS written before the throw.
- **Refused under maintenance mode.** `rsx:task:process`/`:worker` are blocked (exit 75); `rsx:task:run` needs `--force`.

Details: `rsx:man tasks`, `rsx:man task_commands`, `rsx:man locks`, `rsx:man sys_panel` (the Tasks and Task Workers screens). Colocated: `system/app/RSpade/Core/Task/CLAUDE.md`. Related: `rspade:task-commands`, `rspade:locks-and-subprocesses`, `rspade:event-hooks`, `rspade:realtime`.
