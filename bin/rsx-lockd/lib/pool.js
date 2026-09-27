/**
 * rsx-lockd worker pool accounting.
 *
 * A POOL is a named set of member connections plus one FIFO mutex (the pool lock) that
 * serializes every read and write of that set. It exists so a fleet of worker processes
 * can ask "how many of us are there?" and "is that worker still alive?" of a party that
 * actually knows: membership is keyed to the connection, so a worker that crashes, is
 * `kill -9`'d or falls off the network stops being a member the moment its socket closes,
 * with no lease, heartbeat or reaper involved anywhere.
 *
 * Deliberately BESPOKE: none of this is built on the general lock or semaphore code in
 * locktable.js. The pool lock has no modes, no timeout, no group inheritance and no
 * deadlock detection, and its waits are never visible to the general wait-for graph. That
 * is safe only under THE RULE (CLAUDE.md, "Worker pools"): while holding a pool lock a
 * process runs pool ops and task-row reads/writes and nothing else - no other blocking
 * lock, no subprocess wait, no outbound call - so a pool-lock holder can never be part of
 * a wait-for cycle.
 *
 * WORKER IDS AND THE GENERATION. Each member is assigned an integer worker id (wid) from
 * ONE daemon-wide counter, so a wid is unique across every pool for the daemon's lifetime:
 * the first is a random integer in [0, WID_FIRST_RANGE), each later one is the previous
 * plus one, wrapping from WID_MODULUS to 0, and a wid a live member still holds is skipped.
 * A wid alone means nothing after a daemon restart - the counter starts somewhere new and
 * the old members are gone - so the daemon also picks a GENERATION at start (a random
 * positive integer <= Number.MAX_SAFE_INTEGER) and every join returns the pair. A caller
 * asking about a wid from another generation is told `known: false`: this daemon cannot
 * know whether a previous daemon's worker is still running.
 *
 * Pure state manipulation, same style as locktable.js: no sockets, no logging, no timers.
 * A parked pool.lock that is later granted is answered through the injected
 * `deliver(conn_id, frame)`, and the generation and first wid are injectable
 * (`generation`, `first_wid`), so a test drives the whole machine with a collector array
 * and a deterministic id sequence.
 *
 * INVARIANTS a change must not break:
 *   1. Every op answers exactly ONE frame echoing the request id. pool.lock answers when
 *      granted (possibly later, via deliver); every other op answers immediately. An error
 *      is an answer too.
 *   2. FIFO. One queue per pool; the lock goes to the head waiter only.
 *   3. drop_connection() removes the connection's memberships in EVERY pool, releases
 *      every pool lock it holds (granting the next waiter), and removes its queued waits.
 *      Lock_Table.drop_connection() and release_all() both call it.
 *   4. A member is identified by (wid, generation), never by a connection id: conn ids
 *      restart at c1 whenever the daemon restarts, and so does any counter - the
 *      generation is what keeps a previous daemon's persisted wid from answering "alive".
 *   5. No two live members share a wid, in any pool.
 */

const crypto = require('crypto');

const protocol = require('./protocol');

// Pool names are printed in dump output and log lines and are chosen by the client, so
// they are held to a printable, bounded charset. Not a security control - the HMAC key is.
const POOL_NAME_PATTERN = /^[A-Za-z0-9_.:-]{1,128}$/;

// Worker ids live in [0, WID_MODULUS): the counter wraps from WID_MODULUS to 0. The first
// wid after daemon start is random in [0, WID_FIRST_RANGE), leaving the counter far from the
// wrap point in practice while still making consecutive daemon lifetimes start apart.
const WID_MODULUS = 1000000000;
const WID_FIRST_RANGE = 10000000;

function is_wid(value) {
    return Number.isSafeInteger(value) && value >= 0 && value < WID_MODULUS;
}

function is_generation(value) {
    return Number.isSafeInteger(value) && value > 0;
}

/** A random integer in [1, Number.MAX_SAFE_INTEGER] - 53 random bits, zero redrawn. */
function default_generation() {
    for (;;) {
        const value = Number(crypto.randomBytes(8).readBigUInt64BE() & ((1n << 53n) - 1n));
        if (value > 0) return value;
    }
}

function default_first_wid() {
    return crypto.randomInt(0, WID_FIRST_RANGE);
}

class Pool_Table {
    constructor(options = {}) {
        this.deliver = options.deliver || function () {};
        this.now = options.now || function () { return Date.now(); };

        this.generation = options.generation === undefined ? default_generation() : options.generation;
        if (!is_generation(this.generation)) {
            throw new Error('Pool_Table generation must be a positive safe integer, got ' + this.generation);
        }
        const first_wid = options.first_wid === undefined ? default_first_wid() : options.first_wid;
        if (!is_wid(first_wid)) {
            throw new Error('Pool_Table first_wid must be an integer in [0, ' + WID_MODULUS + '), got ' + first_wid);
        }
        // The wid the NEXT join is offered (before skipping any in use). One counter for every
        // pool, so a wid is unique daemon-wide.
        this.next_wid = first_wid;

        // name -> { name, holder: conn_id|null, holder_since, queue: [{conn_id, req, since}],
        //           members: Map<wid, {conn_id, since}> }
        this.pools = new Map();

        this.counters = {
            granted: 0,
            released: 0,
            joined: 0,
            left: 0,
            dropped_members: 0,
        };
    }

    // ---- Ops (one frame per request) --------------------------------------------------

    /** Take the pool lock. Returns the granted frame now, or null when parked (FIFO). */
    lock(conn_id, req) {
        const name = this._pool_name(req);
        if (name.error) return name.error;

        const pool = this._ensure_pool(name.value);

        if (pool.holder === conn_id) {
            return this._error(req, name.value, 'Connection already holds pool lock ' + name.value);
        }
        if (pool.queue.some((entry) => entry.conn_id === conn_id)) {
            return this._error(req, name.value, 'Connection is already waiting for pool lock ' + name.value);
        }

        if (pool.holder === null && pool.queue.length === 0) {
            return this._grant(pool, conn_id, req);
        }

        // Waiting is silence: no frame until granted, or until release_all cancels it.
        pool.queue.push({ conn_id: conn_id, req: req, since: this.now() });
        return null;
    }

    unlock(conn_id, req) {
        const found = this._held_pool(conn_id, req, 'pool.unlock');
        if (found.error) return found.error;

        this._release(found.pool);
        this._gc(found.pool);

        return { id: req.id, status: protocol.STATUS_OK, pool: found.pool.name };
    }

    join(conn_id, req) {
        const found = this._held_pool(conn_id, req, 'pool.join');
        if (found.error) return found.error;
        const pool = found.pool;

        if (this._wid_of(pool, conn_id) !== null) {
            return this._error(req, pool.name, 'Connection is already a member of pool ' + pool.name);
        }

        const wid = this._next_free_wid();
        pool.members.set(wid, { conn_id: conn_id, since: this.now() });
        this.counters.joined++;

        return { id: req.id, status: protocol.STATUS_OK, pool: pool.name, wid: wid, generation: this.generation };
    }

    leave(conn_id, req) {
        const found = this._held_pool(conn_id, req, 'pool.leave');
        if (found.error) return found.error;
        const pool = found.pool;

        const wid = this._wid_of(pool, conn_id);
        if (wid === null) {
            return this._error(req, pool.name, 'Connection is not a member of pool ' + pool.name);
        }

        pool.members.delete(wid);
        this.counters.left++;

        return { id: req.id, status: protocol.STATUS_OK, pool: pool.name, wid: wid };
    }

    /** Members of the pool, EXCLUDING the caller when the caller is one. Lock required. */
    count(conn_id, req) {
        const found = this._held_pool(conn_id, req, 'pool.count');
        if (found.error) return found.error;
        const pool = found.pool;

        const self = this._wid_of(pool, conn_id) !== null ? 1 : 0;

        return { id: req.id, status: protocol.STATUS_OK, pool: pool.name, members: pool.members.size - self };
    }

    /**
     * Is the worker (wid, generation) still a member of this pool? Lock required.
     * `known: false` (and `alive: false`) when the generation is not this daemon's: a
     * previous daemon's worker may or may not still be running, and only the caller's own
     * evidence (its pid, on its host) can say.
     */
    member_alive(conn_id, req) {
        const found = this._held_pool(conn_id, req, 'pool.member_alive');
        if (found.error) return found.error;
        const pool = found.pool;

        const invalid = this._invalid_worker(req);
        if (invalid !== null) {
            return this._error(req, pool.name, 'pool.member_alive ' + invalid);
        }

        return Object.assign(
            { id: req.id, status: protocol.STATUS_OK, pool: pool.name },
            this._liveness(pool, req.wid, req.generation)
        );
    }

    /**
     * member_alive for many workers in one round trip: `items` is [{wid, generation}], the
     * answer's `results` is one {wid, generation, alive, known} per item, in order. Lock
     * required. One malformed item refuses the whole request, answering nothing partial.
     */
    members_alive(conn_id, req) {
        const found = this._held_pool(conn_id, req, 'pool.members_alive');
        if (found.error) return found.error;
        const pool = found.pool;

        if (!Array.isArray(req.items)) {
            return this._error(req, pool.name, 'pool.members_alive requires an items array of {wid, generation}');
        }
        for (let index = 0; index < req.items.length; index++) {
            const item = req.items[index];
            const invalid = item !== null && typeof item === 'object' ? this._invalid_worker(item) : 'requires an object';
            if (invalid !== null) {
                return this._error(req, pool.name, 'pool.members_alive item ' + index + ' ' + invalid);
            }
        }

        return {
            id: req.id,
            status: protocol.STATUS_OK,
            pool: pool.name,
            results: req.items.map((item) => this._liveness(pool, item.wid, item.generation)),
        };
    }

    /**
     * Read-only, unlocked snapshot for health rows and dashboards. With a `pool` name it
     * answers for that pool (an unknown pool is simply empty); without one it lists every
     * live pool.
     */
    stats(req) {
        if (req.pool !== undefined && req.pool !== null) {
            const name = this._pool_name(req);
            if (name.error) return name.error;
            return Object.assign(
                { id: req.id, status: protocol.STATUS_OK, generation: this.generation },
                this._summary(name.value, this.pools.get(name.value))
            );
        }

        const pools = [];
        for (const [name, pool] of this.pools) {
            pools.push(this._summary(name, pool));
        }
        return { id: req.id, status: protocol.STATUS_OK, generation: this.generation, pools: pools };
    }

    // ---- Connection lifecycle -----------------------------------------------------------

    /**
     * The connection is gone, or asked to drop everything (release_all). Remove its
     * membership in every pool, release every pool lock it holds and grant the next FIFO
     * waiter, and remove its queued waits.
     *
     * `options.cancel_frame(entry)`: when given, each removed wait is answered with the
     * frame it returns (release_all - the connection is alive and a parked request must
     * not be abandoned). Absent, removed waits get nothing (the socket is gone).
     */
    drop_connection(conn_id, options = {}) {
        const result = { locks_released: 0, members_removed: 0, waits_cancelled: 0 };

        for (const pool of Array.from(this.pools.values())) {
            for (const [wid, member] of Array.from(pool.members)) {
                if (member.conn_id === conn_id) {
                    pool.members.delete(wid);
                    result.members_removed++;
                    this.counters.dropped_members++;
                }
            }

            const kept = [];
            for (const entry of pool.queue) {
                if (entry.conn_id !== conn_id) {
                    kept.push(entry);
                    continue;
                }
                result.waits_cancelled++;
                if (options.cancel_frame) {
                    this.deliver(conn_id, options.cancel_frame(entry, pool.name));
                }
            }
            pool.queue = kept;

            if (pool.holder === conn_id) {
                result.locks_released++;
                this._release(pool);
            }

            this._gc(pool);
        }

        return result;
    }

    /** Full state for the dump op. */
    dump() {
        const now = this.now();
        const pools = [];
        for (const [name, pool] of this.pools) {
            pools.push({
                pool: name,
                holder: pool.holder,
                held_ms: pool.holder === null ? null : now - pool.holder_since,
                queue: pool.queue.map((entry) => ({ conn_id: entry.conn_id, waiting_ms: now - entry.since })),
                members: Array.from(pool.members.entries()).map(([wid, member]) => ({
                    wid: wid,
                    conn_id: member.conn_id,
                    member_ms: now - member.since,
                })),
            });
        }
        return pools;
    }

    /** What one connection holds, is a member of, and waits for, across every pool. */
    connection_view(conn_id) {
        const view = [];
        for (const [name, pool] of this.pools) {
            const holds_lock = pool.holder === conn_id;
            const wid = this._wid_of(pool, conn_id);
            const waiting = pool.queue.some((entry) => entry.conn_id === conn_id);
            if (holds_lock || wid !== null || waiting) {
                view.push({ pool: name, holds_lock: holds_lock, wid: wid, waiting: waiting });
            }
        }
        return view;
    }

    // ---- Internals ----------------------------------------------------------------------

    _summary(name, pool) {
        return {
            pool: name,
            members: pool ? pool.members.size : 0,
            holder: pool ? pool.holder !== null : false,
            waiting: pool ? pool.queue.length : 0,
        };
    }

    _error(req, pool_name, message) {
        const frame = { id: req ? req.id : undefined, status: protocol.STATUS_ERROR, message: message };
        if (pool_name) frame.pool = pool_name;
        return frame;
    }

    _pool_name(req) {
        const name = req.pool;
        if (typeof name !== 'string' || !POOL_NAME_PATTERN.test(name)) {
            return {
                error: this._error(req, null, "Pool ops require a 'pool' name of 1-128 chars of [A-Za-z0-9_.:-]"),
            };
        }
        return { value: name };
    }

    /** The named pool, provided the caller holds its lock; otherwise an error frame. */
    _held_pool(conn_id, req, op) {
        const name = this._pool_name(req);
        if (name.error) return { error: name.error };

        const pool = this.pools.get(name.value);
        if (!pool || pool.holder !== conn_id) {
            return {
                error: this._error(req, name.value, op + ' requires holding pool lock ' + name.value
                    + ' (send pool.lock first)'),
            };
        }
        return { pool: pool };
    }

    _wid_of(pool, conn_id) {
        for (const [wid, member] of pool.members) {
            if (member.conn_id === conn_id) return wid;
        }
        return null;
    }

    /**
     * Take the counter's next wid, skipping any a live member of ANY pool holds (invariant
     * 5). Live members number a handful, so the skip loop ends after a few steps; the scan
     * per step is over every pool's member set, which is equally small.
     */
    _next_free_wid() {
        let wid = this.next_wid;
        while (this._wid_in_use(wid)) {
            wid = (wid + 1) % WID_MODULUS;
        }
        this.next_wid = (wid + 1) % WID_MODULUS;
        return wid;
    }

    _wid_in_use(wid) {
        for (const pool of this.pools.values()) {
            if (pool.members.has(wid)) return true;
        }
        return false;
    }

    /** null when {wid, generation} is well-formed, else the reason (for an error frame). */
    _invalid_worker(source) {
        if (!is_wid(source.wid)) {
            return 'requires a wid, an integer in [0, ' + WID_MODULUS + ')';
        }
        if (!is_generation(source.generation)) {
            return 'requires a generation, a positive safe integer';
        }
        return null;
    }

    _liveness(pool, wid, generation) {
        const known = generation === this.generation;
        return {
            wid: wid,
            generation: generation,
            alive: known && pool.members.has(wid),
            known: known,
        };
    }

    _ensure_pool(name) {
        let pool = this.pools.get(name);
        if (!pool) {
            pool = { name: name, holder: null, holder_since: null, queue: [], members: new Map() };
            this.pools.set(name, pool);
        }
        return pool;
    }

    _grant(pool, conn_id, req) {
        pool.holder = conn_id;
        pool.holder_since = this.now();
        this.counters.granted++;
        return { id: req.id, status: protocol.STATUS_GRANTED, pool: pool.name };
    }

    /** Release the lock and hand it to the head waiter, delivering its granted frame. */
    _release(pool) {
        pool.holder = null;
        pool.holder_since = null;
        this.counters.released++;

        if (pool.queue.length > 0) {
            const entry = pool.queue.shift();
            this.deliver(entry.conn_id, this._grant(pool, entry.conn_id, entry.req));
        }
    }

    /** Drop an empty pool record so a box that has seen many pool names does not leak. */
    _gc(pool) {
        if (pool.holder === null && pool.queue.length === 0 && pool.members.size === 0) {
            this.pools.delete(pool.name);
        }
    }
}

module.exports = { Pool_Table, POOL_NAME_PATTERN, WID_MODULUS, WID_FIRST_RANGE };
