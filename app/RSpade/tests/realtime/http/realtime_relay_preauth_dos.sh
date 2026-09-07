#!/bin/bash
set -e

TEST_NAME="Realtime Relay Pre-Auth DoS Hardening"

# Regression guard for the P0 pre-auth remote DoS (audit F-003 / UC-108): a malformed
# websocket token signature made crypto.timingSafeEqual THROW RangeError (it does not
# return false on unequal buffer lengths), and the uncaught throw in the ws message
# handler killed the SINGLE relay process for every connected user - reachable before
# authentication via handle_auth()/handle_subscribe() calling validate_token() on raw
# inbound frames.
#
# The relay is a node program; this exercises its now-exported pure validators directly
# (system/bin/realtime-server.js exposes validate_token / parse_json_frame behind a
# require.main === module guard). Requiring the module also proves the guard works: no
# port is bound and no redis connection is opened, so this never touches the live
# supervised relay (which holds port 6200 and shares the one redis DB here).
#
# Asserts:
#   - the PRE-FIX mechanism was genuinely fatal (raw timingSafeEqual throws on the input),
#   - validate_token NEVER throws and returns null on every hostile shape (incl. the exact
#     64-char non-hex signature from the audit),
#   - the VALID-token happy path still returns the decoded payload (fix does not regress),
#   - parse_json_frame drops non-JSON AND the JSON literal null (a second pre-auth throw
#     path: null has no property surface, so a later msg.type read would throw TypeError).

RELAY="/var/www/html/system/bin/realtime-server.js"

if ! command -v node >/dev/null 2>&1; then
    echo "SKIP: $TEST_NAME - node not available"
    exit 0
fi
if ! node -e "require(require('path').join('$(dirname "$RELAY")', '..', 'node_modules', 'ws'))" >/dev/null 2>&1; then
    echo "SKIP: $TEST_NAME - ws module not available"
    exit 0
fi
if ! grep -q '^APP_KEY=' /var/www/html/.env 2>/dev/null; then
    echo "SKIP: $TEST_NAME - APP_KEY not set in .env"
    exit 0
fi

HARNESS="$(mktemp /tmp/realtime_dos_harness-XXXXXX.js)"
trap 'rm -f "$HARNESS"' EXIT

cat > "$HARNESS" <<'NODE'
'use strict';
const crypto = require('crypto');

// require() must NOT start the relay (main() runs only when executed as a program).
// It DOES load .env at module scope, so process.env.APP_KEY is populated here.
const relay = require(process.env.RELAY_PATH);
const { validate_token, parse_json_frame } = relay;

const APP_KEY = process.env.APP_KEY;
if (!APP_KEY) { console.log('  FAIL harness: APP_KEY missing after require'); process.exit(2); }
if (typeof validate_token !== 'function' || typeof parse_json_frame !== 'function') {
    console.log('  FAIL harness: validators not exported'); process.exit(2);
}

let fails = 0;
function check(name, cond) {
    if (cond) { console.log('  ok   ' + name); }
    else { console.log('  FAIL ' + name); fails++; }
}
function call_never_throws(name, fn) {
    try { return { value: fn(), threw: null }; }
    catch (e) {
        console.log('  FAIL ' + name + ' THREW ' + (e && e.constructor ? e.constructor.name : 'error') +
            ': ' + (e && e.message));
        fails++;
        return { value: undefined, threw: e };
    }
}

function mk_token(payload) {
    const json = JSON.stringify(payload);
    const b64 = Buffer.from(json).toString('base64');
    const sig = crypto.createHmac('sha256', APP_KEY).update(json).digest('hex');
    return b64 + '.' + sig;
}

const good_payload = { site_id: 1, user_id: null, session_id: 7, exp: Math.floor(Date.now() / 1000) + 60 };
const good_b64 = Buffer.from(JSON.stringify(good_payload)).toString('base64');
const nonhex64 = 'g'.repeat(64);                 // 64 chars, NOT hex - the exact audit input
const audit_token = good_b64 + '.' + nonhex64;

// 0) Prove the pre-fix mechanism was genuinely fatal: the raw compare throws RangeError.
let pre_fix_fatal = false;
try {
    const expected = crypto.createHmac('sha256', APP_KEY).update('x').digest('hex');
    crypto.timingSafeEqual(Buffer.from(nonhex64, 'hex'), Buffer.from(expected, 'hex'));
} catch (e) {
    pre_fix_fatal = !!(e && (/same byte length/i.test(e.message || '') ||
        /TIMING_SAFE_EQUAL_LENGTH/i.test((e.code || '') + '')));
}
check('pre-fix: raw timingSafeEqual THROWS on 64-char non-hex sig (the DoS mechanism)', pre_fix_fatal);

// 1) validate_token NEVER throws and returns null on every hostile shape.
const hostile = {
    'empty string': '',
    'no dot': 'no-dot-here',
    'dot only': '.',
    '64-char non-hex sig (AUDIT input)': audit_token,
    'short hex sig': good_b64 + '.ab',
    'valid-shape wrong sig': good_b64 + '.' + '0'.repeat(64),
    'uppercase hex sig (wrong shape)': good_b64 + '.' + 'A'.repeat(64),
};
for (const name of Object.keys(hostile)) {
    const res = call_never_throws('validate_token(' + name + ')', () => validate_token(hostile[name]));
    if (!res.threw) { check('validate_token(' + name + ') returns null', res.value === null); }
}

// 2) Happy path preserved: a correctly-signed, unexpired token returns its payload.
const valid = mk_token(good_payload);
const vr = call_never_throws('validate_token(valid token)', () => validate_token(valid));
if (!vr.threw) {
    check('valid token returns a payload object', !!vr.value && typeof vr.value === 'object');
    check('valid token payload.site_id preserved (=1)', !!vr.value && vr.value.site_id === 1);
    check('valid token payload.session_id preserved (=7)', !!vr.value && vr.value.session_id === 7);
}
const expired = mk_token({ site_id: 1, user_id: null, session_id: 7, exp: Math.floor(Date.now() / 1000) - 10 });
check('expired but correctly-signed token returns null', validate_token(expired) === null);

// 3) parse_json_frame: drops non-JSON and the literal null; passes real frames through.
check('parse_json_frame(non-JSON) === null', parse_json_frame('this is not json {') === null);
check('parse_json_frame("null") === null (msg.type TypeError kill path)', parse_json_frame('null') === null);
const frame = parse_json_frame('{"type":"auth","token":"x"}');
check('parse_json_frame(real frame) returns the object', !!frame && frame.type === 'auth');

if (fails > 0) { console.log('RESULT: FAIL (' + fails + ' assertion(s))'); process.exit(1); }
console.log('RESULT: PASS');
process.exit(0);
NODE

output="$(RELAY_PATH="$RELAY" node "$HARNESS" 2>&1)"
status=$?

echo "$output"

if [ $status -ne 0 ] || ! echo "$output" | grep -q '^RESULT: PASS'; then
    echo "FAIL: $TEST_NAME"
    exit 1
fi

echo "PASS: $TEST_NAME"
exit 0
