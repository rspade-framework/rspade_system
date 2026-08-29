# app/RSpade/Commands/ - framework artisan commands

**Full treatment: `php artisan rsx:man artisan_commands`.** Read it before restructuring
anything here. What follows is the set of facts that are non-obvious from the code and
that an LLM working in this directory gets wrong without them.

## The one fact everything else follows from

**Commands are NOT manifest-indexed.** `app/RSpade/Commands` is absent from
`config('rsx.manifest.scan_directories')`. They are registered by Laravel, in
`app/Console/Kernel.php`:

```php
$this->load(__DIR__.'/../RSpade/Commands'); // recursive; subdirectories are organisational only
```

So none of the framework machinery you are used to applies here: no attribute discovery,
no namespace regeneration, no manifest reflection, no auth gates, no code-quality pass. A
command is a plain Symfony/Laravel object. **The framework's conventions stop at this
directory's edge.**

## rsx:check does not lint this directory - ON PURPOSE

`php artisan rsx:check app/RSpade/Commands/...` refuses with *"is not in an allowed
directory"*. **This is purposefully excluded from the `rsx:check` lint because the nature
of commands is so atypical to the rest of how the framework operates.** rsx:check scans the
manifest's directories, and the constructs that are normal here would read as violations:
an unreachable `handle()` in a stub, a deliberate override of a vendor command's name,
flags read from a global instead of `$this->option()`, and "commands" with no PHP runtime
behaviour at all.

**Do not add this directory to `scan_directories` to "fix" the refusal**, and do not move a
command elsewhere so it can be linted. If you want a command checked, read it yourself.

Every house rule still applies to what you write - snake_case methods and variables, no
emoji, `[OK]`/`[ERROR]` ASCII output, no timeouts, explicit `bash`, no interactive prompts.
They are simply not machine-enforced here.

## Pre-boot interception, and why stubs exist

Four commands never reach Laravel. `system/artisan` matches `$argv[1]` before Composer's
autoloader is required and shells straight to bash:

| Command | Script |
|---|---|
| `rsx:framework:pull` | `bin/framework-pull-upstream.sh` |
| `rsx:maintenance:enable` | `bin/maintenance-mode.sh enable` |
| `rsx:maintenance:disable` | `bin/maintenance-mode.sh disable` |
| `rsx:git` | `bin/rsx-git.sh` |

They are intercepted because each must work on a tree too broken to boot, and because the
maintenance pair can never be refusable by the gate it is itself raising and lowering.

**They still need a class in this directory.** `php artisan list` is built by Symfony from
*registered command objects* - a command that exists only as a string comparison in
`system/artisan` appears in no listing and has no `help` output. So each keeps a STUB whose
only jobs are registration, `$signature` (so its flags render in `help`), `$description`,
and a `handle()` that is a loud error, because reaching it means the interception failed.

Shape to copy: `Commands/Rsx/Maintenance_Enable_Command.php`.

**Nothing enforces that a stub's flags match its bash script.** The stub never runs. A flag
added to the script and not to the stub is invisible in help; a flag in the stub the script
rejects is a lie. **Change them together** - no test will catch it.

## Restricted commands

`Commands/Restricted/` overrides Laravel commands RSpade forbids (`migrate:fresh`,
`migrate:refresh`, `migrate:reset`, `db:wipe`, `down`, `up`, the cache family). They
register under the original name, are `$hidden`, and exit non-zero naming the sanctioned
replacement. **Never bypass one** - see `Commands/Restricted/CLAUDE.md` for the policy and
the directive for AI agents.

## Framework-internal flags (`--_`)

A flag whose only caller is the framework itself is spelled `--_<name-with-hyphens>` and is
**never declared as an `InputOption`**. `system/artisan` lifts every `--_` token into
`$GLOBALS['__rsx_internal_flags']` and strips it from argv before Symfony parses, so it
renders in no help output and can never raise an unknown-option error. Read it with
`Rsx_Internal_Flags::has()` / `::get()`, never `$this->option()`.

The strip runs **before** the interception block, which is why `Rsx_Artisan`'s
`--_lock-group` token can ride on every spawn without `bin/maintenance-mode.sh` ever seeing
it.

Use `--_` only for a switch whose caller is the updater or another framework command. A
user-facing flag stays an ordinary option.

## Spawning artisan from PHP

Always `App\RSpade\Core\Console\Rsx_Artisan` (`passthru` / `run` / `dispatch_detached`) -
never `passthru`/`exec_safe`/`shell_exec`/`proc_open` with an artisan command line
(`ARTISAN-SPAWN-01`). The synchronous forms attach `--_lock-group` so the child joins the
parent's lock group instead of deadlocking behind it. In-process `Artisan::call()` needs
none of this.

## Streaming vs capturing output

`\exec_safe()` is the sanctioned wrapper but it **buffers to EOF** - a long dump or restore
prints nothing until it finishes. When the operator needs to watch progress, use
`\passthru('bash -c ' . escapeshellarg($pipeline), $exit_code)`. Explicit `bash -c` is
mandatory: `passthru()` otherwise hands the line to `/bin/sh`, which is dash here.

Credentials go in the **environment** (`putenv('MYSQL_PWD=...')` around the call), never in
the command string, where `ps` shows them to every user on the box.

## THE MIGRATION SCHEMA CACHE IS NEVER REGENERATED ON DEMAND

`php artisan rsx:db:rebuild_provision_cache_snapshot` rebuilds `rsx/resource/db/schema_cache.sql.gz` and
`uploads_cache.tar.gz` - the artifacts a fresh install restores instead of replaying every
migration (`rsx:man migrations`, THE SCHEMA CACHE).

**Never run it, and never suggest running it as a step in some other task.** It is
regenerated every few months at most, and only when the OPERATOR EXPLICITLY ASKS FOR IT BY
NAME. Two reasons:

1. It takes the application down (real maintenance window), drops and recreates the
   developer's database, and empties the blob store, restoring both afterwards. That is a
   deliberate operation, never a housekeeping side effect.
2. Each run rewrites ~500 KB of binary that is committed to the repository. Regenerating
   casually churns history for no benefit.

**A stale cache is not a bug.** Migrations newer than the cache simply apply on top of it,
which is the designed behaviour - the cache is a speed optimisation, never a correctness
requirement. "The cache is out of date" is never a reason to rebuild it on your own
initiative.
