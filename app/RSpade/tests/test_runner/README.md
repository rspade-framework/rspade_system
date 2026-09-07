# test_runner - tests for the tester

The test runner itself: `php artisan rsx:test`, and specifically the DOCKER-PARALLEL
path it takes for the full framework suite on a development box. Everything here exists
because a broken test runner is the one bug the test suite cannot report.

## Applicability

This concern covers the runner's own decisions and formats, NOT the tests it runs:

- **which invocations go to docker** and how many containers they get;
- **the queue protocol** the containers pull classes from;
- **the output format** a run produces, which must be identical whether the classes ran
  in one process or across eight containers;
- **the singleton flock** that stops two runs sharing one test database.

Out of scope: whether any individual framework test passes (that is its own concern), and
the docker image build itself (asserted by running the suite, not by a unit test).

## Source under test

| File | What it owns |
|------|--------------|
| `app/RSpade/Commands/Rsx/Rsx_Test_Command.php` | discovery, the singleton flock, the docker gate, the worker count, the queue ordering, worker mode, the output format (`print_class_results` / `print_summary` / `merge_and_report`), the full-suite result cache keyed by the manifest build key (`results_cache_path` / `read_cached_results` / `write_cached_results`, replayed through `report_records`) |
| `bin/rsx-testd/orchestrator.js` | the run: sweep, build, queue, N containers, `results.jsonl`, prune |
| `bin/rsx-testd/lib/queue_server.js` | the unix-socket work queue, the holder map, and the live per-class line printed as each result arrives |
| `bin/rsx-testd/lib/protocol.js` | frame encode/decode and `MAX_FRAME_BYTES` |
| `bin/rsx-testd/lib/docker.js` | the docker CLI wrappers |
| `app/RSpade/resource/docker/Dockerfile.test` | the test image: a migrated database baked into the datadir template |
| `app/RSpade/resource/docker/rsx-test-worker-run.sh` | the worker CMD: waits for the container's whole service roster before any test runs |

## Man pages

`rsx:man testing` (writing and running tests). The docker mechanics live beside the code
in `bin/rsx-testd/CLAUDE.md` rather than in a man page - they are how the runner achieves
parallelism, not something a developer writing a test can observe.

## The testable surface

**The output format is the contract that matters most.** Two code paths print a run: the
sequential loop prints what `$class::run()` returned, and `merge_and_report()` prints what
containers sent back over a socket. They share `print_class_results()` and
`print_summary()` precisely so they cannot drift, and `Merge_And_Report_Format_Test`
asserts both the exact lines and their equality. Five record shapes have to survive the
round trip: passed, skipped (with its message), failed (with message, file and line), a
class-level throw, and a class that produced NO record at all - which is a container that
died and is reported as a FAILURE, never dropped.

**The gate must fail closed.** `Docker_Dispatch_Test` asserts the refusals: a named class,
a `--filter`, a `--group`, `--sequential`, the application suite, and an unreachable docker
daemon each keep the run in one process. A gate that opened on a subset would build an
image to run four tests.

**The queue is a cross-language contract.** `cli/test_runner_queue_protocol.sh` drives a
real `Queue_Server` the way the PHP worker does - one connection, one line, one answer -
and asserts the wire shapes, the drain sentinel, the holder map, the exact key set of a
`results.jsonl` record, and that abuse (unknown method, malformed frame, oversized frame)
is refused without taking the server down.

**The singleton is asserted against itself.** `Runner_Singleton_Test` proves a subprocess
cannot take `storage/flock/rsx_test_runner.lock` while the run executing the test holds it.

Not asserted here, by design: the image build, the zombie sweep, pruning and signal
teardown. Those are docker lifecycle, they need a daemon and a multi-minute build, and
they are verified by running the suite - which is what every parallel run does.

## Fixtures

There are none, deliberately. `Class_Ordering_Test` needs classes that answer
`requires_db_reset()`, and it uses three REAL framework test classes rather than inventing
any: `Session_Cap_Test` (declares a reset) and `Rsx_Result_Set_Test` /
`Audit_Delete_Stamp_Test` (do not). A purpose-built fixture would have to be a class
extending `Rsx_Test_Abstract`, which discovery would then pick up and RUN - and in the
reset case drag a database re-provision along with it. The declarations the test leans on
are asserted as an explicit precondition, so a class that changes its mind says so plainly
instead of failing an ordering assertion for the wrong reason.
