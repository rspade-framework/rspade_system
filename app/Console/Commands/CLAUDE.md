# system/app/Console/Commands — Laravel's command directory, nearly empty

RSpade's commands live in `system/app/RSpade/Commands/` (its own `CLAUDE.md`, and
`rsx:man artisan_commands`), including `Restricted/` - the stubs that refuse Laravel commands
RSpade forbids (`db:wipe`, `migrate:rollback`, `down`, and the rest). An application writes no
command classes at all: a command is a `#[Task]` with a `#[Command]` (`rsx:man task_commands`).

What is still here:

| File | What it is |
|---|---|
| `FrameworkDeveloperCommand.php` | Base class that hides a command from `php artisan list` unless `IS_FRAMEWORK_DEVELOPER=true`. |
| `KeyGenerateOverride.php` | `key:generate` that refuses to replace an existing APP_KEY without `--force`. |
| `GetEnvironment.php` | `php artisan env`. |
| `Temp/ClearCommand.php` | `temp:clear`. |
| `Rsx/Dev/UpdateNpmCommand.php` | `rsx:dev:update_npm` (framework developers). |
