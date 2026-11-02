/**
 * rsx-companion-sdk - a Node companion's side of the framework. Zero dependencies.
 *
 *     const companion = require('rsx-companion-sdk');    // the daemon sets NODE_PATH
 *
 *     await companion.ready();
 *     companion.status('Draining page 3');
 *     companion.state({cursor_age_s: 41});               // replaced whole
 *     companion.progress(37, 100);
 *     companion.error('throttled', 'Retry-After 120s', {severity: 'warn'});
 *     companion.on('pause', fn); companion.on('resume', fn); companion.on('stop', fn);
 *     const answer = await companion.call('apply', {...}, {site_id: 3});
 *     const answer = await companion.client.apply({...});   // the generated client, after ready()
 *
 * YOUR CONTRACT ON STOP: finish the current unit of work, persist your checkpoint, exit 0. With
 * no 'stop' listener the SDK exits for you. Kill safety comes from idempotency, not from the
 * grace: advance a checkpoint only after the application has accepted the work, so a hard kill
 * repeats work and never loses it.
 *
 * A call retries through every outage the wire contract calls temporary, for as long as the
 * outage lasts: a rebuild, a maintenance window, a restart of the web tier. contract_mismatch
 * is the one failure that exits, and the daemon starts this process again on current code. An
 * end of file on the control pipe means the daemon has died, and this process exits with it.
 */

const crypto = require('crypto');
const EventEmitter = require('events');
const fs = require('fs');
const http = require('http');
const https = require('https');
const net = require('net');

const wire = require('../lib/wire.js');

const FD_CONTROL = 3;
const FD_REPORTS = 4;

/** Status, state and progress are batched to one write per this: only the last of each counts. */
const FLUSH_MS = 1000;

/** How often the daemon hears this process is alive. It writes Redis at most every ~30 s. */
const HEARTBEAT_MS = 10000;

/** Retry delay: the first, then doubled, up to the ceiling. A pacing, never a limit: retries do not end. */
const RETRY_INITIAL_MS = 250;
const RETRY_CEILING_MS = 30000;

/**
 * A POST to this name proves the signature, the instance and the contract key without running
 * anything: the verifier checks all three before asking whether the endpoint exists, so the
 * application answers not_found. Reserved: an application declares no endpoint by this name.
 */
const PROBE_ENDPOINT = 'rsx_sdk_contract_probe';

/** Codes the wire contract calls temporary: the same request, signed afresh, may succeed. */
const RETRY_CODES = ['stale_timestamp', 'replayed_nonce', 'instance_not_live', 'unavailable'];

class Companion_Call_Error extends Error {
    constructor(status, code, message) {
        super(code + ': ' + message);
        this.name = 'Companion_Call_Error';
        this.status = status;
        this.code = code;
    }
}

class Companion_Sdk extends EventEmitter {
    constructor() {
        super();
        this.env = null;
        this.endpoints = null;
        this.client = null;
        this.stopping = false;
        this.pending_reports = {};
        this.flush_timer = null;
    }

    _environment() {
        if (this.env !== null) return this.env;

        const env = {};
        for (const [key, variable] of Object.entries({
            name: 'RSX_COMPANION_NAME',
            instance: 'RSX_COMPANION_INSTANCE',
            started_at: 'RSX_COMPANION_STARTED_AT',
            secret: 'RSX_COMPANION_SECRET',
            base_url: 'RSX_COMPANION_BASE_URL',
            contract_key: 'RSX_COMPANION_CONTRACT_KEY',
            client: 'RSX_COMPANION_CLIENT',
        })) {
            if (!process.env[variable]) {
                throw new Error(variable + ' is not set: a companion runs under rsx-companiond, never by hand');
            }
            env[key] = process.env[variable];
        }

        // Nothing this process starts inherits the secret.
        delete process.env.RSX_COMPANION_SECRET;

        this.env = env;
        return env;
    }

    /** Listen on the control pipe. Unref'd, so it never by itself keeps the process alive. */
    _open_control() {
        const control = new net.Socket({ fd: FD_CONTROL, readable: true, writable: false });
        let buffer = '';

        control.on('data', (chunk) => {
            buffer += chunk.toString('utf8');
            let newline;
            while ((newline = buffer.indexOf('\n')) !== -1) {
                this._on_control(buffer.slice(0, newline));
                buffer = buffer.slice(newline + 1);
            }
        });

        // End of file: the daemon has died. There is no supervisor left to report to, and a new
        // daemon would otherwise start a second copy beside this one.
        control.on('end', () => process.exit(0));
        control.on('error', () => process.exit(0));
        control.unref();
    }

    _on_control(line) {
        let message;
        try {
            message = JSON.parse(line);
        } catch (err) {
            return;
        }

        if (message.type === 'pause' || message.type === 'resume') {
            this.emit(message.type);
        } else if (message.type === 'stop') {
            this._stop();
        }
    }

    _stop() {
        if (this.stopping) return;
        this.stopping = true;
        this._flush();

        if (this.listenerCount('stop') === 0) {
            process.exit(0);
        }
        this.emit('stop');
    }

    /**
     * Writes straight to the descriptor, because a report made in the last moments of a process
     * must not sit in a stream buffer when it exits.
     */
    _send(report) {
        const line = Buffer.from(JSON.stringify(report) + '\n');
        let offset = 0;

        while (offset < line.length) {
            try {
                offset += fs.writeSync(FD_REPORTS, line, offset);
            } catch (err) {
                if (err.code === 'EAGAIN') continue;
                if (err.code === 'EPIPE' || err.code === 'EBADF') return;
                throw err;
            }
        }
    }

    _flush() {
        clearTimeout(this.flush_timer);
        this.flush_timer = null;

        const reports = this.pending_reports;
        this.pending_reports = {};

        for (const report of Object.values(reports)) {
            this._send(report);
        }
    }

    _queue(report) {
        this.pending_reports[report.type] = report;
        if (this.flush_timer === null) {
            this.flush_timer = setTimeout(() => this._flush(), FLUSH_MS);
            this.flush_timer.unref();
        }
    }

    status(text) {
        this._queue({ type: 'status', text: String(text) });
    }

    /** The state document, replaced whole. Keep its shape fixed: a screen reads it. */
    state(value) {
        this._queue({ type: 'state', value });
    }

    progress(current, total) {
        this._queue({ type: 'progress', current, total });
    }

    /** Immediate, and fires companion.error in the application. */
    error(code, message, options = {}) {
        this._flush();
        this._send({
            type: 'error',
            code: String(code),
            message: String(message),
            severity: options.severity === undefined ? 'error' : options.severity,
            context: options.context === undefined ? {} : options.context,
        });
    }

    /**
     * Connects this process to the daemon and the application. Resolves when the application
     * has accepted this process's contract key, however long that takes.
     */
    async ready() {
        const env = this._environment();

        this._open_control();

        process.on('SIGTERM', () => this._stop());

        const generated = await this._load_client(env);
        this.endpoints = new Set(generated.endpoints);

        // Stale before any call: the build moved between the daemon's start and this line.
        if (generated.endpoints_hash !== env.contract_key) {
            this._exit_contract_mismatch();
        }

        this.client = generated.bind((endpoint, params, options) => this.call(endpoint, params, options));

        setInterval(() => this._send({ type: 'heartbeat' }), HEARTBEAT_MS).unref();

        await this._probe();

        this._send({ type: 'ready' });
        this._send({ type: 'heartbeat' });
    }

    /** rsx:clean removes the generated client until the next build: wait for it. */
    async _load_client(env) {
        for (let attempt = 1; ; attempt++) {
            if (fs.existsSync(env.client)) {
                return require(env.client);
            }
            await this._pause_before_retry(attempt, 'the generated client is not built yet');
        }
    }

    async _probe() {
        for (let attempt = 1; ; attempt++) {
            const answer = await this._attempt(PROBE_ENDPOINT, '', {});

            if (answer.retry) {
                await this._pause_before_retry(attempt, answer.reason, answer.retry_after_ms);
                continue;
            }

            if (answer.error && answer.error.code !== 'not_found') {
                throw answer.error;
            }

            return;
        }
    }

    _exit_contract_mismatch() {
        process.stderr.write('[rsx-companion-sdk] contract mismatch: this process is older than the application; exiting to be replaced\n');
        this._flush();
        process.exit(wire.EXIT_CONTRACT_MISMATCH);
    }

    /**
     * Calls one of this companion's endpoints. Resolves with the answer, null for a 204. Rejects
     * with a Companion_Call_Error for a failure the caller must deal with, and at once for a
     * name that is not an endpoint.
     *
     * @param {string} endpoint
     * @param {object|Array} [params]
     * @param {{site_id: (number|undefined)}} [options] A multi tenant install needs site_id
     */
    async call(endpoint, params, options = {}) {
        if (this.endpoints === null) {
            throw new Error('companion.call() before companion.ready()');
        }

        if (!this.endpoints.has(endpoint)) {
            throw new Error('No such endpoint: ' + endpoint + '. This companion has: ' + Array.from(this.endpoints).join(', '));
        }

        const body = params === undefined ? '' : JSON.stringify(params);

        for (let attempt = 1; ; attempt++) {
            const answer = await this._attempt(endpoint, body, options);

            if (answer.retry) {
                await this._pause_before_retry(attempt, answer.reason, answer.retry_after_ms);
                continue;
            }

            if (answer.error) throw answer.error;

            return answer.value;
        }
    }

    async _pause_before_retry(attempt, reason, retry_after_ms = 0) {
        const delay = Math.max(
            Math.min(RETRY_INITIAL_MS * Math.pow(2, Math.min(attempt - 1, 20)), RETRY_CEILING_MS),
            retry_after_ms
        );

        if (attempt === 1 || delay === RETRY_CEILING_MS) {
            process.stderr.write('[rsx-companion-sdk] retrying: ' + reason + '\n');
        }

        await new Promise((resolve) => setTimeout(resolve, delay));
    }

    /** One signed attempt. Answers {value}, {error}, or {retry, reason, retry_after_ms}. */
    async _attempt(endpoint, body, options) {
        const env = this._environment();
        const uri = '/_companion/' + env.name + '/' + endpoint;
        const site = options.site_id === undefined ? '-' : String(options.site_id);
        const timestamp = String(Math.floor(Date.now() / 1000));
        const nonce = wire.new_nonce();

        const headers = {
            'Content-Type': 'application/json',
            'Content-Length': Buffer.byteLength(body),
            'X-Rsx-Companion': env.name,
            'X-Rsx-Companion-Instance': env.instance,
            'X-Rsx-Companion-Started-At': env.started_at,
            'X-Rsx-Companion-Contract': env.contract_key,
            'X-Rsx-Companion-Timestamp': timestamp,
            'X-Rsx-Companion-Nonce': nonce,
            'X-Rsx-Companion-Signature': wire.hmac(env.secret, wire.canonical_string(
                'POST', uri, env.name, env.instance, env.started_at, env.contract_key, site, timestamp, nonce, body
            )),
        };

        if (site !== '-') headers['X-Rsx-Companion-Site'] = site;

        let response;
        try {
            response = await post(env.base_url + uri, headers, body);
        } catch (err) {
            return { retry: true, reason: 'the application is not answering: ' + err.message };
        }

        return this._classify(response);
    }

    _classify(response) {
        const retry_after_ms = Number(response.headers['retry-after'] || 0) * 1000;

        if (response.status === 204) return { value: null };

        let parsed;
        try {
            parsed = JSON.parse(response.body);
        } catch (err) {
            return { retry: true, reason: 'a non-JSON answer, HTTP ' + response.status, retry_after_ms };
        }

        if (response.status === 200) return { value: parsed };

        const code = parsed && parsed.error ? parsed.error.code : null;
        const message = parsed && parsed.error ? parsed.error.message : response.body;

        if (code === 'contract_mismatch') {
            this._exit_contract_mismatch();
        }

        if (response.status === 503 || RETRY_CODES.includes(code)) {
            return { retry: true, reason: code || 'HTTP 503', retry_after_ms };
        }

        return { error: new Companion_Call_Error(response.status, code || 'http_' + response.status, message) };
    }
}

function post(url, headers, body) {
    const transport = url.startsWith('https:') ? https : http;

    return new Promise((resolve, reject) => {
        const request = transport.request(url, { method: 'POST', headers }, (response) => {
            const chunks = [];
            response.on('data', (chunk) => chunks.push(chunk));
            response.on('error', reject);
            response.on('end', () => resolve({
                status: response.statusCode,
                headers: response.headers,
                body: Buffer.concat(chunks).toString('utf8'),
            }));
        });

        request.on('error', reject);
        request.end(body);
    });
}

const sdk = new Companion_Sdk();
sdk.Companion_Call_Error = Companion_Call_Error;
sdk.Companion_Sdk = Companion_Sdk;
sdk.PROBE_ENDPOINT = PROBE_ENDPOINT;

module.exports = sdk;
