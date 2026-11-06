---
name: background-tasks
description: "Writing and watching background tasks - #[Task] methods, Task_Instance reporting (status(), progress_count(), heartbeat(), is_stop_requested(), summary()), the return contract, Task::dispatch / Task::internal, #[Schedule] incl. 'maintenance', #[Exclusive] / #[Debounce], #[Run_As_Dispatcher], per-item Site_Lock::write() sections, request_stop / rerun, rspade-taskd, Task::spawn_workers(false), system/script.php. Use when adding a scheduled or queued job, moving slow endpoint work to a task, or a run 'returned an array', is stuck Pending, was killed with 'no report for Ns', or failed with 'Site lock enforcement: ... by task'."
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

**A PHP error in a run also reaches the Laravel log**, prefixed `[TASK #<id>] <Service>::<method>: ` - a throw (a warning or notice raised as an `ErrorException`, an `Error`, an uncaught exception) and a FATAL that ends the process mid-run (which the run then records too, as `PHP Fatal error: ...`). Grep the log for `[TASK #` to find them. A failure the task RETURNS, and anything it writes to stderr itself, stays on the run only.

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

**Write every task for its watchers.** A run is shown live in `/_sys/tasks` and, through its task's `#[Task_Access]`, to the user who started it - its reports are all anyone sees of it. Use every report that describes the task's work, and none that does not:

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

**Attachments are for ONE shape of task:** a one-time run started by a user action that produces a FILE the initiator is waiting for - they poll or watch the run and take the file when it completes (the CSV export above). The attachment spares that run a storage table, a download endpoint and a cleanup of its own. A run may also attach an advanced DEBUG artifact of itself. **Never use one to deliver results that belong to the application** - converted documents, imported records, a report everyone reads: write those into the app's own tables and files, where they are kept and authorized. An attachment is a TEMP FILE (`Rsx_Temp_Files`, under `uploads/_temp`, never a shared blob): deleted with the run's output (`rsx.tasks.retention`), readable only through its task's `#[Task_Access]`, belonging to no record. Output a page waits for that is NOT a task's is a temp file too - `Rsx_Temp_Files::store_bytes()`, served by the feature's own gated endpoint.

---

## Starting a run

```php
$id  = Task::dispatch('Report_Service', 'generate', ['month' => 12]);   // run id
$run = Task_Run_Model::find($id);
$run = Task::internal('Report_Service', 'generate', ['month' => 12]);   // settled Task_Run_Model
```

```bash
php artisan rsx:task:run Report_Service generate --month=12   # inline; stdout/stderr/exit code
php artisan rsx:task:run Report_Service generate --user=ann@example.com --site=1   # inline, acting as a user
php artisan rsx:task:list                                     # discovered tasks
php artisan rsx:tasks:list                                    # the switch, pools, running runs, schedules
```

- **`dispatch()`** writes a PENDING row and, when due now, spawns a detached worker if a pool has room. Options: `scheduled_for` (a FUTURE value is the only thing that defers a run; refused - it throws - for an `#[Exclusive]`/`#[Debounce]` task, which times its own runs) and `timeout` (a HARD limit on that run, seconds from its start, reporting or not - only for a caller that wants one). Any other option throws - there is no `queue`.
- **A PHP script OUTSIDE the application** boots the whole shell with `$app = require '<project>/system/script.php';` and nothing else - same pre-boot guards, same manifest, same site (`rsx:man scripting`).
- **`Task::spawn_workers(false)`** makes dispatch enqueue ONLY for the rest of the process - the cron tick drains it. A long-running script that writes many rows calls it first (`rsx:man scripting`). **Under the test suite it is OFF by default**: a test drives queued work with `Task::internal()` or `Artisan::call('rsx:task:worker')`; a test whose subject is the spawn opts in with `Task::spawn_workers(true)`.
- **`Task::internal()`** runs in this process and returns the settled row. A throw settles FAILED and is rethrown; a failure return comes back as the row (read `status_id`, `return_code`).
- **A task worth invoking by hand gets its own artisan command**: `#[Command('myapp:report', 'Generate the monthly report')]` beside the `#[Task]`. Skill `rspade:task-commands`.

The row records `dispatched_by` (the signed-in identity, a type-ref pair), `site_id` - what `#[Task_Access('dispatcher')]` and the tenancy check key on - `user_session_id` (the sign-in it was started from), `audit_session_id` (the acting context) and `priority` (its claim tier).

**Every run a worker executes starts BLANK and ends BLANK** - a fresh virtual session before, `Session::_end_virtual()` after (identity, site, realm, impersonation, audit context, timezone all reset), so nothing one run was reaches the next on the same worker. A plain run acts as NOBODY: no identity, no site, `get_session_id()` and `get_audit_session_id()` null, session values throw - right for system work. `Task::internal()` runs in its caller's context and restores it afterwards.

### `#[Run_As_Dispatcher]` - REQUIRED on the async stand-in for a user's Ajax endpoint

**Any task that is a person's request moved off the request thread MUST declare `#[Run_As_Dispatcher]`** beside the `#[Task]`. The test: it is dispatched from a request on a user's behalf (an Ajax endpoint answers "started" and the work finishes in the background), and its writes should be attributed to that user, or it reads the user's identity or site. Without it the run acts as nobody - its revisions and `created_by`/`updated_by` name nobody and its site scope is empty.

```php
#[Task('Set the status of the selected records')]
#[Run_As_Dispatcher]
public static function bulk_set_status(Task_Instance $task, array $params = []) { ... }

// in the Ajax endpoint, as the signed-in user:
$run_id = Task::dispatch('Records_Service', 'bulk_set_status', ['ids' => $ids, 'status_id' => $status_id]);
```

- **Bound at dispatch**: the run records the requester's user session (`Session::get_user_session_id()`) and audit session; it is claimed BEFORE every other pending run.
- **Runs FROZEN as that sign-in** - identity, site, realm (a portal sign-in's run is a portal run; an API key's runs as the key's owner), impersonator, timezone and audit session are the requester's, **even after that browser signed out or signed into another account**. `get_session_id()` is the requester's browser, `get_user_session_id()` the user session. So revisions, `created_by`/`updated_by` and your own log name the person who clicked, from the request's own address and browser.
- **Frozen means**: `set_login_user_id()`, `logout()`, `set_site_id()`, `set_temporary_site_id()`, `begin/stop_impersonation()`, `impersonate()`, `reset()`, the portal setters and all three session-value calls THROW. Pass what the task needs in `$params`.
- **Refused, never run as nobody**: dispatched where there is no sign-in (a guest, a command, a plain task - a declared identity does not count, the worker rebuilds the person from the sign-in alone) it throws at dispatch; when the run starts and the login identity is gone, the membership or its site disabled or deleted, the portal user disabled or its site off, or the user session gone, the run FAILS with the reason and nothing executes. Your own account statuses are yours to check in the task.
- **Inline, it runs as whoever is acting** - `Task::internal()` in the caller's own context, not frozen: a request runs it as the request; a command or test runs it as the identity it declared (no browser, sign-in or audit session; `dispatched_by` names the identity); with nobody acting it is refused ("nobody is acting here", naming the flags below).
- **By hand, as a named person**: `php artisan rsx:task:run Records_Service bulk_set_status --user=<id|email> --site=<id>` - rsx:ajax's flags, resolved identically (`--site` required when the identity is on several sites); without `--user` it exits 1 naming them. Any task accepts the pair; a `#[Command]` alias does not (its options are the task's). Queueing such a run for a worker as a named user is not supported.
- **In a test**: `static::__acting_as_user($user_id); static::__run_task_method(Records_Service::class, 'bulk_set_status', $params);` (or `Task::internal()`) runs it as that user.
- **Never on a timer, never an alias**: `#[Schedule]` or `#[Command]` beside it is a build FATAL (`TASK-RUN-AS-01`). A rerun runs bound to whoever asked for it.

`rsx:man tasks`, RUNNING AS THE DISPATCHER.

### A system task that serves ONE tenant declares its site

Not every task is either a user's request or tenant-agnostic: a per-site sweep, a sync engine, a
recompute for one site is system work ABOUT one site. `#[Run_As_Dispatcher]` is wrong for it (there
is no requester, and it is refused on a `#[Schedule]`), and so is doing nothing: a plain run has
**no site**, and a site-scoped read there **THROWS** (`Site-scoped read of X_Model with no site
declared`). Passing the site through your own `where('site_id', $n)` does not help - the scope is
**ANDed** with it, never replaced by it. Declare the tenant for the span of the work and put the
prior state back:

```php
$prior = Session::has_temporary_site_id() ? Session::get_site_id() : null;
Session::set_temporary_site_id($site_id);       // writes nothing; has_session() answers true
try {
    // site-scoped models read $site_id here, find() included - no where('site_id') of your own
} finally {
    $prior === null ? Session::clear_temporary_site_id() : Session::set_temporary_site_id($prior);
}
```

A task looping over several sites declares each in turn. The declared site must exist - the first
scoped query checks it and throws for an id with no `sites` row. An `#[Emitter]` needs none of
this: the realtime engine declares the site around every emitter call (`rsx:man realtime`, EMITTERS).

**Use `set_temporary_site_id()` in a task, never the permanent `set_site_id()`.** Both work in a
virtual session, but a leaked `set_site_id()` is inherited by the next run on the same worker and,
run inline, repoints the caller's own session; the temporary one restores cleanly and refuses in an
API request and on a frozen `#[Run_As_Dispatcher]` run, where the tenant is not the task's to change.

### A task whose subject is EVERY tenant reads across sites - and writes each row under its own

An hourly "expire every pending invitation" or "unpin every note past its date" has no site to
declare. It reads with `Model::without_site_scope()` - but that is a bypass for what runs INSIDE the
callback, not for the rows it returns. **`save()` of an existing row whose `site_id` is not the
ambient one is REFUSED** (`shouldnt_happen`: "Attempted to save X with site_id 7 but current session
site_id is 0. This indicates a serious security issue." - the message names the symptom; the mistake
is saving outside the bypass at the wrong site). Two correct shapes:

```php
// A plain change to each row: write inside the same callback (the save check and the create
// stamp are suspended there too).
Note_Model::without_site_scope(function () {
    foreach (Note_Model::where('pinned_until', '<', now())->result_set() as $note) {
        $note->pinned_until = null;
        $note->save();
    }
});

// Writes that should run AS each tenant (its hooks, realtime frames, revisions): read across
// sites, then declare each group's site around its writes.
$rows = Note_Model::without_site_scope(fn () => Note_Model::where('pinned_until', '<', now())->get());
foreach ($rows->groupBy('site_id') as $site_id => $site_rows) {
    Session::set_temporary_site_id((int) $site_id);
    try {
        foreach ($site_rows as $note) { $note->pinned_until = null; $note->save(); }
    } finally {
        Session::clear_temporary_site_id();
    }
}
```

**A raw `DB::table()` / `DB::statement()` write is checked by nothing** - no scope narrows it and no
save guard stands in front of it. A site-scoped read with no site declared now fails loud; a raw
write that persists a value computed from it does not. Keep a task's writes on the model layer.

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

Statuses: Pending, Running, Completed, Failed, Stopped, Killed, Cancelled (`Task_Run_Model::STATUS_*`). Search keys and every reader: `rsx:man tasks` (READING A RUN). These are ungated model reads - an endpoint showing runs to a user goes through `Task_Gates` (`can_view()`, `visible_page()`).

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

## Showing a run to users - `#[Task_Access]`, `Rsx_Task`, widgets

**A run reaches the browser only through its task's `#[Task_Access]` - load skill `rspade:task-access` before showing one.** Deny by default: a task that declares none is visible to developers only (`/_sys` shows every run), and `Rsx_Task` prints the refusal to the console naming the task. The two shapes:

```php
#[Task('Import a contact file')]
#[Task_Access('dispatcher')]          // the user who started it may view, stop and cancel
public static function import(Task_Instance $task, array $params = []) { ... }

#[Task('Sync the file share')]
#[Task_Access('sync_access')]         // a check on the same service, asked per run and action
public static function sync(Task_Instance $task, array $params = []) { ... }

public static function sync_access(Task_Run_Model $run, $user, string $action): bool
{
    return $action === 'view' && Permission::can_manage_site_settings();
}
```

Starting a run is never generic: an endpoint of your own validates, checks access and calls `Task::dispatch()`. PHP asks `Task_Gates::can_view()`, `can_control()`, `visible_page()`.

The browser reads through `Rsx_Task_Controller` (public surface; `#[Task_Access]` decides; a run the viewer may not see is "not found"):

```javascript
const status = await Rsx_Task.get(id);          // to_status_array() + can: {stop, cancel, ...}
const {lines, last_id} = await Rsx_Task.output_after(id, null);
const {tasks, next_cursor} = await Rsx_Task.page({live: true}, null, 50);   // only task types declaring #[Task_Access]
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

`Task_Report` kinds: status_text (for a pending run with none: what it is waiting for), progress, progress_count, progress_text, eta, heartbeat, state_json, queue, messages, summary, return_code. Size widgets with CSS on the host. A `queue` report fetches only the rows its box holds (single-line 24px rows; `Rsx_Task.report(id, 'queue', limit)` answers the first N plus `total`), shows "+N more" in the last row, and re-measures on `on_viewport_resize()` - so give it a sized box. Reference screen: the `/_sys` Tasks screen, `system/app/RSpade/Sys/app/sys/tasks/` (`rsx:man sys_panel`).

---

## Recurrence: `#[Schedule]`

```php
#[Task('Drain the outgoing email queue')]
#[Exclusive]
#[Schedule('every 5 minutes')]
public static function drain(Task_Instance $task, array $params = []) { ... }
```

Standard cron works (`'0 3 * * *'`), but **prefer the phrases**: `'every minute'`, `'every 5 minutes'`, `'hourly'`, `'daily at 2am'`, `'weekly on monday at 9:30am'`, `'monthly'`. **Why: a cron step token is `*` followed by `/`, which terminates a block comment and corrupts the file.**

**What ticks the scheduler.** One cron line drives everything outside the container: `* * * * * php artisan rsx:task:process`, a tick every minute. **Inside the RSpade container the `tasks` supervisor program (`rspade-taskd`) runs that SAME tick only when work comes due** - it wakes at a schedule's own time, and whenever the application POKES it (`Task_Daemon::poke()`, done for you by dispatch and the rest) because the next due moment may have moved earlier - so an idle container queries nothing. A tick claims every schedule (and every pending run a schedule produced) due within the next 5 s (`Task_Daemon::SCHEDULE_LEAD_SECONDS`), so a tick landing a moment before 3:00 still runs the 3:00 run; a DISPATCHED run is due at its time exactly, so a short `#[Debounce]` delay is never cut short. `rspade-taskd status` shows the daemon. `rsx:man tasks`.

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

**A task takes NO automatic lock and no automatic transaction**, but its body is a site lock ENFORCEMENT WINDOW: a `save()` / `delete()` of a site-scoped model fatals (`Site lock enforcement: <Model> save on site N by task X::y (run #M) without site N's write lock`) unless it is inside **one `Site_Lock::write($site_id, fn () => ...)` section PER ITEM that RE-READS the record inside** (it may have changed or gone since the list was read). Inside a section nothing else writes the site, so the read and the write need no re-check; between sections other writers run - never one section around the whole run. A maintenance pass that tolerates concurrent writers (a queue drain, a sweep) uses `Site_Lock::bypass($site_id, '<reason>', fn)` / `bypass_all_sites('<reason>', fn)`; a view, audit or telemetry record needs no section - `Site_Lock::write_exception('<what it records>', fn)`. **Never call another system inside a section** (an HTTP API, mail, a subprocess that waits): call first, then write what it returned in a short section of its own (`rsx:man site_locks`, HOLDING A LOCK WELL). `#[Writer]` on a `#[Task]` fails the build (`SITE-LOCK-01`); a `#[Run_As_Dispatcher]` task follows the same rules. Worked example and rules: skill `rspade:site-locks`.

**The architecture principle: an Ajax or API endpoint returns right away.** Data-processing or export-shaped work (a pass over thousands of records, encoding, compression, a large aggregation, building a file) is a task: the endpoint validates, `Task::dispatch()`es, returns the run's handle; the caller waits for the run and fetches the result through the feature's own endpoint (the external API has no generic task interface). Every request holds its site's lock for its whole handler, so a slow endpoint holds back every writer on the site. The task then reads in read sections and writes in short per-item sections, and the feature's result endpoint transmits after its read section ends (read, release, transmit). A dispatch from a `#[Writer]` starts after its commit (or never, on rollback); a coalesced `#[Exclusive]` dispatch inside a writer may leave a second pending run, which is safe to repeat.

Multiple workers run at once and your task shares the database with web requests and other tasks. **A `#[Task]` must not assume it is the only writer.**

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

`rsx:check` (`TASK-CONCURRENCY-01`) refuses only a malformed argument: `#[Debounce]` takes nothing or one non-negative integer, `#[Exclusive]` nothing or one non-empty group name. **The delay is optional**: a bare `#[Debounce]` (or `#[Debounce(0)]`) coalesces with no delay - the one queued follow-up is due the moment the running instance finishes. `dispatch()` of a managed task returns THE pending run's id (existing or new); an inline run waits for a running instance. **They guard one identity against ITSELF**, never a shared table against other writers - a site's data takes `Site_Lock::write()` sections (above); other shared state a named lock:

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

## Worker pools, abandonment, and turning the task system off

Pools `on_demand`, `scheduled` and `kill` (`rsx.tasks.pools`); claim order is `#[Run_As_Dispatcher]` runs, then other on-demand runs, then scheduled ones. **A dispatched run whose worker dies is retried from the start (up to 5 abandonments), so write a dispatched task to be safe to re-run.** `rsx:tasks:disable [--reason=] [--grace=]` / `rsx:tasks:enable` switch the whole task system (a database fact for every host): while off every run queues and an inline caller waits. Pool caps, the abandonment backoff, retention, and the disable/enable contract (exit codes, replacing a server): [reference/operations.md](reference/operations.md).

## Troubleshooting

- **The run FAILED with "The task returned array".** A task returns a return code, not data. Move the data to `summary()`/`state()` and return `null`.
- **Dispatched, stays Pending.** The run says why - its `waiting` line names the task system being off (`php artisan rsx:tasks:enable`), a time, a lock and the run holding it, or the busy workers. Pools full (`rsx:health` "Task Worker Pools", `/_sys/workers`), a future `scheduled_for`, a retry backoff (`status_reason` says), a busy `#[Exclusive]` identity or exclusive group, `Task::spawn_workers(false)`, or inside a test. The cron tick picks it up.
- **A widget says "Unavailable" / `Rsx_Task.get()` answers not found.** The run's task does not admit the viewer. When the console says "declares no #[Task_Access], so the browser task API refuses it", the task needs a declaration (skill `rspade:task-access`); otherwise its check refused.
- **A long task was Killed: "no report for Ns (cap 1800s)".** It went 30 minutes without reporting. Call `$task->heartbeat()` and report progress on every item; a task that reports is never killed for how long it runs.
- **A stop did nothing.** The task never calls `is_stop_requested()`; force-stop it. A run inside a web request is never killed.
- **`Cannot reach rsx-lockd ...`.** The daemon is down (`rsx:health` "Lock Server"); a dispatch's row IS written before the throw.
- **Refused under maintenance mode.** `rsx:task:process`/`:worker` are blocked (exit 75); `rsx:task:run` needs `--force` to pass the gate - and inside a full window the task system is off, so it then queues and waits for the window to end.
- **A command or a request "hangs" in `Task::internal()`.** The task system is off and the call is waiting (the command said so on stderr): `php artisan rsx:tasks:enable`.

Details: `rsx:man tasks`, `rsx:man task_commands`, `rsx:man site_locks`, `rsx:man locks`, `rsx:man sys_panel` (the Tasks and Task Workers screens). Colocated: `system/app/RSpade/Core/Task/CLAUDE.md`. Related: `rspade:site-locks`, `rspade:task-commands`, `rspade:locks-and-subprocesses`, `rspade:event-hooks`, `rspade:realtime`.
