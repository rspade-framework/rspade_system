/**
 * The control socket: newline-delimited JSON over a unix socket under storage/state, which
 * survives rsx:clean. Each request carries an `id` the reply repeats; replies may arrive out of
 * order, because a verb asked to wait answers when the work is done.
 *
 * `ping` answers anyone who can reach the file. Everything else needs a `hello` first, proving
 * the daemon key inside the replay window. Stale-socket handling is rsx-lockd's: a bind probes
 * before unlinking, so a second daemon can never steal a running instance's path.
 */

const crypto = require('crypto');
const fs = require('fs');
const net = require('net');
const path = require('path');

const wire = require('./wire.js');

/** A request line larger than this closes the connection: the protocol has no large frame. */
const MAX_FRAME_BYTES = 1024 * 1024;

const AF_UNIX_PATH_MAX = 107;

function verify_hello(frame, key) {
    const timestamp = Number(frame.ts);
    if (!Number.isInteger(timestamp) || typeof frame.sig !== 'string') return false;
    if (Math.abs(Math.floor(Date.now() / 1000) - timestamp) > wire.WINDOW_SECONDS) return false;
    if (!/^[a-f0-9]{64}$/.test(frame.sig)) return false;

    return crypto.timingSafeEqual(
        Buffer.from(frame.sig),
        Buffer.from(wire.socket_hello_signature(key, String(timestamp)))
    );
}

function socket_is_live(file) {
    return new Promise((resolve) => {
        const probe = net.connect(file);
        probe.once('connect', () => { probe.destroy(); resolve(true); });
        probe.once('error', () => resolve(false));
    });
}

class Socket_Server {
    /**
     * @param {object} options
     * @param {string} options.path
     * @param {string} options.key  The daemon key
     * @param {?{uid: number, gid: number}} options.owner  Who owns the socket file
     * @param {function(object): Promise<object>} options.handle  Answers a verified frame
     * @param {function(): object} options.ping  The unauthenticated liveness answer
     */
    constructor(options) {
        this.options = options;
        this.server = null;
        this.connections = new Set();
    }

    async listen() {
        const file = this.options.path;

        if (file.length > AF_UNIX_PATH_MAX) {
            throw new Error('The control socket path is too long for a unix socket: ' + file);
        }

        fs.mkdirSync(path.dirname(file), { recursive: true });

        if (fs.existsSync(file)) {
            if (await socket_is_live(file)) {
                throw new Error('rsx-companiond is already running on ' + file);
            }
            fs.unlinkSync(file);
        }

        this.server = net.createServer((connection) => this._accept(connection));

        await new Promise((resolve, reject) => {
            this.server.once('error', reject);
            this.server.listen(file, resolve);
        });

        const owner = this.options.owner;
        if (owner !== null) fs.chownSync(file, owner.uid, owner.gid);
        fs.chmodSync(file, 0o660);
    }

    _accept(connection) {
        let buffer = '';
        let authenticated = false;

        this.connections.add(connection);
        connection.on('close', () => this.connections.delete(connection));
        connection.on('error', () => {});

        const reply = (frame) => {
            if (!connection.destroyed) connection.write(JSON.stringify(frame) + '\n');
        };

        connection.on('data', (chunk) => {
            buffer += chunk.toString('utf8');

            if (buffer.length > MAX_FRAME_BYTES) {
                connection.destroy();
                return;
            }

            let newline;
            while ((newline = buffer.indexOf('\n')) !== -1) {
                const line = buffer.slice(0, newline);
                buffer = buffer.slice(newline + 1);

                let frame;
                try {
                    frame = JSON.parse(line);
                } catch (err) {
                    reply({ ok: false, error: 'invalid_frame' });
                    continue;
                }

                if (frame.verb === 'ping') {
                    reply(Object.assign({ id: frame.id, ok: true }, this.options.ping()));
                } else if (frame.verb === 'hello') {
                    authenticated = this.options.key !== '' && verify_hello(frame, this.options.key);
                    reply({ id: frame.id, ok: authenticated, error: authenticated ? undefined : 'invalid_hello' });
                } else if (!authenticated) {
                    reply({ id: frame.id, ok: false, error: 'hello_required' });
                } else {
                    this.options.handle(frame).then(
                        (answer) => reply(Object.assign({ id: frame.id, ok: true }, answer)),
                        (err) => reply({ id: frame.id, ok: false, error: err.message })
                    );
                }
            }
        });
    }

    close() {
        for (const connection of this.connections) connection.destroy();

        if (this.server !== null) {
            this.server.close();
            this.server = null;
        }

        try {
            fs.unlinkSync(this.options.path);
        } catch (err) {
            // Already gone.
        }
    }
}

module.exports = { Socket_Server };
