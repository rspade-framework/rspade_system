/**
 * One companion's single_instance() election: an rsx-lockd lock held on its own connection.
 *
 * The connection IS the lock: no lease, no TTL, no renewal. A blocking acquire parks silently
 * and is granted the instant the holder dies, in FIFO order across hosts, so there is nothing
 * to poll. The daemon holds it, never the child: a lock descriptor must not reach a long-lived
 * process, and the child is outside the connection's life.
 *
 * LOSING THE CONNECTION IS LOSING THE ELECTION, so the owner stops the companion at once.
 * Another host may already hold it; the companion's safety in that window is its idempotency.
 * Lockd unreachable fails closed: the companion waits, with that reason.
 */

const path = require('path');

const lockd_client = require(path.join(__dirname, '..', '..', 'rsx-lockd', 'lib', 'client.js'));
const protocol = require(path.join(__dirname, '..', '..', 'rsx-lockd', 'lib', 'protocol.js'));

/** The server's own keepalive interval: a partitioned peer is noticed on the same clock. */
const KEEPALIVE_MS = 30000;

const STATE_IDLE = 'idle';
const STATE_CONNECTING = 'connecting';
const STATE_PARKED = 'parked';
const STATE_HELD = 'held';
const STATE_UNREACHABLE = 'unreachable';

class Instance_Lease {
    /**
     * @param {object} options
     * @param {string} options.lock_name  The wire name
     * @param {{host: string, port: number}} options.lockd
     * @param {string} options.hmac_key   APP_KEY
     * @param {function()} options.on_change  Called on every state change
     */
    constructor(options) {
        this.options = options;
        this.state = STATE_IDLE;
        this.client = null;
        this.reason = null;
        this.generation = 0;
    }

    /** Start electing. Does nothing while an election is under way or held. */
    acquire() {
        if (this.state !== STATE_IDLE && this.state !== STATE_UNREACHABLE) return;

        this.generation++;
        this._run(this.generation).catch((err) => this._unreachable(err));
    }

    async _run(generation) {
        this.state = STATE_CONNECTING;

        // The lockd client takes a parsed config and uses it only to name the target.
        const config = { unix: { enabled: false }, tcp: { bind: this.options.lockd.host, port: this.options.lockd.port } };

        const client = await lockd_client.connect(config, {
            host: this.options.lockd.host,
            port: this.options.lockd.port,
            hmac_key: this.options.hmac_key,
            retries: 1,
        });

        if (generation !== this.generation) {
            client.close();
            return;
        }

        this.client = client;
        client.socket.setKeepAlive(true, KEEPALIVE_MS);
        client.socket.on('close', () => this._on_close(generation));

        this.state = STATE_PARKED;
        this.options.on_change();

        const response = await client.request({
            op: 'acquire',
            name: this.options.lock_name,
            mode: 'write',
            timeout: null,
        });

        if (generation !== this.generation) return;

        if (response.status !== protocol.STATUS_GRANTED) {
            throw new Error(response.message || 'acquire failed: ' + response.status);
        }

        this.state = STATE_HELD;
        this.options.on_change();
    }

    _unreachable(err) {
        this.reason = err.message;
        this.state = STATE_UNREACHABLE;
        if (this.client) this.client.close();
        this.client = null;
        this.options.on_change();
    }

    _on_close(generation) {
        if (generation !== this.generation || this.state === STATE_IDLE) return;

        this.client = null;
        this.reason = 'the connection to rsx-lockd closed';
        this.state = STATE_UNREACHABLE;
        this.options.on_change();
    }

    /** Give the lock up: closing the connection is the release. */
    release() {
        this.generation++;
        this.state = STATE_IDLE;
        if (this.client) this.client.close();
        this.client = null;
    }
}

/** RsxLocks' spelling, so `lockd dump` reads the same: domain, the scope token, the name. */
function lock_name(token, companion_name) {
    return 'companion:' + token + '__' + companion_name;
}

module.exports = {
    Instance_Lease,
    lock_name,
    STATE_IDLE,
    STATE_CONNECTING,
    STATE_PARKED,
    STATE_HELD,
    STATE_UNREACHABLE,
};
