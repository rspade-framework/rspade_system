/**
 * Everything the daemon reads from the environment, in one place.
 *
 * rsx_paths.env_value() copies .env INTO process.env, so the boot environment is captured
 * first: a child is built from that capture and an allowlist, never from process.env, and so
 * never inherits APP_KEY or a database password.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');

const BOOT_ENV = Object.assign({}, process.env);

const rsx_paths = require('../../lib/rsx_paths.js');
const wire = require('./wire.js');

/** Companion_Store::REDIS_DB. */
const REDIS_DB = 4;

const DEFAULT_BASE_URL = 'http://127.0.0.1';

/** What a child inherits from the daemon's own environment. */
const CHILD_ENV_ALLOWLIST = ['PATH', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TZ'];

/**
 * Laravel's spelling of "no password" is the literal null, which phpredis never sends. An AUTH
 * with it is refused by a server that has none, so it is none here too.
 */
function redis_password() {
    const value = rsx_paths.env_value('REDIS_PASSWORD');
    return value === 'null' ? '' : value;
}

/** The uid's name and home, from /etc/passwd. Nulls when it has no entry. */
function passwd_entry(uid) {
    let lines = [];
    try {
        lines = fs.readFileSync('/etc/passwd', 'utf8').split('\n');
    } catch (err) {
        return { name: null, home: null };
    }

    for (const line of lines) {
        const fields = line.split(':');
        if (Number(fields[2]) === uid && fields[2] !== '') {
            return { name: fields[0], home: fields[5] };
        }
    }

    return { name: null, home: null };
}

/**
 * Who a child runs as. A daemon running as root hands children to the application's user: the
 * owner of the storage tree, the one tree that user must be able to write in every image, else
 * the owner of the project. Anything else runs them as itself.
 */
function child_identity(storage_root, project_root) {
    if (process.getuid() !== 0) return null;

    for (const candidate of [storage_root, project_root]) {
        let stat;
        try {
            stat = fs.statSync(candidate);
        } catch (err) {
            continue;
        }
        if (stat.uid !== 0) {
            return Object.assign({ uid: stat.uid, gid: stat.gid }, passwd_entry(stat.uid));
        }
    }

    return null;
}

/**
 * @param {{project_root: ?string, build_root: ?string, host: ?string}} overrides
 *        Command line overrides, which point a test's daemon at a scratch tree
 */
function load(overrides) {
    const app_key = rsx_paths.env_value('APP_KEY');
    const project_root = overrides.project_root || rsx_paths.project_root();
    const build_root = overrides.build_root || rsx_paths.build_root();
    const storage_root = rsx_paths.storage_root();
    const state_root = rsx_paths.state_root();

    // Rsx_Connection_Scope::token(): the same defaults as its config.
    const token = wire.scope_token(
        rsx_paths.env_value('DB_DATABASE', 'forge'),
        rsx_paths.env_value('DB_HOST', '127.0.0.1')
    );

    return {
        app_key,
        daemon_key: app_key === '' ? '' : wire.daemon_key(app_key),
        token,
        prefix: 'rsx_companion:' + token + ':',
        host: overrides.host || os.hostname(),

        project_root,
        build_root,
        descriptors_root: path.join(build_root, 'companions'),
        build_key_file: path.join(build_root, 'build_key'),

        storage_root,
        state_root,
        companion_state_dir: rsx_paths.companion_state_dir(),
        maintenance_flag_file: path.join(state_root, '.maintenance.mode.framework.update'),
        overlay_flag_file: rsx_paths.companion_maintenance_flag_file(),
        manifest_marker_file: rsx_paths.manifest_build_marker_file(),
        socket_file: rsx_paths.companiond_socket_file(),
        children_file: rsx_paths.companiond_children_file(),
        daemon_log_file: rsx_paths.companiond_log_file(),

        redis: {
            socket: rsx_paths.env_value('REDIS_SOCKET'),
            host: rsx_paths.env_value('REDIS_HOST', '127.0.0.1'),
            port: Number(rsx_paths.env_value('REDIS_PORT', '6379')),
            password: redis_password(),
            db: REDIS_DB,
        },
        // Read once: a change of REALTIME_ENABLED reaches the daemon at its next start.
        realtime_enabled: ['true', '1', 'yes', 'on', '(true)'].includes(rsx_paths.env_value('REALTIME_ENABLED', 'false').toLowerCase()),
        lockd: {
            host: rsx_paths.env_value('LOCK_SERVER_HOST', '127.0.0.1'),
            port: Number(rsx_paths.env_value('LOCK_SERVER_PORT', '6210')),
        },

        base_url: rsx_paths.env_value('RSX_COMPANION_BASE_URL', DEFAULT_BASE_URL).replace(/\/+$/, ''),
        sdk_directory: path.join(__dirname, '..', 'sdk'),
        identity: child_identity(storage_root, project_root),
    };
}

/**
 * The application mode, read per decision: a mode change arrives with no daemon restart. An
 * explicit RSX_MODE in the daemon's own environment wins, as it does for PHP.
 */
function current_mode() {
    if (BOOT_ENV.RSX_MODE) return BOOT_ENV.RSX_MODE;

    let content;
    try {
        content = fs.readFileSync(path.join(rsx_paths.project_root(), '.env'), 'utf8');
    } catch (err) {
        return 'production';
    }

    const match = /^[ \t]*RSX_MODE[ \t]*=[ \t]*["']?([a-z]+)/m.exec(content);

    return match ? match[1] : 'production';
}

/** The child's base environment. */
function child_base_env(config) {
    const env = {};

    for (const key of CHILD_ENV_ALLOWLIST) {
        if (BOOT_ENV[key] !== undefined) env[key] = BOOT_ENV[key];
    }

    const identity = config.identity;
    const home = identity !== null ? identity.home : BOOT_ENV.HOME;
    if (home) env.HOME = home;

    const user = identity !== null ? identity.name : BOOT_ENV.USER;
    if (user) env.USER = user;

    env.NODE_PATH = config.sdk_directory;

    return env;
}

module.exports = { load, current_mode, child_base_env, DEFAULT_BASE_URL };
