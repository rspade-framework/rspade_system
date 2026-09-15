/**
 * RSpade path resolution for node - the twin of system/bootstrap/rsx_paths.php.
 *
 * Every helper daemon in system/bin that needs the build tree, the tmp tree, storage
 * or the state directory asks here rather than composing "storage/..." itself, so an
 * operator who relocated a root with RSX_TMP_PATH or RSX_STORAGE_PATH gets the same
 * answer from node that PHP gives. build/ is fixed at <project>/build and has no key.
 *
 * Reading order matches phpdotenv's immutable behaviour and the PHP resolver: a
 * non-empty real environment variable wins, otherwise the FIRST matching line of the
 * project-root .env. PHP that spawns a helper exports the resolved roots first, so a
 * child normally reads them straight out of its environment; supervisor-started
 * daemons resolve for themselves.
 *
 * CommonJS, zero dependencies: these run before anything is installed.
 */

const fs = require('fs');
const path = require('path');

// <project>/system/bin/lib/rsx_paths.js -> <project>
const PROJECT_ROOT = path.resolve(__dirname, '..', '..', '..');

let env_file_loaded = false;

/**
 * Hand-rolled .env reader. Only fills keys that are not already in the real
 * environment, so an explicit export always wins. Absent file is not an error.
 */
function load_env_file(env_path) {
    if (!fs.existsSync(env_path)) return;

    const content = fs.readFileSync(env_path, 'utf8');
    for (const line of content.split('\n')) {
        const trimmed = line.trim();
        if (!trimmed || trimmed.startsWith('#')) continue;
        const eq = trimmed.indexOf('=');
        if (eq === -1) continue;
        const key = trimmed.substring(0, eq).trim();
        let value = trimmed.substring(eq + 1).trim();
        if ((value.startsWith('"') && value.endsWith('"'))
            || (value.startsWith("'") && value.endsWith("'"))) {
            value = value.slice(1, -1);
        }
        if (!process.env[key]) {
            process.env[key] = value;
        }
    }
}

function project_root() {
    return PROJECT_ROOT;
}

/**
 * One key: the environment when set and non-empty, else the project-root .env.
 */
function env_value(key, fallback = '') {
    if (process.env[key] && String(process.env[key]).trim() !== '') {
        return String(process.env[key]).trim();
    }

    if (!env_file_loaded) {
        env_file_loaded = true;
        load_env_file(path.join(PROJECT_ROOT, '.env'));
    }

    if (process.env[key] && String(process.env[key]).trim() !== '') {
        return String(process.env[key]).trim();
    }

    return fallback;
}

function resolve_root(key, default_name) {
    const value = env_value(key);

    if (value === '') {
        return path.join(PROJECT_ROOT, default_name);
    }

    // A relative value is relative to the PROJECT ROOT, never to the working directory.
    if (!path.isAbsolute(value)) {
        return path.join(PROJECT_ROOT, value.replace(/^\.\//, '')).replace(/\/+$/, '');
    }

    return value.replace(/\/+$/, '');
}

// build/ is FIXED beside system/ and rsx/: the seal, the manifest index and the build
// key live in it, and it is made read-only with them on a production box.
function build_root()   { return path.join(PROJECT_ROOT, 'build'); }
function tmp_root()     { return resolve_root('RSX_TMP_PATH', 'tmp'); }
function storage_root() { return resolve_root('RSX_STORAGE_PATH', 'storage'); }
function state_root()   { return path.join(storage_root(), 'state'); }

module.exports = {
    load_env_file,
    project_root,
    env_value,
    build_root,
    tmp_root,
    storage_root,
    state_root,
};
