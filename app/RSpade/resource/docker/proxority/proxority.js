#!/usr/bin/env node
/**
 * Proxority - a fair-queueing proxy in front of php-fpm.
 *
 *     node proxority.js [--flags]          serve
 *     node proxority.js status             the ledgers, the queues, the whitelist
 *     node proxority.js log [--follow]     the event ring; --follow streams new events
 *     node proxority.js close --reason=...  stop admitting EXTERNAL clients; returns once the
 *                                          external requests already admitted have finished
 *     node proxority.js open               admit everyone again
 *     node proxority.js hold --reason=...  stop dispatching: every new request waits (parked, never
 *                                          refused); returns once nothing is in flight at php-fpm
 *     node proxority.js release            dispatch the parked requests and resume
 *
 * The image installs this directory at /opt/rspade/proxority and the operator's wrapper
 * /usr/local/bin/proxority (`proxority status`, `proxority log --follow`).
 *
 * It sits between the public nginx and a second, loopback-only nginx that talks to php-fpm,
 * and decides which pending request runs next on php-fpm's ONE pool - so one client, a
 * crawler or a run of large downloads cannot occupy every worker. Pages, Ajax and companion
 * calls all share that pool; fairness between clients is this proxy's job. The policy itself is
 * lib/scheduler.js (pure); this file is the network around it. Zero npm dependencies: node
 * core only.
 *
 * Serve flags (every one has the production default; the test suite points them at scratch):
 *
 *     --listen=127.0.0.1:3300            where the public nginx sends PHP-bound requests
 *     --upstream-http=127.0.0.1:3301     the loopback nginx for X-Rsx-Scheme: http (or none)
 *     --upstream-https=127.0.0.1:3302    the loopback nginx for X-Rsx-Scheme: https
 *     --socket=/run/proxority/proxority.sock   the status socket (also read by status/log)
 *     --pool-dir=/etc/php/8.5/fpm/pool.d       www.conf, the one php-fpm pool
 *     --refresh-command=...              the whitelist command; a JSON array of argv, or
 *                                        words split on whitespace. Spawned with no shell.
 *                                        Default: php /var/www/html/system/artisan rsx:proxority:routes --json
 *     --refresh-interval=60              seconds between whitelist refreshes
 *
 * Test-only flags (never set in production): --_connect-timeout-ms, --_response-timeout-ms.
 *
 * Signals: SIGHUP re-reads the pool size. SIGTERM / SIGINT stop accepting, let what is
 * already in flight finish, then exit; a second one exits at once.
 */

const fs = require('fs');
const http = require('http');
const net = require('net');
const path = require('path');

const { Pool_Ledger } = require('./lib/scheduler.js');
const { classify } = require('./lib/classify.js');
const { Event_Log, format_event } = require('./lib/event_log.js');
const { read_capacity } = require('./lib/pool_capacity.js');
const { Whitelist } = require('./lib/whitelist.js');
const { is_loopback: is_loopback_address } = require('./lib/classify.js');

/**
 * The kernel start time of a running process (field 22 of /proc/<pid>/stat, as a string), or
 * null when there is none - an exited process nobody has reaped yet (a zombie) included. A pid
 * alone names no process once it is reused; pid + start time does. The command name (field 2)
 * may hold spaces and parentheses, so the fields proper start after its LAST ')'.
 */
function process_start_time(pid) {
    let text;
    try {
        text = fs.readFileSync('/proc/' + pid + '/stat', 'utf8');
    } catch (error) {
        return null;
    }

    const fields = text.slice(text.lastIndexOf(')') + 2).split(' ');
    if (fields.length <= 19 || fields[0] === 'Z' || fields[0] === 'X') return null;

    return fields[19];
}

/** Whether pid is still the process that had start_time; a null start time matches nothing. */
function process_is_alive(pid, start_time) {
    return start_time !== null && process_start_time(pid) === start_time;
}

/**
 * THE TWO SANCTIONED TIMEOUTS - the owner's specified values (2026-11-03).
 *
 * Both bound the wait on the UPSTREAM NGINX, an external party that may never answer: the
 * connect to its loopback port, and then the response head once connected. Neither covers
 * time spent in the queue (a queued request waits as long as fairness requires), and neither
 * covers streaming the body (a download takes as long as the client reads). On expiry the
 * client gets a 504, the worker slot is released for the next request, and a `timeout`
 * event is logged.
 */
const CONNECT_TIMEOUT_MS = 60 * 1000;
const RESPONSE_HEAD_TIMEOUT_MS = 360 * 1000;

/**
 * At most this much of one response sits in the proxy before the upstream is paused
 * (backpressure): php-fpm can hand over a 10 MB page and move on while a slow client reads,
 * and a 2 GB download never lands in memory. Hard-coded for now; it may become configurable.
 */
const RESPONSE_BUFFER_BYTES = 10 * 1024 * 1024;

/** A status-socket request line larger than this closes the connection: no frame is large. */
const MAX_SOCKET_FRAME_BYTES = 64 * 1024;

/**
 * What a client is told while the proxy is CLOSED to external traffic: try again shortly.
 * A hint to the client, not a deadline on anything.
 */
const CLOSED_RETRY_AFTER_SECONDS = 30;

/**
 * While HELD, how often the holder process is checked for life (the dead-holder release).
 * A polling cadence, not a deadline: a live holder keeps the hold as long as it likes.
 */
const HOLDER_CHECK_INTERVAL_MS = 1000;

/** Proxority's own routing header: which public listener received the request. Never forwarded. */
const SCHEME_HEADER = 'x-rsx-scheme';

/** Response headers that describe the upstream connection, not the response. Node frames its own. */
const HOP_BY_HOP_RESPONSE_HEADERS = new Set(['connection', 'keep-alive', 'transfer-encoding']);

/**
 * Request headers that describe the client's hop, not the request (RFC 9110 7.6.1), plus every
 * header the Connection header names. Never forwarded: nginx sends `Connection: close` on every
 * proxied request, and forwarded it would make node close each upstream connection instead of
 * returning it to the keep-alive agent. Content-Length and Transfer-Encoding are NOT dropped -
 * node frames the upstream body by them (a chunked body is re-chunked as it is piped).
 */
const HOP_BY_HOP_REQUEST_HEADERS = new Set(['connection', 'keep-alive', 'proxy-connection', 'te', 'trailer', 'upgrade']);

/**
 * Never dropped because a Connection header named them: the body framing and the Host. A
 * request whose framing headers vanished would reach the upstream with its body unframed.
 */
const FRAMING_REQUEST_HEADERS = new Set(['content-length', 'transfer-encoding', 'host']);

/** How much of a path an event line shows. */
const EVENT_PATH_CHARS = 200;

/**
 * The RSpade project the proxy serves, at the container's fixed docroot. The proxy itself is
 * part of the IMAGE (/opt/rspade/proxority), not of the checkout, so this is a fixed path rather
 * than one derived from __dirname. The whitelist refresh is the only thing that reads it, and a
 * container with no checkout there simply fails each refresh and keeps the empty list.
 */
const PROJECT_ROOT = '/var/www/html';

const DEFAULTS = {
    'listen': '127.0.0.1:3300',
    'upstream-http': '127.0.0.1:3301',
    'upstream-https': '127.0.0.1:3302',
    'socket': '/run/proxority/proxority.sock',
    'pool-dir': '/etc/php/8.5/fpm/pool.d',
    'refresh-command': null,
    'refresh-interval': '60',
    '_connect-timeout-ms': String(CONNECT_TIMEOUT_MS),
    '_response-timeout-ms': String(RESPONSE_HEAD_TIMEOUT_MS),
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

function parse_address(value, flag) {
    const colon = String(value).lastIndexOf(':');
    const port = Number(String(value).substring(colon + 1));
    if (colon <= 0 || !Number.isInteger(port) || port < 0 || port > 65535) {
        throw new Error('--' + flag + ' must be host:port, got "' + value + '"');
    }
    return { host: String(value).substring(0, colon), port };
}

function parse_positive_number(value, flag) {
    const number = Number(value);
    if (!Number.isFinite(number) || number <= 0) {
        throw new Error('--' + flag + ' must be a positive number, got "' + value + '"');
    }
    return number;
}

function parse_command(value) {
    if (value === null) {
        return ['php', path.join(PROJECT_ROOT, 'system', 'artisan'), 'rsx:proxority:routes', '--json'];
    }

    const text = String(value).trim();
    const command = text.startsWith('[') ? JSON.parse(text) : text.split(/\s+/);
    if (!Array.isArray(command) || command.length === 0 || !command.every((word) => typeof word === 'string' && word !== '')) {
        throw new Error('--refresh-command must name a command, got "' + value + '"');
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
        listen: parse_address(value('listen'), 'listen'),
        upstreams: {
            http: parse_address(value('upstream-http'), 'upstream-http'),
            https: parse_address(value('upstream-https'), 'upstream-https'),
        },
        socket: String(value('socket')),
        pool_dir: String(value('pool-dir')),
        refresh_command: parse_command(value('refresh-command')),
        refresh_cwd: PROJECT_ROOT,
        refresh_interval_ms: parse_positive_number(value('refresh-interval'), 'refresh-interval') * 1000,
        connect_timeout_ms: parse_positive_number(value('_connect-timeout-ms'), '_connect-timeout-ms'),
        response_timeout_ms: parse_positive_number(value('_response-timeout-ms'), '_response-timeout-ms'),
    };
}

// ---------------------------------------------------------------------------------------
// The proxy
// ---------------------------------------------------------------------------------------

/**
 * The raw request headers to send upstream. Every header goes through as received, in order
 * and spelling - X-Forwarded-For and X-Real-IP included, since the loopback nginx resolves the
 * client from them - except our own routing header and the hop-by-hop headers.
 */
function forwarded_request_headers(raw) {
    const dropped = new Set(HOP_BY_HOP_REQUEST_HEADERS);
    dropped.add(SCHEME_HEADER);
    for (let i = 0; i < raw.length; i += 2) {
        if (raw[i].toLowerCase() !== 'connection') continue;
        for (const token of raw[i + 1].split(',')) {
            const name = token.trim().toLowerCase();
            if (name !== '' && !FRAMING_REQUEST_HEADERS.has(name)) dropped.add(name);
        }
    }

    const headers = [];
    for (let i = 0; i < raw.length; i += 2) {
        if (dropped.has(raw[i].toLowerCase())) continue;
        headers.push(raw[i], raw[i + 1]);
    }
    return headers;
}

class Proxority {
    constructor(options) {
        this.options = options;
        this.started_at = Date.now();
        this.next_id = 0;
        this.active = new Set();
        this.shutting_down = false;

        // CLOSED TO EXTERNAL TRAFFIC (the `close` / `open` verbs). Null while open; otherwise
        // {reason, since, holder_pid, holder_start_time}. In memory only, so a restart always
        // comes up OPEN.
        this.closed = null;

        // `close` callers waiting for the admitted external requests to finish.
        this.drain_waiters = [];

        // HELD (the `hold` / `release` verbs): nothing new is dispatched. Null while not held;
        // otherwise {reason, since, holder_pid, holder_start_time}. In memory only, so a
        // restart always comes up dispatching. Requests arriving while held wait in `parked`
        // in arrival order, outside the ledger; `release` submits them.
        this.held = null;
        this.parked = [];

        // `hold` callers waiting for nothing to be in flight at php-fpm.
        this.hold_waiters = [];

        // While held with a holder pid: the periodic dead-holder check.
        this.holder_check = null;

        this.event_log = new Event_Log({
            echo: (line) => process.stdout.write('[proxority] ' + line + '\n'),
        });

        this.whitelist = new Whitelist({
            command: options.refresh_command,
            cwd: options.refresh_cwd,
            interval_ms: options.refresh_interval_ms,
            event_log: this.event_log,
        });

        this.agents = {
            http: new http.Agent({ keepAlive: true }),
            https: new http.Agent({ keepAlive: true }),
        };

        this.ledger = null;
        this.server = null;
        this.status_server = null;
    }

    async start() {
        // Fails loud: a pool file that cannot be read means a proxy that cannot schedule.
        const capacity = read_capacity(this.options.pool_dir);
        this.ledger = new Pool_Ledger('web', capacity);

        await this._listen_status_socket();

        // requestTimeout and headersTimeout are node's own request deadlines (300 s and 60 s by
        // default). Both are off: a queued request has not had its body read, and holding it
        // as long as fairness requires is the whole point. Only the two constants above bound
        // anything. The idle keep-alive close (node's default) ends no request and stays.
        this.server = http.createServer({
            highWaterMark: RESPONSE_BUFFER_BYTES,
            requestTimeout: 0,
            headersTimeout: 0,
        }, (req, res) => this._handle(req, res));

        await new Promise((resolve, reject) => {
            this.server.once('error', reject);
            this.server.listen(this.options.listen.port, this.options.listen.host, resolve);
        });

        // Port 0 binds a free port; status and the log show the one actually bound.
        this.options.listen.port = this.server.address().port;

        this.event_log.log('start', 'listening on ' + this._address(this.options.listen)
            + '; pool ' + capacity
            + '; upstreams http ' + this._address(this.options.upstreams.http)
            + ', https ' + this._address(this.options.upstreams.https));

        this.whitelist.start();

        process.on('SIGHUP', () => this.reload_capacity());
        process.on('SIGTERM', () => this.shutdown('SIGTERM'));
        process.on('SIGINT', () => this.shutdown('SIGINT'));
    }

    reload_capacity() {
        let capacity;
        try {
            capacity = read_capacity(this.options.pool_dir);
        } catch (error) {
            this.event_log.log('capacity', 'SIGHUP: ' + error.message + '; the current size is kept');
            return;
        }

        const before = this.ledger.capacity;
        const started = this.ledger.set_capacity(capacity);
        this.event_log.log('capacity', 'SIGHUP: pool ' + before + ' -> ' + capacity);
        for (const job of started) this._dispatch(job);
    }

    shutdown(signal) {
        if (this.shutting_down) {
            this.event_log.log('stop', signal + ' again: exiting with ' + this.active.size + ' request(s) in flight');
            process.exit(0);
        }
        this.shutting_down = true;

        // A held request is inside too: it is dispatched and finishes like the rest.
        if (this.held) this.release('shutting down; ' + this.parked.length + ' parked request(s) dispatched');

        this.event_log.log('stop', signal + ': no longer accepting; ' + this.active.size + ' request(s) in flight will finish');
        this.server.close();
        this.whitelist.stop();
        this._close_status_socket();
        this._exit_when_idle();
    }

    _exit_when_idle() {
        if (this.shutting_down && this.active.size === 0) {
            process.exit(0);
        }
    }

    // -----------------------------------------------------------------------------------
    // Closed to external traffic
    // -----------------------------------------------------------------------------------

    /**
     * Stop admitting EXTERNAL clients - every address that is not loopback - and resolve once
     * the external requests already admitted (queued or running) have finished. Loopback
     * callers (a test harness's browser, rsx:debug, companions) are untouched.
     *
     * holder_pid names the process that asked; its kernel start time is read here and kept with
     * it. While closed, every refused request first checks that the pid is still THAT process
     * (the same start time: a pid the kernel has since reused is not the holder), and reopens
     * the proxy when it is gone - so a closer that died without calling `open` cannot leave a
     * site refusing its visitors. A holder already gone when it closes is gone at once.
     */
    close(reason, holder_pid) {
        const text = String(reason || '').trim() || 'closed for maintenance';
        const pid = Number.isInteger(holder_pid) && holder_pid > 0 ? holder_pid : null;

        this.closed = { reason: text, since: new Date().toISOString(), holder_pid: pid, holder_start_time: pid ? process_start_time(pid) : null };
        this.event_log.log('closed', text + (pid ? ' (held by pid ' + pid + ')' : '') + '; '
            + this._external_in_flight() + ' external request(s) to finish');

        return new Promise((resolve) => {
            this.drain_waiters.push(resolve);
            this._settle_drain();
        });
    }

    open(why) {
        const was = this.closed;
        this.closed = null;
        if (was) this.event_log.log('opened', why || ('was closed: ' + was.reason));
        this._settle_drain();
        return was;
    }

    _refuses_while_closed(info) {
        if (!this.closed || info.loopback || is_loopback_address(info.ip)) return false;

        if (this.closed.holder_pid && !process_is_alive(this.closed.holder_pid, this.closed.holder_start_time)) {
            this.open('the holder, pid ' + this.closed.holder_pid + ', is gone; reopened');
            return false;
        }

        return true;
    }

    _external_in_flight() {
        let count = 0;
        for (const job of this.active) {
            if (!job.loopback && !is_loopback_address(job.ip)) count++;
        }
        return count;
    }

    /** Resolve the `close` callers once nothing external is left in flight (or it reopened). */
    _settle_drain() {
        if (this.drain_waiters.length === 0) return;
        if (this.closed && this._external_in_flight() > 0) return;

        const waiters = this.drain_waiters;
        this.drain_waiters = [];
        if (this.closed) this.event_log.log('drained', 'no external request in flight');
        for (const resolve of waiters) resolve(this.closed !== null);
    }

    // -----------------------------------------------------------------------------------
    // Held: nothing dispatched
    // -----------------------------------------------------------------------------------

    /**
     * Stop dispatching. Every request that arrives from now on - loopback included - is
     * PARKED: kept open, never refused, never counted, and submitted to the ledger in arrival
     * order at `release`. Resolve once nothing is in flight at php-fpm: every request already
     * admitted (queued ones included, which still dispatch as workers free) has had its
     * upstream response end. A wait with no deadline. A client still reading a page whose
     * upstream response has ended no longer involves php-fpm and does not delay it.
     *
     * The holder is a pid plus its kernel start time, read here (see close()). While held,
     * the holder is checked every HOLDER_CHECK_INTERVAL_MS; a holder that is gone - or a pid
     * that now names another process - releases the hold, so a holder that died between
     * `hold` and `release` never leaves every request waiting. A holder already gone when it
     * holds is released at the first check. A closure (`close`) is separate and still
     * refuses external clients first: a request it refuses is never parked.
     */
    hold(reason, holder_pid) {
        const text = String(reason || '').trim() || 'held';
        const pid = Number.isInteger(holder_pid) && holder_pid > 0 ? holder_pid : null;

        this.held = { reason: text, since: new Date().toISOString(), holder_pid: pid, holder_start_time: pid ? process_start_time(pid) : null };
        this.event_log.log('held', text + (pid ? ' (held by pid ' + pid + ')' : '') + '; '
            + this._php_in_flight() + ' request(s) in flight to finish');

        if (this.holder_check) clearInterval(this.holder_check);
        this.holder_check = null;
        if (pid) {
            this.holder_check = setInterval(() => this._check_holder(), HOLDER_CHECK_INTERVAL_MS);
            this.holder_check.unref();
        }

        return new Promise((resolve) => {
            this.hold_waiters.push(resolve);
            this._settle_hold();
        });
    }

    /** Resume dispatching: the parked requests are submitted in arrival order. */
    release(why) {
        const was = this.held;
        this.held = null;
        if (this.holder_check) clearInterval(this.holder_check);
        this.holder_check = null;

        const parked = this.parked;
        this.parked = [];

        if (was) this.event_log.log('resumed', why || ('was held: ' + was.reason + '; ' + parked.length + ' parked request(s) dispatched'));
        this._settle_hold();

        for (const job of parked) {
            job.parked = false;
            this._admit(job);
        }
        return was;
    }

    _check_holder() {
        if (!this.held || !this.held.holder_pid) return;
        if (process_is_alive(this.held.holder_pid, this.held.holder_start_time)) return;

        this.release('the holder, pid ' + this.held.holder_pid + ', is gone; released with '
            + this.parked.length + ' parked request(s)');
    }

    /** Requests admitted to the ledger whose upstream response has not ended (queued ones included). */
    _php_in_flight() {
        let count = 0;
        for (const job of this.active) {
            if (!job.upstream_done) count++;
        }
        return count;
    }

    _upstream_finished(job) {
        if (job.upstream_done) return;
        job.upstream_done = true;
        this._settle_hold();
    }

    /** Resolve the `hold` callers once nothing is in flight at php-fpm (or it was released). */
    _settle_hold() {
        if (this.hold_waiters.length === 0) return;
        if (this.held && this._php_in_flight() > 0) return;

        const waiters = this.hold_waiters;
        this.hold_waiters = [];
        if (this.held) this.event_log.log('idle', 'nothing in flight; ' + this.parked.length + ' request(s) parked');
        for (const resolve of waiters) resolve(this.held !== null);
    }

    // -----------------------------------------------------------------------------------
    // Requests
    // -----------------------------------------------------------------------------------

    _handle(req, res) {
        const info = classify({
            method: req.method,
            url: req.url,
            headers: req.headers,
            remote_address: req.socket.remoteAddress,
        }, this.whitelist.current);

        if (this._refuses_while_closed(info)) {
            this.event_log.log('refused', info.ip + ' ' + req.method + ' ' + info.path.substring(0, EVENT_PATH_CHARS)
                + ' (closed: ' + this.closed.reason + ')');
            res.setHeader('Retry-After', String(CLOSED_RETRY_AFTER_SECONDS));
            this._plain(res, 503, 'Service Unavailable: ' + this.closed.reason + '. Please try again shortly.\n');
            return;
        }

        const job = Object.assign(info, {
            id: ++this.next_id,
            req,
            res,
            ledger: this.ledger,
            arrived_at: Date.now(),
            upstream_req: null,
            timer: null,
            released: false,
            timed_out: false,
            upstream_done: false,
            parked: false,
        });

        res.on('close', () => this._client_closed(job));

        if (this.held) {
            job.parked = true;
            this.parked.push(job);
            this.event_log.log('parked', this._describe(job) + ' (' + this.parked.length + ' parked; held: ' + this.held.reason + ')');
            return;
        }

        this._admit(job);
    }

    /** Into the ledger: dispatched now if a worker is free, else queued. */
    _admit(job) {
        this.active.add(job);

        const started = job.ledger.submit(job);
        if (job._state === 'queued') {
            this.event_log.log('queued', this._describe(job) + ' (' + job.ledger.queued_total + ' queued)');
        }
        for (const ready of started) this._dispatch(ready);
    }

    _dispatch(job) {
        const { req, res } = job;
        const scheme = req.headers[SCHEME_HEADER] === 'https' ? 'https' : 'http';
        const upstream = this.options.upstreams[scheme];

        this.event_log.log('dispatched', this._describe(job) + ' to ' + scheme + ' after ' + (Date.now() - job.arrived_at) + ' ms');

        const headers = forwarded_request_headers(req.rawHeaders);

        const upstream_req = http.request({
            host: upstream.host,
            port: upstream.port,
            method: req.method,
            path: req.url,
            headers,
            agent: this.agents[scheme],
        });
        job.upstream_req = upstream_req;

        upstream_req.on('socket', (socket) => {
            if (socket.connecting) {
                this._arm(job, this.options.connect_timeout_ms, 'connecting to the upstream');
                socket.once('connect', () => {
                    this._arm(job, this.options.response_timeout_ms, 'waiting for the response head');
                });
            } else {
                this._arm(job, this.options.response_timeout_ms, 'waiting for the response head');
            }
        });

        upstream_req.on('response', (upstream_res) => this._respond(job, upstream_res));

        upstream_req.on('error', (error) => {
            this._disarm(job);
            this._upstream_finished(job);
            if (job.timed_out || job.client_gone) return;

            this.event_log.log('upstream_error', this._describe(job) + ': ' + error.message);
            if (!res.headersSent) {
                this._plain(res, 502, 'Bad Gateway: the application server did not answer.\n');
            } else {
                res.destroy();
            }
            this._release(job, 'upstream error');
        });

        // nginx has buffered the body already; it streams through with backpressure.
        req.pipe(upstream_req);
    }

    _respond(job, upstream_res) {
        const { res } = job;
        this._disarm(job);

        // The head is the first byte: a page's worker is free from here on. A download's is
        // held until the upstream has sent its last byte.
        if (!job.media) this._release(job, 'first byte');

        const headers = [];
        for (let i = 0; i < upstream_res.rawHeaders.length; i += 2) {
            if (HOP_BY_HOP_RESPONSE_HEADERS.has(upstream_res.rawHeaders[i].toLowerCase())) continue;
            headers.push(upstream_res.rawHeaders[i], upstream_res.rawHeaders[i + 1]);
        }

        res.writeHead(upstream_res.statusCode, upstream_res.statusMessage, headers);

        // writeHead() alone holds the head until the first body byte; the client gets it now,
        // exactly when the upstream sent it.
        res.flushHeaders();

        upstream_res.on('end', () => {
            this._upstream_finished(job);
            this._release(job, 'response end');
        });
        upstream_res.on('aborted', () => {
            this._upstream_finished(job);
            if (job.client_gone) return;
            this.event_log.log('upstream_error', this._describe(job) + ': the upstream aborted the response');
            res.destroy();
            this._release(job, 'upstream aborted');
        });
        upstream_res.on('error', () => {
            this._upstream_finished(job);
            if (job.client_gone) return;
            res.destroy();
            this._release(job, 'upstream error');
        });

        // pipe() pauses the upstream whenever res holds RESPONSE_BUFFER_BYTES (the server's
        // highWaterMark) and resumes on drain: the proxy never holds a whole body.
        upstream_res.pipe(res);
    }

    _client_closed(job) {
        if (job.parked) {
            job.parked = false;
            this.parked.splice(this.parked.indexOf(job), 1);
            this.event_log.log('client_abort', this._describe(job) + ' left while parked after ' + (Date.now() - job.arrived_at) + ' ms');
            this._exit_when_idle();
            return;
        }

        this.active.delete(job);
        this._settle_drain();
        this._settle_hold();

        if (job._state === 'queued') {
            job.ledger.cancel(job);
            this.event_log.log('client_abort', this._describe(job) + ' left while queued after ' + (Date.now() - job.arrived_at) + ' ms');
        } else if (!job.res.writableFinished) {
            job.client_gone = true;
            this._disarm(job);
            this.event_log.log('client_abort', this._describe(job) + ' left while running');
            if (job.upstream_req) job.upstream_req.destroy();
            this._release(job, 'client left');
        } else {
            this._release(job, 'complete');
        }

        this._exit_when_idle();
    }

    _release(job, reason) {
        if (job.released || job._state !== 'running') return;
        job.released = true;

        const started = job.ledger.release(job);
        this.event_log.log('released', this._describe(job) + ' (' + reason + ')');
        for (const ready of started) this._dispatch(ready);
    }

    /** One of the two sanctioned timeouts; see CONNECT_TIMEOUT_MS. */
    _arm(job, ms, waiting_for) {
        this._disarm(job);
        job.timer = setTimeout(() => {
            job.timer = null;
            job.timed_out = true;
            this._upstream_finished(job);
            this.event_log.log('timeout', this._describe(job) + ': ' + ms + ' ms ' + waiting_for + '; answered 504');
            job.upstream_req.destroy();
            if (!job.res.headersSent) {
                this._plain(job.res, 504, 'Gateway Timeout: the application server did not answer in time.\n');
            } else {
                job.res.destroy();
            }
            this._release(job, 'timeout');
        }, ms);
    }

    _disarm(job) {
        if (job.timer) clearTimeout(job.timer);
        job.timer = null;
    }

    _plain(res, status, body) {
        res.writeHead(status, {
            'Content-Type': 'text/plain; charset=utf-8',
            'Content-Length': Buffer.byteLength(body),
        });
        res.end(body);
    }

    _describe(job) {
        const kind = [];
        if (job.loopback) kind.push('loopback');
        if (job.unthrottled) kind.push('unthrottled');
        if (job.priority) kind.push('priority');
        if (job.media) kind.push('media');
        if (job.robot) kind.push('robot');

        const shown = job.path.length > EVENT_PATH_CHARS ? job.path.substring(0, EVENT_PATH_CHARS) + '...' : job.path;
        return '#' + job.id + ' ' + job.ip + ' ' + job.req.method + ' ' + shown
            + (kind.length ? ' [' + kind.join(',') + ']' : '');
    }

    _address(address) {
        return address.host + ':' + address.port;
    }

    // -----------------------------------------------------------------------------------
    // The status socket: newline-delimited JSON, one request line per verb.
    // -----------------------------------------------------------------------------------

    status() {
        return {
            pid: process.pid,
            started_at: new Date(this.started_at).toISOString(),
            uptime_seconds: Math.floor((Date.now() - this.started_at) / 1000),
            listen: this._address(this.options.listen),
            upstreams: {
                http: this._address(this.options.upstreams.http),
                https: this._address(this.options.upstreams.https),
            },
            pool: this.ledger.snapshot(),
            whitelist: this.whitelist.snapshot(),
            closed: this.closed,
            held: this.held,
            parked: this.parked.length,
        };
    }

    async _listen_status_socket() {
        const file = this.options.socket;
        fs.mkdirSync(path.dirname(file), { recursive: true });

        if (fs.existsSync(file)) {
            // A live socket is another proxority: refuse rather than steal its path.
            const live = await new Promise((resolve) => {
                const probe = net.connect(file);
                probe.once('connect', () => { probe.destroy(); resolve(true); });
                probe.once('error', () => resolve(false));
            });
            if (live) throw new Error('Proxority is already running on ' + file);
            fs.unlinkSync(file);
        }

        this.status_server = net.createServer((connection) => this._status_connection(connection));
        await new Promise((resolve, reject) => {
            this.status_server.once('error', reject);
            this.status_server.listen(file, resolve);
        });
    }

    _close_status_socket() {
        if (!this.status_server) return;
        this.status_server.close();
        this.status_server = null;
        try { fs.unlinkSync(this.options.socket); } catch (error) { /* already gone */ }
    }

    _status_connection(connection) {
        let buffer = '';
        let unfollow = null;

        const send = (object) => connection.write(JSON.stringify(object) + '\n');

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
                if (line === '') continue;

                let frame;
                try {
                    frame = JSON.parse(line);
                } catch (error) {
                    send({ ok: false, error: 'not JSON' });
                    continue;
                }

                if (frame.verb === 'status') {
                    send({ ok: true, status: this.status() });
                } else if (frame.verb === 'log') {
                    send({ ok: true, events: this.event_log.entries() });
                } else if (frame.verb === 'close') {
                    const pid = Number(frame.holder_pid);
                    this.close(frame.reason, Number.isInteger(pid) ? pid : null)
                        .then((still_closed) => send(still_closed
                            ? { ok: true, closed: this.closed }
                            : { ok: false, error: 'reopened before the external requests finished' }));
                } else if (frame.verb === 'open') {
                    send({ ok: true, was_closed: this.open() !== null });
                } else if (frame.verb === 'hold') {
                    const pid = Number(frame.holder_pid);
                    this.hold(frame.reason, Number.isInteger(pid) ? pid : null)
                        .then((still_held) => send(still_held
                            ? { ok: true, held: this.held }
                            : { ok: false, error: 'released before the requests in flight finished' }));
                } else if (frame.verb === 'release') {
                    const parked = this.parked.length;
                    send({ ok: true, was_held: this.release() !== null, dispatched: parked });
                } else if (frame.verb === 'follow') {
                    send({ ok: true, events: this.event_log.entries() });
                    if (!unfollow) unfollow = this.event_log.follow((event) => send({ event }));
                } else {
                    send({ ok: false, error: 'unknown verb ' + JSON.stringify(frame.verb) });
                }
            }
        });

        connection.on('close', () => { if (unfollow) unfollow(); });
        connection.on('error', () => {});
    }
}

// ---------------------------------------------------------------------------------------
// The CLI client
// ---------------------------------------------------------------------------------------

function client_request(socket_file, request, on_frame) {
    return new Promise((resolve, reject) => {
        const connection = net.connect(socket_file);
        let buffer = '';

        connection.once('error', (error) => {
            reject(new Error('Proxority is not running (cannot connect to ' + socket_file + ': ' + error.message + ')'));
        });
        const frame = typeof request === 'string' ? { verb: request } : request;
        connection.on('connect', () => connection.write(JSON.stringify(frame) + '\n'));
        connection.on('data', (chunk) => {
            buffer += chunk.toString('utf8');
            let newline;
            while ((newline = buffer.indexOf('\n')) !== -1) {
                const line = buffer.substring(0, newline);
                buffer = buffer.substring(newline + 1);
                try {
                    if (on_frame(JSON.parse(line)) === 'done') {
                        connection.end();
                        resolve();
                    }
                } catch (error) {
                    connection.destroy();
                    reject(error);
                    return;
                }
            }
        });
        connection.on('close', () => resolve());
    });
}

function format_duration(seconds) {
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const parts = [];
    if (days) parts.push(days + 'd');
    if (days || hours) parts.push(hours + 'h');
    if (days || hours || minutes) parts.push(minutes + 'm');
    parts.push((seconds % 60) + 's');
    return parts.join(' ');
}

/** How many addresses the status display lists from the queue. */
const STATUS_TOP_ADDRESSES = 10;

function format_status(status) {
    const lines = [];
    lines.push('Proxority - pid ' + status.pid + ', up ' + format_duration(status.uptime_seconds) + ' (since ' + status.started_at + ')');
    lines.push('Listening on ' + status.listen + ' -> http ' + status.upstreams.http + ', https ' + status.upstreams.https);

    const pool = status.pool;
    const busy = pool.busy_by_class;
    lines.push('');
    lines.push('Pool ' + pool.name + ': capacity ' + pool.capacity
        + ' (caps: per IP ' + pool.caps.per_ip + ', media ' + pool.caps.media + ', robot ' + pool.caps.robot + ')');
    lines.push('  busy ' + pool.busy_total + '/' + pool.capacity
        + ': normal ' + busy.normal + ', priority ' + busy.priority + ', media ' + busy.media
        + ', robot ' + busy.robot + ', unthrottled ' + busy.unthrottled);
    lines.push('  loopback in flight (not counted): ' + pool.loopback_running);
    lines.push('  queued ' + pool.queued_total + ' (priority ' + pool.queued_priority + ')');
    for (const entry of pool.queued_by_ip.slice(0, STATUS_TOP_ADDRESSES)) {
        lines.push('    ' + entry.ip + '  ' + entry.count);
    }
    if (pool.queued_by_ip.length > STATUS_TOP_ADDRESSES) {
        lines.push('    ... and ' + (pool.queued_by_ip.length - STATUS_TOP_ADDRESSES) + ' more address(es)');
    }

    lines.push('');
    lines.push(status.closed
        ? 'CLOSED to external clients since ' + status.closed.since + ': ' + status.closed.reason
            + (status.closed.holder_pid ? ' (held by pid ' + status.closed.holder_pid + ')' : '')
        : 'Open to every client');
    lines.push(status.held
        ? 'HELD since ' + status.held.since + ': ' + status.held.reason
            + (status.held.holder_pid ? ' (held by pid ' + status.held.holder_pid + ')' : '')
            + '; ' + status.parked + ' request(s) parked'
        : 'Dispatching (not held)');

    const whitelist = status.whitelist;
    lines.push('');
    lines.push('Whitelist: ' + whitelist.counts.unthrottled + ' unthrottled, ' + whitelist.counts.priority
        + ' priority, ' + whitelist.counts.media + ' media pattern(s)');
    lines.push('  last accepted: ' + (whitelist.accepted_at || 'never (everything is throttled)'));
    lines.push('  last refresh:  ' + (whitelist.last_run_at || 'never') + ' - ' + whitelist.last_outcome);
    if (whitelist.running_since) {
        lines.push('  a refresh has been running since ' + whitelist.running_since);
    }

    return lines.join('\n');
}

async function run_client(verb, flags) {
    const socket_file = String(flags.socket || DEFAULTS.socket);

    if (verb === 'status') {
        await client_request(socket_file, 'status', (frame) => {
            if (!frame.ok) throw new Error(frame.error);
            process.stdout.write(format_status(frame.status) + '\n');
            return 'done';
        });
        return;
    }

    if (verb === 'close') {
        const reason = typeof flags.reason === 'string' ? flags.reason : '';
        const holder = flags['holder-pid'] === undefined ? null : Number(flags['holder-pid']);
        process.stdout.write('Closing to external clients; waiting for the external requests in flight to finish...\n');
        await client_request(socket_file, { verb: 'close', reason, holder_pid: holder }, (frame) => {
            if (!frame.ok) throw new Error(frame.error);
            process.stdout.write('[OK] Closed to external clients: ' + frame.closed.reason + '\n');
            return 'done';
        });
        return;
    }

    if (verb === 'open') {
        await client_request(socket_file, 'open', (frame) => {
            if (!frame.ok) throw new Error(frame.error);
            process.stdout.write(frame.was_closed ? '[OK] Open to every client\n' : '[OK] Already open to every client\n');
            return 'done';
        });
        return;
    }

    if (verb === 'hold') {
        const reason = typeof flags.reason === 'string' ? flags.reason : '';
        const holder = flags['holder-pid'] === undefined ? null : Number(flags['holder-pid']);
        process.stdout.write('Holding; waiting for the requests in flight to finish...\n');
        await client_request(socket_file, { verb: 'hold', reason, holder_pid: holder }, (frame) => {
            if (!frame.ok) throw new Error(frame.error);
            process.stdout.write('[OK] Held, nothing in flight: ' + frame.held.reason + '\n');
            return 'done';
        });
        return;
    }

    if (verb === 'release') {
        await client_request(socket_file, 'release', (frame) => {
            if (!frame.ok) throw new Error(frame.error);
            process.stdout.write(frame.was_held
                ? '[OK] Released; ' + frame.dispatched + ' parked request(s) dispatched\n'
                : '[OK] Was not held\n');
            return 'done';
        });
        return;
    }

    const follow = flags.follow === true;
    await client_request(socket_file, follow ? 'follow' : 'log', (frame) => {
        if (frame.event) {
            process.stdout.write(format_event(frame.event) + '\n');
            return null;
        }
        if (!frame.ok) throw new Error(frame.error);
        for (const event of frame.events) {
            process.stdout.write(format_event(event) + '\n');
        }
        return follow ? null : 'done';
    });
}

// ---------------------------------------------------------------------------------------

async function main() {
    const { flags, words } = parse_argv(process.argv.slice(2));
    const verb = words[0] || 'serve';

    if (['status', 'log', 'close', 'open', 'hold', 'release'].includes(verb)) {
        await run_client(verb, flags);
        return;
    }
    if (verb !== 'serve') {
        throw new Error('Unknown command "' + verb + '". Usage: proxority.js [serve] | status | log [--follow] | close --reason=... [--holder-pid=N] | open | hold --reason=... [--holder-pid=N] | release');
    }

    const proxy = new Proxority(serve_options(flags));
    await proxy.start();
}

if (require.main === module) {
    main().catch((error) => {
        process.stderr.write('[proxority] [ERROR] ' + error.message + '\n');
        process.exit(1);
    });
}

module.exports = { Proxority, process_start_time, process_is_alive, format_status, forwarded_request_headers, parse_command, CONNECT_TIMEOUT_MS, RESPONSE_HEAD_TIMEOUT_MS, RESPONSE_BUFFER_BYTES };
