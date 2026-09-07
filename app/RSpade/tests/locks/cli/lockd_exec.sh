#!/bin/bash

TEST_NAME="rsx-lockd exec CLI"

# `lockd exec` is the shell-level face of the daemon: hold a lock for exactly as long as a
# command runs. It is what a cron entry or a deploy script uses, so its EXIT CODES are the
# contract - a caller can only react to what it is told.
#
# Asserts:
#   - the child's exit code is passed through verbatim (0, 7, and 128+N for a signal);
#   - 124 on acquire timeout (GNU `timeout` convention) and the child never runs;
#   - --quiet suppresses lockd's own chatter and NEVER the child's output;
#   - the lock is released when the child exits, so the next exec runs straight through;
#   - two concurrent execs SERIALIZE - the second's child does not start until the first's
#     has finished;
#   - bad usage fails loudly with exit 1 rather than running anything.
#
# Against a scratch daemon on a scratch port. The supervised daemon is never touched.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

trap lockd_stop EXIT

if ! lockd_start 6296; then
    echo "FAIL: $TEST_NAME - could not start the scratch daemon"
    exit 1
fi

FAILS=0

ok() { echo "  ok   $1"; }
bad() { echo "  FAIL $1"; FAILS=$((FAILS + 1)); }

assert_eq() {   # assert_eq <expected> <actual> <message>
    if [ "$1" = "$2" ]; then
        ok "$3"
    else
        bad "$3 (expected '$1', got '$2')"
    fi
}

assert_contains() {   # assert_contains <haystack> <needle> <message>
    if echo "$1" | grep -qF -- "$2"; then
        ok "$3"
    else
        bad "$3 (missing '$2')"
    fi
}

assert_not_contains() {
    if echo "$1" | grep -qF -- "$2"; then
        bad "$3 (unexpectedly present: '$2')"
    else
        ok "$3"
    fi
}

lockd_exec() {   # lockd_exec <args...>
    node "$LOCKD_DIR/lockd.js" exec --config="$LOCKD_CONF" "$@"
}

# A lock holder in its own process, so `exec` meets real contention.
start_holder() {   # start_holder <lock name>
    node "$SCRIPT_DIR/../resource/lockd_holder.js" --name="$1" >"$LOCKD_TMP/holder.out" 2>&1 </dev/null &
    HOLDER_PID=$!
    local attempt=0
    while [ "$attempt" -lt 50 ]; do
        if grep -q '^ACQUIRED' "$LOCKD_TMP/holder.out" 2>/dev/null; then
            return 0
        fi
        sleep 0.1
        attempt=$((attempt + 1))
    done
    return 1
}

stop_holder() {
    if [ -n "$HOLDER_PID" ]; then
        kill "$HOLDER_PID" 2>/dev/null
        wait "$HOLDER_PID" 2>/dev/null
        HOLDER_PID=""
    fi
}

# ---- Exit code passthrough ---------------------------------------------------------
out="$(lockd_exec --name=EXEC_RC -- bash -c 'echo CHILD_RAN; exit 0' 2>&1)"
assert_eq 0 $? "a successful child exits 0"
assert_contains "$out" "CHILD_RAN" "the child's stdout is streamed through"

out="$(lockd_exec --name=EXEC_RC -- bash -c 'exit 7' 2>&1)"
assert_eq 7 $? "the child's exit code is passed through verbatim (7)"

out="$(lockd_exec --name=EXEC_RC -- bash -c 'kill -TERM $$' 2>&1)"
assert_eq 143 $? "a signalled child reports 128+N (SIGTERM -> 143)"

# The lock must be gone the moment each child exited, or this next one would hang.
out="$(lockd_exec --name=EXEC_RC --timeout=2 -- bash -c 'echo REACQUIRED' 2>&1)"
assert_eq 0 $? "the lock is released when the child exits"
assert_contains "$out" "REACQUIRED" "so the very next exec runs immediately"

# ---- 124 on acquire timeout --------------------------------------------------------
if start_holder EXEC_BUSY; then
    ok "a separate process is holding EXEC_BUSY"
else
    bad "could not start the holder process"
fi

out="$(lockd_exec --name=EXEC_BUSY --timeout=1 -- bash -c 'echo SHOULD_NOT_RUN' 2>&1)"
rc=$?
assert_eq 124 "$rc" "an acquire timeout exits 124"
assert_not_contains "$out" "SHOULD_NOT_RUN" "the child never runs when the lock is not acquired"
assert_contains "$out" "Failed to acquire" "the timeout message is the canonical one"

# ---- --quiet ------------------------------------------------------------------------
noisy="$(lockd_exec --name=EXEC_QUIET -- bash -c 'echo CHILD_OUT' 2>&1)"
assert_contains "$noisy" "[lockd] holding" "without --quiet, lockd narrates the hold"
assert_contains "$noisy" "CHILD_OUT" "and the child's output is there too"

quiet="$(lockd_exec --quiet --name=EXEC_QUIET -- bash -c 'echo CHILD_OUT' 2>&1)"
assert_not_contains "$quiet" "[lockd]" "--quiet suppresses lockd's own chatter"
assert_contains "$quiet" "CHILD_OUT" "--quiet NEVER suppresses the child's output"

# --quiet must not swallow a real failure either: the timeout still reports.
quiet_fail="$(lockd_exec --quiet --name=EXEC_BUSY --timeout=1 -- bash -c 'echo NOPE' 2>&1)"
assert_eq 124 $? "--quiet still exits 124 on a timeout"

stop_holder

# ---- Two concurrent execs serialize -------------------------------------------------
LOG="$LOCKD_TMP/serialize.log"
: > "$LOG"

lockd_exec --quiet --name=EXEC_SERIAL -- bash -c "echo start A >> '$LOG'; sleep 2; echo end A >> '$LOG'" &
first=$!
sleep 0.3
lockd_exec --quiet --name=EXEC_SERIAL -- bash -c "echo start B >> '$LOG'; sleep 2; echo end B >> '$LOG'" &
second=$!

wait $first; first_rc=$?
wait $second; second_rc=$?

assert_eq 0 "$first_rc" "the first concurrent exec succeeded"
assert_eq 0 "$second_rc" "the second concurrent exec succeeded"

line1="$(sed -n 1p "$LOG")"
line2="$(sed -n 2p "$LOG")"
line3="$(sed -n 3p "$LOG")"
line4="$(sed -n 4p "$LOG")"
echo "  ..   serialization log: [$line1 | $line2 | $line3 | $line4]"

first_id="${line1##* }"
second_id="${line3##* }"

assert_eq "start $first_id" "$line1" "the first child started first"
assert_eq "end $first_id" "$line2" "the first child FINISHED before the second started (no interleave)"
assert_eq "start $second_id" "$line3" "the second child started only afterwards"
assert_eq "end $second_id" "$line4" "and then finished"
if [ "$first_id" != "$second_id" ] && [ -n "$first_id" ]; then
    ok "the two execs were different children ($first_id then $second_id)"
else
    bad "expected two distinct children, got '$first_id' and '$second_id'"
fi

# ---- Bad usage fails loudly ---------------------------------------------------------
out="$(lockd_exec --name=EXEC_USAGE 2>&1)"
assert_eq 1 $? "exec with no command after -- exits 1"
assert_contains "$out" "[ERROR]" "and says why"

out="$(lockd_exec --name=EXEC_USAGE --mode=exclusive -- true 2>&1)"
assert_eq 1 $? "an invalid --mode exits 1"

out="$(lockd_exec -- true 2>&1)"
assert_eq 1 $? "exec without --name exits 1"

if [ $FAILS -gt 0 ]; then
    echo "RESULT: FAIL ($FAILS assertion(s))"
    echo "FAIL: $TEST_NAME"
    exit 1
fi

echo "RESULT: PASS"
echo "PASS: $TEST_NAME"
exit 0
