#!/usr/bin/env node
/**
 * rspade-taskd - the RSpade container's task scheduler: runs the task tick when it is wanted,
 * instead of once a minute.
 *
 *     node rspade-taskd.js [--flags]     serve
 *     node rspade-taskd.js status        what it last did and when it next ticks
 *     node rspade-taskd.js poke          ask for a tick now (the application does this itself)
 *
 * The image installs this directory at /opt/rspade/rspade-taskd, supervisor runs it as the
 * `tasks` program, and /usr/local/bin/rspade-taskd is the operator's command.
 *
 * THE TICK IS THE APPLICATION'S. `php artisan rsx:task:process` reaps dead runs, enforces the
 * silence limit, reconciles the #[Schedule] declarations and spawns workers for due work - the
 * same command cron runs every minute outside the container. Called with the internal flag
 * --_report-next-wake it also prints one line, RSX_TASK_NEXT_WAKE_MS=<ms>|none: how long until
 * it is wanted again (the next schedule, the next deferred run, or a minute while anything is
 * running - App\RSpade\Core\Task\Task_Daemon::next_wake_ms()). This daemon only decides WHEN to
 * run it:
 *
 *   - at start, and then at the moment the last tick named, never later than CEILING_MS;
 *   - when POKED (a line `poke` on the socket): the application pokes whenever the next due
 *     moment may have moved earlier - a dispatch, a schedule's next run set, a worker that ran
 *     something exiting, a manifest rebuild, rsx:tasks:enable (which leaving maintenance runs);
 *   - pokes and wakes are DEBOUNCED: a request runs the tick at once unless one ran within
 *     DEBOUNCE_MS; every request during a tick or within DEBOUNCE_MS after it becomes ONE
 *     follow-up tick DEBOUNCE_MS after it ended. Ticks never overlap.
 *
 * WHEN THE TICK FAILS. Exit 75 is the maintenance gate refusing the runners: retried every
 * MAINTENANCE_RETRY_MS (rsx:maintenance:disable pokes when the window closes, so this is only
 * the backstop). Any other failure - MySQL down, a broken tree - is retried every
 * ERROR_RETRY_MS, and logged once when it starts and once when it ends; until the FIRST tick of
 * this process has succeeded a failure is expected (the container's services are still coming
 * up) and is retried every STARTUP_RETRY_MS without a word.
 *
 * NO DEADLINE ON A TICK. A tick runs as long as it runs; nothing here kills it. The intervals
 * below are cadences - when to ASK again - and the owner's values.
 *
 * Flags (every one has the production default; the tests point them at scratch):
 *
 *     --socket=/run/rspade-taskd/taskd.sock
 *     --tick-command=...      JSON array of argv, or words split on whitespace; spawned with
 *                             no shell. Default: php /var/www/html/system/artisan
 *                             rsx:task:process --_report-next-wake
 *
 * Test-only flags: --_debounce-ms, --_ceiling-ms, --_error-retry-ms, --_maintenance-retry-ms,
 * --_startup-retry-ms.
 *
 * Signals: SIGTERM / SIGINT stop accepting pokes, let a tick in progress finish, then exit.
 * Zero npm dependencies: node core only.
 */

const child_process = require('child_process');
const fs = require('fs');
const net = require('net');
const path = require('path');

/** A poke never runs the tick more often than this; owner-specified. */
const DEBOUNCE_MS = 2 * 1000;

/**
 * The longest the daemon goes without asking, even when the last tick named nothing to wait
 * for: the backstop against clock drift and against work no tick announced (another host's
 * run that has gone silent, settled by the vanished-host rule). Owner-specified.
 */
const CEILING_MS = 60 * 60 * 1000;

/** A failing tick is asked again this often; owner-specified. */
const ERROR_RETRY_MS = 60 * 1000;

/** The maintenance gate refused the tick: asked again this often; owner-specified. */
const MAINTENANCE_RETRY_MS = 5 * 60 * 1000;

/** Before the first good tick (the container still starting): asked again this often. */
const STARTUP_RETRY_MS = 5 * 1000;

/** The maintenance gate's exit code for the task runners (bootstrap/rsx_preboot.php). */
const MAINTENANCE_EXIT_CODE = 75;

const NEXT_WAKE_PREFIX = 'RSX_TASK_NEXT_WAKE_MS=';

/** A socket request line larger than this closes the connection: no frame is large. */
const MAX_SOCKET_FRAME_BYTES = 4096;

const DEFAULTS = {
    'socket': '/run/rspade-taskd/taskd.sock',
    'tick-command': null,
    '_debounce-ms': String(DEBOUNCE_MS),
    '_ceiling-ms': String(CEILING_MS),
    '_error-retry-ms': String(ERROR_RETRY_MS),
    '_maintenance-retry-ms': String(MAINTENANCE_RETRY_MS),
    '_startup-retry-ms': String(STARTUP_RETRY_MS),
};

// ---------------------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------------------

function parse_argv(argv) {
    const flags = {};
    const words = [];

    for (const arg of argv) {
        if (arg.startsWith('--')) {
            const eq = arg.indexOf('=');
            if (eq === -1) {
                flags[arg.substring(2)] = true;
            } else {
                flags[arg.substring(2, eq)] = arg.substring(eq + 1);
            }
        } else {
            words.push(arg);
        }
    }

    return { flags, words };
}

function parse_ms(value, flag) {
    const number = Number(value);
    if (!Number.isFinite(number) || number < 0) {
        throw new Error('--' + flag + ' must be a number of milliseconds, got "' + value + '"');
    }
    return number;
}

function parse_command(value) {
    if (value === null) {
        return ['php', '/var/www/html/system/artisan', 'rsx:task:process', '--_report-next-wake'];
    }

    const text = String(value).trim();
    const command = text.startsWith('[') ? JSON.parse(text) : text.split(/\s+/);
    if (!Array.isArray(command) || command.length === 0 || !command.every((word) => typeof word === 'string' && word !== '')) {
        throw new Error('--tick-command must name a command, got "' + value + '"');
    }
    return command;
}

function serve_options(flags) {
    const known = new Set(Object.keys(DEFAULTS));
    for (const flag of Object.keys(flags)) {
        if (!known.has(flag)) throw new Error('Unknown flag --' + flag);
    }

    const value = (flag) => (flags[flag] === undefined ? DEFAULTS[flag] : flags[flag]);

    return {
        socket: String(value('socket')),
        tick_command: parse_command(value('tick-command')),
        debounce_ms: parse_ms(value('_debounce-ms'), '_debounce-ms'),
        ceiling_ms: parse_ms(value('_ceiling-ms'), '_ceiling-ms'),
        error_retry_ms: parse_ms(value('_error-retry-ms'), '_error-retry-ms'),
        maintenance_retry_ms: parse_ms(value('_maintenance-retry-ms'), '_maintenance-retry-ms'),
        startup_retry_ms: parse_ms(value('_startup-retry-ms'), '_startup-retry-ms'),
    };
}

/**
 * What a finished tick said: the next wake (ms, or null for "nothing waiting"), and its other
 * output lines - the tick's own conditional narration (a spawn, a reaped run), which is logged.
 */
function parse_tick_output(stdout) {
    let next_wake_ms;
    const lines = [];

    for (const line of stdout.split('\n')) {
        const trimmed = line.trim();
        if (trimmed.startsWith(NEXT_WAKE_PREFIX)) {
            const value = trimmed.substring(NEXT_WAKE_PREFIX.length);
            next_wake_ms = value === 'none' ? null : Number(value);
        } else if (trimmed !== '') {
            lines.push(trimmed);
        }
    }

    if (next_wake_ms !== undefined && next_wake_ms !== null && !(Number.isFinite(next_wake_ms) && next_wake_ms >= 0)) {
        next_wake_ms = undefined;
    }

    return { next_wake_ms, lines };
}

// ---------------------------------------------------------------------------------------
// The daemon
// ---------------------------------------------------------------------------------------

class Task_Daemon {
    constructor(options) {
        this.options = options;
        this.started_at = Date.now();

        this.ticking = false;
        this.follow_up = false;          // a request arrived during the tick in progress
        this.last_tick_ended_at = 0;
        this.timer = null;
        this.timer_at = null;
        this.timer_reason = null;

        this.ever_succeeded = false;
        this.failing_since = null;
        this.in_maintenance = false;
        this.tick_count = 0;
        this.poke_count = 0;
        this.last_outcome = null;
        this.last_tick_at = null;

        this.stopping = false;
        this.child = null;
        this.socket_server = null;
    }

    log(line) {
        process.stdout.write('[rspade-taskd] ' + line + '\n');
    }

    async start() {
        await this._listen_socket();

        this.log('started; tick: ' + this.options.tick_command.join(' '));

        process.on('SIGTERM', () => this.stop('SIGTERM'));
        process.on('SIGINT', () => this.stop('SIGINT'));

        this.request_tick('start');
    }

    stop(signal) {
        if (this.stopping) return;
        this.stopping = true;

        this.log(signal + ': stopping' + (this.ticking ? ' once the tick in progress ends' : ''));
        this._clear_timer();
        this._close_socket();
        if (!this.ticking) process.exit(0);
    }

    // -----------------------------------------------------------------------------------
    // When to tick
    // -----------------------------------------------------------------------------------

    /**
     * Ask for a tick. Runs it now unless one is running (then ONE follow-up after it) or one
     * ended within the debounce window (then ONE tick when the window ends).
     */
    request_tick(reason) {
        if (this.stopping) return;

        if (this.ticking) {
            this.follow_up = true;
            return;
        }

        const since_last = Date.now() - this.last_tick_ended_at;
        if (this.last_tick_ended_at > 0 && since_last < this.options.debounce_ms) {
            this._arm(this.options.debounce_ms - since_last, reason, true);
            return;
        }

        this._run_tick(reason);
    }

    /**
     * Wake for a tick in `ms`. With `keep_earlier`, a wake already armed for sooner stands (a
     * poke never postpones a tick); without it this wake replaces whatever was armed (what a
     * finished tick says is the new truth).
     */
    _arm(ms, reason, keep_earlier) {
        const at = Date.now() + ms;
        if (keep_earlier && this.timer_at !== null && this.timer_at <= at) return;

        this._clear_timer();
        this.timer_at = at;
        this.timer_reason = reason;
        this.timer = setTimeout(() => {
            this.timer = null;
            this.timer_at = null;
            this.request_tick(reason);
        }, ms);
    }

    _clear_timer() {
        if (this.timer) clearTimeout(this.timer);
        this.timer = null;
        this.timer_at = null;
        this.timer_reason = null;
    }

    _run_tick(reason) {
        this._clear_timer();
        this.ticking = true;
        this.follow_up = false;
        this.tick_count++;
        this.last_tick_at = new Date().toISOString();

        let stdout = '';
        let stderr = '';
        const [command, ...args] = this.options.tick_command;

        this.child = child_process.spawn(command, args, { stdio: ['ignore', 'pipe', 'pipe'] });
        this.child.stdout.on('data', (chunk) => { stdout += chunk.toString('utf8'); });
        this.child.stderr.on('data', (chunk) => { stderr += chunk.toString('utf8'); });

        let finished = false;
        const finish = (code, spawn_error) => {
            if (finished) return;
            finished = true;
            this.child = null;
            this._tick_finished(reason, code, stdout, stderr, spawn_error);
        };

        this.child.on('error', (error) => finish(null, error));
        this.child.on('close', (code) => finish(code, null));
    }

    _tick_finished(reason, code, stdout, stderr, spawn_error) {
        this.ticking = false;
        this.last_tick_ended_at = Date.now();

        if (this.stopping) {
            process.exit(0);
        }

        const parsed = parse_tick_output(stdout);
        let wait_ms;

        if (code === 0 && parsed.next_wake_ms !== undefined) {
            for (const line of parsed.lines) this.log('tick: ' + line);

            if (this.failing_since) {
                this.log('the tick is working again (failing since ' + this.failing_since + ')');
                this.failing_since = null;
            }
            if (this.in_maintenance) {
                this.log('maintenance is over; ticking again');
                this.in_maintenance = false;
            }
            this.ever_succeeded = true;

            wait_ms = parsed.next_wake_ms === null
                ? this.options.ceiling_ms
                : Math.min(parsed.next_wake_ms, this.options.ceiling_ms);
            this.last_outcome = 'ok (' + reason + '); next tick in ' + Math.round(wait_ms / 1000) + ' s';
            this._arm(wait_ms, parsed.next_wake_ms === null ? 'ceiling' : 'due', false);
        } else if (code === MAINTENANCE_EXIT_CODE) {
            if (!this.in_maintenance) {
                this.log('maintenance mode: the tick is refused; asking again every '
                    + Math.round(this.options.maintenance_retry_ms / 1000) + ' s, or when poked');
                this.in_maintenance = true;
            }
            this.last_outcome = 'maintenance (' + reason + ')';
            this._arm(this.options.maintenance_retry_ms, 'maintenance', false);
        } else {
            const why = spawn_error
                ? spawn_error.message
                : 'exit ' + code + (code === 0 ? ' without a ' + NEXT_WAKE_PREFIX + ' line' : '');

            if (!this.ever_succeeded) {
                this.last_outcome = 'not ready yet (' + why + ')';
                this._arm(this.options.startup_retry_ms, 'startup', false);
            } else {
                if (!this.failing_since) {
                    this.failing_since = new Date().toISOString();
                    this.log('[ERROR] the tick failed (' + why + '); asking again every '
                        + Math.round(this.options.error_retry_ms / 1000) + ' s');
                    const detail = (stderr.trim() || parsed.lines.join('\n')).split('\n').slice(-20).join('\n');
                    if (detail) this.log(detail);
                }
                this.last_outcome = 'failed (' + why + ')';
                this._arm(this.options.error_retry_ms, 'error', false);
            }
        }

        // Somebody asked while the tick ran: one follow-up, debounced.
        if (this.follow_up) {
            this.follow_up = false;
            this._arm(this.options.debounce_ms, 'poke', true);
        }
    }

    // -----------------------------------------------------------------------------------
    // The socket: one request line, one answer line.
    // -----------------------------------------------------------------------------------

    status() {
        return {
            pid: process.pid,
            started_at: new Date(this.started_at).toISOString(),
            ticking: this.ticking,
            tick_count: this.tick_count,
            poke_count: this.poke_count,
            last_tick_at: this.last_tick_at,
            last_outcome: this.last_outcome,
            next_tick_at: this.timer_at === null ? null : new Date(this.timer_at).toISOString(),
            next_tick_reason: this.timer_reason,
            in_maintenance: this.in_maintenance,
            failing_since: this.failing_since,
        };
    }

    async _listen_socket() {
        const file = this.options.socket;
        fs.mkdirSync(path.dirname(file), { recursive: true });

        if (fs.existsSync(file)) {
            // A live socket is another rspade-taskd: refuse rather than steal its path.
            const live = await new Promise((resolve) => {
                const probe = net.connect(file);
                probe.once('connect', () => { probe.destroy(); resolve(true); });
                probe.once('error', () => resolve(false));
            });
            if (live) throw new Error('rspade-taskd is already running on ' + file);
            fs.unlinkSync(file);
        }

        this.socket_server = net.createServer((connection) => this._connection(connection));
        await new Promise((resolve, reject) => {
            this.socket_server.once('error', reject);
            this.socket_server.listen(file, resolve);
        });

        // The application pokes from php-fpm and from every CLI process, whatever user they
        // run as; a poke can only ask for a tick that would happen anyway.
        fs.chmodSync(file, 0o666);
    }

    _close_socket() {
        if (!this.socket_server) return;
        this.socket_server.close();
        this.socket_server = null;
        try { fs.unlinkSync(this.options.socket); } catch (error) { /* already gone */ }
    }

    _connection(connection) {
        let buffer = '';

        connection.on('data', (chunk) => {
            buffer += chunk.toString('utf8');
            if (buffer.length > MAX_SOCKET_FRAME_BYTES) {
                connection.destroy();
                return;
            }

            let newline;
            while ((newline = buffer.indexOf('\n')) !== -1) {
                const line = buffer.substring(0, newline).trim();
                buffer = buffer.substring(newline + 1);

                if (line === 'poke') {
                    this.poke_count++;
                    this.request_tick('poke');
                    connection.write('ok\n');
                } else if (line === 'status') {
                    connection.write(JSON.stringify(this.status()) + '\n');
                } else if (line !== '') {
                    connection.write(JSON.stringify({ error: 'unknown request ' + JSON.stringify(line) }) + '\n');
                }
            }
        });

        // A poker writes its line and closes without reading the answer.
        connection.on('error', () => {});
    }
}

// ---------------------------------------------------------------------------------------
// The CLI client
// ---------------------------------------------------------------------------------------

function client_request(socket_file, line) {
    return new Promise((resolve, reject) => {
        const connection = net.connect(socket_file);
        let buffer = '';

        connection.once('error', (error) => {
            reject(new Error('rspade-taskd is not running (cannot connect to ' + socket_file + ': ' + error.message + ')'));
        });
        connection.on('connect', () => connection.write(line + '\n'));
        connection.on('data', (chunk) => {
            buffer += chunk.toString('utf8');
            const newline = buffer.indexOf('\n');
            if (newline !== -1) {
                connection.end();
                resolve(buffer.substring(0, newline));
            }
        });
    });
}

function format_status(status) {
    return [
        'rspade-taskd - pid ' + status.pid + ', since ' + status.started_at,
        'Ticks: ' + status.tick_count + ' (pokes received: ' + status.poke_count + ')' + (status.ticking ? ' - a tick is running now' : ''),
        'Last tick: ' + (status.last_tick_at || 'never') + (status.last_outcome ? ' - ' + status.last_outcome : ''),
        'Next tick: ' + (status.next_tick_at ? status.next_tick_at + ' (' + status.next_tick_reason + ')' : 'when poked'),
        status.in_maintenance ? 'Maintenance mode: the tick is refused until the window closes' : null,
        status.failing_since ? 'FAILING since ' + status.failing_since : null,
    ].filter((line) => line !== null).join('\n');
}

async function main() {
    const { flags, words } = parse_argv(process.argv.slice(2));
    const verb = words[0] || 'serve';

    if (verb === 'status' || verb === 'poke') {
        const socket_file = String(flags.socket || DEFAULTS.socket);
        const answer = await client_request(socket_file, verb);
        process.stdout.write(verb === 'status' ? format_status(JSON.parse(answer)) + '\n' : '[OK] poked\n');
        return;
    }
    if (verb !== 'serve') {
        throw new Error('Unknown command "' + verb + '". Usage: rspade-taskd.js [serve] | status | poke');
    }

    const daemon = new Task_Daemon(serve_options(flags));
    await daemon.start();
}

if (require.main === module) {
    main().catch((error) => {
        process.stderr.write('[rspade-taskd] [ERROR] ' + error.message + '\n');
        process.exit(1);
    });
}

module.exports = { Task_Daemon, parse_tick_output, parse_command, DEBOUNCE_MS, CEILING_MS };
