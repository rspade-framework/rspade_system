---
name: task-access
description: "Deciding who the browser may show a background task run to, and who may stop it - #[Task_Access('dispatcher')] (only the user who started a run may view, stop and cancel it), #[Task_Access('<check method>')] with a static check (Task_Run_Model $run, $user, string $action): bool on the same service, the realm: 'staff'|'portal'|'any' argument, deny by default (a task with no #[Task_Access] is visible to developers and /_sys only), and how that reaches Rsx_Task, Task_Report, Task_Output, Task_Status_Badge, Task_Report_Browser, the task realtime topics and Task_Gates::can_view() / can_control() / visible_page(). Use when a page shows a user the progress or output of a task they started (a file import, a mass edit, an export), an admin screen shows a sync or maintenance task's live output, a portal user watches a run, a widget says Unavailable, or the console prints \"declares no #[Task_Access], so the browser task API refuses it\" or \"declares #[Task_Access] for another realm\", or a manifest build fails with \"Invalid #[Task_Access]\" or \"Duplicate #[Task_Access]\"."
---

# Task Access

A run's status, reports, output and files are operator data, and stopping one is an operator
act. So the **browser task API** - `Rsx_Task`, the Core widgets (`<Task_Status_Badge>`,
`<Task_Report>`, `<Task_Report_Browser>`, `<Task_Output>`), the three task realtime topics
and task attachments - shows a run only when the run's **task type** says who may see it:

```php
#[Task('Import a contact file')]
#[Task_Access('dispatcher')]
public static function import(Task_Instance $task, array $params = []) { ... }
```

`#[Task_Access]` sits on the `#[Task]` method. It is the ONE place that decision lives - no
event handler, no app-wide policy - so the person writing a task decides who watches it in
the same breath.

## The two forms

| Declaration | Who passes |
|---|---|
| `#[Task_Access('dispatcher')]` | the identity that dispatched the run (its `dispatched_by` pair), for `view`, `stop` and `cancel` - nothing else, nobody else |
| `#[Task_Access('my_check')]` | whoever `my_check()` answers `true` for, per run and per action |

A check is a public static method **on the same service** (inherited is fine), with exactly
this signature:

```php
public static function my_check(Task_Run_Model $run, $user, string $action): bool
```

- `$action` is `'view'` (status, reports, output, messages, attachments, appearing in a list)
  or one of `'stop'`, `'force_stop'`, `'force_kill'`, `'cancel'`, `'rerun'` (`Task_Gates::ACTIONS`).
- `$user` is the realm's signed-in identity - `User_Model` on staff, `Portal_User_Model` on the
  portal - and is **never null**: a caller who is not signed in is refused before any check runs.
- Return a real `bool`; anything else throws.
- Keep it cheap: it is asked once per run per question, and for every row a list walks.

**`realm:`** names who may ask at all: `'staff'` (default), `'portal'`, or `'any'`. A task
declared for staff is refused to portal users, with its own console message, and vice versa.

Attribute arguments are **literals only** (never a class constant). The manifest build FAILS on
`#[Task_Access]` without `#[Task]`, on a class, declared twice, without a check name, with an
unknown argument or realm, or naming a check that is missing, not static or not three-argument.

## Deny by default

A task with **no** `#[Task_Access]` is visible and controllable by **developers only**
(`Session::is_developer()`, the `/_sys` panel). Everyone else:

| Caller | Gets |
|---|---|
| a developer (staff realm) | everything, no check asked - `/_sys` shows and controls every run |
| signed in, task undeclared (or declared for the other realm) | an `unauthorized` Ajax error naming the task |
| signed in, task declared, check says no | `not found` (a run, like a missing one) / `unauthorized` (an action) |
| not signed in | `not found`, never the task's name (anti-enumeration) |

The undeclared refusal carries `metadata.task_access` (`undeclared` / `wrong_realm`) and
`metadata.task` (`Service::method`). **`Rsx_Task` prints it with `console.error`** and still
rejects, so the widget shows "Unavailable" and the console says what to fix:

```
[Task access] Task Contact_Import_Service::import declares no #[Task_Access], so the browser
task API refuses it to everyone but developers. Add #[Task_Access('dispatcher')] (only the user
who started a run may watch, stop and cancel it) or #[Task_Access('<check method>')] to the
task. See skill rspade:task-access.
```

The fix is always on the TASK: add the declaration that matches who should watch it.
`Rsx_Task.is_access_undeclared(error)` tells your own code the error apart.

A list (`Rsx_Task.find()` / `page()` / `live_runs()`) simply leaves out undeclared task types -
unless its filter names ONLY such tasks (`{class, method}`), which is refused with the same
named error.

## Example 1 - an import watched by the user who started it

A file import or mass edit: the user uploads, the work runs in the background, the same user
watches it and may stop it. Nobody else sees it.

```php
class Contact_Import_Service extends Rsx_Service_Abstract
{
    #[Task('Import a contact file')]
    #[Task_Access('dispatcher')]
    #[Run_As_Dispatcher]                     // the import writes as the user who asked
    public static function import(Task_Instance $task, array $params = [])
    {
        $rows = Contact_Import::read_rows($params['file_attachment_id']);
        $task->progress_count(0, count($rows));
        foreach ($rows as $i => $row) {
            if ($task->is_stop_requested()) {
                return null;
            }
            Contact_Import::import_row($row);
            $task->progress_count($i + 1, count($rows));
        }
        $task->summary(count($rows) . ' contacts imported.');

        return null;
    }
}
```

The browser never starts the task. **An endpoint of your own** validates, checks access and
dispatches - the task is a privileged system call, not a web surface:

```php
#[Auth('is_logged_in', 'can_import_contacts')]
#[Ajax_Endpoint]
public static function start_import(Request $request, array $params = [])
{
    $attachment = File_Attachment_Model::find_by_key($params['file_key'] ?? '');
    if (!$attachment || !$attachment->can_user_assign_this_file()) {
        return response_form_error('Choose a file to import.', ['file' => 'Upload a CSV file.']);
    }
    // ... validate the file's shape, claim it, then:
    $task_id = Task::dispatch('Contact_Import_Service', 'import', ['file_attachment_id' => $attachment->id]);

    return ['task_id' => $task_id];
}
```

The page that shows it uses the Core widgets - each reads through `Rsx_Task`, so the
`dispatcher` declaration is all the wiring there is:

```html
<Task_Status_Badge $task_id=this.args.task_id />
<Task_Report $task_id=this.args.task_id $kind="progress_text" $bar=true />
<Task_Output $task_id=this.args.task_id style="height: 300px" />
```

A Stop button calls `Rsx_Task.stop(id)`; show it when `(await Rsx_Task.get(id)).can.stop`.
`force_stop`, `force_kill` and `rerun` stay the developer's.

## Example 2 - a sync task any administrator watches in a control panel

A downstream file-sync task runs on a schedule; its live output belongs on an admin settings
screen, readable by any administrator of the site, controlled by nobody but developers:

```php
class File_Sync_Service extends Rsx_Service_Abstract
{
    #[Task('Sync the shared file store')]
    #[Schedule('every 15 minutes')]
    #[Exclusive]
    #[Task_Access('sync_access')]
    public static function sync(Task_Instance $task, array $params = []) { ... }

    public static function sync_access(Task_Run_Model $run, $user, string $action): bool
    {
        return $action === 'view' && Permission::can_manage_site_settings();
    }
}
```

```javascript
// The panel: the newest runs of the task, refreshed on every run start/finish.
on_create() { this.subscribe('Task_List_Changed_Topic', {class: 'File_Sync_Service', method: 'sync'}, () => this.refresh()); }
async on_load() { this.data.runs = (await Rsx_Task.page({class: 'File_Sync_Service', method: 'sync'}, null, 20)).tasks; }
```

**Tenancy is the framework's**: a run stamped with a site (`site_id > 0`) is shown only to a
caller on that site, before your check is asked. A scheduled run has no site, so the check
decides alone - write it knowing a site administrator of ANY tenant reaches it.

Listing a custom-check task walks the runs newest first and asks the check about each until
the page is full, so a page is never cut short by rows the viewer may not see, and its
`next_cursor` is the last row returned.

## Example 3 - a portal user watches a run done for them

A portal user starts a document bundle export from the portal and watches it:

```php
#[Task('Build the client document bundle')]
#[Task_Access('dispatcher', realm: 'portal')]
public static function bundle(Task_Instance $task, array $params = []) { ... }
```

Or staff start a job for a client, and that client's portal users may watch it:

```php
#[Task('Prepare the year-end pack')]
#[Task_Access('client_can_watch', realm: 'any')]
public static function year_end(Task_Instance $task, array $params = []) { ... }

public static function client_can_watch(Task_Run_Model $run, $user, string $action): bool
{
    if ($user instanceof Portal_User_Model) {
        return $action === 'view' && Portal_Permission::has_client_access((int) ($run->params['client_id'] ?? 0));
    }

    return $action === 'view' && Permission::can_view_data();          // staff
}
```

The read endpoints are `#[Portal_Impersonation_Readable]` and the lifecycle ones are not, so
View as Client watches a run and never stops one.

## The system-call rule, and the external API

- **Starting** a task is never generic, from anywhere: Ajax and the external API never invoke
  a `#[Task]` directly. An endpoint validates the request, checks ACLs and records, and then
  calls `Task::dispatch()` (Example 1).
- **Reading and controlling** an existing run from the browser is the generic part, and
  `#[Task_Access]` is what governs it.
- **The public API has no task interface at all.** An integration that needs a run's status
  gets an `#[Api_Endpoint]` of its own that answers for the run its own endpoint dispatched
  (look the run up, check it belongs to the caller, return what the integration needs).
  `#[Task_Access]` does not reach the API, and nothing generic should be added there.

## Server side

`Task_Gates` is the one seam - ask it from any endpoint of your own that shows a run (a task
attachment download, a custom status payload):

```php
Task_Gates::can_view($run);                       // bool
Task_Gates::can_control($run, 'stop');            // bool
Task_Gates::visible_page($filter, $cursor, 50);   // ['tasks' => [...], 'next_cursor' => ?string]
```

`Task_Run_Model` reads themselves are ungated, like any model read.

## Checklist for a new task

1. Will a user ever see this run in the browser? If not, declare nothing - developers still
   see it in `/_sys`.
2. Only the user who started it: `#[Task_Access('dispatcher')]` (add `realm: 'portal'` for a
   portal-started task).
3. Anyone else: a check on the same service that grants the least - usually `view` only.
   `force_kill` and `rerun` are almost never a user's.
4. Start it from an endpoint that validates and dispatches - never anything generic.

`rsx:man tasks` (TASK ACCESS) is the contract.
