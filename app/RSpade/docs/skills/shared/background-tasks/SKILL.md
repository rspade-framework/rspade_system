---
name: background-tasks
description: "Writing, running and watching RSpade background tasks - #[Task] service methods, the Task_Instance reporting API (status(), progress(), progress_count(), eta(), state(), queue_push()/queue_pop()/queue_remove()/queue_clear()/queue_depth(), message(), stdout()/stderr(), attach_file()/attach_bytes(), summary(), heartbeat(), flush(), run_again_in()), the return contract (null/true/0 = success, an integer = return code), Task::dispatch / Task::internal and the Task_Run_Model run row, #[Schedule] recurrence and Task::set_next_run(), maintenance tasks (#[Schedule('maintenance')] + #[Exclusive('maintenance')] in the rsx.tasks.maintenance_hour window), the silence limit (rsx.tasks.silent_timeout - a task that stops reporting is killed after 30 minutes), what a pending run is waiting for (Task_Waiting, Rsx_Task.waiting_text), #[Exclusive]/#[Debounce] identities, the on_demand/scheduled/kill worker pools, lifecycle control (request_stop, force_stop, force_kill, cancel, rerun, rsx:tasks:stop), the deny-by-default task gates (Task_Gates, task.view.authorize, task.view.scope, task.control.authorize), Rsx_Task in JS, and the widgets Task_Status_Badge, Task_Report, Task_Report_Browser and Task_Output. Use when adding a scheduled job, a nightly or weekly cleanup / retention / maintenance task, a queued background job, a cleanup/import/report task, making a long task stoppable (is_stop_requested), reporting progress, showing a user the progress or output of a task they started, letting users stop or rerun their runs, or debugging a task that 'returned an array' and failed, a widget that says Unavailable, a run stuck Pending, or a long task killed with 'no report for Ns'."
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

Data goes in `summary()`, `state()`, the queue or an attachment. A failure is **never retried** - only work a dead worker ABANDONED is (below).

---

## Reporting - the whole `Task_Instance` API

```php
$task->heartbeat();                         // "still alive" - what keeps a LONG run from being killed (below)
$task->status('Importing page 3');          // one line, replacing the last; a CHANGE also goes to stderr
$task->progress(45.5);                      // 0-100, two decimals
$task->progress_count(3, 257);              // "3 of 257" (percentage derived from it)
$task->eta(120);                            // seconds from now - ONLY when no progress is reported (below)
$task->state(['done' => 3, 'last' => 'x']); // a JSON state object, replacing the last
$task->queue_push_many(['item 4', 'item 5']); // THE QUEUE: what is ahead, a row per item (below)
$task->queue_pop();                         // the head is done
$task->message('Halfway');                  // kept, in order, for watchers
$task->stdout('line');  $task->stderr('line');   // output lines; echo/print are stdout too
$task->attach_file('report', $path, 'report.csv');          // named file -> a temp file (rsx:man temp_files)
$task->attach_bytes('report', $bytes, 'report.csv', 'text/csv');   // same name again replaces
$task->summary('480 rows exported.');       // the completion summary
$task->flush();                             // write held reports now
$task->run_again_in(600);                   // set this task's own #[Schedule] to run next in 10 minutes
$task->is_stop_requested();                 // the cooperative stop check
$dir = $task->get_temp_dir();               // removed when the run ends
$task->get_id(); $task->get_class(); $task->get_method(); $task->get_params();
```

**Reports are written at a capped rate, not per call** (`Task_Instance::FLUSH_INTERVAL`, 0.25 s): the first after a quiet spell goes at once, later ones wait for the first report call or `is_stop_requested()` after the interval, a `flush()` or the end of the run. `is_stop_requested()` reads the row every call (a stop is seen at once) but writes only at that rate. Report on every item of a loop freely; call `flush()` before a long silent step. That is a write rate, not a timeout.

**A LONG TASK MUST REPORT AS IT GOES, OR IT IS KILLED AFTER 30 MINUTES WITHOUT A REPORT.** A task has no limit on how long it runs so long as it keeps reporting that it is alive. A task expected to run long - "render a preview of everything in the system" - reports its status as it goes: `heartbeat()` and its progress on every item. A worker-run task that shows no sign of life for `rsx.tasks.silent_timeout` (1800 s) is taken for stuck and force-killed ("no report for Ns (cap 1800s)"). Every reporting call above counts (a repeated `status()` and a captured `echo` included); `is_stop_requested()`, `flush()` and `run_again_in()` do not. See **The silence limit** below.

**Echo is captured as stdout** wherever the run executes, line by line, and passed through unchanged. A console runner echoes the task's stdout/stderr live to its own streams; nothing else does - a task run from a web request prints to nobody's console.


## Designing a task's reporting

**Write every task for its watchers.** A run is shown live in `/_sys/tasks` and, through the app's gates, to the user who started it - its reports are all anyone sees of it. Use every report that describes the task's work, and none that does not:

- `status()` - what it is doing now; nearly every multi-step task has one (each change is also a stderr line, so the output reads as a narrative).
- `stdout()` / `stderr()` - the narrative: what was done, and what went wrong without stopping the run (a skipped record, a retried call). **Say what the run did, and say so when it did nothing**: name each thing as the run acts on it, with the outcome on the same line (`Sent #4812 to dana@example.com "Your invoice" (<id>)`), end with the totals, and print a line when idle (`No mail was due.`), skipped (with the reason) or stopped (with how far it got) - a scheduled run with no output is indistinguishable from one that never looked. Do not flood: one count line for bulk housekeeping, and a task that could list thousands of items lists the first couple of hundred and counts the rest.
- **ONE progress indicator, only if the task has measurable progress.** `progress_count($done, $total)` when it counts items, `progress($percent)` when its measure is not a count - never both. A task whose only honest values are 0% and 100% (one document converted, one remote call) reports NO progress: `status()` and the lifecycle already say working / done.
- **The ETA estimates itself from progress.** Every `progress()` / `progress_count()` call re-projects it from the pace so far (`elapsed * (1 - done) / done`); with nothing done yet, or a total of 0, it is cleared to null rather than guessed. **Call `eta($seconds)` only in a task that reports no progress** - the next progress report overwrites it. `state()` for working state worth inspecting (counters); **the queue** for what is still ahead (below); `message()` for milestones; `summary()` at the end.

**The queue is the report that shows whether a run is MOVING.** A percentage and a counter cannot tell a stuck run from one working through something large; the same items sitting at the head of the queue can. So it is advanced on every item, and built for that - one stored row per item, a push appends one, a pop removes one, nothing between is rewritten:

```php
$task->queue_clear();                      // clear + push_many DECLARES the queue
$task->queue_push_many($labels);           // a few hundred: push the whole list

foreach ($work as $item) {
    // ... the work ...
    $task->queue_pop();                    // the head is done
}
```

For a list too large to push, slide a window: `queue_push_many(array_slice($labels, 0, 100))`, then per item `queue_pop()` and `queue_push($labels[$i + 100])` when there is one. `queue_remove($item)` removes ONE item equal to the exact value pushed (the earliest - a queue may hold duplicates) for work finished out of order; `queue_depth()` counts it. **It is a report, not a work queue**: `queue_pop()` returns nothing and a task never drives itself from what it reported. The report exists once the queue has held an item. **Never batch your own reports** ("refresh the queue every 25th item") - the framework already holds writes to its rate, and batching only makes the watcher staler.

**A task that loops - over a queue, records or steps - calls `is_stop_requested()` and `heartbeat()` on every item** (see the example above). Without the stop check a graceful stop is never answered and only a kill ends the run; check between items, where stopping leaves the data coherent, and return `null` so the run settles STOPPED. The heartbeat lets a watcher tell a slow item from a stuck one **and is what keeps a long run alive** (the silence limit). Both are cheap (reports are written at a rate). A single long step with no loop needs neither, beyond a `flush()` before it goes quiet - but a step that can outlast 30 minutes is not one step: break it up so the task can report between the pieces.

**Attachments are for ONE shape of task:** a one-time run started by a user action that produces a FILE the initiator is waiting for - they poll or watch the run and take the file when it completes (the CSV export above). The attachment spares that run a storage table, a download endpoint and a cleanup of its own. A run may also attach an advanced DEBUG artifact of itself. **Never use one to deliver results that belong to the application** - converted documents, imported records, a report everyone reads: write those into the app's own tables and files, where they are kept and authorized. An attachment is a TEMP FILE (`Rsx_Temp_Files`, under `uploads/_temp`, never a shared blob): deleted with the run's output (`rsx.tasks.retention`), readable only through the task gates, belonging to no record. Output a page waits for that is NOT a task's is a temp file too - `Rsx_Temp_Files::store_bytes()`, served by the feature's own gated endpoint.

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
php artisan rsx:tasks:list                                    # the switch, pools, running runs, schedules
```

- **`dispatch()`** writes a PENDING row and, when due now, spawns a detached worker if a pool has room. Options: `scheduled_for` (a FUTURE value is the only thing that defers a run; refused - it throws - for an `#[Exclusive]`/`#[Debounce]` task, which times its own runs) and `timeout` (a HARD limit on that run, seconds from its start, reporting or not - only for a caller that wants one). Any other option throws - there is no `queue`.
- **`Task::spawn_workers(false)`** makes dispatch enqueue ONLY for the rest of the process - the cron tick drains it. A long-running script that writes many rows calls it first (`rsx:man scripting`). **Under the test suite it is OFF by default**: a test drives queued work with `Task::internal()` or `Artisan::call('rsx:task:worker')`; a test whose subject is the spawn opts in with `Task::spawn_workers(true)`.
- **`Task::internal()`** runs in this process and returns the settled row. A throw settles FAILED and is rethrown; a failure return comes back as the row (read `status_id`, `return_code`).
- **A task worth invoking by hand gets its own artisan command**: `#[Command('myapp:report', 'Generate the monthly report')]` beside the `#[Task]`. Skill `rspade:task-commands`.

The row records `dispatched_by` (the signed-in identity, a type-ref pair) and `site_id` - what a view gate keys on.

---

## Reading a run

```php
$run->status_id;  $run->status_id__label;  $run->is_live();  $run->is_terminal();
$run->status_text();  $run->progress_percent();  $run->progress_count();  $run->eta_at();
$run->state();  $run->queue($limit);  $run->queue_depth();  $run->summary();  $run->return_code;  $run->error;
$run->output_after($after_id, ['stdout', 'stderr', 'operator']);   // cursor page
$run->messages_after($after_id);
$run->attachment('report')?->download_response();                  // or read_bytes()
$run->available_reports();  $run->to_status_array();           // carries `waiting` for a pending run
Task_Waiting::of($run);                                        // what a PENDING run is waiting for, or null
Task_Run_Model::to_status_arrays($runs);                       // several runs, holders resolved once

Task_Run_Model::find_first_1000(['class' => 'Report_Service', 'live' => true]);
Task_Run_Model::find_page($filter, $cursor);    // ['tasks' => [...], 'next_cursor' => ?string]
```

**A pending run says what it is waiting for** - `{reason, text, lock, held_by_task_id}`, derived every time the run is read and NEVER stored, so it cannot go stale. The first that applies:

| `reason` | `text` |
|---|---|
| `scheduled` | `scheduled for <time>` (not due yet: a deferred dispatch, a debounce delay, a retry backoff) |
| `lock` | `waiting for exclusive lock "<group>" (held by task #<id>)` - or, with no group name, `waiting for exclusive lock (held by task #<id>)`. The parenthesis is left off in the moment a lock is taken before its run is marked running |
| `workers` | `waiting for a free worker (<busy> of <max> busy)` |
| `queued` | `waiting for a worker to start` |

Only the holder's run id is exposed to a viewer. The widgets show the line where a pending run has no status of its own; in JS it is `Rsx_Task.waiting_text(status)` (which formats the `scheduled` time in the viewer's timezone). When a run starts or settles, every pending run on the same run lock gets a `Task_Changed_Topic` frame, so a single-run view updates live.

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

Keep the gate and the scope the same rule.

**Least privilege - write the handlers like an AWS `policy.json`: grant only what your screens were built to do.** Each grant names WHO (the user type - realm, `User_Model` / `Portal_User_Model`, a role or ACL if only some users - and normally ownership: the run's `dispatched_by` is the viewer), WHAT (the specific tasks, `'Service::method'`, a screen shows them - never every task, never a pattern) and ACTIONS (per task, only what that screen offers: a progress panel needs view only; `force_kill` is a developer's, in `/_sys`). A task not granted stays invisible to users, even one they started. **Every new task, and every task whose behaviour changes, is checked against the handlers in the same change** - a grant added, re-checked, or deliberately absent.

Worked example: `system/app/RSpade/resource/reference_app/handlers/Task_Gate_Handlers.php` - a `USER_TASKS` map of `'Service::method' => [actions]`, granted to staff users on the runs THEY started, the scope narrowing to the same owner and the same tasks. PHP asks `Task_Gates::can_view()`, `scope_viewable()`, `can_control()`.

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

`Task_Report` kinds: status_text (for a pending run with none: what it is waiting for), progress, progress_count, progress_text, eta, heartbeat, state_json, queue, messages, summary, return_code. Size widgets with CSS on the host. A `queue` report fetches only the rows its box holds (single-line 24px rows; `Rsx_Task.report(id, 'queue', limit)` answers the first N plus `total`), shows "+N more" in the last row, and re-measures on `on_viewport_resize()` - so give it a sized box. Reference screen: `system/app/RSpade/resource/reference_app/app/frontend/system/tasks/`.

---

## Recurrence: `#[Schedule]`

```php
#[Task('Drain the outgoing email queue')]
#[Exclusive]
#[Schedule('every 5 minutes')]
public static function drain(Task_Instance $task, array $params = []) { ... }
```

Standard cron works (`'0 3 * * *'`), but **prefer the phrases**: `'every minute'`, `'every 5 minutes'`, `'hourly'`, `'daily at 2am'`, `'weekly on monday at 9:30am'`, `'monthly'`. **Why: a cron step token is `*` followed by `/`, which terminates a block comment and corrupts the file.**

### Maintenance tasks

Housekeeping that runs **once a day or less** - a retention purge, a cleanup, a disposal sweep, a nightly rebuild - is a MAINTENANCE task, and every one is built the same three ways:

1. **Scheduled in the window**: `#[Schedule('maintenance')]` (daily), `'maintenance weekly on sunday'`, `'maintenance monthly'` (the 1st). The window opens at the top of `rsx.tasks.maintenance_hour` (default `2`) on the server timezone's clock, so the operator moves every heavy job at once. Never hard-code `'daily at 3am'`.
2. **`#[Exclusive('maintenance')]`**: the window's tasks run one at a time across pools and hosts, and none overlaps its own next run (it replaces a bare `#[Exclusive]`).
3. **Says what it is doing**: `status()` before each phase, `progress_count()` over a counted backlog, `heartbeat()` + `is_stop_requested()` on every item, a `stdout()` line naming what changed - or that nothing was due - and a `summary()`. A run read the next morning must explain itself.

```php
#[Task('Purge export files older than the retention window')]
#[Exclusive('maintenance')]
#[Schedule('maintenance')]
public static function purge_exports(Task_Instance $task, array $params = [])
{
    $days = (int) config('exports.retention_days');
    $task->status("Deleting exports older than {$days} days");
    $deleted = 0;
    foreach (Export_Model::where('created_at', '<', now()->subDays($days))->result_set() as $export) {
        if ($task->is_stop_requested()) {
            break;
        }
        $export->delete();
        $deleted++;
        $task->heartbeat();
    }
    $task->stdout($deleted > 0 ? "Deleted {$deleted} export(s)." : 'No export is old enough; nothing deleted.');
    $task->summary("Deleted {$deleted} exports older than {$days} days.");
    return null;
}
```

Hourly and faster sweeps are not maintenance tasks and keep their own cadence. Changing `rsx.tasks.maintenance_hour` re-registers every maintenance schedule on the next tick. Framework examples: `Revision_Cleanup_Service::cleanup_revisions`, `Api_Cleanup_Service::cleanup_request_log`.

**The wall clock is the SERVER TIMEZONE** - `RSX_SERVER_TIMEZONE` in `.env`, UTC when unset - so `'daily at 2am'` with `America/Chicago` configured runs at 2am Chicago time. Daylight saving follows cron: a fixed-time schedule (no `*` leading the minute or hour field) whose wall time the spring-forward jump skips runs right after the jump, and one the fall-back jump repeats runs once; `'every 5 minutes'`/`'hourly'` run on real elapsed time. Changing the zone re-registers every schedule on the next tick (`rsx:man tasks`, SCHEDULES AND TIMEZONES).

**Setting the next run explicitly** - one run is moved, the cadence resumes by itself after it, and the tick's reconciliation leaves it alone:

```php
Task::set_next_run('Report_Service', 'nightly', '2026-10-12T02:00:00Z');   // any caller; an ISO moment
$task->run_again_in(600);                // inside the task: its OWN schedule, 10 minutes from now
```

Both throw for a task that declares no `#[Schedule]`. `run_again_in()` names the task, not the run, so it works whether the run was scheduled, dispatched or inline. It is how a long drain hands its worker back voluntarily: after N minutes with work still due, `run_again_in()`, say how much remains in the summary, `return null` (a successful run) - the framework's document worker does exactly this.

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
| `#[Exclusive('group')]` | `class::method`, plus one RUN lock shared by the group | the same per method, and at most one task of the GROUP running at a time |
| `#[Debounce]` | `class::method` + the params | at most one running + one pending per PARAMETER SET, no delay: the one queued follow-up is due the moment the running instance finishes |
| `#[Debounce(30)]` | `class::method` + the params | the same, and the coalesced follow-up waits 30 s after the previous run COMPLETED |
| `#[Debounce(30)]` + `#[Exclusive]` | `class::method` + the params | exactly `#[Debounce(30)]` alone - the bare `#[Exclusive]` adds nothing |
| `#[Debounce(30)]` + `#[Exclusive('group')]` | pending: `class::method` + the params; RUN lock: the group's | queued like a debounce (one pending per parameter set, the 30 s delay per parameter set), run like a group member (one task of the group at a time) |
| `#[Exclusive('a')]` `#[Exclusive('b')]` | `class::method`, plus the RUN locks of both groups | runs only while it holds BOTH groups' locks, taken all at once or not at all |

**An exclusive group is for tasks that all write one resource** (six sync tasks, one remote folder tree): every method naming the group runs one at a time across the cluster. Pending stays **per method**, so a member queued behind another is never replaced by a third - only a repeat of the same method coalesces. A pending run whose group is busy is passed over by every worker and **holds no worker slot while it waits**; the worker that finishes a member re-reads the queue at once. **`#[Exclusive]` is repeatable**: a task naming several groups needs every one of their locks, taken together or not at all, so a waiting run never holds part of them. **Never get this effect by taking a lock inside the task body** - by then the run occupies a slot, and waiters starve the pool. `Task_Concurrency::is_group_running('group')` asks without acquiring.

**The two markers compose, because a policy has two identities decided separately**: the PENDING identity (one pending run, the delay, the re-anchor on settle) and the RUN-LOCK identity (what excludes what while running). `#[Debounce]` sets the first to method + params; `#[Exclusive('group')]` sets the second to the group. So a debounced group member with a due run stays queued - no worker slot - while any other member runs, and starts in queue order; while it runs, a dispatch of the same params queues ONE follow-up at the end of the line and a dispatch with other params queues its own, which also waits for the group. A blocked run's wait line names every held group and its holder (`scheduled for ...` while its delay has not elapsed).

**Every call is a call, whatever made it.** `dispatch()`, a due `#[Schedule]` and an inline run all go through the same rules: a scheduled task can be started on demand at any time, and a schedule that comes due while that run is still going becomes its ONE pending follow-up (debounced by the delay, if any), exactly like a second dispatch. **A call never moves a pending run**: its due time is set when queued and re-anchored only when a run settles, so a due debounced run just waits for its locks and further calls join it. **Which run starts next**: at every claim a worker walks the due pending runs most overdue first and starts the first whose locks are ALL free right now; nothing reserves a lock, so a younger run needing one free lock goes ahead of an older run needing two, which runs in the first window where both are free (re-checked after every finished run and every cron tick).

`rsx:check` (`TASK-CONCURRENCY-01`) refuses only a malformed argument: `#[Debounce]` takes nothing or one non-negative integer, `#[Exclusive]` nothing or one non-empty group name. **The delay is optional**: a bare `#[Debounce]` (or `#[Debounce(0)]`) coalesces with no delay - the one queued follow-up is due the moment the running instance finishes. `dispatch()` of a managed task returns THE pending run's id (existing or new); an inline run waits for a running instance. **They guard one identity against ITSELF**, never a shared table against other writers - take a lock for that:

```php
$token = RsxLocks::named_write_lock('rebuild_report_cache');
try { /* critical section - however long it takes */ }
finally { RsxLocks::release_lock($token); }
```

A worker releases any lock a task left held at the task boundary - and names it on the run's stderr and in a `[WORKER]` warning, because that is a defect. **Never spawn artisan with `passthru`/`exec_safe`/`shell_exec` from a task** - use `Rsx_Artisan`. Skill `rspade:locks-and-subprocesses`.

---

## The silence limit

**A task has no limit on how long it runs so long as it keeps reporting that it is alive. The 30-minute limit is for a task that is NOT reporting.** A worker-run task whose last sign of life - the latest of its start, its last `heartbeat()` and its last written report - is older than `rsx.tasks.silent_timeout` (1800 s; 0 = none) gets a force kill from the cron tick and settles KILLED with `no report for Ns (cap 1800s)`.

**So a task expected to run long ("render a preview of everything in the system") must report its status as it goes - `heartbeat()` and progress on every item - or it will be killed after 30 minutes without a report.** A task that reports nothing is killed 30 minutes after it started.

- Every `Task_Instance` reporting call is a sign of life: `heartbeat`, `status` (a repeat too), `progress`, `progress_count`, `eta`, `state`, the `queue_*` calls, `message`, `stdout`/`stderr` and captured `echo`, `attach_*`, `summary`. `is_stop_requested()` is a read, not a report.
- An INLINE run (`Task::internal()`, `rsx:task:run`, a `#[Command]`) belongs to the process that started it and is never killed for time.
- A run dispatched with an explicit `timeout` option is under that HARD limit from its start instead (`timed out after Ns (cap Ns)`), reporting or not.

**You never add a timeout to your own task.** The silence limit is framework infrastructure and bounds silence, never work. A task that should give its worker back partway through a long job does it voluntarily with `$task->run_again_in()` (Recurrence, above).

---

## The worker pools

| Pool | Runs | Cap (`rsx.tasks.pools.<pool>.max_workers`) |
|---|---|---|
| `on_demand` | dispatched work | 2 (`RSX_TASK_MAX_WORKERS`) |
| `scheduled` | queued dispatched work FIRST, then due schedules | 1 |
| `kill` | force stops and kills | 10 |

**rsx-lockd is the one count of workers** (`Task_Pool`): a worker's pool membership IS its lifelong daemon connection - no heartbeat, lease or TTL. A worker admits itself under its pool's lock, claims with a guarded write, runs the task unlocked, settles under the lock. The cron tick settles an ABANDONED run (its worker gone) with no grace period: a dispatched run goes back to PENDING after `rsx.tasks.retry.base_seconds` (600) * 2^(n-1) and FAILS on the `attempts`-th (5) abandonment; a scheduled or inline run is FAILED. **So a dispatched task's body may run more than once when a worker dies under it - write it to be safe to re-run from the start.** Full contract: `rsx:man tasks`.

Retention: finished runs' output is cut to 150 lines and their attachments unlinked after 7 days; runs are purged after 21 days (`rsx.tasks.retention`, minutes).

---

## Turning the task system off and on

```bash
php artisan rsx:tasks:disable --reason="replacing the server" [--grace=30]
php artisan rsx:tasks:enable
```

One switch, **stored in the database** (one fact for every host sharing it). **While it is off, every task - dispatched or inline - QUEUES; nothing runs until `rsx:tasks:enable`; an inline caller waits for its answer.**

- `Task::dispatch()` writes its PENDING row as always; no worker is started, a worker claims nothing and exits, and no `#[Schedule]` produces a run. A schedule that came due meanwhile runs **once** after enable, then follows its cadence.
- **`Task::internal()`, `rsx:task:run` and a `#[Command]` queue the run PENDING and WAIT** - no limit, re-reading every 2 s; a command says once on stderr that it is waiting for `php artisan rsx:tasks:enable`. After enable the waiting process claims and runs its own run in-process and the caller gets its ordinary answer; if a worker claimed it first the caller returns that settled run. It executes exactly once. **A waiter that goes away loses nothing**: the process dies with the server, or a web server ends a request that outlived its limit, and the run stays queued for a worker - that caller just never sees the answer. So an endpoint that calls `Task::internal()` blocks while the system is off; where the answer is not needed in the request, `dispatch()`.
- `rsx:task:process` keeps settling dead runs and starting kill workers. No flag makes anything run.
- **`rsx:tasks:disable` is synchronous**: switch first, then every RUNNING run gets a force stop - asked to stop, killed after the grace (`--grace`, default `rsx.tasks.stop_grace_seconds`) - and it returns once this host's runs have settled. **Exit 0 = off and nothing running here**; 2 = off, with the inline web-request runs it lists still running (never signalled); another host's runs keep their kill request for that host's tick. Idempotent both ways.
- It is seen everywhere: every pending run's wait line reads `task system disabled - php artisan rsx:tasks:enable`, `rsx:tasks:list` leads with it, `rsx:health` FAILs its **Task System** row, `/_sys` Tasks and Task Workers show an alert. In PHP: `Task::is_enabled()`, `Task::disabled_state()`.
- `rsx:maintenance:enable` runs the disable and `rsx:maintenance:disable` the enable - so a task system you had turned off on purpose is ON after a maintenance window.

**Replacing a server**: `rsx:tasks:disable` -> wait for exit 0 -> replace it -> `rsx:tasks:enable` on the new one. A host lost without that leaves its RUNNING runs unsettled when it returns under another hostname (a recreated container's hostname is its id unless pinned): only the host that ran a worker can judge its pid.

## Troubleshooting

- **The run FAILED with "The task returned array".** A task returns a return code, not data. Move the data to `summary()`/`state()` and return `null`.
- **Dispatched, stays Pending.** The run says why - its `waiting` line names the task system being off (`php artisan rsx:tasks:enable`), a time, a lock and the run holding it, or the busy workers. Pools full (`rsx:health` "Task Worker Pools", `/_sys/workers`), a future `scheduled_for`, a retry backoff (`status_reason` says), a busy `#[Exclusive]` identity or exclusive group, `Task::spawn_workers(false)`, or inside a test. The cron tick picks it up.
- **A widget says "Unavailable" / `Rsx_Task.get()` answers not found.** The viewer fails `task.view.authorize` - with no handler nobody but a developer passes.
- **A long task was Killed: "no report for Ns (cap 1800s)".** It went 30 minutes without reporting. Call `$task->heartbeat()` and report progress on every item; a task that reports is never killed for how long it runs.
- **A stop did nothing.** The task never calls `is_stop_requested()`; force-stop it. A run inside a web request is never killed.
- **`Cannot reach rsx-lockd ...`.** The daemon is down (`rsx:health` "Lock Server"); a dispatch's row IS written before the throw.
- **Refused under maintenance mode.** `rsx:task:process`/`:worker` are blocked (exit 75); `rsx:task:run` needs `--force` to pass the gate - and inside a full window the task system is off, so it then queues and waits for the window to end.
- **A command or a request "hangs" in `Task::internal()`.** The task system is off and the call is waiting (the command said so on stderr): `php artisan rsx:tasks:enable`.

Details: `rsx:man tasks`, `rsx:man task_commands`, `rsx:man locks`, `rsx:man sys_panel` (the Tasks and Task Workers screens). Colocated: `system/app/RSpade/Core/Task/CLAUDE.md`. Related: `rspade:task-commands`, `rspade:locks-and-subprocesses`, `rspade:event-hooks`, `rspade:realtime`.
