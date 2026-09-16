# Concern: external scripting

`system/script.php` is the single include that boots the RSpade shell for a PHP script
living OUTSIDE the application tree - a standalone tooling repository, a one-off
maintenance script, a cron job:

```php
$app = require '<project>/system/script.php';
```

What comes up is what an artisan command gets: the pre-boot guards, Composer's
autoloader and the RSpade autoloader, the manifest, the morph map, `Main::init()` and
with it the application's declared site, and the ORM with every hook live. What does
NOT come up is Symfony's console dispatch - the kernel is `bootstrap()`ed, never
`handle()`d, so there is no command name, no `Task_Instance` and no progress bar.

## The two things that make it more than a documented recipe

**ONE pre-boot sequence.** `bootstrap/rsx_preboot.php` exposes `rsx_preboot(array
$options)`, and both entry points call it: `system/artisan` with no options,
`system/script.php` with `['script' => true, 'force' => ...]`. A script therefore
cannot drift from the command line on the guards - path export and writability, the
environment and build links, the submodule sync guard, the environment heal, the
container gate, the `--_` internal-flag strip, the maintenance gate.

**Script mode ends the argv confusion.** Several framework seams read
`$_SERVER['argv'][1]` because under artisan that token IS the command name. A script's
first argument is the SCRIPT'S, and reading it as a command name produced a silent
failure: `php my_tool.php` (no argument) and `php my_tool.php help` matched the
manifest boot-skip list, so `Manifest::init()`, the autoloader registration, the morph
map and `Main::init()` never ran, and every site-scoped model answered zero rows with
no error at all. `system/script.php` defines `RSX_SCRIPT_MODE` before anything reads
argv; `App\RSpade\Core\Console\Rsx_Script::is_active()` is the booted-world reader, and
every such seam asks it first.

| Seam | In script mode |
|---|---|
| `Manifest::__cli_skips_manifest_boot()` | false - a script always gets the full boot |
| `Manifest::_is_migration_context()` | false |
| `Rsx_Framework_Provider::boot()` `rsx:manifest:build --clean` case | never fires |
| `Rsx_Build_Context::is_active()` argv branch | false (the `--_build-context` flag branch is untouched) |
| `bootstrap/rsx_paths.php` `$is_build` | false (reads the raw constant - pre-boot has no autoloader) |
| `Rsx_Php_Requirements::is_exempt_invocation()` | false - a script is always enforced |
| `rsx_container_gate_refuse()` | prints `php <script> <args>`, not `php artisan ...` |

## Maintenance mode

Stricter than the artisan gate. Artisan has a command name to classify and refuses only
the automated task runners; a script has no command name and is automation by
definition, so the WHOLE process is refused with exit 75:

```
503 - System is in maintenance mode (<reason>); external scripts are refused.
Set $RSX_SCRIPT_OPTIONS['force'] = true before the include to run anyway.
Exit maintenance mode: php artisan rsx:maintenance:disable
```

`$RSX_SCRIPT_OPTIONS['force'] = true` before the include overrides it, and so does the
internal `--_framework-update-override` token. `RSPADE_MAINT_MODE` is defined either
way - it is the per-process snapshot the lock backend selection reads.

## Source files under test

- `system/script.php` (the entry point), `system/bootstrap/rsx_preboot.php` (the shared sequence)
- `system/artisan` (the other caller - its behaviour must not have moved)
- `app/RSpade/Core/Console/Rsx_Script.php` (the booted-world reader)
- `app/RSpade/Core/Manifest/Manifest.php`, `app/RSpade/Core/Providers/Rsx_Framework_Provider.php`,
  `app/RSpade/Core/Prod/Rsx_Build_Context.php`, `app/RSpade/Core/Health/Rsx_Php_Requirements.php`,
  `system/bootstrap/rsx_paths.php`, `system/bootstrap/rsx_container_gate.php` (the guarded sniffs)

## Man pages

- `rsx:man scripting` (primary)
- `rsx:man artisan_commands` (the pre-boot interceptions, the other caller)
- `rsx:man maintenance_mode` (the window the script gate honours)

## Testing notes

The subject is a whole process's boot, and the pre-boot half of it happens before any
class exists, so every test writes a THROWAWAY script into
`Rsx_Project_Paths::tmp_path('scripting-test')`, runs it with `php <script>` from `/tmp`
- a working directory outside the project, which is the point of the entry point - and
asserts on the JSON it prints. What it spawns is a plain PHP script, not an artisan
command line, so the `Rsx_Artisan` mandate does not apply.

The site assertion compares against `Session::get_site_id()` as the TEST process sees
it rather than against a literal: the declared tenant is `Main::init()`'s, which is
application vocabulary, and a framework test must not name it.

The maintenance case raises the REAL flag (there is no other way to test the real gate),
always with `--no-services`, always through try/finally, and `teardown()` clears it
unconditionally.
