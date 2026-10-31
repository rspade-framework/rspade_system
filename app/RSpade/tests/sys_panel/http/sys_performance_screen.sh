#!/bin/bash
set -e

TEST_NAME="Sys Performance Screen"

# The /_sys Performance screen in a real browser: both charts draw one area per kind; a
# kind toggle redraws the charts without that kind and keeps the choice in the URL hash;
# the refresh is armed while the screen shows, and disarmed once the SPA navigates away.

cd /var/www/html/system

read -r -d '' EVAL <<'JS' || true
const results = {};
for (let i = 0; i < 40 && $('._Sys_Stacked_Chart').length < 2; i++) await sleep(250);
const action = $('._Sys_Performance_Action').component();

results.charts = $('._Sys_Stacked_Chart').length;
results.areas_before = $('._Sys_Stacked_Chart').first().find('polygon').length;
results.armed = action._poll_timer !== null;

$('[data-kind-toggle="page"]').prop('checked', false).trigger('change');
await sleep(250);
results.areas_after = $('._Sys_Stacked_Chart').first().find('polygon').length;
results.workers_after = $('._Sys_Stacked_Chart').last().find('polygon').length;
results.hash = String(Rsx.url_hash_get('kinds'));

Spa.dispatch(Rsx.Route('_Sys_Dashboard_Action'));
for (let i = 0; i < 40 && !action.is_stopped(); i++) await sleep(250);
results.stopped = action.is_stopped();
results.disarmed = action._poll_timer === null;

return JSON.stringify(results);
JS

result="$(php artisan rsx:debug /_sys/performance --user=1 --eval="$EVAL" 2>&1 | grep -o '{"charts":[^}]*}')"

if [ -z "$result" ]; then
    echo "FAIL: $TEST_NAME - no result returned from the harness"
    exit 1
fi

fail() {
    echo "FAIL: $TEST_NAME - $1: $result"
    exit 1
}

echo "$result" | grep -q '"charts":2'                 || fail "the two charts did not draw"
echo "$result" | grep -q '"areas_before":4'           || fail "a chart did not draw one area per kind"
echo "$result" | grep -q '"armed":true'               || fail "the refresh was not armed"
echo "$result" | grep -q '"areas_after":3'            || fail "the memory chart still draws the hidden kind"
echo "$result" | grep -q '"workers_after":3'          || fail "the workers chart still draws the hidden kind"
echo "$result" | grep -q '"hash":"ajax,api,task"'     || fail "the toggle was not kept in the URL hash"
echo "$result" | grep -q '"stopped":true'             || fail "the screen did not stop on navigation"
echo "$result" | grep -q '"disarmed":true'            || fail "the refresh outlived the screen"

echo "PASS: $TEST_NAME"
exit 0
