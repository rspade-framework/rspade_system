/**
 * rsx-lockd network layer.
 *
 * Owns the sockets and the per-connection state; the lock semantics live entirely in
 * locktable.js. This file's whole job is: accept a connection, authenticate it, turn bytes
 * into frames, hand each frame to the table, write the answer back, and - the important
 * part - tell the table the moment a connection dies.
 *
 * DOCTRINE: this is a SINGLE shared process serving every lock holder on the box. One
 * uncaught throw here would drop every lock in the cluster. Nothing in a frame handling
 * path is allowed to throw: hostile or malformed input is answered with a status:error
 * frame, and the outer try/catch around dispatch is a guarantee, not decoration.
 */

const fs = require('fs');
const net = require('net');
const path = require('path');

const protocol = require('./protocol');
const { Lock_Table } = require('./locktable');

// A connection that has not said hello inside this window is dropped. Bounds the number
// of unauthenticated sockets a stranger can park on the daemon.
const HELLO_TIMEOUT_MS = 10000;

// Applied to every accepted socket. Keepalive is what turns a partitioned peer (power
// loss, cable pull - the cases where no FIN ever arrives) into a close event, which is
// what releases its locks. Without it those locks would be held by a ghost forever, since
// there is no lease to expire.
const KEEPALIVE_DELAY_MS = 30000;

class Lockd_Server {
    constructor(options) {
        this.config = options.config;
        this.hmac_key = options.hmac_key || '';

        // The logger object (lockd.js, Lockd_Logger) when there is one, so the `log` op can
        // flip its switches at runtime. `options.log` stays supported for a caller that only
        // has a write function - every test constructs the server that way.
        this.logger = options.logger || null;
        this.log = options.log || (this.logger ? (m) => this.logger.write(m) : function () {});

        this.conn_seq = 0;
        this.connections = new Map();   // conn_id -> { id, socket, authenticated, reader, hello_timer }
        this.servers = [];
        this.unix_path = null;

        this.table = new Lock_Table({
            deliver: (conn_id, frame) => {
                this._send_to(conn_id, frame);
            },
            // INJECTED, because locktable.js is pure and must stay that way: it describes
            // the event, this decides whether anything is written. A no-op when no logger
            // was given, which is what keeps the table's unit tests side-effect free.
            log: this.logger
                ? (build, level) => this.logger.event(build, level)
                : function () {},
        });
    }

    // ---- Lifecycle ------------------------------------------------------------------

    async start() {
        if (this.config.unix.enabled) {
            await this._listen_unix();
        }
        if (this.config.tcp.enabled) {
            await this._listen_tcp();
        }
        // Persisted beside every task row a worker claims; logged so a row can be matched
        // to the daemon lifetime that issued its wid.
        this.log('[OK] Pool generation ' + this.table.pool.generation);
    }

    async stop() {
        for (const [, conn] of this.connections) {
            this._destroy(conn);
        }
        this.connections.clear();

        for (const server of this.servers) {
            await new Promise((resolve) => server.close(() => resolve()));
        }
        this.servers = [];

        // Best-effort: a leftover socket file blocks the next bind, and the file is
        // meaningless once this process is gone.
        if (this.unix_path) {
            try {
                fs.unlinkSync(this.unix_path);
            } catch (err) {
                if (err.code !== 'ENOENT') {
                    this.log('[WARNING] Could not remove socket ' + this.unix_path + ': ' + err.message);
                }
            }
        }
    }

    _listen_unix() {
        const socket_path = this.config.unix.path;

        // A stale socket file from a crashed instance makes bind fail with EADDRINUSE even
        // though nobody is listening. Probe it: if a connect succeeds, another daemon is
        // live and we must NOT steal its path.
        return this._probe_unix(socket_path).then((in_use) => {
            if (in_use) {
                throw new Error('Another process is already listening on ' + socket_path);
            }
            try {
                fs.unlinkSync(socket_path);
            } catch (err) {
                if (err.code !== 'ENOENT') {
                    throw new Error('Cannot remove stale socket ' + socket_path + ': ' + err.message);
                }
            }

            const dir = path.dirname(socket_path);
            if (!fs.existsSync(dir)) {
                fs.mkdirSync(dir, { recursive: true });
            }

            return new Promise((resolve, reject) => {
                const server = net.createServer((socket) => this._accept(socket, 'unix'));
                server.on('error', (err) => reject(err));
                server.listen(socket_path, () => {
                    try {
                        fs.chmodSync(socket_path, parseInt(this.config.unix.mode, 8));
                    } catch (err) {
                        this.log('[WARNING] Could not chmod ' + socket_path + ': ' + err.message);
                    }
                    this.unix_path = socket_path;
                    this.servers.push(server);
                    this.log('[OK] Listening on unix socket ' + socket_path);
                    resolve();
                });
            });
        });
    }

    _probe_unix(socket_path) {
        return new Promise((resolve) => {
            if (!fs.existsSync(socket_path)) {
                resolve(false);
                return;
            }
            const probe = net.connect(socket_path);
            probe.on('connect', () => {
                probe.destroy();
                resolve(true);
            });
            probe.on('error', () => {
                probe.destroy();
                resolve(false);
            });
        });
    }

    _listen_tcp() {
        return new Promise((resolve, reject) => {
            const server = net.createServer((socket) => this._accept(socket, 'tcp'));
            server.on('error', (err) => reject(err));
            server.listen(this.config.tcp.port, this.config.tcp.bind, () => {
                this.servers.push(server);
                this.log('[OK] Listening on tcp ' + this.config.tcp.bind + ':' + this.config.tcp.port);
                resolve();
            });
        });
    }

    // ---- Connections ----------------------------------------------------------------

    _accept(socket, kind) {
        this.conn_seq++;
        const id = 'c' + this.conn_seq;

        if (kind === 'tcp') {
            // Tiny request/response frames: Nagle would add 40ms to a lock acquisition for
            // no benefit. Keepalive is the liveness detector described at the top.
            socket.setNoDelay(true);
            socket.setKeepAlive(true, KEEPALIVE_DELAY_MS);
        }
        const remote = kind === 'tcp'
            ? (socket.remoteAddress || '?') + ':' + (socket.remotePort || '?')
            : 'unix';

        const conn = {
            id: id,
            socket: socket,
            kind: kind,
            authenticated: false,
            reader: new protocol.Frame_Reader(),
            hello_timer: null,
        };
        this.connections.set(id, conn);
        this.table.register_connection(id, { remote: remote });

        conn.hello_timer = setTimeout(() => {
            if (!conn.authenticated) {
                this._send(conn, { status: protocol.STATUS_ERROR, message: 'hello timeout' });
                this._destroy(conn);
            }
        }, HELLO_TIMEOUT_MS);

        socket.on('data', (chunk) => this._on_data(conn, chunk));
        socket.on('close', () => this._on_close(conn));
        socket.on('error', (err) => {
            // Not exceptional: a client that exits mid-write produces ECONNRESET. The
            // close handler that follows is what releases the locks.
            if (err && err.code !== 'ECONNRESET' && err.code !== 'EPIPE') {
                this.log('[WARNING] Connection ' + conn.id + ' error: ' + err.message);
            }
        });
    }

    _on_data(conn, chunk) {
        const result = conn.reader.push(chunk);

        for (const line of result.lines) {
            const decoded = protocol.decode_frame(line);
            if (!decoded.ok) {
                this._send(conn, { status: protocol.STATUS_ERROR, message: decoded.error });
                continue;
            }
            this._dispatch(conn, decoded.value);
        }

        if (result.error) {
            this._send(conn, { status: protocol.STATUS_ERROR, message: result.error });
            this._destroy(conn);
        }
    }

    _on_close(conn) {
        if (conn.hello_timer) {
            clearTimeout(conn.hello_timer);
            conn.hello_timer = null;
        }
        if (!this.connections.has(conn.id)) return;
        this.connections.delete(conn.id);

        // THE correctness model: the connection is the lock, so its disappearance - clean
        // exit, crash, kill -9, or a dead TCP peer - releases everything it held and
        // unparks whoever was waiting on it.
        const result = this.table.drop_connection(conn.id);
        if (result.released > 0 || result.cancelled > 0) {
            this.log('[lockd] ' + conn.id + ' closed: released ' + result.released
                + ' hold(s), cancelled ' + result.cancelled + ' wait(s)');
        }
        const pool = result.pool;
        if (pool.locks_released > 0 || pool.members_removed > 0 || pool.waits_cancelled > 0) {
            this.log('[lockd] ' + conn.id + ' closed: released ' + pool.locks_released
                + ' pool lock(s), removed ' + pool.members_removed + ' pool membership(s), cancelled '
                + pool.waits_cancelled + ' pool wait(s)');
        }
    }

    _destroy(conn) {
        try {
            conn.socket.destroy();
        } catch (err) {
            // Already gone. The close handler still runs and still releases.
        }
    }

    // ---- Dispatch -------------------------------------------------------------------

    _dispatch(conn, frame) {
        // The guarantee. Any bug or hostile shape below produces an error frame on ONE
        // connection instead of terminating the daemon for every lock holder on the box.
        try {
            const op = frame.op;

            if (typeof op !== 'string' || op.length === 0) {
                this._send(conn, { id: frame.id, status: protocol.STATUS_ERROR, message: "Frame requires an 'op'" });
                return;
            }

            // Liveness probe, deliberately open pre-hello: it discloses nothing and lets a
            // health check confirm the daemon is answering without holding a key.
            if (op === 'ping') {
                this._send(conn, { id: frame.id, status: protocol.STATUS_OK, pong: true });
                return;
            }

            if (op === 'hello') {
                this._handle_hello(conn, frame);
                return;
            }

            if (!conn.authenticated) {
                this._send(conn, {
                    id: frame.id,
                    status: protocol.STATUS_ERROR,
                    message: 'Not authenticated - send hello first',
                });
                return;
            }

            const response = this._handle_authenticated(conn, op, frame);
            if (response !== null && response !== undefined) {
                this._send(conn, response);
            }
        } catch (err) {
            this.log('[ERROR] Dispatch failure on ' + conn.id + ' (' + (frame && frame.op) + '): ' + err.stack);
            this._send(conn, {
                id: frame ? frame.id : undefined,
                status: protocol.STATUS_ERROR,
                message: 'Internal error: ' + err.message,
            });
        }
    }

    _handle_authenticated(conn, op, frame) {
        switch (op) {
            case 'acquire':      return this.table.acquire(conn.id, frame);
            case 'release':      return this.table.release(conn.id, frame);
            case 'release_all':  return this.table.release_all(conn.id, frame);
            case 'upgrade':      return this.table.upgrade(conn.id, frame);
            case 'sem_acquire':  return this.table.sem_acquire(conn.id, frame);
            case 'sem_release':  return this.table.sem_release(conn.id, frame);
            case 'stats':        return this.table.stats(frame);
            case 'dump':         return this.table.dump(frame);
            case 'force_clear':  return this.table.force_clear(frame);
            case 'log':          return this._handle_log(frame);
            case 'pool.lock':              return this.table.pool.lock(conn.id, frame);
            case 'pool.unlock':            return this.table.pool.unlock(conn.id, frame);
            case 'pool.join':              return this.table.pool.join(conn.id, frame);
            case 'pool.leave':             return this.table.pool.leave(conn.id, frame);
            case 'pool.count':             return this.table.pool.count(conn.id, frame);
            case 'pool.member_alive':      return this.table.pool.member_alive(conn.id, frame);
            case 'pool.members_alive':     return this.table.pool.members_alive(conn.id, frame);
            case 'pool.stats':             return this.table.pool.stats(frame);
            default:
                return {
                    id: frame.id,
                    status: protocol.STATUS_ERROR,
                    message: 'Unknown op: ' + op + ' (known: ' + protocol.OPS.join(', ') + ')',
                };
        }
    }

    /**
     * Turn event logging on or off in this running daemon, or report it.
     *
     * Frame: { op:'log', enabled?:bool, verbose?:bool, path?:string }.
     *   no keys                 a pure query - nothing changes
     *   enabled:true            start recording; verbose (default false) and path apply
     *   enabled:false           stop recording
     *   verbose alone           change the verbosity of a log that is already recording
     *
     * AUTHENTICATED ONLY - dispatched from _handle_authenticated(), never pre-hello like
     * `ping`: it names a file this process will write to. The HMAC key is the trust
     * boundary for the whole protocol, so a peer that can say hello may name a path.
     *
     * EVERY KEY IS VALIDATED AND NOTHING IS IGNORED. A combination that would do nothing
     * (a path without enabled:true, verbosity for a log that is off) is an error frame,
     * and so is a destination that cannot be opened - in which case nothing changed.
     */
    _handle_log(frame) {
        const refuse = (message) => ({ id: frame.id, status: protocol.STATUS_ERROR, message: message });
        const given = (key) => frame[key] !== undefined && frame[key] !== null;

        if (!this.logger) {
            return refuse('This daemon was started without a logger - event logging cannot be switched');
        }
        if (given('enabled') && typeof frame.enabled !== 'boolean') {
            return refuse('log enabled must be true or false');
        }
        if (given('verbose') && typeof frame.verbose !== 'boolean') {
            return refuse('log verbose must be true or false');
        }
        if (given('path')) {
            // Absolute, because a relative path would resolve against THIS process's
            // working directory, which the caller cannot know.
            if (typeof frame.path !== 'string' || !path.isAbsolute(frame.path)) {
                return refuse("log path must be an absolute path on the daemon's host");
            }
            if (frame.enabled !== true) {
                return refuse('log path is only accepted together with enabled:true');
            }
        }

        if (frame.enabled === true) {
            const opened = this.logger.enable({
                path: given('path') ? frame.path : null,
                verbose: frame.verbose === true,
            });
            if (!opened) {
                return refuse('Cannot open the log destination: ' + this.logger.last_error);
            }
            // write(), not event(): a reader of the file needs to see when recording began.
            this.logger.write('[OK] Event logging ON' + (this.logger.verbose ? ' (verbose)' : '')
                + ' - switched at runtime');
        } else if (frame.enabled === false) {
            if (frame.verbose === true) {
                return refuse('log verbose:true contradicts enabled:false');
            }
            if (this.logger.events) {
                this.logger.write('[OK] Event logging OFF - switched at runtime');
            }
            this.logger.disable();
        } else if (given('verbose')) {
            if (!this.logger.events) {
                return refuse('Event logging is off - send enabled:true to start it');
            }
            this.logger.set_verbose(frame.verbose);
        }

        return { id: frame.id, status: protocol.STATUS_OK, logging: this.logger.status() };
    }

    _handle_hello(conn, frame) {
        if (conn.authenticated) {
            this._send(conn, { id: frame.id, status: protocol.STATUS_ERROR, message: 'Already authenticated' });
            return;
        }

        const now_seconds = Math.floor(Date.now() / 1000);
        const verified = protocol.verify_hello(frame, this.hmac_key, now_seconds);
        if (!verified.ok) {
            // No retry loop and no partial credit: a failed hello closes the connection.
            this.log('[WARNING] hello rejected on ' + conn.id + ': ' + verified.error);
            this._send(conn, { id: frame.id, status: protocol.STATUS_ERROR, message: verified.error });
            this._destroy(conn);
            return;
        }

        conn.authenticated = true;
        clearTimeout(conn.hello_timer);
        conn.hello_timer = null;

        this.table.set_connection_meta(conn.id, {
            host: verified.meta.host,
            pid: verified.meta.pid,
            group_id: verified.meta.group_id || null,
        });

        this._send(conn, {
            id: frame.id,
            status: protocol.STATUS_OK,
            conn_id: conn.id,
            group_id: verified.meta.group_id || conn.id,
            server: 'rsx-lockd',
            protocol: protocol.PROTOCOL_VERSION,
        });
    }

    // ---- Output ---------------------------------------------------------------------

    _send(conn, frame) {
        if (!conn || !conn.socket || conn.socket.destroyed || !conn.socket.writable) return;
        try {
            conn.socket.write(protocol.encode_frame(frame));
        } catch (err) {
            this.log('[WARNING] Write to ' + conn.id + ' failed: ' + err.message);
        }
    }

    _send_to(conn_id, frame) {
        this._send(this.connections.get(conn_id), frame);
    }
}

module.exports = { Lockd_Server, HELLO_TIMEOUT_MS, KEEPALIVE_DELAY_MS };
