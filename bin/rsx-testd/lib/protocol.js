/**
 * rsx-testd wire protocol.
 *
 * Newline-delimited JSON, strict request/response, exactly the house RPC shape: the
 * client opens ONE connection per request, writes one line, reads ONE line, closes. The
 * server never sends an unsolicited frame.
 *
 * Everything here is PURE - no sockets, no timers, no process state - so it is
 * require()-able from a test without binding anything. Deliberately mirrors
 * system/bin/rsx-lockd/lib/protocol.js: same encode/decode contract, same newline
 * splitter, same frame cap, so a reader of one already knows the other.
 */

// A frame is one line. This cap exists so a peer that never sends a newline (hostile or
// broken) cannot grow the per-connection buffer without bound. A test-result frame is a
// few hundred bytes at most; a megabyte is orders of magnitude of headroom.
const MAX_FRAME_BYTES = 1024 * 1024;

/**
 * Serialize one frame, newline-terminated. JSON.stringify cannot produce a bare newline
 * inside a string (it escapes them), so the newline is an unambiguous frame boundary.
 */
function encode_frame(obj) {
    return JSON.stringify(obj) + '\n';
}

/**
 * Parse one line into a frame. NEVER throws - a broken worker's bytes arrive here, and
 * this process is the only thing that can write results.jsonl, so one uncaught throw
 * would lose the whole run's outcome. Returns { ok: true, value } or { ok: false, error }.
 *
 * A JSON literal `null` parses fine but has no property surface, so a later frame.method
 * read would throw TypeError. It is rejected here, as are all non-object frames.
 */
function decode_frame(line) {
    let value;
    try {
        value = JSON.parse(line);
    } catch (err) {
        return { ok: false, error: 'Malformed JSON frame: ' + err.message };
    }
    if (value === null || typeof value !== 'object' || Array.isArray(value)) {
        return { ok: false, error: 'Frame must be a JSON object' };
    }
    return { ok: true, value };
}

/**
 * Stateful newline splitter for a byte stream. One instance per connection.
 *
 * push(chunk) returns { lines, error }. Once `error` is non-null the connection is
 * unrecoverable (the frame cap was blown) and the caller must respond and destroy;
 * further pushes keep reporting the same error.
 */
class Frame_Reader {
    constructor(max_bytes = MAX_FRAME_BYTES) {
        this.max_bytes = max_bytes;
        this.buffer = '';
        this.error = null;
    }

    push(chunk) {
        if (this.error) {
            return { lines: [], error: this.error };
        }

        this.buffer += chunk.toString('utf8');

        const lines = [];
        let index = this.buffer.indexOf('\n');
        while (index !== -1) {
            const line = this.buffer.slice(0, index).trim();
            this.buffer = this.buffer.slice(index + 1);
            if (line.length > 0) {
                lines.push(line);
            }
            index = this.buffer.indexOf('\n');
        }

        if (this.buffer.length > this.max_bytes) {
            this.error = 'Frame exceeds ' + this.max_bytes + ' bytes without a newline';
            this.buffer = '';
            return { lines, error: this.error };
        }

        return { lines, error: null };
    }
}

module.exports = {
    MAX_FRAME_BYTES,
    encode_frame,
    decode_frame,
    Frame_Reader,
};
