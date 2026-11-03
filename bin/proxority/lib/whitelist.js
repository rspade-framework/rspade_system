/**
 * The application's routing directives, refreshed on an interval.
 *
 * The application answers `php artisan rsx:proxority:routes --json` with
 *
 *     {"unthrottled": [patterns], "priority": [patterns], "media": [patterns]}
 *
 * or `{"maintenance": true}` while maintenance mode is up. The command is spawned WITHOUT a
 * shell. Only a run that exits 0 with a well-formed answer replaces the list; maintenance,
 * a non-zero exit, a spawn error, bad JSON or a malformed pattern all keep the previous list
 * and are logged. Before the first accepted answer the list is empty: everything is
 * throttled, which is the safe direction to be wrong in.
 *
 * One run at a time, and NO timeout on it: a run that is still going when the next interval
 * comes is logged and that interval is skipped, so a hung command is visible in the event log
 * every interval and costs nothing else. The interval is a poll cadence, not a deadline.
 */

const child_process = require('child_process');

const { compile_whitelist, empty_whitelist } = require('./classify.js');

/** The command's stderr kept for the log line of a failed run: enough to name the error. */
const STDERR_EXCERPT_BYTES = 400;

class Whitelist {
    /**
     * @param {object} options
     * @param {string[]} options.command  argv; spawned with no shell
     * @param {string} options.cwd
     * @param {number} options.interval_ms
     * @param {import('./event_log.js').Event_Log} options.event_log
     */
    constructor(options) {
        this.command = options.command;
        this.cwd = options.cwd;
        this.interval_ms = options.interval_ms;
        this.event_log = options.event_log;

        this.current = empty_whitelist();
        this.counts = { unthrottled: 0, priority: 0, media: 0 };
        this.accepted_at = null;
        this.last_run_at = null;
        this.last_outcome = 'never run';

        this.child = null;
        this.child_started_at = null;
        this.interval = null;
    }

    start() {
        this.refresh();
        this.interval = setInterval(() => this.refresh(), this.interval_ms);
    }

    stop() {
        if (this.interval) clearInterval(this.interval);
        this.interval = null;
        if (this.child) this.child.kill('SIGTERM');
    }

    refresh() {
        if (this.child) {
            this.event_log.log('refresh_skipped',
                'the previous refresh (pid ' + this.child.pid + ', started ' + this.child_started_at + ') is still running');
            return;
        }

        this.last_run_at = new Date().toISOString();
        this.child_started_at = this.last_run_at;

        let child;
        try {
            child = child_process.spawn(this.command[0], this.command.slice(1), {
                cwd: this.cwd,
                shell: false,
                stdio: ['ignore', 'pipe', 'pipe'],
            });
        } catch (error) {
            this._failed('could not spawn ' + this.command[0] + ': ' + error.message);
            return;
        }
        this.child = child;

        // The answer is the application's own route list, collected whole to be parsed.
        const stdout = [];
        let stderr = '';
        let spawn_error = null;

        child.stdout.on('data', (chunk) => stdout.push(chunk));
        child.stderr.on('data', (chunk) => {
            if (stderr.length < STDERR_EXCERPT_BYTES) stderr += chunk.toString('utf8');
        });
        child.on('error', (error) => { spawn_error = error; });

        child.on('close', (code, signal) => {
            this.child = null;

            if (spawn_error) {
                this._failed('could not run ' + this.command[0] + ': ' + spawn_error.message);
                return;
            }

            const text = Buffer.concat(stdout).toString('utf8').trim();
            let answer = null;
            let parse_error = null;
            try {
                answer = JSON.parse(text);
            } catch (error) {
                parse_error = error;
            }

            if (answer && typeof answer === 'object' && answer.maintenance === true) {
                this.last_outcome = 'maintenance (previous list kept)';
                this.event_log.log('refresh_maintenance', 'the application is in maintenance mode; the previous list is kept');
                return;
            }

            if (code !== 0) {
                const how = signal ? 'was killed by ' + signal : 'exited ' + code;
                this._failed('the command ' + how + (stderr.trim() ? ': ' + excerpt(stderr) : ''));
                return;
            }

            if (parse_error) {
                this._failed('the answer is not JSON: ' + excerpt(text || '(empty)'));
                return;
            }

            let compiled;
            try {
                compiled = compile_whitelist(answer);
            } catch (error) {
                this._failed('the answer is malformed: ' + error.message);
                return;
            }

            this.current = compiled;
            this.counts = {
                unthrottled: compiled.unthrottled.count,
                priority: compiled.priority.count,
                media: compiled.media.count,
            };
            this.accepted_at = new Date().toISOString();
            this.last_outcome = 'ok';
            this.event_log.log('refresh_ok', this.counts.unthrottled + ' unthrottled, '
                + this.counts.priority + ' priority, ' + this.counts.media + ' media patterns');
        });
    }

    snapshot() {
        return {
            counts: Object.assign({}, this.counts),
            accepted_at: this.accepted_at,
            last_run_at: this.last_run_at,
            last_outcome: this.last_outcome,
            running_since: this.child ? this.child_started_at : null,
        };
    }

    _failed(reason) {
        this.last_outcome = 'failed: ' + reason + ' (previous list kept)';
        this.event_log.log('refresh_failed', reason + '; the previous list is kept');
    }
}

function excerpt(text) {
    const single = String(text).replace(/\s+/g, ' ').trim();
    return single.length > 200 ? single.substring(0, 200) + '...' : single;
}

module.exports = { Whitelist };
