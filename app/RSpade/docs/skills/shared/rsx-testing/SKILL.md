---
name: rsx-testing
description: "Writing and running tests with Rsx_Test_Abstract and php artisan rsx:test - discovery, the __assert_* helpers, user impersonation helpers, a signed-in test browser (__as_signed_in_browser, __run_pending_task) for an endpoint that dispatches a #[Run_As_Dispatcher] task, per-test transactions, database baselines, --filter / --group / class selectors. Use when writing a test for a feature, deciding whether a class needs $requires_db_reset or --fresh, or on 'test database is the same as the development database'."
---

# RSpade testing

**The full reference is `php artisan rsx:man testing`** - every assertion signature, the context and outcome helpers, database isolation, the test-database interlock and dump cache, and the selector algebra. Read it when you need an exact signature or flag. This skill is the judgment layer: what to write, when to run it, and the traps that waste an afternoon.

## Anatomy of a test class

```php
namespace Rsx\Tests;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

class Invoice_Test extends Rsx_Test_Abstract
{
    public static function setup()    { /* shared fixtures - runs once, OUTSIDE the per-test transaction */ }
    public static function teardown() { /* cleanup - runs once after all methods */ }

    public static function test_totals_round_to_cents()
    {
        $invoice = new Invoice_Model();
        $invoice->amount = 10.005;
        $invoice->save();

        static::__assert_equals_approx(10.01, (float) $invoice->fresh()->amount);
    }
}
```

- Extend `App\RSpade\Core\Testing\Rsx_Test_Abstract`.
- **Test methods are `public static` and named `test_*`** - that is the discovery rule.
- Classes are auto-discovered via the manifest; there is no registration step. Application tests live under `/rsx/` (conventionally `/rsx/tests/`); framework tests live under `app/RSpade/tests/<concern>/php/` and run with `--framework`.
- **The namespace MUST match the file path** - the single most common reason a new test class never runs.
- Helpers are `__`-prefixed and `protected static` - call them as `static::__assert_equals(...)`.
- A runnable reference class ships at `/rsx/tests/Example_Test.php`.

## What to test

**Test the failure paths.** `__assert_throws()` is how a fail-loud framework gets tested - if a function is documented to throw on bad input, there is a test proving it does. It returns the caught throwable, so you can assert further on it.

```php
$e = static::__assert_throws(\RuntimeException::class, function () {
    Rsx_Date::parse('2025-12-24T15:30:00Z');   // datetime into a date function
}, 'expects a date');
```

Assertions are strict (`===`); use `__assert_equals_approx()` for floats and money. `__skip()` is for a genuinely inapplicable environment (a missing optional binary), **never** to silence a failure.

## Choosing the isolation mode

| The class... | Declare | Why |
|---|---|---|
| reads, or writes and rolls back (the default) | nothing | each `test_*` runs in a transaction that is rolled back |
| commits, spans a subprocess, or touches no DB at all | `$use_database_transactions = false` | the transaction would be wrong or pointless |
| has the build/migration pipeline as its SUBJECT | `$requires_db_reset = true` **and** `$use_database_transactions = false` | the runner gives it a pristine migrated baseline and restores it afterwards |

`setup()`/`teardown()` run OUTSIDE the per-test transaction, so fixtures created there persist for the whole class and are `teardown()`'s job to remove.

**Queued work does not run by itself under the suite.** `Task::dispatch()` (and everything that dispatches - mail, uploads, realtime emitters) enqueues and spawns no worker while a test runs. A test that asserts on the OUTCOME of queued work drives it itself - `Task::internal()`, the service method, or `Artisan::call('rsx:task:worker')` in-process; a test whose subject is the spawn opts in with `Task::spawn_workers(true)` (reset at the class boundary). A `#[Run_As_Dispatcher]` task runs inline as the test's declared user: `__acting_as_user($id)` then `__run_task_method(Service::class, 'method', $params)` (or `Task::internal()`); with nobody declared it is refused. **The endpoint that dispatches one** is tested through a signed-in browser: `__as_signed_in_browser($user_id)` (portal: `__as_signed_in_portal_browser()`) gives the test a real sign-in, the endpoint's `Task::dispatch()` binds the run to it and leaves it PENDING, and `__run_pending_task($task_id)` executes it as a worker would, as its dispatcher, returning the settled `Task_Run_Model` (assert `status_id`, `error`, `state()`). `rsx:man tasks`, TESTING.

**A transaction rolls back rows, not statics.** The runner clears the CLI session, `Portal_Session` and the Turnstile latch after every test - so a `Portal_Session::set_site_id()` in `setup()` does NOT survive into the tests. Declare the portal site inside each test method. A class that passes in the suite and fails standalone (or the reverse) is borrowed state, essentially always this.

## Running and narrowing

```bash
rsx:test                                    # the whole application suite
rsx:test Invoice_Test Client_Test           # one or more classes (substring match)
rsx:test --filter=invite --filter=expire    # match a class OR a test_* method name
rsx:test --group=portal --group=billing     # whole concern directories
rsx:test --framework --group=locks          # the framework suite, one group
rsx:test --fresh                            # drop + recreate the test DB first
```

All three selectors take a SET; within one selector members are OR'd, across selectors they are AND'd. `--framework` selects the framework partition rather than adding to it. Exit code is 1 only if something failed - a run that selected NOTHING exits 0, so read the summary line.

**A run inside the RSpade container closes the site to outside visitors while its classes run.** The test run and the served site share one manifest, so the runner closes Proxority to external clients (they get a 503 saying a test run is in progress) and reopens it when the run ends, pass or fail - `Proxority: closing the site ...` / `Proxority: open to every client again.` Browser tests and `rsx:debug` (loopback) still reach the site. A runner killed mid-run cannot leave it closed: the proxy reopens once the runner's process is gone. So a 503 on a box during a run is expected, and running the suite on a box serving real users takes it offline for the run. `rsx:man testing`, THE TEST TREES IN THE MANIFEST.

**You rarely need `--fresh`**: a plain run applies pending migrations to the test database first. Reach for it when you suspect the test DB has drifted or been dirtied, not because you added a migration.

## Two things that are never the answer

**Never switch to the production database**, and **never mark a failing test as passing.** The runner refuses to start when `DB_TEST_DATABASE` equals the dev database; that refusal is the feature, not an obstacle.

## When to run the suite

The rules are an always-on conduct mandate (verification is a smoke test; the suite runs at three moments; several groups are one command; one foreground run, never re-run because slow). The reasons behind them:

- **Verification after a change is a SMOKE TEST, not a test run.** `rsx:debug /path` (or `rsx:debug /` when nothing more specific applies) answering 200 with no console errors proves the environment is functional, and a manifest-build failure or a code-quality violation surfaces there on its own. A minute of tests per edit, multiplied across an epic, is how a day disappears with nothing shipped - so no `rsx:test` after a small change, no group "to be safe", and never permutations of groups and filters as evidence.
- **The three moments**: (1) the test you JUST WROTE or JUST CHANGED, run by itself (class name or `--filter=`), without asking; (2) the end of a major phase or epic, ONCE, the full suite or the affected groups, read then; (3) when the user asks. **A patch handed to you to apply is verified by the smoke test and by the tests it touched - nothing more.** Every feature you write still gets a test: the restriction targets RUNNING, never writing.
- **Several groups or classes are ONE command, never a loop.** `--group` and the class argument both repeat (`rsx:test --group=session --group=two_factor --group=mail`, `rsx:test Foo_Test Bar_Test`); one invocation hands the whole selection to the parallel runner, which spreads it across every worker. A shell `for` loop over groups runs them one at a time, each paying its own startup, and throws the parallelism away. Application tests and `--framework` tests are the one split a single command cannot make.
- **One FOREGROUND command, awaited and read.** A framework run occupies several full-stack containers (mysql, php-fpm, nginx and the rest in each), so a run started in the background and abandoned, or a second run beside one in flight, is paid for by everything else on the host. Slowness is load, not failure: re-running a slow test doubles the load that made it slow.

## Further reading

- `php artisan rsx:man testing` - the complete API and runner reference.
- `system/app/RSpade/tests/CLAUDE.md` - framework test structure: concerns, the five test types (php/cli/asset/http/playwright), and the README/test_catalog contract.
