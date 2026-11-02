/**
 * A minimal RESP2 client: the daemon must start with no npm dependency at all, and needs only
 * ordered commands on one connection. Replies arrive in the order commands were written, so a
 * FIFO of waiters is the whole correlation.
 *
 * It reconnects for as long as it runs. A command issued with no connection rejects at once:
 * the caller's data is either republished after the next connect or is lossy by design.
 */

const EventEmitter = require('events');
const net = require('net');

/** How often a lost connection is retried. A poll interval: nothing fails when it passes. */
const RECONNECT_INTERVAL_MS = 2000;

const CRLF = '\r\n';

function encode_command(args) {
    let out = '*' + args.length + CRLF;
    for (const arg of args) {
        const text = String(arg);
        out += '$' + Buffer.byteLength(text) + CRLF + text + CRLF;
    }
    return out;
}

/**
 * Parse one reply from `buffer` at `offset`. Answers null when the reply is incomplete, else
 * {value, offset}. An error reply is an Error VALUE, so it can sit inside an array.
 */
function parse_reply(buffer, offset) {
    const line_end = buffer.indexOf(CRLF, offset);
    if (line_end === -1) return null;

    const type = String.fromCharCode(buffer[offset]);
    const line = buffer.toString('utf8', offset + 1, line_end);
    const next = line_end + 2;

    if (type === '+') return { value: line, offset: next };
    if (type === '-') return { value: new Error(line), offset: next };
    if (type === ':') return { value: Number(line), offset: next };

    if (type === '$') {
        const length = Number(line);
        if (length === -1) return { value: null, offset: next };
        if (buffer.length < next + length + 2) return null;
        return { value: buffer.toString('utf8', next, next + length), offset: next + length + 2 };
    }

    if (type === '*') {
        const count = Number(line);
        if (count === -1) return { value: null, offset: next };
        const values = [];
        let cursor = next;
        for (let i = 0; i < count; i++) {
            const item = parse_reply(buffer, cursor);
            if (item === null) return null;
            values.push(item.value);
            cursor = item.offset;
        }
        return { value: values, offset: cursor };
    }

    throw new Error('Unparseable Redis reply type: ' + type);
}

class Redis_Client extends EventEmitter {
    /**
     * @param {{socket: string, host: string, port: number, password: string, db: number}} options
     */
    constructor(options) {
        super();
        this.options = options;
        this.socket = null;
        this.connected = false;
        this.buffer = Buffer.alloc(0);
        this.waiters = [];
        this.stopped = false;
        this.reconnect_timer = null;
    }

    start() {
        this._connect();
    }

    stop() {
        this.stopped = true;
        if (this.reconnect_timer) clearTimeout(this.reconnect_timer);
        if (this.socket) this.socket.destroy();
    }

    _connect() {
        if (this.stopped) return;

        const options = this.options;
        const socket = options.socket
            ? net.connect(options.socket)
            : net.connect(options.port, options.host);

        this.socket = socket;
        this.buffer = Buffer.alloc(0);

        socket.on('connect', () => this._on_connect(socket));
        socket.on('data', (chunk) => this._on_data(chunk));
        socket.on('error', () => { /* 'close' follows, and is the one place that reacts */ });
        socket.on('close', () => this._on_close(socket));
    }

    async _on_connect(socket) {
        try {
            if (this.options.password) {
                await this._write_command(['AUTH', this.options.password]);
            }
            await this._write_command(['SELECT', this.options.db]);
        } catch (err) {
            this.emit('failure', err);
            socket.destroy();
            return;
        }

        this.connected = true;
        this.emit('connect');
    }

    _on_data(chunk) {
        this.buffer = this.buffer.length === 0 ? chunk : Buffer.concat([this.buffer, chunk]);

        let offset = 0;
        for (;;) {
            let reply;
            try {
                reply = parse_reply(this.buffer, offset);
            } catch (err) {
                this.emit('failure', err);
                this.socket.destroy();
                return;
            }
            if (reply === null) break;

            offset = reply.offset;
            const waiter = this.waiters.shift();
            if (waiter) {
                if (reply.value instanceof Error) waiter.reject(reply.value);
                else waiter.resolve(reply.value);
            }
        }

        this.buffer = this.buffer.subarray(offset);
    }

    _on_close(socket) {
        if (socket !== this.socket) return;

        const was_connected = this.connected;
        this.connected = false;
        this.socket = null;

        const waiters = this.waiters;
        this.waiters = [];
        for (const waiter of waiters) {
            waiter.reject(new Error('Redis connection closed'));
        }

        if (was_connected) this.emit('disconnect');

        if (!this.stopped) {
            this.reconnect_timer = setTimeout(() => this._connect(), RECONNECT_INTERVAL_MS);
        }
    }

    _write_command(args) {
        return new Promise((resolve, reject) => {
            this.waiters.push({ resolve, reject });
            this.socket.write(encode_command(args));
        });
    }

    /** One command. Rejects at once with no connection. */
    command(...args) {
        if (!this.connected) {
            return Promise.reject(new Error('Redis is not connected'));
        }
        return this._write_command(args);
    }

    /**
     * Several commands in one write. Resolves with each reply, an error reply as an Error
     * value, so one refused command cannot hide what the others answered.
     */
    pipeline(commands) {
        if (!this.connected) {
            return Promise.reject(new Error('Redis is not connected'));
        }

        const replies = commands.map(() => new Promise((resolve) => {
            this.waiters.push({ resolve, reject: resolve });
        }));
        this.socket.write(commands.map(encode_command).join(''));

        return Promise.all(replies);
    }
}

module.exports = { Redis_Client, encode_command, parse_reply };
