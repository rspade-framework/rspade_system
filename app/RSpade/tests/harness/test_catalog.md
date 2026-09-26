# Test catalog: harness

Status legend: `implemented` | `deferred` | `blocked` | `planned`.
Type: php. Last updated: 2026-06-16.

## Test_Harness_Test (php, default isolation)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| harness-01 | all __assert_* helpers pass on valid input | mixed | no throw | implemented |
| harness-02 | __assert_throws returns caught exception | throwing fn | exception w/ message | implemented |
| harness-03 | __assert_throws fails when nothing throws | non-throwing fn | itself throws | implemented |
| harness-04 | __assert_throws fails on wrong class | wrong exception | itself throws | implemented |
| harness-05 | session impersonation set/reset | __acting_as_site / __reset_session | site id set then cleared | implemented |
| harness-06 | transaction isolation: insert visible within test | insert | count 1 | implemented |
| harness-07 | transaction isolation: prior insert rolled back | next test | count 0 | implemented |

## Nested_Run_Isolation_Test (php, no transactions)

Inner class: `Nested_Run_Fixture_Test_Abstract` - declared abstract so the runner's discovery
(`php_get_extending` + skip-abstract) never auto-runs it; called directly as `::run()`.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| harness-08 | a result recorded before a nested run exists to be lost | __pass() | entry present | implemented |
| harness-09 | a nested `run()` returns its OWN results and leaves the caller's array and `$current_test` untouched | `Nested_Run_Fixture_Test_Abstract::run()` inside a test_* method | 2 inner results; caller results identical; current_test restored | implemented |

## Detached_Process_Containment_Test (php, no transactions)

Subject: `Core/Testing/Rsx_Test_Detached_Processes.php` - the class-boundary containment of
detached processes (see `tests/CLAUDE.md`, "Detached processes are contained at the class
boundary").

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| harness-10 | `dispatch_detached()` under the suite registers its child before returning | `Rsx_Artisan::dispatch_detached('--version')` | registry holds a `<pid> <start time>` line; `contain()` empties it | implemented |
| harness-11 | `contain()` returns only after a registered process has exited | a registered `bash -c 'sleep 1'` child | the child is no longer running when `contain()` returns | implemented |
| harness-12 | a reused pid is not mistaken for the registered process | registry line naming this running process with a foreign start time | `contain()` returns at once and consumes the entry | implemented |

## Planned

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| harness-p-01 | dedicated $requires_db_reset reset-between-classes assertion | php | currently exercised indirectly; a self-contained pair would be clearer | planned |
| harness-p-02 | __skip records skipped (not failed) status | php | needs runner-result introspection | planned |
| harness-p-03 | application vs framework suite partition (--framework) | php/cli | runner-level; assert discovery split | planned |
| harness-c-01 | Two overlapping rsx:debug runs serialize on the rsx_debug system lock: both render with a 200, and one reports on stderr that it waited | cli | `rsx:debug /` and `rsx:debug /login` started together | Both 200; one "Waiting for another rsx:debug to finish" line | implemented |
