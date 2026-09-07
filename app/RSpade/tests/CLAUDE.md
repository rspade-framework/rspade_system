# RSpade Framework Tests

Authoritative structure for **framework** tests (the RSpade framework itself).
Application tests live under `/rsx/` and are out of scope here.

This directory is being built out by a per-man-page audit: for each subsystem we
review the code + man page, reconcile any divergence, and catalog everything
worth testing across all test types - implemented or not.

## Layout

```
tests/
├── CLAUDE.md                  # this file - the structure of record
├── _lib/                     # shared shell infra (test_env.sh, db_reset.sh, snapshot)
├── _archive/                  # retired tests + superseded docs, kept for reference (never run/discovered)
├── <concern>/                 # one directory per subsystem of concern (e.g. session, enums, tasks)
│   ├── README.md              # domain overview + applicability + source/man-page map + testable surface
│   ├── test_catalog.md        # the full catalog of tests (implemented AND not), one row each
│   ├── issues_encountered.md  # ONLY if a man-page/code divergence or bug was found
│   ├── php/                   # PHP tests (rsx:test)              [discovered + run by the manifest]
│   ├── cli/                   # artisan-command tests             [php class OR shell script]
│   ├── asset/                 # scss / bundle / build-artifact tests
│   ├── http/                  # live-server integration (curl)    [shell]
│   └── playwright/            # browser / SPA / UI tests          [js, NOT manifest-indexed]
```

A concern only has the type subdirs it needs. Every concern has the three docs
(`README.md`, `test_catalog.md`; `issues_encountered.md` only when applicable).

## Test types

| Type | Runner | Use for | Form |
|------|--------|---------|------|
| `php` | `rsx:test --framework` | Unit/integration: pure logic, models, DB behavior, anything callable in-process. | PHP class extending `Rsx_Test_Abstract` |
| `cli` | `rsx:test --framework` (preferred) or shell | Artisan command output/exit behavior (e.g. `rsx:man`, `migrate:status`, `rsx:check`). Use a PHP class that calls `Artisan::call()` when feasible; a shell script only when the command can't run cleanly in-process. | PHP class, or `*.sh` |
| `asset` | `rsx:test --framework` or shell | SCSS compilation, bundle output, generated artifacts. PHP when the compiler runs in-process; shell when it shells out. | PHP class, or `*.sh` |
| `http` | shell (curl) via the runner | Behavior only observable over real HTTP: cookies, headers, FPC cache, SSR, redirects, CSRF round-trips. | `*.sh` (uses `_lib` helpers) |
| `playwright` | Playwright runner | Browser/SPA/UI/visual/interaction: jqhtml components, SPA routing, modals, forms, droppable, nav. | `*.js` |

PHP-runnable types (`php`, and `cli`/`asset` written as PHP classes) are
auto-discovered by the manifest and run by `rsx:test --framework`. `http` (shell)
and `playwright` (js) are NOT manifest-discovered - the `playwright` basename is
excluded from scanning, and `.sh`/`.js` aren't indexed as PHP.

## Writing a PHP test

Namespace MUST match the path (enforced by the framework's namespace validator):
`app/RSpade/tests/<concern>/php/` -> `namespace App\RSpade\Tests\<Concern>\Php;`
(each path segment PascalCased).

```php
namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

class Session_Cli_Test extends Rsx_Test_Abstract
{
    public static function test_behaviour()
    {
        static::__assert_equals($expected, Session::method());
    }
}
```

Methods start with `test_`. Helpers are `__`-prefixed/`protected`: `__assert_*`
(equals, true/false, null, contains, count, greater_than/less_than, instance_of,
empty/not_empty, equals_approx, **throws**), `__acting_as_site/__acting_as_user/
__reset_session`, `__pass/__fail/__skip`. See `Rsx_Test_Abstract`.

Rules: snake_case methods/vars; no emoji/unicode; never the words "fallback" or
"legacy" (code-quality rule rejects them); professional ASCII only.

## Database isolation (PHP tests)

| Need | Declare | Effect |
|------|---------|--------|
| Read-mostly / isolated writes (default) | nothing | Each `test_*` runs in a transaction rolled back afterward. The DB starts at the migrated + migration-seeded baseline. |
| Must commit, or tests the build/migration/data-mutation path needing a pristine slate | `protected static $requires_db_reset = true;` **and** `protected static $use_database_transactions = false;` | The runner re-provisions a clean migrated baseline once before the class, and restores it before the next class (so transaction-based classes never see a reset class's committed data). |
| Pure logic, no DB at all | `protected static $use_database_transactions = false;` | Skips the per-test transaction entirely. |

`$requires_db_reset` is per-class (reset once, not per-method). Resets reuse the
migration-hash dump cache, so they are fast when migrations are unchanged. Class
run order is deterministic (by class name) in the sequential runner only - see
Running below.

**The baseline carries exactly one user.** The migrated test baseline is provisioned
with a single account - `users` id 1, `site_id` 1, role `ROLE_DEVELOPER` (100),
email `test-user-1@rspade.test`, with an activated + verified `login_users`
credential behind it. It exists because the first-user migration creates nobody
unless `RSPADE_DEFAULT_*` are configured, while `__acting_as_user(1)` is how a test
says "somebody is signed in". Tests may rely on it. A test that needs a DIFFERENT
identity - another role, another site, a disabled account, a second user - creates
its own; never mutate user 1 outside a rolled-back transaction. The seed lives in
`Rsx_Test_Command::seed_test_baseline_user()`, runs on every re-provision path, and
refuses to run against any database but the configured test one.

## Running a child in another mode

A test that spawns `php artisan` through `Rsx_Artisan` with `RSX_MODE=debug` (or
`production`) in the child's environment is exercising that mode's code path on
whatever box it happens to be on - and an http box (a docker test container, a local
`APP_URL=http://localhost:8080` dev box) is not something the test can change. The
https-outside-development requirement is an operator guardrail against launching a
real site over http, so it does not apply under the suite: `rsx:test` declares the
internal `--_test-run` flag on itself, `Rsx_Artisan` forwards it to every child, and
`Rsx_App_Url::enforce_scheme_from_env()` grants http to any process carrying it
(`Rsx_Test_Abstract::suite_is_running()`). Nothing else consults the flag, and a served
web request never carries argv, so the guardrail is intact for what it protects. No
`.env` setting is involved - a test-spawned child simply works in every mode.

## File-storage isolation

A test run relocates the ENTIRE file subsystem - the content-addressed blob store,
the thumbnail cache, and the rendition cache - to `storage/rsx-tmp/test-storage`
(mirroring the DB swap). The runner sets the internal `rsx.files.storage_root`
config, which `App\RSpade\Core\Files\Rsx_File_Paths` reads, and passes it to the
migrate-provisioning subprocess via `migrate --rsx-storage-root` so seed migrations
that write blobs (the template app's `import_sample_documents`) stay isolated too.
Without this, a test-DB attachment delete unlinks a shared disk file and can destroy
a developer-database blob whose bytes match (backlog B-38). The test root persists
across runs like the dump cache and is wiped by `rsx:clean`. Tests never touch the
real store; any file-subsystem code you write must resolve its disk paths through
`Rsx_File_Paths` (never `storage_path('uploads'|'rsx-thumbnails'|'rsx-renditions')`
directly), or it reopens the hole.

## Waiting for something (the contention principle)

**Tests assume EXTREME resource contention.** A test proves ORDER and EXECUTION - that the
thing happened, and that it happened after the thing it depends on - and NEVER speed. A
suite running twenty containers on a loaded box gives any single process arbitrarily little
CPU, so any assertion that depends on something completing "quickly enough" is not a test,
it is a coin toss.

- **Every wait is a bounded POLL of the condition**, never a fixed sleep. Poll on a short
  interval, return the moment the condition holds.
- **120 seconds is the house bound.** It is generous by design: only a genuinely stuck
  condition can reach it, so reaching it is evidence, not noise.
- **The failure message names the condition that was never reached**, not the elapsed time.
  "the idle daemon never exited and unlinked its socket <path>" is a diagnosis;
  "timed out after 120s" is not.
- **Fixed sleeps are not synchronization.** The one legitimate fixed wait is proving a
  NEGATIVE over a window the code itself defines (nothing happened while a request was still
  arriving) - and then the window belongs to the code under test, not to the sleep.
- A wait on an external process is the sanctioned exception to the no-timeout mandate
  (`01-engineering-mandates.md`): it bounds a wait on a party this test does not control, it
  truncates no work, and expiry FAILS the test loudly. Say so in a comment beside the bound.

`Rpc_Lifecycle_Test::__poll_until()` is the worked example.

## Running

```bash
php artisan rsx:test --framework              # all PHP/cli/asset framework tests
php artisan rsx:test <Class> --framework      # one class (name substring)
php artisan rsx:test --framework --filter=x   # methods matching a pattern
php artisan rsx:test --framework --fresh      # force a full test-DB rebuild first
# A plain run auto-applies any PENDING migrations to the test DB before testing
# (silent when in sync) - --fresh is only needed for a full drop+recreate.

# Shell-based http/cli/asset tests use the _lib helpers; see run_all_tests.sh.
# Playwright tests are run by the Playwright tooling (see man custom_playwright_tests_spa).
```

The FULL framework suite (`--framework` with no narrowing selector) runs in parallel
docker containers automatically on a docker-capable development box, with identical
output and exit code; `--sequential` forces the single-process runner anywhere. Any
subset - a class, `--filter`, `--group` - always runs in this process. Mechanics:
`system/bin/rsx-testd/CLAUDE.md`.

**A full docker run is cached by manifest build key plus an environment fingerprint**
(`storage/rsx-tmp/test-results/framework_<key>.json`; the fingerprint covers `system/bin`,
`node_modules` and the docker resource dir): a second full run with no
scanned file changed replays the recorded verdict, pass or fail, and says so. Mechanics:
`system/bin/rsx-testd/CLAUDE.md`.

**The test trees are in the manifest only while `rsx:test` is running.** `app/RSpade/tests`,
`app/RSpade/temp` and `rsx/tests` are appended to the scan list by
`Manifest::scan_directories()` when `Rsx_Test_Abstract::suite_is_running()` is true, so a
plain build carries no fixture class, route or surface, and the first ordinary web request
after a run drops them again - a fixture is real indexed source and a served site must not
have one (`rsx:man testing`, THE TEST TREES IN THE MANIFEST; rule TEST-AUTH-01).

**Class run order is deterministic (by class name) only in sequential mode.** The docker
runner pulls from a shared queue, so a class can be preceded by a class it has never
followed before - never write a test that leans on run order.

## Per-concern documents

Each `<concern>/` carries:

1. **`README.md`** - domain overview & applicability; the source files under
   test; the man page(s) that define behavior; the testable surface (what should
   be tested, by type). Prose, kept current.
2. **`test_catalog.md`** - the comprehensive catalog: every test identified as
   worth having, implemented or not. One row per test:

   | ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |

   - **Type** is one of php/cli/asset/http/playwright.
   - **Status** is one of: `implemented`, `deferred` (with a reason), `blocked`
     (e.g. by a known bug - cross-reference `issues_encountered.md`), `planned`.
   - Deferred/planned rows are first-class: the catalog is the source of truth
     for coverage holes, not just a list of what exists.
3. **`issues_encountered.md`** - created only when the audit finds the man page
   diverging from the code, or a real bug. State: what the man page says, what
   the code does, why it matters. Do NOT change framework behavior to make a test
   pass; record it here for human review.

## Man-page reconciliation

While auditing a concern, verify the man page against the code:
- **Clear documentation error** (wrong name/signature/default/stale) -> fix the
  man page directly.
- **Ambiguous, or a real code bug** -> leave the code; record in
  `issues_encountered.md`.

## Archive policy

Retired or superseded tests/docs move to `_archive/` (not deleted) so history is
preserved. `_archive/` is excluded from manifest scanning and never run.

## Shared infra

`_lib/` holds the shell test harness: `test_env.sh`
(switches to `rspade_test`), `db_reset.sh`, snapshot tooling. Shell tests source
these with `../../_lib/test_env.sh` (depth is always `tests/<concern>/<type>/`).
NEVER touch the `rspade` (dev) database from a test.
