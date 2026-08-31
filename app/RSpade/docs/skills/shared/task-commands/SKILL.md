---
name: task-commands
description: Giving a background task its own artisan command in RSpade by annotating it `#[Command('prefix:name', 'Description')]` beside its `#[Task]`, and the stdout-is-the-value / stderr-is-the-narration output contract every console run shares with `rsx:task:run`. Use when asked to "make an artisan command", "add a CLI command", a cron entry or deploy step that runs app work, when piping a task's JSON result into jq or a script, when reaching for `Task_Instance::info` or `update_progress` to narrate a run, or when a manifest build fails with "#[Command] may only annotate a #[Task] method", "needs a 'prefix:name' segment", "The 'rsx:' prefix belongs to the framework", "already a framework command", or "the description and is required".
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
        $task->info('Fetching ' . ($params['since'] ?? 'everything'));
        $task->update_progress(50, 'Parsing');

        return ['rows' => 1500];        // the return value IS stdout
    }
}
```

That is the whole recipe. No class, no file to place, no `Kernel` to edit, no registration
call. The manifest discovers the attribute at build time and the framework registers one
thin command per declaration at console boot.

`php artisan myapp:import` is EXACTLY `php artisan rsx:task:run Import_Service import` under
a friendlier name - same argv parsing, same `--debug`, same output, same exit codes, same
code path. So the work stays a task: still dispatchable in the background, still
`#[Schedule]`-able, still pollable through `Task::status()`, still recorded in `_tasks`.

---

## The output contract

Shared verbatim by `rsx:task:run` and every alias.

| Stream | Carries |
|---|---|
| **stdout** | The VALUE. The return value as pretty JSON, and nothing else. `--debug` wraps it `{success, result}`. A failure prints `{success:false, error, error_type, trace}` and exits **1**. |
| **stderr** | The NARRATION. Every `info()` / `error()` / `debug()` line, live and flushed, formatted exactly as the `_tasks.logs` lines. |

A worked transcript:

```
$ php artisan myapp:import --since=2026-08-01
[2026-08-31 09:12:04] [info] Fetching 2026-08-01          <- stderr
[2026-08-31 09:12:09] [info] [50%] Parsing                <- stderr
{                                                         <- stdout
    "rows": 1500
}
$ echo $?
0
```

```bash
php artisan myapp:import | jq .rows       # narration goes to the terminal, value to jq
php artisan myapp:import 2>/dev/null      # discard the narration
php artisan myapp:import -q               # never produce the narration
php artisan myapp:import 2>&1 | tee run.log   # keep both, interleaved
```

**`-q` silences the narration and never the value** - the JSON is written at quiet
verbosity precisely so a scripted caller can keep the answer and drop everything else.

`update_progress($percent, $message)` is an ordinary info line reading `[50%] message`
(`[50%]` with no message). Because it is a log line like any other, it reaches the console
AND `_tasks.logs`, so a console transcript and `Task::status($id)['logs']` read identically:

```php
$s = Task::status($id);
$last = end($s['logs']);            // "[2026-08-31 09:12:09] [info] [50%] Parsing"
```

**The sink belongs to the RUNNER.** The command attaches stderr; nothing else does.
`Task::internal()` called from a web request or from another task prints to nobody's
console, and a queued run writes its lines to the database exactly as always. Your task
code cannot tell which is happening, and must not try to - narrate with `$task->info()` and
never with `echo`, `print` or `$this->line()`.

---

## Parameters

Every `--key=value` becomes `$params['key']`; a bare `--flag` becomes `true`; a JSON value
is decoded.

```bash
php artisan myapp:import --since=2026-08-01 --dry --data='{"vendor":"acme"}'
# $params = ['since' => '2026-08-01', 'dry' => true, 'data' => ['vendor' => 'acme']]
```

Laravel's own options (`--quiet`, `--verbose`, `--ansi`, `--no-interaction`, `--env`,
`--help`, `--version`, `--force`) and `--debug` never reach `$params`.

**Validate in the task, and throw.** A bad parameter is an exception: it lands as the JSON
error on stdout with exit 1, which is what a script checks.

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

Named arguments work: `#[Command(name: 'myapp:import', description: 'Import')]`.
`#[Command]` is reflection-only - **never define a Command attribute class.**

---

## Seeing what exists

```bash
php artisan list                 # aliases appear under their own prefix, with descriptions
php artisan rsx:task:list        # every task, with the COMMAND column ('-' when it has none)
```

The reference app's worked example is `rsx_app:seed` on `Seeder_Service::seed_all`
(`system/app/RSpade/resource/reference_app/services/seeder_service.php`).

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
