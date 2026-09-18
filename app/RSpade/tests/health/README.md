# health - rsx:health dependency & environment check

## Domain

The `rsx:health` command: one read-only command that verifies every dependency,
service, and environment invariant RSpade relies on is installed, reachable, and sane -
so a fresh container / CI / client copy can gate on "this box can actually run
everything" before bugs surface as mysterious runtime failures.

Checks are DECLARED WHERE THEIR FEATURE LIVES via a bare `#[Health_Check('label')]`
attribute on a public static method, discovered through the manifest
(`Manifest::get_with_attribute('Health_Check')`) - so the inventory can never drift from
the features it covers. The command is a thin formatter over `Health_Check_Runner`
(discovery + invocation + return-contract normalization).

Shipped in the Document Pipeline epic, Batch 5.

## Source under test

- `Commands/Rsx/Health_Command.php` - the `rsx:health {--json}` command (table + [OK]/[FAIL]
  summary, exit 1 iff any FAIL, WARN/INFO never flip the exit).
- `Core/Health/Health_Check_Runner.php` - discovery, `run()` / `report()` / `run_one()`,
  the MODE AXIS (`normalize_modes()`, `applies_in_mode()`, `partition()`), and
  `normalize_check_result()` (single-row vs list, label inheritance + #n suffix, invalid
  shape/status -> FAIL-naming-offender, throw -> FAIL).
- `Core/Health/Rsx_Php_Requirements.php` - the ONE declaration of the required PHP
  extensions and minimum version, read by BOTH the rsx:health PHP row and the boot
  check in `Rsx_Preboot_Service::init()` (which throws, exempting only rsx:health
  and rsx:heal).
- `Core/Health/Environment_Health_Checks.php` - PHP version + extensions, node, storage
  writability, env-encryption posture, application-mode posture.
- `Core/Health/Playwright_Stack.php` - the ONE probe of the node -> playwright ->
  chromium chain and the ONE set of install commands, read by both the health row and
  `rsx:debug`'s preflight refusal. Probe only; it never installs anything.
- `Core/Health/Playwright_Health_Checks.php` - the health row over that probe: WARN and
  never FAIL, `modes: 'development'`.
- `Core/Health/Opcache_Advisory.php` - NOT a health row: the once-per-six-hours log
  recommendation a web process with no OPcache writes during boot (whether a host runs
  OPcache is the host's decision, so rsx:health carries no OPcache check).
- `Core/Health/Production_Health_Checks.php` - the rows that only mean something on a
  sealed box (the seal, the APP_URL scheme, the hostname, login auto-fill, the
  console_debug variant, the mail delivery target), each declaring
  `modes: ['debug', 'production']`.
- `Core/Database/Database_Health_Checks.php` - MySQL connectivity, pending migrations.
- `Core/Database/Schema_Contract.php` - the ONE declaration of what the framework
  reads by name on application-owned (non-underscore) tables: required columns each
  naming its framework consumer, the unique indexes and foreign keys the code relies
  on, the rows it assumes, and the semantic probes. Audit pairs and timestamps are
  excluded (normalization guarantees them); `deleted_at` is included (it does not).
- `Core/Database/Schema_Contract_Health_Checks.php` - the "Schema Contract" row over
  it, in every mode: one row per contracted table plus `schema: foreign keys`,
  `schema: rows` and `schema: semantics`. FAIL is structural, WARN is semantic.
- `Core/Task/Task_Health_Checks.php` - #[Schedule] tracker-staleness scheduler-liveness.
- `Core/Task/Task_Worker_Registry.php::redis_connectivity` - Redis reachability.
- `Core/Realtime/Realtime.php::realtime_relay` - relay TCP probe (or INFO when disabled).
- `Core/Files/Libreoffice.php::soffice_available` - soffice presence / config-disabled posture.
- `Core/Search/Search_Health_Checks.php::poppler_utils` - pdftotext + pdfinfo presence.
- `Core/Search/Search_Index_Service.php::index_backlog` - queued / failed extraction counts.
- `Core/Prod/Rsx_Env_Symlink.php::env_symlink` - the system/.env symlink invariant.
- `Core/Health/Environment_Health_Checks.php::env_encryption` - a `.env.encrypted` snapshot
  at the project root: WARN on a development box (nothing keeps it in step with the `.env`
  the heal rewrites), INFO on a sealed build, OK when there is none.

- `Core/Health/Submodule_Visibility_Health_Checks.php` - is a framework update VISIBLE in
  git output? WARN when `submodule.system.ignore` is not `dirty` in the tracked
  `.gitmodules`, WARN on a repo-wide `diff.ignoreSubmodules` in `.git/config`, INFO in the
  monorepo and on a non-submodule project. Heal target `submodule-ignore-dirty` runs
  `bin/environment_updates/080_submodule_ignore_dirty.sh`.

## Behavior defined by

The CR `docs.dev/external_requests/archive/2026_07_16_health_check_command.md` and the plan doc
(`hashed-whistling-pumpkin.md`, BATCH 5). A man page is authored in Batch 6.

## The mode axis

A check declares the modes it applies to (`#[Health_Check('Label', modes: ...)]`); one
that does not apply to the running `RSX_MODE` is not invoked and prints no row, and its
LABEL is reported instead. The axis is tested as the pure partition it is
(`Health_Mode_Axis_Test`) rather than by running the real inventory under a forced mode:
a forced-production run on this unsealed box would fatal at the manifest gate before a
single row ran, and the prod rows are driven directly through their public row builders
instead (`Production_Health_Test`).

## Testable surface

- **php** - the command end-to-end (plain exit 0, `--json` parses + ok/exit consistency,
  always-present labels), and the runner's normalization contract as pure-logic units
  (including the throw-to-FAIL path via `run_one` and a non-discovered probe class).
- **cli** - covered by the php `Health_Command_Test` via `Artisan::call`; a dedicated cli
  shell test is not needed (the command runs cleanly in-process).
- **playwright / http** - none (no UI surface).

## Path seams

`Env_Encryption_Health_Test` drives every branch of `env_encryption()` against a THROWAWAY
project root through `Rsx_Env_Symlink::_testing_set_paths()` - the same seam the check reads
its path from - and forces the mode with `Rsx::_testing_set_mode()`. No real `.env` or
`.env.encrypted` is read, written or named.

`Submodule_Visibility_Health_Test` drives `inspect()` against sandbox `git init`
repositories through its project-root argument - the same seam the check reads - so the
missing / wrong / blanket cases are reachable without touching this box's own
`.gitmodules` or `.git/config`. No submodule is ever cloned: both settings are pure
configuration.

`Schema_Contract_Test` runs the REAL check against the live test schema (which is the
contract's own regression test - it is what keeps the map honest as the framework's
reads move) and drives every failure branch through the row builders with an INJECTED
shape. A database missing `users.login_user_id` or `uk_login_users_email` is not one a
test may create, which is exactly why each builder takes the introspected shape as a
parameter.

`Project_Tree_Health_Test` drives the three project-tree rows through
`Environment_Health_Checks::__tree_row()` against throwaway sandbox paths, because
`Rsx_Project_Paths::storage_root()` is deliberately NOT redirectable per process (a
subprocess must share `storage/state` with its parent or their flock files stop excluding
each other). The mode-dependent rows - which trees are reported, the production read-only
posture, the log-level warning - use `Rsx::_testing_set_mode()` and Laravel's `config()`
seam. The uncreatable branch is reached with a regular FILE in the way rather than a
permission bit, so it holds whoever the suite runs as; the unwritable branch needs a
permission bit and skips under root, saying so.

## Fixtures

`Health_Fixture_Probe` carries NO `#[Health_Check]` attribute on purpose: a
manifest-discovered fixture check would pollute the REAL `rsx:health` inventory. The
normalization tests hand its plain static methods to `Health_Check_Runner::run_one()`
instead (see the deferred catalog row).
