#!/bin/bash

TEST_NAME="rsx-lockd dump renders holders, waiters and semaphores"

# `lockd.js dump` is the primary tool for diagnosing a stuck lock, so its rendering is
# pinned: a scratch daemon is put in a known state - a WRITE lock held, a second connection
# WAITING behind it, a semaphore slot held - by a separate node process, and the CLI is run
# against it the way an operator would run it.
#
# Proves the ASCII report names each holder (HELD WRITE / HELD SEM), each waiter (WAITING,
# with "no timeout"), the per-lock holders -> queue summary and the semaphore occupancy;
# that the report is plain ASCII; and that --json emits the daemon's raw dump frame.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

STATE_PID=""
cleanup() {
    local status=$?
    if [ -n "$STATE_PID" ]; then
        kill "$STATE_PID" 2>/dev/null
        wait "$STATE_PID" 2>/dev/null
    fi
    lockd_stop
    return $status
}
trap cleanup EXIT

if ! lockd_start 6299; then
    echo "FAIL: $TEST_NAME - could not start the scratch daemon"
    exit 1
fi

# The state holder: three connections, held until this process is killed.
cat > "$LOCKD_TMP/state.js" <<'NODE'
'use strict';

const h = require(process.env.LOCKD_HARNESS_LIB);

(async function () {
    const holder = await h.connect();
    const waiter = await h.connect();
    const sem = await h.connect();

    await holder.request({ op: 'acquire', name: 'DUMP_LOCK', mode: 'write', timeout: null });
    await sem.request({ op: 'sem_acquire', name: 'DUMP_SEM', max_slots: 2, timeout: null });
    waiter.request({ op: 'acquire', name: 'DUMP_LOCK', mode: 'read', timeout: null });

    // Ready once the waiter is visibly queued.
    const observer = await h.connect();
    for (;;) {
        const stats = await observer.request({ op: 'stats', name: 'DUMP_LOCK' });
        if (stats.queue_length === 1) break;
        await h.sleep(20);
    }
    observer.close();
    console.log('ready ' + holder.conn_id + ' ' + waiter.conn_id + ' ' + sem.conn_id);
    setInterval(() => {}, 1000);
})().catch((err) => {
    console.log('error ' + err.message);
    process.exit(1);
});
NODE

node "$LOCKD_TMP/state.js" > "$LOCKD_TMP/state.out" 2>&1 &
STATE_PID=$!

ready=""
for attempt in $(seq 1 100); do
    ready="$(grep '^ready ' "$LOCKD_TMP/state.out" 2>/dev/null)"
    [ -n "$ready" ] && break
    if ! kill -0 "$STATE_PID" 2>/dev/null; then break; fi
    sleep 0.1
done
if [ -z "$ready" ]; then
    echo "FAIL: $TEST_NAME - the state holder never reported ready"
    cat "$LOCKD_TMP/state.out"
    exit 1
fi
read -r _ holder_conn waiter_conn sem_conn <<< "$ready"

fail() {
    echo "FAIL: $TEST_NAME - $1"
    echo "$report"
    exit 1
}

report="$(node "$LOCKD_DIR/lockd.js" dump --config="$LOCKD_CONF" 2>&1)"
[ $? -eq 0 ] || fail "dump exited non-zero"

echo "$report" | grep -q '^rsx-lockd state' || fail "no report header"
echo "$report" | grep -qE "^  connections: [0-9]+   locks: 1   semaphores: 1" || fail "the summary line does not count one lock and one semaphore"
echo "$report" | grep -qE "^    HELD     WRITE DUMP_LOCK   for [0-9.]+s" || fail "the WRITE holder is not shown"
echo "$report" | grep -qE "^    WAITING  READ  DUMP_LOCK   for [0-9.]+s   \(no timeout\)" || fail "the waiter is not shown with its bound"
echo "$report" | grep -qE "^    HELD     SEM   DUMP_SEM slot [0-9]+" || fail "the semaphore slot is not shown"
echo "$report" | grep -q "(this dump)" || fail "the dump's own connection is not marked"
echo "$report" | grep -q "^    holders: ${holder_conn}/write$" || fail "the lock summary does not name the holder"
echo "$report" | grep -q "^    queue:   ${waiter_conn}/read$" || fail "the lock summary does not name the queue"
echo "$report" | grep -q "^  DUMP_SEM  1/2 slots, 0 waiting$" || fail "the semaphore occupancy is wrong"
if LC_ALL=C grep -q '[^[:print:][:space:]]' <<< "$report"; then
    fail "the report is not plain ASCII"
fi

json="$(node "$LOCKD_DIR/lockd.js" dump --config="$LOCKD_CONF" --json 2>&1)"
[ $? -eq 0 ] || fail "dump --json exited non-zero"

DUMP_JSON="$json" HOLDER="$holder_conn" WAITER="$waiter_conn" SEMCONN="$sem_conn" node -e '
const d = JSON.parse(process.env.DUMP_JSON);
const lock = d.locks.find((l) => l.name === "DUMP_LOCK");
const sem = d.semaphores.find((s) => s.name === "DUMP_SEM");
const ok = d.status === "ok"
    && Array.isArray(d.connections) && d.counters && typeof d.counters.granted === "number"
    && lock && lock.holders.length === 1 && lock.holders[0].conn_id === process.env.HOLDER
    && lock.queue.length === 1 && lock.queue[0].conn_id === process.env.WAITER
    && sem && sem.max_slots === 2 && sem.holders.length === 1;
process.exit(ok ? 0 : 1);
' || fail "--json is not the raw dump frame: $json"

echo "PASS: $TEST_NAME"
exit 0
