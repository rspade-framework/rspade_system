---
name: task-commands
description: "Giving a background task its own artisan command in RSpade by annotating it `#[Command('prefix:name', 'Description')]` beside its `#[Task]`, and the console contract every console run shares with `rsx:task:run`: the task's stdout is the command's stdout, its stderr (each status() change included) is the command's stderr, and the exit code is the task's return code. Use when asked to \"make an artisan command\", \"add a CLI command\", a cron entry or deploy step that runs app work, when piping a task's output into another program, when choosing between `$task->stdout()`, `$task->stderr()` and `$task->status()` for console output, when a command exits non-zero unexpectedly, or when a manifest build fails with \"#[Command] may only annotate a #[Task] method\", \"needs a 'prefix:name' segment\", \"The 'rsx:' prefix belongs to the framework\", \"already a framework command\", or \"the description and is required\", or when running a task by hand as a particular user (rsx:task:run --user=<id|email> --site=<id>), or rsx:task:run refuses a task with \"runs as the user who dispatched it (#[Run_As_Dispatcher]). From the command line, say who it acts as\" (TASK-RUN-AS-01)."
---

# Task Commands

**An application never writes an artisan command class.** All of `system/` is framework
property and is overwritten by every framework update, so there is nowhere for one to live.
Instead you write a `#[Task]` and name it:

```php
// /rsx/services/import_service.php
class Import_Service extends Rsx_Service_Abstract
{
    #[Task('Import the nightly vendor feed')]
    #[Command('myapp:import', 'Import the nightly vendor feed')]
    public static function import(Task_Instance $task, array $params = [])
    {
        $task->status('Fetching ' . ($params['since'] ?? 'everything'));
        $rows = Vendor_Feed::fetch($params['since'] ?? null);
        $task->status('Importing');
        $task->stdout(count($rows) . ' rows');

        return null;                    // the exit code: null/true/0 -> 0
    }
}
```

That is the whole recipe. No class, no file to place, no `Kernel` to edit, no registration
call. The manifest discovers the attribute at build time and the framework registers one
thin command per declaration at console boot.

`php artisan myapp:import` is EXACTLY `php artisan rsx:task:run Import_Service import` under
a friendlier name - same argv parsing, same output, same exit codes, same code path. So the
work stays a task: still dispatchable in the background, still `#[Schedule]`-able, and every
console run is recorded as an INLINE run (`Task_Run_Model`, origin Inline) with its reports
and output, visible in `/_sys/tasks`.

---

## The console contract

Shared verbatim by `rsx:task:run` and every alias. The task's own streams ARE the
command's - nothing wraps them.

| Stream | Carries |
|---|---|
| **stdout** | The task's stdout: every `$task->stdout()` line and everything it `echo`es, live. Nothing else. |
| **stderr** | The task's stderr: every `$task->stderr()` line, every CHANGED `status()` text, and the `Task failed: <error>` line of a failing run, live. `-q` silences it. |
| **exit code** | The task's return code: 0 for `null`/`true`/`0`; the returned integer for a failure (clamped to 1..255); 1 for `false`, any other return value, or a throw. |

A worked transcript:

```
$ php artisan myapp:import --since=2026-08-01
Fetching 2026-08-01                                       <- stderr
Importing                                                 <- stderr
1500 rows                                                 <- stdout
$ echo $?
0
```

```bash
php artisan myapp:import | wc -l          # stdout to the pipe, stderr to the terminal
php artisan myapp:import 2>/dev/null      # discard stderr
php artisan myapp:import -q               # never produce stderr
php artisan myapp:import 2>&1 | tee run.log   # keep both, interleaved
```

**Choose the stream for the reader.** A command whose output is meant to be piped writes
exactly that to stdout and narrates with `status()` / `stderr()`. A summary for the run's
history is `summary()` - recorded on the run, never printed. Data never goes in the return
value: returning an array is a FAILED run (exit 1).

Everything printed is ALSO recorded on the run (`_task_output`), so a console transcript and
the run's output in `/_sys` read the same. Operator lines (a stop or kill someone performed)
are recorded, never echoed.

**The streams belong to the RUNNER.** The command attaches its stdout and stderr; nothing
else does. `Task::internal()` called from a web request or from another task prints to
nobody's console, and a queued run writes its lines to the database only. Your task code
cannot tell which is happening, and must not try to - report with `$task->stdout()`,
`stderr()` and `status()`, never `$this->line()`.

**A console run acts as nobody** (a virtual session: `get_session_id()` and `get_audit_session_id()` null, session values throw) until somebody is declared - `rsx:task:run --user=<id|email> --site=<id>` (rsx:ajax's two flags, one shared resolution: `--site` required when the identity is on several sites; the run's `dispatched_by`, revisions and audit pairs name the user; neither flag reaches `$params`), or the task itself with `Session::set_login_user_id()` / `set_site_id()`. **A `#[Run_As_Dispatcher]` task runs from a console only as a named person**: without `--user`, `rsx:task:run` refuses it, exit 1, nothing recorded (`[ERROR] X::y runs as the user who dispatched it (#[Run_As_Dispatcher]). From the command line, say who it acts as: --user=<id|email> --site=<id>`); with it, it runs inline as them. **An alias takes no `--user`/`--site`** - every option it gets is the task's parameter - so `#[Command]` beside `#[Run_As_Dispatcher]` fails the build (`TASK-RUN-AS-01`). `rsx:man task_commands`, ACTING AS A USER.

A console run is an inline run: `#[Exclusive]`/`#[Debounce]` hold (it waits for a running
instance of its identity). Being inline, it is never killed for time - the silence limit
(`rsx.tasks.silent_timeout`) applies only to runs a pool worker executes.

---

## Parameters

Every `--key=value` becomes `$params['key']`; a bare `--flag` becomes `true`; a JSON value
is decoded.

```bash
php artisan myapp:import --since=2026-08-01 --dry --data='{"vendor":"acme"}'
# $params = ['since' => '2026-08-01', 'dry' => true, 'data' => ['vendor' => 'acme']]
```

Laravel's own options (`--quiet`, `--verbose`, `--ansi`, `--no-ansi`, `--no-interaction`,
`--env`, `--help`, `--version`) and the maintenance gate's `--force` never reach `$params`.

**Validate in the task.** A bad parameter is a `stderr()` line and a non-zero return
(`return 2;`), or an exception (exit 1, the error on the run) - either way the exit code is
what a script checks.

---

## The build-time rules

All five are manifest-build FATALs naming the file and method. There is no warning tier -
the build stays broken until the declaration is fixed.

| Message | Cause | Fix |
|---|---|---|
| `#[Command] may only annotate a #[Task] method` | No `#[Task]` on the method | Add `#[Task('...')]`, or drop the `#[Command]` |
| `A command name needs a 'prefix:name' segment` | `'import'`, `':import'`, `'myapp:'` | `'myapp:import'` |
| `The 'rsx:' prefix belongs to the framework` | `'rsx:import'` | Name it after the app |
| `One command name names exactly one task` | Two `#[Command]`s with the same name | Rename one |
| `That name is already a framework command` | Collides with a `$signature` under `app/RSpade/Commands` or `app/Console/Commands` | Rename yours; an alias never shadows one |
| `The second argument is the description and is required` | Missing or blank | Write the line `php artisan list` prints |
| `TASK-RUN-AS-01` | `#[Command]` beside `#[Run_As_Dispatcher]` | An alias takes no `--user`, so it has nobody to run as - drop one of the two; `rsx:task:run ... --user=` runs it by hand |

Named arguments work: `#[Command(name: 'myapp:import', description: 'Import')]`.
`#[Command]` is reflection-only - **never define a Command attribute class.**

---

## Seeing what exists

```bash
php artisan list                 # aliases appear under their own prefix, with descriptions
php artisan rsx:task:list        # every task, with the COMMAND column ('-' when it has none)
```

The reference app's worked example is `rsx_app:seed` on `Sample_Data_Service::seed`
(`system/app/RSpade/resource/reference_app/services/sample_data/sample_data_service.php`),
which also shows a `--site=<id>` parameter validated in the task.

---

## When NOT to add one

A task is not made better by having a command; it is made **reachable**. Add one when a
human or a script invokes the work directly - a maintenance chore, an import, a report, a
repair. Leave it off when:

- **the task is only ever dispatched by application code.** It is already reachable by hand
  as `rsx:task:run <Service> <method>`.
- **the task is purely `#[Schedule]`d.** The cron tick runs it; a name in `artisan list`
  invites a hand-run of something nobody should be hand-running.
- **you actually want framework plumbing** - something that must work on a tree too broken
  to boot, or before the manifest exists. That is a hand-written command in
  `app/RSpade/Commands`, and it is the framework's to write, not an application's.

---

## See also

`rsx:man task_commands` (the contract) · `rsx:man tasks` · `rsx:man artisan_commands` ·
skill `rspade:background-tasks` (writing the task itself)
