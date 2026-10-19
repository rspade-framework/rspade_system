#!/bin/bash
set -e

TEST_NAME="Realtime Connection Monitor (watch_connection)"

# Rsx_Realtime.watch_connection() is the whole public connection surface: three values,
# 'connected' | 'disconnected' | 'disabled', and registering holds the socket open. Pinned:
#   DISABLED   with no realtime_url: 'disabled' synchronously, nothing registered, no
#              network, never again.
#   SYNC       a monitor registered while connected hears 'connected' synchronously.
#   ONE WINDOW an idle client that cannot connect: monitor A registers, monitor B two seconds
#              later; both hear 'disconnected' at the SAME moment, ~5s after A - B's
#              registration does not start or extend a window of its own.
#   HOLD       a registered monitor connects an idle client and suppresses idle disconnect.
#   RELEASE    stopping the last monitor lets the idle disconnect run, and a later monitor
#              starts a fresh window (no replayed verdict) and hears 'connected'.
#   NO REPEATS no monitor ever receives the same value twice in a row.
#
# The page is /_sys, which under the debug harness starts with no anchor and no watches -
# an idle, lazily-connecting client.

cd /var/www/html/system

if ! grep -q '^REALTIME_ENABLED=true' .env 2>/dev/null; then
    echo "SKIP: $TEST_NAME - REALTIME_ENABLED is not true in .env"
    exit 0
fi

read -r -d '' EVAL <<'JS' || true
const results = {};
const no_repeats = (list) => list.every((v, i) => i === 0 || list[i - 1] !== v);
results.setup_idle = Rsx_Realtime._watches.size === 0 && !Rsx_Realtime._anchored;
Rsx_Realtime._idle_disconnect_delay = 300;

// --- DISABLED
const url = window.rsxapp.realtime_url;
delete window.rsxapp.realtime_url;
const disabled_seen = [];
const sockets_before_disabled = Rsx_Realtime._sockets_opened;
const disabled_monitor = Rsx_Realtime.watch_connection((s) => disabled_seen.push(s));
results.disabled_sync = disabled_seen.join(',');
results.disabled_registered = Rsx_Realtime._connection_monitors.size;
results.disabled_is_enabled = Rsx_Realtime.is_enabled();
window.rsxapp.realtime_url = url;
await sleep(500);
results.disabled_after = disabled_seen.join(',');
results.disabled_no_socket = Rsx_Realtime._sockets_opened === sockets_before_disabled;
disabled_monitor.stop();

// --- HOLD + SYNC: an idle client connects for a monitor and keeps the socket.
const hold_seen = [];
const hold = Rsx_Realtime.watch_connection((s) => hold_seen.push(s));
results.hold_sync_silent = hold_seen.length === 0;      // undecided at registration
for (let i = 0; i < 40 && hold_seen.length === 0; i++) await sleep(250);
results.hold_first = hold_seen.join(',');
await sleep(1000);                                        // > idle delay
results.hold_kept_open = !!Rsx_Realtime._ws && Rsx_Realtime._connected;

const sync_seen = [];
const sync_monitor = Rsx_Realtime.watch_connection((s) => sync_seen.push(s));
results.sync_connected = sync_seen.join(',');
sync_monitor.stop();
hold.stop();

// --- RELEASE: the idle disconnect runs once the last monitor is gone.
await sleep(1500);
results.release_closed = Rsx_Realtime._ws === null;
results.release_forgotten = Rsx_Realtime._public_connection === null;

// --- ONE WINDOW: the token endpoint fails, so no connection can succeed.
const real_token = Realtime_Controller.get_connection_token;
Realtime_Controller.get_connection_token = async function () { throw new Error('simulated outage'); };

const a_seen = [], b_seen = [];
let a_at = 0, b_at = 0;
const t0 = Date.now();
const a = Rsx_Realtime.watch_connection((s) => { a_seen.push(s); if (!a_at) a_at = Date.now() - t0; });
await sleep(2000);
const b = Rsx_Realtime.watch_connection((s) => { b_seen.push(s); if (!b_at) b_at = Date.now() - t0; });
results.window_b_sync_silent = b_seen.length === 0;
await sleep(4500);
results.window_a = a_seen.join(',');
results.window_b = b_seen.join(',');
results.window_a_at = a_at;
results.window_b_at = b_at;

// A monitor registering into a DECIDED outage hears it synchronously.
const late_seen = [];
const late = Rsx_Realtime.watch_connection((s) => late_seen.push(s));
results.window_late_sync = late_seen.join(',');
late.stop();

// Recovery: every monitor hears 'connected'.
Realtime_Controller.get_connection_token = real_token;
for (let i = 0; i < 80 && a_seen[a_seen.length - 1] !== 'connected'; i++) await sleep(500);
results.recovered_a = a_seen.join(',');
results.recovered_b = b_seen.join(',');
results.no_repeats = no_repeats(a_seen) && no_repeats(b_seen) && no_repeats(hold_seen);
a.stop();
b.stop();

// --- FRESH WINDOW after release: no replayed verdict, an honest 'connected'.
await sleep(1500);
const fresh_seen = [];
const fresh = Rsx_Realtime.watch_connection((s) => fresh_seen.push(s));
results.fresh_sync_silent = fresh_seen.length === 0;
for (let i = 0; i < 40 && fresh_seen.length === 0; i++) await sleep(250);
results.fresh_first = fresh_seen.join(',');
fresh.stop();

return JSON.stringify(results);
JS

result="$(timeout 300 php artisan rsx:debug /_sys --user=1 --timeout=120000 --eval="$EVAL" 2>&1 \
    | grep -o '{"setup_idle":[^}]*}')"

if [ -z "$result" ]; then
    echo "FAIL: $TEST_NAME - no result returned from the harness"
    exit 1
fi

fail() {
    echo "FAIL: $TEST_NAME - $1: $result"
    exit 1
}

field() {
    echo "$result" | grep -o "\"$1\":[0-9]*" | cut -d: -f2
}

echo "$result" | grep -q '"setup_idle":true'                    || fail "the page did not start idle (an anchor or a watch was already held)"
echo "$result" | grep -q '"disabled_sync":"disabled"'           || fail "a disabled client did not answer 'disabled' synchronously"
echo "$result" | grep -q '"disabled_registered":0'              || fail "a disabled client registered the monitor"
echo "$result" | grep -q '"disabled_is_enabled":false'          || fail "is_enabled() did not report the missing realtime_url"
echo "$result" | grep -q '"disabled_after":"disabled"'          || fail "a disabled monitor heard something after 'disabled'"
echo "$result" | grep -q '"disabled_no_socket":true'            || fail "a disabled monitor opened a socket"
echo "$result" | grep -q '"hold_sync_silent":true'              || fail "an undecided registration emitted synchronously"
echo "$result" | grep -q '"hold_first":"connected"'             || fail "a monitor on an idle client did not connect it"
echo "$result" | grep -q '"hold_kept_open":true'                || fail "a registered monitor did not hold the socket past the idle delay"
echo "$result" | grep -q '"sync_connected":"connected"'         || fail "a monitor registered while connected did not hear 'connected' synchronously"
echo "$result" | grep -q '"release_closed":true'                || fail "stopping the last monitor did not let the idle disconnect run"
echo "$result" | grep -q '"release_forgotten":true'             || fail "the answer was not forgotten when the last monitor stopped"
echo "$result" | grep -q '"window_b_sync_silent":true'          || fail "a monitor joining a running window emitted synchronously"
echo "$result" | grep -q '"window_a":"disconnected"'            || fail "monitor A did not hear 'disconnected' (and only that) during the outage"
echo "$result" | grep -q '"window_b":"disconnected"'            || fail "monitor B did not hear 'disconnected' (and only that) during the outage"
echo "$result" | grep -q '"window_late_sync":"disconnected"'    || fail "a monitor registered into a decided outage did not hear it synchronously"
echo "$result" | grep -q '"recovered_a":"disconnected,connected"' || fail "monitor A did not hear the recovery"
echo "$result" | grep -q '"recovered_b":"disconnected,connected"' || fail "monitor B did not hear the recovery"
echo "$result" | grep -q '"no_repeats":true'                    || fail "a monitor received the same value twice in a row"
echo "$result" | grep -q '"fresh_sync_silent":true'             || fail "a registration after release replayed an old verdict"
echo "$result" | grep -q '"fresh_first":"connected"'            || fail "a registration after release did not connect"

a_at="$(field window_a_at)"
b_at="$(field window_b_at)"
[ "$a_at" -ge 4500 ] && [ "$a_at" -le 6500 ]  || fail "monitor A heard 'disconnected' at ${a_at}ms, outside the 5s window"
diff=$(( b_at - a_at )); diff=${diff#-}
[ "$diff" -le 300 ]                            || fail "monitor B was answered ${diff}ms apart from A - its registration started a window of its own"

echo "PASS: $TEST_NAME"
exit 0
