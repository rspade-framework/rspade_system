# tests/framework_update

Covers the framework self-update under the SUBMODULE model, and the pre-boot guard that
catches what the update cannot.

## What is being tested

`system/` is a git submodule tracking the framework distribution, and ALL of it is
framework property that every update overwrites. The update is:

    raise the maintenance window
      -> reset --hard + clean -fdx inside the submodule
      -> fetch, check out the upstream tip
      -> commit the gitlink (+ the history log) BEFORE the rebuild
      -> rebuild
    lower the window
    -> report pending migrations and breaking_changes

There is no tamper gate, no mutation ledger, no owned zones, no three-way reconciliation
and no release inventory. Those existed to reconstruct a boundary git could not see when
the framework and the application shared one index; a submodule makes that boundary
structural, and the tests describing them were deleted along with the code.

## Layout

    cli/_lib_fixture            fixture: fake distribution + downstream project
    cli/t*.sh                   the update, end to end
    php/*.php                   the pre-boot guards and the maintenance gate

## The fixture

`fx_build_downstream` builds a project whose `system/` is a submodule of a fake
distribution carrying two revisions (v1, v2). `fx_build_downstream_vendored` builds the
PRE-submodule shape, for testing the one-time conversion.

Two things it does deliberately, both learned by getting them wrong:

- The fake distribution ships a **flag-only `bin/maintenance-mode.sh`**. The updater calls
  that script directly, so there is no seam through which a test could pass
  `--no-services` - and a test that reached supervisorctl would stop the HOST's real
  php-fpm. It is tracked in the distribution so it survives the submodule reset; a stub
  written into the project's `system/` afterwards is written into a checkout that
  `git clean -fdx` is about to empty.
- The fixture does **not** pin `RSX_MODE`. Absent means development, and pinning it would
  shadow a test that sets the mode somewhere else.

`fx_add_upstream_release` publishes a THIRD release on top of v1/v2, which is what makes a
multi-release changelog range testable (t35/t36).

`fx_recorded_revision` / `fx_actual_revision` are the two values everything turns on: what
the project RECORDS for `system/`, and what it is CHECKED OUT at.

**The RECORD is where an update starts from.** The range the changelog and the commit count
describe is `recorded gitlink .. fetched tip`; the checkout is never an end of it. A run
that dies between `checkout_new` and `commit_pointer` leaves the checkout AHEAD of the
record, and reading the checkout there made the next run call the framework "up to date"
and drop that range's changelog from the application's history for good (t35). A shallow
`system/` may not even hold the recorded revision - it is deepened, and an unrecoverable
one is said out loud rather than silently producing an empty changelog (t36).

## Running

    php artisan rsx:test --framework --group=framework_update
    bash system/app/RSpade/tests/framework_update/cli/t1_clean_update.sh   # one test

Every test is self-contained: it builds its own temp repositories, runs against them, and
removes them on exit. Nothing touches this box's services, database or git state.

See `test_catalog.md` for what each test proves.
