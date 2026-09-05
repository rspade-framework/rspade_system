# rsx-testd

The node orchestrator behind `php artisan rsx:test --framework` on a docker-capable RSpade
development container. It is never invoked by hand: `Rsx_Test_Command::run_docker()` spawns
it as `node system/bin/rsx-testd/orchestrator.js --run-dir= --workers= --image= --dev-image=
--project-root=`, streams its output to the console, and reads the `results.jsonl` it leaves
in the run directory. It exits 0 whenever it produced that file - a container that died is a
test-run outcome PHP reports as a failed class, not an infrastructure error - and non-zero
only when there is nothing to report. Zero npm dependencies: node core only.
