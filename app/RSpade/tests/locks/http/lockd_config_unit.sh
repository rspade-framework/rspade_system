#!/bin/bash

TEST_NAME="rsx-lockd config search order and total validation (pure, no daemon)"

# lib/config.js driven through its export seam over fixture files in a scratch directory.
# No daemon is started and no port is opened.
#
# Proves:
#   - the search order: --config= beats LOCKD_CONFIG beats <project>/lockd.conf beats the
#     shipped copy, and an explicitly named file that is missing THROWS rather than falling
#     through to the next candidate;
#   - total validation: an unknown key (top-level or nested), a wrong type, and both
#     listeners disabled are each an error naming the key - never a silent default - and
#     one config carrying several problems reports ALL of them in one throw;
#   - absent keys take the documented defaults, and whole-line // comments are tolerated.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

LOCKD_TMP="$(mktemp -d /tmp/lockd-test-XXXXXX)"
export LOCKD_TMP
trap lockd_stop EXIT

cat > "$LOCKD_TMP/harness.js" <<'NODE'
'use strict';

const fs = require('fs');
const path = require('path');
const h = require(process.env.LOCKD_HARNESS_LIB);
const config_lib = require(path.join(h.LOCKD_DIR, 'lib', 'config.js'));

const TMP = process.env.LOCKD_TMP;

function write(relative, body) {
    const file = path.join(TMP, relative);
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, typeof body === 'string' ? body : JSON.stringify(body));
    return file;
}

/** The message config_lib throws for `fn`, or null when it does not throw. */
function thrown(fn) {
    try {
        fn();
    } catch (err) {
        return err.message;
    }
    return null;
}

h.run(async function () {
    // ---- Search order -------------------------------------------------------------
    const flag_file = write('flag/lockd.conf', {});
    const env_file = write('env/lockd.conf', {});
    const project_root = path.join(TMP, 'project');
    write('project/lockd.conf', {});
    const shipped_dir = path.join(TMP, 'shipped');
    write('shipped/lockd.conf', {});

    const all = { cli_path: flag_file, env_path: env_file, project_root: project_root, shipped_dir: shipped_dir };
    h.check('--config= wins over everything',
        config_lib.resolve_config_path(all).source === 'flag');
    h.check('LOCKD_CONFIG wins when there is no flag',
        config_lib.resolve_config_path({ ...all, cli_path: null }).source === 'env');
    h.check('the project-root file wins over the shipped copy',
        config_lib.resolve_config_path({ project_root: project_root, shipped_dir: shipped_dir }).source === 'project');
    h.check('the shipped copy is the last resort',
        config_lib.resolve_config_path({ project_root: path.join(TMP, 'no_such_project'), shipped_dir: shipped_dir }).source === 'shipped');

    const missing_flag = thrown(() => config_lib.resolve_config_path({ ...all, cli_path: path.join(TMP, 'nope.conf') }));
    h.check('a missing --config= file throws instead of falling through',
        missing_flag !== null && missing_flag.includes('nope.conf') && missing_flag.includes('--config='));
    const missing_env = thrown(() => config_lib.resolve_config_path({ ...all, cli_path: null, env_path: path.join(TMP, 'nope.conf') }));
    h.check('a missing LOCKD_CONFIG file throws instead of falling through',
        missing_env !== null && missing_env.includes('LOCKD_CONFIG'));
    h.check('no candidate at all throws',
        thrown(() => config_lib.resolve_config_path({ project_root: path.join(TMP, 'none'), shipped_dir: path.join(TMP, 'none') })) !== null);

    // ---- Defaults and comments ----------------------------------------------------
    const commented = write('commented.conf', '// operator note\n{\n  // tcp on a scratch port\n  "tcp": { "port": 7001 }\n}\n');
    const loaded = config_lib.load_config({ cli_path: commented });
    h.check('whole-line // comments are tolerated', loaded.config.tcp.port === 7001);
    h.check('absent keys take the documented defaults',
        loaded.config.tcp.enabled === true && loaded.config.auth.hmac_key_env === 'APP_KEY'
        && loaded.config.log_events === false);

    // ---- Total validation ---------------------------------------------------------
    const unknown_top = thrown(() => config_lib.validate_config({ tpc: { port: 1 } }));
    h.check('an unknown top-level key is an error naming it',
        unknown_top !== null && unknown_top.includes("unknown key 'tpc'"));

    const unknown_nested = thrown(() => config_lib.validate_config({ tcp: { prot: 1 } }));
    h.check('an unknown nested key is an error naming its full path',
        unknown_nested !== null && unknown_nested.includes("unknown key 'tcp.prot'"));

    const wrong_type = thrown(() => config_lib.validate_config({ tcp: { port: '6210' } }));
    h.check('a wrong type is an error naming the key',
        wrong_type !== null && wrong_type.includes("'tcp.port' must be an integer"));

    const wrong_bool = thrown(() => config_lib.validate_config({ log_events: 'yes' }));
    h.check('a non-boolean switch is an error naming the key',
        wrong_bool !== null && wrong_bool.includes("'log_events' must be true or false"));

    const no_listener = thrown(() => config_lib.validate_config({ tcp: { enabled: false }, unix: { enabled: false } }));
    h.check('both listeners disabled is an error',
        no_listener !== null && no_listener.includes('both listeners are disabled'));

    const several = thrown(() => config_lib.validate_config({ bogus: 1, tcp: { port: 'x' }, pidfile: '' }));
    h.check('several problems are reported together in one throw',
        several !== null && several.includes("unknown key 'bogus'")
        && several.includes("'tcp.port' must be an integer")
        && several.includes("'pidfile' must be a non-empty string"));

    h.check('a config file that is not JSON throws naming the file',
        (thrown(() => config_lib.load_config({ cli_path: write('broken.conf', '{ nope') })) || '').includes('broken.conf'));
});
NODE

output="$(node "$LOCKD_TMP/harness.js" 2>&1)"
status=$?
echo "$output"

if [ $status -ne 0 ] || ! echo "$output" | grep -q '^RESULT: PASS'; then
    echo "FAIL: $TEST_NAME"
    exit 1
fi

echo "PASS: $TEST_NAME"
exit 0
