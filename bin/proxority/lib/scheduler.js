/**
 * The ledger of one php-fpm pool: who is running, who is waiting, and who runs next.
 *
 * PURE. No I/O, no timers, no clock: every call answers the requests that should start NOW,
 * and the caller starts them. That keeps the whole fairness policy testable as plain function
 * calls (tests/proxority/resource/unit_tests.js) and keeps the network code free of policy.
 *
 * A request is a plain object the caller owns. The ledger reads these fields:
 *
 *     ip           the client address the per-IP cap and the round robin key on
 *     media        counted against the media cap
 *     robot        counted against the robot cap
 *     priority     jumps the queue (never past the per-IP cap)
 *     unthrottled  never queued, still counted busy
 *     loopback     never queued and NOT counted busy
 *
 * and writes `_state` ('queued' | 'running' | 'done') and `_counted`.
 *
 * The caps, for a pool of N workers (pm.max_children):
 *
 *     per IP   max(1, N - 1)       one client can never take every worker
 *     media    max(1, floor(N/2))  a run of downloads leaves the other half for pages
 *     robot    max(1, floor(N/5))  crawlers get a fifth
 *
 * A request counts against EVERY cap that applies to it: per-IP always, media when it is
 * media, robot when it is a robot. A request whose cap is full is skipped for this round,
 * and the next one that fits runs in its place - so a blocked download never stalls the
 * pages queued behind it.
 *
 * Dispatch order when a worker is free: the oldest priority request that fits, then round
 * robin across client addresses, one request per address per turn. Unthrottled requests
 * may push the busy count past N (they are never held), in which case nothing queued starts
 * until the count is back under N.
 */

const CLASSES = ['normal', 'priority', 'media', 'robot', 'unthrottled'];

function caps_for(capacity) {
    return {
        per_ip: Math.max(1, capacity - 1),
        media: Math.max(1, Math.floor(capacity / 2)),
        robot: Math.max(1, Math.floor(capacity / 5)),
    };
}

/** The one label a running request is shown under in the busy-by-class counts. */
function class_of(request) {
    if (request.unthrottled) return 'unthrottled';
    if (request.priority) return 'priority';
    if (request.media) return 'media';
    if (request.robot) return 'robot';
    return 'normal';
}

class Pool_Ledger {
    /**
     * @param {string} name
     * @param {number} capacity  pm.max_children
     */
    constructor(name, capacity) {
        this.name = name;
        this.set_capacity(capacity);

        this.busy_total = 0;
        this.busy_by_class = {};
        for (const label of CLASSES) this.busy_by_class[label] = 0;
        this.running_by_ip = new Map();
        this.media_running = 0;
        this.robot_running = 0;
        this.loopback_running = 0;

        /** Priority requests, oldest first, every address together. */
        this.priority_queue = [];
        /** address -> its other queued requests, oldest first */
        this.queues_by_ip = new Map();
        /** The addresses with something in queues_by_ip, in turn order. */
        this.rotation = [];
        /** Index into rotation of the address whose turn is next. */
        this.cursor = 0;
        this.queued_total = 0;
    }

    /**
     * Change N. Answers what may start now that the pool is larger (nothing when smaller:
     * requests already running are never stopped, the pool simply drains down to the new N).
     */
    set_capacity(capacity) {
        if (!Number.isInteger(capacity) || capacity < 1) {
            throw new Error('Pool ' + this.name + ': capacity must be a whole number of at least 1, got ' + capacity);
        }
        this.capacity = capacity;
        this.caps = caps_for(capacity);

        return this.queued_total === undefined ? [] : this._pump();
    }

    /**
     * A request arrives. Answers the requests to start now - the new one included when it
     * may start at once. A request not in the answer is queued.
     */
    submit(request) {
        if (request.loopback) {
            request._state = 'running';
            request._counted = false;
            this.loopback_running++;
            return [request];
        }

        if (request.unthrottled) {
            this._mark_running(request);
            return [request];
        }

        request._state = 'queued';
        if (request.priority) {
            this.priority_queue.push(request);
        } else {
            let queue = this.queues_by_ip.get(request.ip);
            if (!queue) {
                queue = [];
                this.queues_by_ip.set(request.ip, queue);
                this.rotation.push(request.ip);
            }
            queue.push(request);
        }
        this.queued_total++;

        return this._pump();
    }

    /**
     * A running request gave its worker back. Answers the requests to start now.
     * Releasing twice, or releasing a request that never ran, is a no-op.
     */
    release(request) {
        if (request._state !== 'running') return [];
        request._state = 'done';

        if (!request._counted) {
            this.loopback_running--;
            return [];
        }

        this.busy_total--;
        this.busy_by_class[class_of(request)]--;

        const running = this.running_by_ip.get(request.ip) - 1;
        if (running === 0) {
            this.running_by_ip.delete(request.ip);
        } else {
            this.running_by_ip.set(request.ip, running);
        }
        if (request.media) this.media_running--;
        if (request.robot) this.robot_running--;

        return this._pump();
    }

    /** A queued request's client went away. Answers whether it was queued here. */
    cancel(request) {
        if (request._state !== 'queued') return false;

        if (request.priority) {
            this.priority_queue.splice(this.priority_queue.indexOf(request), 1);
        } else {
            const queue = this.queues_by_ip.get(request.ip);
            queue.splice(queue.indexOf(request), 1);
            if (queue.length === 0) this._drop_from_rotation(request.ip);
        }
        this.queued_total--;
        request._state = 'done';

        return true;
    }

    /** Everything the status display shows, as plain data. */
    snapshot() {
        const queued_by_ip = new Map();
        for (const request of this.priority_queue) {
            queued_by_ip.set(request.ip, (queued_by_ip.get(request.ip) || 0) + 1);
        }
        for (const [ip, queue] of this.queues_by_ip) {
            queued_by_ip.set(ip, (queued_by_ip.get(ip) || 0) + queue.length);
        }

        return {
            name: this.name,
            capacity: this.capacity,
            caps: Object.assign({}, this.caps),
            busy_total: this.busy_total,
            busy_by_class: Object.assign({}, this.busy_by_class),
            loopback_running: this.loopback_running,
            media_running: this.media_running,
            robot_running: this.robot_running,
            queued_total: this.queued_total,
            queued_priority: this.priority_queue.length,
            queued_by_ip: Array.from(queued_by_ip, ([ip, count]) => ({ ip, count }))
                .sort((a, b) => b.count - a.count || (a.ip < b.ip ? -1 : 1)),
        };
    }

    // ------------------------------------------------------------------------------------

    _fits(request) {
        if ((this.running_by_ip.get(request.ip) || 0) >= this.caps.per_ip) return false;
        if (request.media && this.media_running >= this.caps.media) return false;
        if (request.robot && this.robot_running >= this.caps.robot) return false;
        return true;
    }

    _mark_running(request) {
        request._state = 'running';
        request._counted = true;
        this.busy_total++;
        this.busy_by_class[class_of(request)]++;
        this.running_by_ip.set(request.ip, (this.running_by_ip.get(request.ip) || 0) + 1);
        if (request.media) this.media_running++;
        if (request.robot) this.robot_running++;
    }

    _drop_from_rotation(ip) {
        const index = this.rotation.indexOf(ip);
        this.rotation.splice(index, 1);
        this.queues_by_ip.delete(ip);

        // The address after the removed one now sits at its index, and keeps its turn.
        if (index < this.cursor) this.cursor--;
        if (this.cursor > this.rotation.length) this.cursor = 0;
    }

    /** Start whatever fits while a worker is free. */
    _pump() {
        const started = [];

        while (this.busy_total < this.capacity && this.queued_total > 0) {
            const request = this._take_priority() || this._take_round_robin();
            if (!request) break;

            this.queued_total--;
            this._mark_running(request);
            started.push(request);
        }

        return started;
    }

    _take_priority() {
        for (let i = 0; i < this.priority_queue.length; i++) {
            if (this._fits(this.priority_queue[i])) {
                return this.priority_queue.splice(i, 1)[0];
            }
        }
        return null;
    }

    _take_round_robin() {
        const count = this.rotation.length;

        // The cursor may sit one past the end: the turn belongs to whoever is appended next,
        // or wraps to the front when nobody is.
        for (let k = 0; k < count; k++) {
            const index = (this.cursor + k) % count;
            const ip = this.rotation[index];
            const queue = this.queues_by_ip.get(ip);

            for (let i = 0; i < queue.length; i++) {
                if (!this._fits(queue[i])) continue;

                const request = queue.splice(i, 1)[0];
                if (queue.length === 0) {
                    this.cursor = index;
                    this._drop_from_rotation(ip);
                } else {
                    this.cursor = index + 1;
                }
                return request;
            }
        }
        return null;
    }
}

module.exports = { Pool_Ledger, caps_for, class_of, CLASSES };
