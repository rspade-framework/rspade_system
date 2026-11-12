# Lib/Operations/Health — rsx:health and rsx:heal

**Reading the report or running a remedy? `rsx:man health`.** This file says what is in this
DIRECTORY, how a check is added, and what must stay true when editing it.

## The shape

`php artisan rsx:health` is an inventory of whether this box is configured correctly; it
**exits 1 iff at least one row is FAIL** (WARN and INFO are advisory). `rsx:heal <target>`
is its executable half: a remedy for a row, limited to creating what is DEFINITIONALLY absent.
The commands (`Commands/Rsx/Health_Command.php`, `Heal_Command.php`) are thin formatters; all
the logic is here so it is testable in-process.

**A check lives WHERE ITS FEATURE LIVES**, not in this directory: a public static method
tagged `#[Health_Check('Human Label')]` anywhere in the manifest scan (`Core/Task/`,
`Core/Files/`, `Core/Realtime/`, `Core/Database/` ... each carry their own). This directory
holds the runners and the cross-cutting checks that have no single feature file to hang on.
Discovery is a manifest attribute scan - never reflection `getAttributes()` (`PHP-ATTR-01`)
and never a defined attribute class. `app/RSpade/Commands/` is not in the scan, which is why a
check a command also needs (`Playwright_Stack`) lives here.

## What is here

- `Health_Check_Runner.php` — discovery, invocation, normalization. A check returns one row or
  a list: `['status' => 'OK'|'WARN'|'FAIL'|'INFO', 'detail', 'remediation'?, 'label'?]`. A
  check that throws or returns an out-of-contract shape becomes a FAIL row naming it - **one
  broken check never hides the rest of the report**. The MODE axis
  (`#[Health_Check('X', modes: 'development')]`, absent = every mode): a check outside the
  current `RSX_MODE` is not invoked and prints no row.
- `Heal_Runner.php` — `#[Health_Heal('target-name')]` discovery and invocation; a healer
  answers `HEALED` / `ALREADY_OK` / `REFUSED`, and one that throws is a REFUSED result.
- `Environment_Health_Checks.php` — PHP, Node, storage writability, env encryption, mode
  posture.
- `Rsx_Php_Requirements.php` — the ONE declaration of the PHP runtime the framework needs,
  read by the health row (diagnostic) and by `enforce()` at every boot (the guard that throws
  naming each missing extension). Nothing has a degraded second path for a missing extension.
- `Production_Health_Checks.php` — rows that only mean something on a SEALED box (seal,
  https, development conveniences left on); every builder takes its inputs as parameters so
  its failure branches can be exercised on a development box.
- `Security_Health_Checks.php` — the Web Exposure probe: fetches `.env`, `.git` and the IDE
  bridge directory from the site's own `APP_URL` and CONTENT-matches them against disk (a
  status check would false-positive on a catch-all). Every mode.
- `Security_Update_Health_Checks.php`, `Resource_Sizing_Health_Checks.php` +
  `Rsx_Container_Sizing.php` — what the RSpade container's updater and `rspade-sizing`
  recorded at start (`resource/docker/`).
- `Supervised_Service_Health_Checks.php` — "Supervised Services": the retry runners' state
  files (`/run/rspade-services/<label>.state`, format in `resource/docker/retry-run.sh`), FAIL
  naming each service `failing` (five or more consecutive exits) whose runner is still live.
  The container's Docker health check (`resource/docker/healthcheck.sh`) reads the same files.
- `Playwright_Health_Checks.php` + `Playwright_Stack.php` — the node -> playwright -> chromium
  probe `rsx:debug`'s preflight reads too, so both print the same install command. Probe only.
- `Class_Override_Drift_Health_Checks.php` — the operator view of `CLASS-OVERRIDE-DRIFT-01`.
- `Claude_Skills_Health_Checks.php` — app skill wiring, with the `claude-skills` heal target.
- `Submodule_Visibility_Health_Checks.php` — whether the framework submodule pointer shows in
  ordinary git output, with a heal target.
- `Opcache_Advisory.php` — not a row: a log line at most every six hours when php-fpm runs
  without OPcache, because that is the host's decision.

## Adding a check

Put the method beside the feature, pick the status honestly - **FAIL only for a box that is
broken or unsafe**; a missing development convenience or a condition the site already runs
with is WARN, because FAIL breaks deploy gates and container healthchecks - write a
`remediation` that is the literal command or edit, and declare `modes:` when the question has
no answer in some mode. A healer goes beside its check and REFUSES anything present-but-wrong:
quietly repairing a broken install is a silent fallback, which the fail-loud mandate forbids.

## References

`rsx:man health` · skill `rspade:application-modes-deployment` (the sealed-box rows) ·
siblings `Core/Database/Schema_Contract_Health_Checks.php` (the Schema Contract rows),
`Core/Manifest/` (`Class_Override_Drift`), `resource/docker/` (sizing, security updates,
service state and the container health check).
