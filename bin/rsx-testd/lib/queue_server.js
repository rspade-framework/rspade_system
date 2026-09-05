/**
 * The work queue the test containers pull from.
 *
 * ONE unix socket, newline-delimited JSON, one request per connection - the house RPC
 * shape (see Core/JsParsers/resource/node-service.js and bin/rsx-lockd). The PHP client is
 * Rsx_Test_Command::queue_request(): connect, write one line, read ONE line with fgets,
 * close. So every response goes back on the connection its request arrived on, and nothing
 * is ever written unsolicited.
 *
 * Methods:
 *   {"id":N,"method":"ping"}
 *       -> {"id":N,"result":"pong"}
 *   {"id":N,"method":"queue.next","worker_id":W}
 *       -> {"id":N,"class":"<fqcn>","short":"<short>","requires_db_reset":<bool>}
 *          or {"id":N,"class":null} once the queue is drained
 *   {"id":N,"method":"queue.result","worker_id":W,"class","short","results","duration"[,"error"]}
 *       -> {"id":N,"ok":true}
 *
 * THE HOLDER MAP IS THE DEAD-WORKER STORY. queue.next records which worker took a class
 * and queue.result clears it, so when a container exits the orchestrator can name every
 * class that worker was still holding. Those classes get NO record - which is exactly what
 * the PHP merge turns into a FAIL ("worker terminated before it finished"). A dead worker
 * is a test-run outcome, never a silent drop and never an infrastructure abort.
 *
 * RESULTS ARE APPENDED AS THEY ARRIVE, not at the end: a run that dies half way still
 * leaves every class that finished on disk, which is what makes the file a post-mortem.
 */

const fs = require('fs');
const net = require('net');

const { encode_frame, decode_frame, Frame_Reader } = require('./protocol.js');

class Queue_Server {
    /**
     * @param {object} options
     * @param {string} options.socket_path Unix socket to bind (the containers bind-mount it)
     * @param {Array} options.classes classes.json rows, in dispatch order (longest-first)
     * @param {string} options.results_path results.jsonl, appended one record per class
     * @param {function} options.log Terse line logger
     */
    constructor(options) {
        this.socket_path = options.socket_path;
        this.results_path = options.results_path;
        this.log = options.log || (() => {});

        // The pending work, head first. Shift() is the whole dispatch policy: PHP already
        // ordered it longest-first.
        this.pending = (options.classes || []).slice();

        // fqcn -> worker_id, for every class handed out and not yet answered.
        this.holders = new Map();

        // fqcn -> row, so a holder can be named in a log line without a second lookup.
        this.by_class = new Map();
        for (const row of this.pending) {
            this.by_class.set(row.fqcn, row);
        }

        this.result_count = 0;
        this.server = null;
    }

    /**
     * Bind the socket. The results file is created (empty) here, so it exists from the
     * moment the run can produce one - the orchestrator's exit code is "did I produce
     * results.jsonl", and an empty file with every class missing is a legitimate, fully
     * reportable answer.
     */
    start() {
        if (fs.existsSync(this.socket_path)) {
            fs.unlinkSync(this.socket_path);
        }

        fs.writeFileSync(this.results_path, '');

        this.server = net.createServer((socket) => this.__handle_connection(socket));

        return new Promise((resolve, reject) => {
            this.server.once('error', reject);
            this.server.listen(this.socket_path, () => {
                // A container runs as a different uid than nothing here does, but the
                // socket is reached through a bind mount, so make it world-usable rather
                // than depending on uid alignment between host and container.
                fs.chmodSync(this.socket_path, 0o777);
                resolve();
            });
        });
    }

    close() {
        return new Promise((resolve) => {
            if (!this.server) {
                resolve();
                return;
            }
            this.server.close(() => {
                if (fs.existsSync(this.socket_path)) {
                    fs.unlinkSync(this.socket_path);
                }
                resolve();
            });
        });
    }

    /**
     * Every class this worker took and has not answered for.
     *
     * @param {number} worker_id
     * @return {Array<{fqcn:string, short:string}>}
     */
    held_by(worker_id) {
        const held = [];
        for (const [fqcn, holder] of this.holders.entries()) {
            if (holder === worker_id) {
                const row = this.by_class.get(fqcn) || {};
                held.push({ fqcn, short: row.short || fqcn });
            }
        }

        return held;
    }

    __handle_connection(socket) {
        const reader = new Frame_Reader();

        socket.on('error', () => {
            // A worker that died mid-request drops its connection. That is a dead-worker
            // outcome, reported when its container exits - never this server's failure.
        });

        socket.on('data', (chunk) => {
            const { lines, error } = reader.push(chunk);

            for (const line of lines) {
                socket.write(encode_frame(this.__answer(line)));
            }

            if (error) {
                socket.write(encode_frame({ id: null, error: error }));
                socket.destroy();
            }
        });
    }

    /**
     * Answer ONE request line. Never throws: this process is the only thing that can write
     * results.jsonl, so a malformed frame from one worker must not cost the run.
     */
    __answer(line) {
        const decoded = decode_frame(line);
        if (!decoded.ok) {
            return { id: null, error: decoded.error };
        }

        const frame = decoded.value;
        const id = frame.id === undefined ? null : frame.id;

        try {
            if (frame.method === 'ping') {
                return { id: id, result: 'pong' };
            }

            if (frame.method === 'queue.next') {
                return this.__queue_next(id, frame);
            }

            if (frame.method === 'queue.result') {
                return this.__queue_result(id, frame);
            }

            return { id: id, error: 'Unknown method: ' + String(frame.method) };
        } catch (err) {
            return { id: id, error: err.message };
        }
    }

    __queue_next(id, frame) {
        const row = this.pending.shift();

        if (!row) {
            return { id: id, class: null };
        }

        this.holders.set(row.fqcn, frame.worker_id);

        return {
            id: id,
            class: row.fqcn,
            short: row.short,
            requires_db_reset: !!row.requires_db_reset,
        };
    }

    __queue_result(id, frame) {
        const fqcn = frame.class;
        if (typeof fqcn !== 'string' || fqcn === '') {
            return { id: id, error: 'queue.result requires a non-empty string class' };
        }

        this.holders.delete(fqcn);

        // EXACTLY the record merge_and_report() reads. worker_id is queue bookkeeping and
        // is dropped here; results is passed through as sent (PHP encodes an empty array
        // as [] and a populated one as an object - the merge accepts both).
        const record = {
            class: fqcn,
            short: frame.short,
            results: frame.results === undefined ? [] : frame.results,
            duration: frame.duration,
        };
        if (frame.error !== undefined && frame.error !== null) {
            record.error = frame.error;
        }

        fs.appendFileSync(this.results_path, JSON.stringify(record) + '\n');
        this.result_count++;

        return { id: id, ok: true };
    }
}

module.exports = { Queue_Server };
