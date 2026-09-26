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
 * Pure state manipulation, same style as locktable.js: no sockets, no logging, no timers.
 * A parked pool.lock that is later granted is answered through the injected
 * `deliver(conn_id, frame)`, and member ids come from the injected `random_id()`, so a
 * test drives the whole machine with a collector array and a deterministic id source.
 *
 * INVARIANTS a change must not break:
 *   1. Every op answers exactly ONE frame echoing the request id. pool.lock answers when
 *      granted (possibly later, via deliver); every other op answers immediately. An error
 *      is an answer too.
 *   2. FIFO. One queue per pool; the lock goes to the head waiter only.
 *   3. drop_connection() removes the connection's memberships in EVERY pool, releases
 *      every pool lock it holds (granting the next waiter), and removes its queued waits.
 *      Lock_Table.drop_connection() and release_all() both call it.
 *   4. A member id is minted from randomness, never derived from a connection id: those
 *      restart at c1 whenever the daemon restarts, so a recycled id would make a dead
 *      worker's persisted member id answer "alive".
 */

const crypto = require('crypto');

const protocol = require('./protocol');

// Pool names are printed in dump output and log lines and are chosen by the client, so
// they are held to a printable, bounded charset. Not a security control - the HMAC key is.
const POOL_NAME_PATTERN = /^[A-Za-z0-9_.:-]{1,128}$/;

// Member ids are minted here and only ever echoed back, but member_alive takes one from
// the client, so the same bound applies on the way in.
const MEMBER_ID_PATTERN = /^[A-Za-z0-9_.:-]{1,128}$/;

function default_random_id() {
    return 'pm_' + crypto.randomBytes(16).toString('hex');
}

class Pool_Table {
    constructor(options = {}) {
        this.deliver = options.deliver || function () {};
        this.now = options.now || function () { return Date.now(); };
        this.random_id = options.random_id || default_random_id;

        // name -> { name, holder: conn_id|null, holder_since, queue: [{conn_id, req, since}],
        //           members: Map<member_id, {conn_id, since}> }
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

        if (this._member_id_of(pool, conn_id) !== null) {
            return this._error(req, pool.name, 'Connection is already a member of pool ' + pool.name);
        }

        let member_id = this.random_id();
        // A collision in 128 random bits does not happen; this loop exists so an injected
        // test id source that repeats cannot silently alias two members.
        while (pool.members.has(member_id)) {
            member_id = this.random_id();
        }

        pool.members.set(member_id, { conn_id: conn_id, since: this.now() });
        this.counters.joined++;

        return { id: req.id, status: protocol.STATUS_OK, pool: pool.name, member_id: member_id };
    }

    leave(conn_id, req) {
        const found = this._held_pool(conn_id, req, 'pool.leave');
        if (found.error) return found.error;
        const pool = found.pool;

        const member_id = this._member_id_of(pool, conn_id);
        if (member_id === null) {
            return this._error(req, pool.name, 'Connection is not a member of pool ' + pool.name);
        }

        pool.members.delete(member_id);
        this.counters.left++;

        return { id: req.id, status: protocol.STATUS_OK, pool: pool.name, member_id: member_id };
    }

    /** Members of the pool, EXCLUDING the caller when the caller is one. Lock required. */
    count(conn_id, req) {
        const found = this._held_pool(conn_id, req, 'pool.count');
        if (found.error) return found.error;
        const pool = found.pool;

        const self = this._member_id_of(pool, conn_id) !== null ? 1 : 0;

        return { id: req.id, status: protocol.STATUS_OK, pool: pool.name, members: pool.members.size - self };
    }

    /** Is this member id still a member of this pool? Lock required. */
    member_alive(conn_id, req) {
        const found = this._held_pool(conn_id, req, 'pool.member_alive');
        if (found.error) return found.error;
        const pool = found.pool;

        const member_id = req.member_id;
        if (typeof member_id !== 'string' || !MEMBER_ID_PATTERN.test(member_id)) {
            return this._error(req, pool.name, 'pool.member_alive requires a member_id of 1-128 chars of [A-Za-z0-9_.:-]');
        }

        return {
            id: req.id,
            status: protocol.STATUS_OK,
            pool: pool.name,
            member_id: member_id,
            alive: pool.members.has(member_id),
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
                { id: req.id, status: protocol.STATUS_OK },
                this._summary(name.value, this.pools.get(name.value))
            );
        }

        const pools = [];
        for (const [name, pool] of this.pools) {
            pools.push(this._summary(name, pool));
        }
        return { id: req.id, status: protocol.STATUS_OK, pools: pools };
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
            for (const [member_id, member] of Array.from(pool.members)) {
                if (member.conn_id === conn_id) {
                    pool.members.delete(member_id);
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
                members: Array.from(pool.members.entries()).map(([member_id, member]) => ({
                    member_id: member_id,
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
            const member_id = this._member_id_of(pool, conn_id);
            const waiting = pool.queue.some((entry) => entry.conn_id === conn_id);
            if (holds_lock || member_id !== null || waiting) {
                view.push({ pool: name, holds_lock: holds_lock, member_id: member_id, waiting: waiting });
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

    _member_id_of(pool, conn_id) {
        for (const [member_id, member] of pool.members) {
            if (member.conn_id === conn_id) return member_id;
        }
        return null;
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

module.exports = { Pool_Table, POOL_NAME_PATTERN, MEMBER_ID_PATTERN };
