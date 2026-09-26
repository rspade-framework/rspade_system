#!/bin/bash

TEST_NAME="test_runner cli orchestrator teardown"

# A run's containers die when its orchestrator is told to stop. This drives the REAL
# orchestrator (bin/rsx-testd/orchestrator.js) against a FAKE `docker` placed first on
# PATH, so the whole lifecycle - sweep, generated Dockerfile, build, queue, N worker
# containers - runs in about a second with no daemon, and what is asserted is exactly
# what the orchestrator asks docker to do:
#
#   - SIGTERM, SIGHUP, and the parent's death (its stdin pipe closing, under
#     --watch-parent-stdin=true - how PHP's death reaches node when PHP was SIGKILLed)
#     each kill and remove THIS run's containers and exit 1;
#   - only this run's label VALUE is touched: a container labelled for another run,
#     started after this run's sweep, survives the teardown;
#   - the generated .dockerignore is removed.
#
# The fake docker keeps one file per "container" (its label) and one pid per
# "container" process: `docker run` records itself and then execs `sleep infinity`,
# `docker kill` kills that process and forgets the container (the orchestrator runs
# containers --rm), `docker ps --filter label=K[=V]` lists the matching records.
#
# SIGKILL of the orchestrator itself cannot be trapped; the container side of that
# guarantee (the worker wrapper ending its container when the queue socket closes) is
# asserted against a real container by Worker_Container_Liveness_Test.
#
# NO DOCKER, no database, no .env. Every wait below is on an event or on a condition
# polled while the process it depends on is still alive - never a deadline.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SYSTEM_DIR="$(cd "$SCRIPT_DIR/../../../../.." 2>/dev/null && pwd)"
ORCHESTRATOR="$SYSTEM_DIR/bin/rsx-testd/orchestrator.js"

if ! command -v node > /dev/null 2>&1; then
    echo "SKIP: $TEST_NAME - node is not installed"
    exit 0
fi

if [ ! -f "$ORCHESTRATOR" ]; then
    echo "FAIL: $TEST_NAME - $ORCHESTRATOR not found"
    exit 1
fi

WORK_DIR="$(mktemp -d)"
FOREIGN_PID=''

cleanup() {
    # Every fake container process still alive, whatever the outcome.
    local f
    for f in "$WORK_DIR"/state/pids/*; do
        [ -f "$f" ] && kill -KILL "$(cat "$f")" 2>/dev/null
    done
    [ -n "$FOREIGN_PID" ] && kill -KILL "$FOREIGN_PID" 2>/dev/null
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT

FAILURES=0

check() {
    local description="$1" condition="$2" detail="${3:-}"
    if [ "$condition" = "1" ]; then
        echo "  [OK] $description"
    else
        echo "  [FAIL] $description${detail:+ - $detail}"
        FAILURES=$((FAILURES + 1))
    fi
}

# -----------------------------------------------------------------------------
# The fake docker.
# -----------------------------------------------------------------------------
mkdir -p "$WORK_DIR/bin" "$WORK_DIR/state/containers" "$WORK_DIR/state/pids"

cat > "$WORK_DIR/bin/docker" <<'FAKE_DOCKER'
#!/bin/bash
state="$FAKE_DOCKER_STATE"
printf '%s\n' "$*" >> "$state/calls.log"

command="$1"
shift

case "$command" in
    ps)
        label=''
        while [ $# -gt 0 ]; do
            case "$1" in
                --filter) label="${2#label=}"; shift 2 ;;
                *) shift ;;
            esac
        done
        for record in "$state"/containers/*; do
            [ -f "$record" ] || continue
            value="$(cat "$record")"
            if [ "$value" = "$label" ] || [ "${value%%=*}" = "$label" ]; then
                basename "$record"
            fi
        done
        ;;
    run)
        name=''
        label=''
        while [ $# -gt 0 ]; do
            case "$1" in
                --name) name="$2"; shift 2 ;;
                --label) label="$2"; shift 2 ;;
                *) shift ;;
            esac
        done
        printf '%s' "$label" > "$state/containers/$name"
        printf '%s' "$$" > "$state/pids/$name"
        exec sleep infinity
        ;;
    kill)
        for id in "$@"; do
            [ -f "$state/pids/$id" ] && kill -KILL "$(cat "$state/pids/$id")" 2>/dev/null
            rm -f "$state/containers/$id" "$state/pids/$id"
        done
        ;;
    rm)
        for id in "$@"; do
            [ "$id" = "-f" ] && continue
            rm -f "$state/containers/$id" "$state/pids/$id"
        done
        ;;
esac

exit 0
FAKE_DOCKER

# The ONE executable-bit file in this test: the orchestrator runs `docker` by NAME through
# PATH (execFile, no shell), exactly as it runs the real one, so the fake must be an
# executable found first on PATH. Without the bit, PATH lookup skips it and silently
# reaches the REAL docker - which this test must never touch - so its absence is fatal.
chmod 755 "$WORK_DIR/bin/docker"
if [ ! -x "$WORK_DIR/bin/docker" ]; then
    echo "FAIL: $TEST_NAME - the fake docker could not be made executable"
    exit 1
fi

export FAKE_DOCKER_STATE="$WORK_DIR/state"
export PATH="$WORK_DIR/bin:$PATH"

if [ "$(command -v docker)" != "$WORK_DIR/bin/docker" ]; then
    echo "FAIL: $TEST_NAME - docker resolves to $(command -v docker), not the fake"
    exit 1
fi

# The build context the orchestrator filters and enumerates: a scratch root holding the
# real framework tree (where the Dockerfile template is read from) and an empty
# application tree (where the generator looks for an optional schema snapshot).
mkdir -p "$WORK_DIR/project/rsx/resource"
ln -s "$SYSTEM_DIR" "$WORK_DIR/project/system"

# -----------------------------------------------------------------------------
# One scenario: start a run with two workers, plant another run's container once this
# run is up, end the run the way $mode says, and assert what was left behind.
# -----------------------------------------------------------------------------
run_scenario() {
    local mode="$1" run_id="test-run-teardown-$1"
    local run_dir="$WORK_DIR/$run_id" log="$WORK_DIR/$run_id.log" orchestrator_pid status

    mkdir -p "$run_dir/ipc"
    printf '%s' '[{"fqcn":"A\\One_Test","short":"One_Test","requires_db_reset":false,"class_matches":false},{"fqcn":"A\\Two_Test","short":"Two_Test","requires_db_reset":false,"class_matches":false}]' \
        > "$run_dir/classes.json"
    : > "$WORK_DIR/state/calls.log"

    local args=(
        "$ORCHESTRATOR"
        "--run-dir=$run_dir" --workers=2 --image=fake-test:latest --dev-image=fake-dev:latest
        "--project-root=$WORK_DIR/project" --framework-developer=true --suite=framework
    )

    if [ "$mode" = "parent-gone" ]; then
        # The parent's pipe: node reads the fifo, this shell holds its write end on fd 7
        # exactly as Rsx_Test_Command holds node's stdin, and closing fd 7 is the parent
        # dying.
        mkfifo "$WORK_DIR/$run_id.stdin"
        node "${args[@]}" --watch-parent-stdin=true < "$WORK_DIR/$run_id.stdin" > "$log" 2>&1 &
        orchestrator_pid=$!
        exec 7> "$WORK_DIR/$run_id.stdin"
    else
        node "${args[@]}" < /dev/null > "$log" 2>&1 &
        orchestrator_pid=$!
    fi

    # Both of this run's containers are up (or the orchestrator died trying).
    while kill -0 "$orchestrator_pid" 2>/dev/null \
        && { [ ! -f "$WORK_DIR/state/pids/rsx-test-w1" ] || [ ! -f "$WORK_DIR/state/pids/rsx-test-w2" ]; }; do
        sleep 0.1
    done

    if ! kill -0 "$orchestrator_pid" 2>/dev/null; then
        check "$mode: the orchestrator reached its workers" 0 "$(cat "$log")"
        [ "$mode" = "parent-gone" ] && exec 7>&-
        return
    fi

    local w1_pid w2_pid
    w1_pid="$(cat "$WORK_DIR/state/pids/rsx-test-w1")"
    w2_pid="$(cat "$WORK_DIR/state/pids/rsx-test-w2")"

    # Another run's container, planted AFTER this run's sweep. fd 7 is closed for it: a
    # child holding the write end of the parent pipe would keep the "parent" alive.
    bash -c 'exec sleep infinity' 7>&- &
    FOREIGN_PID=$!
    printf '%s' 'rsx-test-run=some-other-run' > "$WORK_DIR/state/containers/foreign"
    printf '%s' "$FOREIGN_PID" > "$WORK_DIR/state/pids/foreign"

    case "$mode" in
        sigterm)     kill -TERM "$orchestrator_pid" ;;
        sighup)      kill -HUP "$orchestrator_pid" ;;
        parent-gone) exec 7>&- ;;
    esac

    wait "$orchestrator_pid"
    status=$?

    check "$mode: the orchestrator exits 1" "$([ "$status" -eq 1 ] && echo 1 || echo 0)" "exit $status; $(cat "$log")"
    check "$mode: it asked docker for THIS run's containers by label value" \
        "$(grep -qxF -- "ps -a -q --filter label=rsx-test-run=$run_id" "$WORK_DIR/state/calls.log" && echo 1 || echo 0)" \
        "$(cat "$WORK_DIR/state/calls.log")"
    check "$mode: both of this run's containers are gone" \
        "$([ ! -e "$WORK_DIR/state/containers/rsx-test-w1" ] && [ ! -e "$WORK_DIR/state/containers/rsx-test-w2" ] && echo 1 || echo 0)"
    check "$mode: both of this run's container processes are dead" \
        "$(! kill -0 "$w1_pid" 2>/dev/null && ! kill -0 "$w2_pid" 2>/dev/null && echo 1 || echo 0)"
    check "$mode: another run's container is untouched" \
        "$([ -e "$WORK_DIR/state/containers/foreign" ] && kill -0 "$FOREIGN_PID" 2>/dev/null && echo 1 || echo 0)"
    check "$mode: the generated .dockerignore is removed" \
        "$([ ! -e "$WORK_DIR/project/.dockerignore" ] && echo 1 || echo 0)"

    kill -KILL "$FOREIGN_PID" 2>/dev/null
    wait "$FOREIGN_PID" 2>/dev/null
    FOREIGN_PID=''
    rm -f "$WORK_DIR/state/containers/foreign" "$WORK_DIR/state/pids/foreign"
}

run_scenario sigterm
run_scenario sighup
run_scenario parent-gone

if [ "$FAILURES" -eq 0 ]; then
    echo "PASS: $TEST_NAME"
    exit 0
fi

echo "FAIL: $TEST_NAME"
exit 1
