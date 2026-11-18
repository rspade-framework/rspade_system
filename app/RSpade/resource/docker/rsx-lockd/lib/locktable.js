/**
 * rsx-lockd lock state machine.
 *
 * THE MODEL: the connection is the lock. A lock is held from the moment it is granted
 * until the holder releases it or its connection goes away. There is no lease, no TTL and
 * no renewal: nothing here ever takes a lock away from a holder. The clocks present are all
 * on WAITERS, and each is one the waiter's request asked for:
 *
 *   - `timeout`  the caller's own wait budget (answered `timeout`);
 *   - `stall`    the progress watchdog: a waiter whose holders have shown no progress for
 *                this many seconds is answered `stalled` (see _check_stall());
 *   - `notice`   one `waiting` notice frame after this many seconds, the wait continuing.
 *
 * PROGRESS is what a holder shows by being granted (a fresh holder is progressing) or by
 * sending a `progress` frame (a keep-alive covering everything its connection holds). It
 * never extends, renews or bounds a HOLD - a silent holder keeps its lock for as long as
 * its connection lives; only the waiters behind it are told, by name, that it went silent.
 *
 * Everything here is pure state manipulation. No sockets, no logging, no process globals.
 * Delivery of a deferred answer (a waiter that later gets granted, times out, or is
 * cancelled) goes through the injected `deliver(conn_id, frame)` callback, and timers go
 * through injected set_timeout/clear_timeout, so a test can drive the whole machine
 * deterministically with a fake clock and a collector array.
 *
 * INVARIANTS a change must not break:
 *   1. FIFO. One queue per lock name. Grants come off the head only; nothing bypasses a
 *      queued waiter, ever. Consecutive readers at the head grant as one batch.
 *   2. A waiter receives NO frame until it is granted, times out, is cancelled, or its
 *      connection dies. Waiting is silence.
 *   3. drop_connection() releases every hold and removes every wait for that connection,
 *      then re-runs grants. This is the entire correctness model for crashes.
 *   4. Deadlock is refused at ENQUEUE time, before parking. Wait-forever is the default,
 *      so an undetected cycle is a permanent hang.
 */

const protocol = require('./protocol');
const { Pool_Table } = require('./pool');

const MODE_READ = protocol.MODE_READ;
const MODE_WRITE = protocol.MODE_WRITE;

// Hard ceiling on wait-for graph expansion during one deadlock probe. The graph is one
// node per connected client, so this is unreachable in practice; it exists so a
// pathological state can never turn one enqueue into an unbounded walk.
const MAX_GRAPH_EXPANSIONS = 100000;

class Lock_Table {
    constructor(options = {}) {
        this.deliver = options.deliver || function () {};
        this.now = options.now || function () { return Date.now(); };
        this.set_timeout = options.set_timeout || function (fn, ms) { return setTimeout(fn, ms); };
        this.clear_timeout = options.clear_timeout || function (handle) { return clearTimeout(handle); };

        // Event logging, INJECTED so this module stays pure (CLAUDE.md: no side effects -
        // delivery and timers are injected, and so is this). A no-op by default, which is
        // what lets a unit test drive the whole state machine with no sink at all.
        //
        // Signature: log(build, level). `build` is a FUNCTION returning the line, so an
        // event nobody is recording costs one closure and no string work - this runs on
        // every acquire. A sink calls build() synchronously, inside the log() call, or not
        // at all: the line describes the table as it is at that instant. `level` is
        // 'verbose' for per-operation events and absent for the contention events worth
        // leaving on permanently.
        this.log = options.log || function () {};

        // name -> { name, holders: Map<conn_id, {mode, token, since}>, queue: [entry] }
        this.locks = new Map();

        // name -> { name, max_slots, holders: Map<token, {conn_id, slot, since}>, queue: [entry] }
        this.semaphores = new Map();

        // conn_id -> { conn_id, group_id, meta, since, tokens: Set<token>, waits: [entry] }
        this.connections = new Map();

        // token -> { kind: 'lock'|'sem', conn_id, name, mode?, slot? }
        this.tokens = new Map();

        this.token_seq = 0;
        this.waiter_seq = 0;

        // Worker pool accounting (lib/pool.js). A separate, bespoke state machine - owned
        // here only so that drop_connection() and release_all() cannot forget it
        // (invariant 1). Its waits never enter conn.waits, so the deadlock detector never
        // walks them; see CLAUDE.md, "Worker pools".
        this.pool = options.pool || new Pool_Table({
            deliver: (conn_id, frame) => this.deliver(conn_id, frame),
            now: () => this.now(),
            generation: options.pool_generation,
            first_wid: options.pool_first_wid,
        });

        // Lifetime counters, reported by stats/dump. Cheap and invaluable when a box is
        // behaving oddly at 3am.
        this.counters = {
            granted: 0,
            released: 0,
            timed_out: 0,
            stalled: 0,
            deadlocked: 0,
            dropped_connections: 0,
        };
    }

    // ---- Connections ----------------------------------------------------------------

    register_connection(conn_id, meta) {
        this.connections.set(conn_id, {
            conn_id: conn_id,
            // A connection with no declared group is its own group. That makes "the
            // connection is the lock" the DEFAULT and group inheritance the opt-in, so
            // every existing client keeps byte-identical behavior.
            group_id: (meta && meta.group_id) || conn_id,
            meta: meta || {},
            since: this.now(),
            // The last `progress` frame this connection sent, or null. A hold's own grant
            // time is progress too, so a connection that never sends one is still a
            // progressing holder for its first stall window.
            progress_at: null,
            tokens: new Set(),
            waits: [],
        });
    }

    set_connection_meta(conn_id, meta) {
        const conn = this.connections.get(conn_id);
        if (!conn) return;
        conn.meta = Object.assign({}, conn.meta, meta || {});
        if (meta && meta.group_id) {
            conn.group_id = meta.group_id;
        }
    }

    /** The group a connection belongs to; an unknown connection is its own group. */
    _group_of(conn_id) {
        const conn = this.connections.get(conn_id);
        return conn ? conn.group_id : conn_id;
    }

    /**
     * Strongest mode this group already holds on this lock, or null.
     *
     * A GROUP is one process tree: a parent that spawned a synchronous subprocess and is
     * blocked waiting for it. Without this, the child queues behind its own parent and the
     * two wait on each other forever - a cycle the deadlock detector structurally cannot
     * see, because the parent's half of it is an OS waitpid, not a lock wait.
     */
    _group_hold_mode(lock, group_id) {
        let mode = null;
        for (const [holder_conn_id, holder] of lock.holders) {
            if (this._group_of(holder_conn_id) !== group_id) continue;
            if (holder.mode === MODE_WRITE) return MODE_WRITE;
            mode = MODE_READ;
        }
        return mode;
    }

    /**
     * The connection is gone (clean close, crash, kill -9, or a dead TCP peer detected by
     * keepalive). Release EVERYTHING it held, remove it from every queue, then grant what
     * that unblocks. This is the whole reason this daemon exists, so it is deliberately
     * exhaustive and order-independent: holds first, then waits, then one grant pass over
     * every touched lock.
     */
    drop_connection(conn_id) {
        const conn = this.connections.get(conn_id);
        if (!conn) {
            return { released: 0, cancelled: 0, pool: this.pool.drop_connection(conn_id) };
        }

        const touched_locks = new Set();
        const touched_semaphores = new Set();
        let released = 0;

        // Holds.
        for (const token of Array.from(conn.tokens)) {
            const record = this.tokens.get(token);
            if (!record) continue;
            if (record.kind === 'lock') {
                const lock = this.locks.get(record.name);
                if (lock && lock.holders.delete(conn_id)) {
                    released++;
                    touched_locks.add(lock);
                }
            } else {
                const semaphore = this.semaphores.get(record.name);
                if (semaphore && semaphore.holders.delete(token)) {
                    released++;
                    touched_semaphores.add(semaphore);
                }
            }
            this.tokens.delete(token);
        }
        conn.tokens.clear();

        // Waits. The waiter gets no frame - its socket is gone.
        let cancelled = 0;
        for (const entry of Array.from(conn.waits)) {
            this._remove_waiter(entry, false);
            cancelled++;
            if (entry.kind === 'lock') {
                const lock = this.locks.get(entry.name);
                if (lock) touched_locks.add(lock);
            } else {
                const semaphore = this.semaphores.get(entry.name);
                if (semaphore) touched_semaphores.add(semaphore);
            }
        }

        // A DISCONNECT THAT RELEASED SOMETHING. "The connection is the lock", so this is
        // how a crashed or killed holder lets go - and from the outside it is
        // indistinguishable from an orderly release, which is exactly why it is worth a
        // line. Silent when the connection held and wanted nothing, which is almost every
        // disconnect.
        //
        // Logged BEFORE the grants below run, so the file reads in causal order: the drop
        // is the cause and the GRANT lines it produces are the effect. Written the other
        // way round the log claims a lock was granted before the holder let go of it.
        if (released > 0 || cancelled > 0) {
            this.log(() => 'DROP ' + this._describe_conn(conn_id, conn)
                + ' released ' + released + ' hold(s), cancelled ' + cancelled + ' wait(s)');
        }

        this.connections.delete(conn_id);
        this.counters.dropped_connections++;
        this.counters.released += released;

        for (const lock of touched_locks) this._run_grants(lock);
        for (const semaphore of touched_semaphores) this._run_semaphore_grants(semaphore);

        this._gc_locks(touched_locks);
        this._gc_semaphores(touched_semaphores);

        // Pool membership, the pool lock and queued pool waits die with the connection too.
        // The socket is gone, so removed pool waits are answered with nothing.
        const pool = this.pool.drop_connection(conn_id);

        return { released: released, cancelled: cancelled, pool: pool };
    }

    // ---- Locks ----------------------------------------------------------------------

    /**
     * Request a lock. Returns a frame to send back IMMEDIATELY, or null when the request
     * parked - in which case the answer arrives later through deliver().
     *
     * req: { id?, name, mode, timeout }
     */
    acquire(conn_id, req) {
        const conn = this.connections.get(conn_id);
        if (!conn) {
            return this._error(req, 'Unknown connection');
        }

        const name = this._normalize_name(req.name);
        if (!name) {
            return this._error(req, 'acquire requires a non-empty string name');
        }

        const mode = protocol.normalize_mode(req.mode);
        if (!mode) {
            return this._error(req, "acquire mode must be 'read' or 'write'");
        }

        const timeout = protocol.normalize_timeout(req.timeout);
        if (!timeout.ok) {
            return this._error(req, 'acquire timeout must be null or a non-negative number of seconds');
        }

        const watch = this._normalize_watch(req, 'acquire');
        if (watch.error) {
            return this._error(req, watch.error);
        }

        const lock = this._ensure_lock(name);

        // Reentrancy is the CLIENT's job (the PHP client keeps the counts, so a nested
        // acquire never leaves the process). The server therefore sees at most one acquire
        // per connection per lock; a second one is a client bug. Refuse it loudly rather
        // than corrupting the holder set - and name the upgrade path, since read-then-write
        // is the mistake this catches most often.
        if (lock.holders.has(conn_id)) {
            const held_mode = lock.holders.get(conn_id).mode;
            const hint = (held_mode === MODE_READ && mode === MODE_WRITE)
                ? " - use op 'upgrade' to convert a held READ lock to WRITE"
                : '';
            return this._error(
                req,
                'Connection already holds ' + held_mode.toUpperCase() + ' lock ' + name + hint
            );
        }
        if (this._find_wait(conn, 'lock', name)) {
            return this._error(req, 'Connection is already waiting for lock ' + name);
        }

        // GROUP INHERITANCE - and note it deliberately bypasses the FIFO queue below.
        //
        // This connection's group already holds the lock, which means the holder is this
        // connection's parent, blocked in waitpid on us. Making us queue would be the exact
        // deadlock this exists to prevent, and queueing behind a FOREIGN waiter would
        // deadlock just as hard - that waiter cannot be granted until the parent releases,
        // and the parent cannot release until we exit.
        //
        // Bypassing costs the foreign waiter nothing: the group already excluded it, and
        // adding a second same-group holder does not extend that exclusion by one lock
        // acquisition - it ends when the parent releases either way.
        //
        // Read-held + write-wanted is NOT inherited: that is a cross-process upgrade, and
        // inventing one here would let a group hold read and write at once. Such a parent
        // should take the WRITE lock before it spawns. See README (LOCK GROUPS).
        const group_mode = this._group_hold_mode(lock, conn.group_id);
        if (group_mode === MODE_WRITE || (group_mode === MODE_READ && mode === MODE_READ)) {
            return this._grant_lock(lock, conn, mode, req);
        }

        // Strict FIFO: an empty queue is a precondition for an immediate grant. A grantable
        // request that jumped a queued waiter would starve writers.
        if (lock.queue.length === 0 && this._can_grant(lock, mode)) {
            return this._grant_lock(lock, conn, mode, req);
        }

        const cycle = this._detect_deadlock(conn_id, { kind: 'lock', name: name, mode: mode });
        if (cycle) {
            this.counters.deadlocked++;
            this._log_deadlock(conn_id, conn, 'acquiring ' + mode.toUpperCase() + ' ' + name, cycle);
            return {
                id: req.id,
                status: protocol.STATUS_DEADLOCK,
                name: name,
                mode: mode,
                message: 'Deadlock: acquiring ' + mode.toUpperCase() + ' lock ' + name
                    + ' would close a wait-for cycle',
                cycle: cycle,
            };
        }

        this._enqueue(lock, conn, {
            kind: 'lock',
            name: name,
            mode: mode,
            timeout: timeout.value,
            stall: watch.stall,
            notice: watch.notice,
            req: req,
        });

        return null;
    }

    /**
     * Release a held lock, by token or by name (+ optional mode). Always answers
     * immediately. `held` is false when this connection did not hold it - which is how a
     * client learns the daemon restarted under it.
     */
    release(conn_id, req) {
        const conn = this.connections.get(conn_id);
        if (!conn) {
            return this._error(req, 'Unknown connection');
        }

        let name = null;
        if (req.token !== undefined && req.token !== null) {
            const record = this.tokens.get(req.token);
            if (!record || record.conn_id !== conn_id || record.kind !== 'lock') {
                return { id: req.id, status: protocol.STATUS_OK, held: false };
            }
            name = record.name;
        } else {
            name = this._normalize_name(req.name);
            if (!name) {
                return this._error(req, 'release requires a token or a non-empty string name');
            }
        }

        const lock = this.locks.get(name);
        if (!lock || !lock.holders.has(conn_id)) {
            return { id: req.id, status: protocol.STATUS_OK, name: name, held: false };
        }

        const holder = lock.holders.get(conn_id);
        if (req.mode !== undefined && req.mode !== null) {
            const mode = protocol.normalize_mode(req.mode);
            if (mode && mode !== holder.mode) {
                return { id: req.id, status: protocol.STATUS_OK, name: name, held: false };
            }
        }

        this._release_hold(lock, conn, holder);
        this._run_grants(lock);
        this._gc_locks([lock]);

        return { id: req.id, status: protocol.STATUS_OK, name: name, held: true };
    }

    /**
     * Drop every hold and every wait belonging to this connection, without closing it.
     * The PHP client sends this at end of script; correctness does not depend on the frame
     * arriving, because the socket close does the same thing.
     */
    release_all(conn_id, req) {
        const conn = this.connections.get(conn_id);
        if (!conn) {
            return this._error(req, 'Unknown connection');
        }

        const touched_locks = new Set();
        const touched_semaphores = new Set();
        let released = 0;

        for (const token of Array.from(conn.tokens)) {
            const record = this.tokens.get(token);
            if (!record) continue;
            if (record.kind === 'lock') {
                const lock = this.locks.get(record.name);
                if (lock && lock.holders.delete(conn_id)) {
                    released++;
                    touched_locks.add(lock);
                }
            } else {
                const semaphore = this.semaphores.get(record.name);
                if (semaphore && semaphore.holders.delete(token)) {
                    released++;
                    touched_semaphores.add(semaphore);
                }
            }
            this.tokens.delete(token);
        }
        conn.tokens.clear();
        this.counters.released += released;

        if (released > 0) {
            this.log(() => 'RELEASE_ALL by ' + this._describe_conn(conn_id, conn)
                + ' released ' + released + ' hold(s)', 'verbose');
        }

        // A connection that is waiting cannot also be sending release_all in the PHP
        // client (it is synchronous), but the protocol permits it. Answer the parked
        // request rather than abandoning it - silence is reserved for "still waiting".
        let cancelled = 0;
        for (const entry of Array.from(conn.waits)) {
            const parked = entry.req;
            this._remove_waiter(entry, false);
            cancelled++;
            if (entry.kind === 'lock') {
                const lock = this.locks.get(entry.name);
                if (lock) touched_locks.add(lock);
            } else {
                const semaphore = this.semaphores.get(entry.name);
                if (semaphore) touched_semaphores.add(semaphore);
            }
            this.deliver(conn_id, {
                id: parked.id,
                status: protocol.STATUS_ERROR,
                name: entry.name,
                message: 'Wait cancelled by release_all',
            });
        }

        for (const lock of touched_locks) this._run_grants(lock);
        for (const semaphore of touched_semaphores) this._run_semaphore_grants(semaphore);
        this._gc_locks(touched_locks);
        this._gc_semaphores(touched_semaphores);

        // Pool state too (invariant 1). The connection is alive, so each parked pool.lock is
        // answered with an error frame exactly as the parked lock waits above are.
        const pool = this.pool.drop_connection(conn_id, {
            cancel_frame: (entry, pool_name) => ({
                id: entry.req.id,
                status: protocol.STATUS_ERROR,
                pool: pool_name,
                message: 'Wait cancelled by release_all',
            }),
        });

        return {
            id: req.id,
            status: protocol.STATUS_OK,
            released: released,
            cancelled: cancelled,
            pool: pool,
        };
    }

    /**
     * Convert a held READ lock to WRITE. NOT atomic, by design and matching the semantics
     * this replaces: the read hold is dropped FIRST and a write request joins the TAIL of
     * the queue like any other. A caller must therefore re-verify whatever it read before
     * the upgrade - another writer may have run in between.
     *
     * Dropping the read first is also what makes the classic upgrade deadlock impossible:
     * when two readers upgrade at once, each has already given up its read, so one is
     * granted and the other simply waits behind it. An atomic upgrade would hang both.
     * The deadlock check still runs, because an upgrade can close a cycle through OTHER
     * locks the connection holds.
     */
    upgrade(conn_id, req) {
        const conn = this.connections.get(conn_id);
        if (!conn) {
            return this._error(req, 'Unknown connection');
        }

        let name = null;
        if (req.token !== undefined && req.token !== null) {
            const record = this.tokens.get(req.token);
            if (!record || record.conn_id !== conn_id || record.kind !== 'lock') {
                return this._error(req, 'upgrade token is not a lock held by this connection');
            }
            name = record.name;
        } else {
            name = this._normalize_name(req.name);
            if (!name) {
                return this._error(req, 'upgrade requires a token or a non-empty string name');
            }
        }

        const timeout = protocol.normalize_timeout(req.timeout);
        if (!timeout.ok) {
            return this._error(req, 'upgrade timeout must be null or a non-negative number of seconds');
        }

        const watch = this._normalize_watch(req, 'upgrade');
        if (watch.error) {
            return this._error(req, watch.error);
        }

        const lock = this.locks.get(name);
        const holder = lock ? lock.holders.get(conn_id) : null;
        if (!holder) {
            return this._error(req, 'Connection does not hold lock ' + name);
        }

        // Already exclusive: a no-op that hands back the same token, so a caller can call
        // upgrade unconditionally.
        if (holder.mode === MODE_WRITE) {
            return {
                id: req.id,
                status: protocol.STATUS_GRANTED,
                name: name,
                mode: MODE_WRITE,
                token: holder.token,
                upgraded: false,
            };
        }

        this._release_hold(lock, conn, holder);

        if (lock.queue.length === 0 && this._can_grant(lock, MODE_WRITE)) {
            const frame = this._grant_lock(lock, conn, MODE_WRITE, req);
            frame.upgraded = true;
            return frame;
        }

        const cycle = this._detect_deadlock(conn_id, { kind: 'lock', name: name, mode: MODE_WRITE });
        if (cycle) {
            // The read hold is already gone. Put the connection back where it was so a
            // refused upgrade does not silently downgrade it to holding nothing.
            this._restore_hold(lock, conn, holder);
            this.counters.deadlocked++;
            this._log_deadlock(conn_id, conn, 'upgrading ' + name + ' to WRITE', cycle);
            return {
                id: req.id,
                status: protocol.STATUS_DEADLOCK,
                name: name,
                mode: MODE_WRITE,
                message: 'Deadlock: upgrading lock ' + name + ' would close a wait-for cycle',
                cycle: cycle,
            };
        }

        this._enqueue(lock, conn, {
            kind: 'lock',
            name: name,
            mode: MODE_WRITE,
            timeout: timeout.value,
            stall: watch.stall,
            notice: watch.notice,
            req: req,
            upgraded: true,
        });

        // Dropping the read hold may have unblocked a queued writer ahead of us.
        this._run_grants(lock);

        return null;
    }

    // ---- Semaphores -----------------------------------------------------------------

    /**
     * Take one of `max_slots` slots on a counting semaphore. max_slots <= 0 means
     * unlimited: granted immediately with slot null, so a caller never has to special-case
     * "no quota configured".
     */
    sem_acquire(conn_id, req) {
        const conn = this.connections.get(conn_id);
        if (!conn) {
            return this._error(req, 'Unknown connection');
        }

        const name = this._normalize_name(req.name);
        if (!name) {
            return this._error(req, 'sem_acquire requires a non-empty string name');
        }

        const max_slots = req.max_slots;
        if (!Number.isInteger(max_slots)) {
            return this._error(req, 'sem_acquire requires an integer max_slots');
        }

        const timeout = protocol.normalize_timeout(req.timeout);
        if (!timeout.ok) {
            return this._error(req, 'sem_acquire timeout must be null or a non-negative number of seconds');
        }

        const watch = this._normalize_watch(req, 'sem_acquire');
        if (watch.error) {
            return this._error(req, watch.error);
        }

        if (max_slots <= 0) {
            return {
                id: req.id,
                status: protocol.STATUS_GRANTED,
                name: name,
                token: this._issue_token(),
                slot: null,
                unlimited: true,
            };
        }

        const semaphore = this._ensure_semaphore(name, max_slots);
        // Last writer wins on the quota. The quota is a caller-supplied configuration
        // value, not daemon state; refusing a changed value would mean a config edit could
        // never take effect without a restart.
        semaphore.max_slots = max_slots;

        if (semaphore.queue.length === 0 && semaphore.holders.size < semaphore.max_slots) {
            return this._grant_semaphore(semaphore, conn, req);
        }

        const cycle = this._detect_deadlock(conn_id, { kind: 'sem', name: name });
        if (cycle) {
            this.counters.deadlocked++;
            this._log_deadlock(conn_id, conn, 'acquiring semaphore ' + name, cycle);
            return {
                id: req.id,
                status: protocol.STATUS_DEADLOCK,
                name: name,
                message: 'Deadlock: acquiring semaphore ' + name + ' would close a wait-for cycle',
                cycle: cycle,
            };
        }

        this._enqueue(semaphore, conn, {
            kind: 'sem',
            name: name,
            mode: null,
            timeout: timeout.value,
            stall: watch.stall,
            notice: watch.notice,
            req: req,
        });

        return null;
    }

    sem_release(conn_id, req) {
        const conn = this.connections.get(conn_id);
        if (!conn) {
            return this._error(req, 'Unknown connection');
        }

        if (req.token === undefined || req.token === null) {
            return this._error(req, 'sem_release requires a token');
        }

        const record = this.tokens.get(req.token);
        if (!record || record.conn_id !== conn_id || record.kind !== 'sem') {
            // Includes the unlimited sentinel, which never entered the tokens map.
            return { id: req.id, status: protocol.STATUS_OK, held: false };
        }

        const semaphore = this.semaphores.get(record.name);
        let held = false;
        if (semaphore && semaphore.holders.delete(req.token)) {
            held = true;
            this.counters.released++;
            this.log(() => 'RELEASE semaphore ' + record.name + ' slot ' + record.slot
                + ' by ' + this._describe_conn(conn_id, conn), 'verbose');
        }
        this.tokens.delete(req.token);
        conn.tokens.delete(req.token);

        if (semaphore) {
            this._run_semaphore_grants(semaphore);
            this._gc_semaphores([semaphore]);
        }

        return { id: req.id, status: protocol.STATUS_OK, name: record.name, held: held };
    }

    // ---- Progress -------------------------------------------------------------------

    /**
     * The holder says it is still working: a keep-alive covering every lock and semaphore
     * slot this connection holds (and any it takes later - the stamp belongs to the
     * connection). It changes nothing about any hold; it only restarts the stall window of
     * every waiter queued behind this connection, which those waiters' own checks read when
     * they fire. Answered at once, holding something or not.
     */
    progress(conn_id, req) {
        const conn = this.connections.get(conn_id);
        if (!conn) {
            return this._error(req, 'Unknown connection');
        }

        conn.progress_at = this.now();

        this.log(() => 'PROGRESS by ' + this._describe_conn(conn_id, conn)
            + ' holding ' + conn.tokens.size, 'verbose');

        return { id: req.id, status: protocol.STATUS_OK, holds: conn.tokens.size };
    }

    // ---- Introspection --------------------------------------------------------------

    stats(req) {
        const name = this._normalize_name(req.name);
        if (!name) {
            return this._error(req, 'stats requires a non-empty string name');
        }

        const lock = this.locks.get(name);
        const semaphore = this.semaphores.get(name);

        let readers_active = 0;
        let writer_active = false;
        let writer_conn = null;
        let readers_waiting = 0;
        let writers_waiting = 0;
        let queue_length = 0;

        if (lock) {
            for (const [conn_id, holder] of lock.holders) {
                if (holder.mode === MODE_WRITE) {
                    writer_active = true;
                    writer_conn = conn_id;
                } else {
                    readers_active++;
                }
            }
            for (const entry of lock.queue) {
                if (entry.mode === MODE_WRITE) writers_waiting++;
                else readers_waiting++;
            }
            queue_length = lock.queue.length;
        }

        const frame = {
            id: req.id,
            status: protocol.STATUS_OK,
            name: name,
            readers_active: readers_active,
            writer_active: writer_active,
            writer_conn: writer_conn,
            readers_waiting: readers_waiting,
            writers_waiting: writers_waiting,
            queue_length: queue_length,
            counters: Object.assign({}, this.counters),
            connections: this.connections.size,
            pools: this.pool.pools.size,
        };

        if (semaphore) {
            frame.semaphore = {
                max_slots: semaphore.max_slots,
                slots_used: semaphore.holders.size,
                waiting: semaphore.queue.length,
            };
        }

        return frame;
    }

    /** Full machine state: every connection with what it holds and waits for, per lock. */
    dump(req) {
        const now = this.now();

        const connections = [];
        for (const [conn_id, conn] of this.connections) {
            const holds = [];
            const semaphores = [];
            for (const token of conn.tokens) {
                const record = this.tokens.get(token);
                if (!record) continue;
                if (record.kind === 'lock') {
                    const lock = this.locks.get(record.name);
                    const holder = lock ? lock.holders.get(conn_id) : null;
                    if (!holder) continue;
                    holds.push({
                        name: record.name,
                        mode: holder.mode,
                        token: token,
                        held_ms: now - holder.since,
                        progress_ms: now - this._own_progress_at(conn, holder.since),
                    });
                } else {
                    const semaphore = this.semaphores.get(record.name);
                    const holder = semaphore ? semaphore.holders.get(token) : null;
                    if (!holder) continue;
                    semaphores.push({
                        name: record.name,
                        slot: holder.slot,
                        token: token,
                        held_ms: now - holder.since,
                        progress_ms: now - this._own_progress_at(conn, holder.since),
                    });
                }
            }

            const waiting = conn.waits.map((entry) => ({
                kind: entry.kind,
                name: entry.name,
                mode: entry.mode,
                timeout: entry.timeout,
                stall: entry.stall,
                notice: entry.notice,
                waiting_ms: now - entry.since,
            }));

            connections.push({
                conn_id: conn_id,
                // Null when this connection is its own group - the ordinary case. A
                // non-null value is the thing worth seeing in a dump: two connections
                // sharing one is a parent and the subprocess it is waiting on.
                group_id: conn.group_id === conn_id ? null : conn.group_id,
                host: conn.meta.host || null,
                pid: conn.meta.pid || null,
                // The process start time its hello carried (null when it sent none): with
                // host and pid, the identity an operator checks before signalling it.
                start: conn.meta.start === undefined ? null : conn.meta.start,
                remote: conn.meta.remote || null,
                connected_ms: now - conn.since,
                // Since this connection's last `progress` frame; null when it never sent one.
                progress_ms: conn.progress_at === null ? null : now - conn.progress_at,
                holds: holds,
                semaphores: semaphores,
                waiting: waiting,
                pools: this.pool.connection_view(conn_id),
            });
        }

        const locks = [];
        for (const [name, lock] of this.locks) {
            locks.push({
                name: name,
                holders: Array.from(lock.holders.entries()).map(([conn_id, holder]) => ({
                    conn_id: conn_id,
                    mode: holder.mode,
                    held_ms: now - holder.since,
                    progress_ms: now - this._own_progress_at(this.connections.get(conn_id), holder.since),
                })),
                queue: lock.queue.map((entry) => ({
                    conn_id: entry.conn_id,
                    mode: entry.mode,
                    timeout: entry.timeout,
                    stall: entry.stall,
                    notice: entry.notice,
                    waiting_ms: now - entry.since,
                })),
            });
        }

        const semaphores = [];
        for (const [name, semaphore] of this.semaphores) {
            semaphores.push({
                name: name,
                max_slots: semaphore.max_slots,
                holders: Array.from(semaphore.holders.entries()).map(([token, holder]) => ({
                    conn_id: holder.conn_id,
                    slot: holder.slot,
                    token: token,
                    held_ms: now - holder.since,
                    progress_ms: now - this._own_progress_at(this.connections.get(holder.conn_id), holder.since),
                })),
                queue: semaphore.queue.map((entry) => ({
                    conn_id: entry.conn_id,
                    timeout: entry.timeout,
                    stall: entry.stall,
                    notice: entry.notice,
                    waiting_ms: now - entry.since,
                })),
            });
        }

        return {
            id: req.id,
            status: protocol.STATUS_OK,
            connections: connections,
            locks: locks,
            semaphores: semaphores,
            pools: this.pool.dump(),
            counters: Object.assign({}, this.counters),
            pool_generation: this.pool.generation,
            pool_counters: Object.assign({}, this.pool.counters),
        };
    }

    /**
     * Operator escape hatch: drop every holder of a lock and let the queue proceed.
     * Waiters are NOT killed - the point is to unstick them. The evicted holders are told
     * nothing (they hold a token that will simply report held:false on release), which is
     * the honest outcome: force_clear is a declaration that those holders are wrong.
     */
    force_clear(req) {
        const name = this._normalize_name(req.name);
        if (!name) {
            return this._error(req, 'force_clear requires a non-empty string name');
        }

        let holders_cleared = 0;
        let slots_cleared = 0;

        const lock = this.locks.get(name);
        const semaphore = this.semaphores.get(name);

        // Somebody other than the holder let go of it. Logged before anything is cleared,
        // so the line can still name who held it, and before the grants the clear triggers.
        if ((lock && lock.holders.size > 0) || (semaphore && semaphore.holders.size > 0)) {
            this.log(() => 'FORCE_CLEAR ' + name + ' - was held by '
                + (lock && lock.holders.size > 0
                    ? this._describe_holders(lock)
                    : this._describe_slots(semaphore)));
        }

        if (lock) {
            for (const [conn_id, holder] of Array.from(lock.holders)) {
                const conn = this.connections.get(conn_id);
                if (conn) conn.tokens.delete(holder.token);
                this.tokens.delete(holder.token);
                lock.holders.delete(conn_id);
                holders_cleared++;
            }
            this._run_grants(lock);
            this._gc_locks([lock]);
        }

        if (semaphore) {
            for (const [token, holder] of Array.from(semaphore.holders)) {
                const conn = this.connections.get(holder.conn_id);
                if (conn) conn.tokens.delete(token);
                this.tokens.delete(token);
                semaphore.holders.delete(token);
                slots_cleared++;
            }
            this._run_semaphore_grants(semaphore);
            this._gc_semaphores([semaphore]);
        }

        return {
            id: req.id,
            status: protocol.STATUS_OK,
            name: name,
            holders_cleared: holders_cleared,
            slots_cleared: slots_cleared,
        };
    }

    // ---- Internals ------------------------------------------------------------------

    _error(req, message) {
        return { id: req ? req.id : undefined, status: protocol.STATUS_ERROR, message: message };
    }

    _normalize_name(name) {
        if (typeof name !== 'string') return null;
        const trimmed = name.trim();
        return trimmed.length > 0 ? trimmed : null;
    }

    _issue_token() {
        this.token_seq++;
        return 'lk' + this.token_seq;
    }

    _ensure_lock(name) {
        let lock = this.locks.get(name);
        if (!lock) {
            lock = { name: name, holders: new Map(), queue: [] };
            this.locks.set(name, lock);
        }
        return lock;
    }

    _ensure_semaphore(name, max_slots) {
        let semaphore = this.semaphores.get(name);
        if (!semaphore) {
            semaphore = { name: name, max_slots: max_slots, holders: new Map(), queue: [] };
            this.semaphores.set(name, semaphore);
        }
        return semaphore;
    }

    /** Drop empty lock records so a box that has touched a million names does not leak. */
    _gc_locks(locks) {
        for (const lock of locks) {
            if (lock && lock.holders.size === 0 && lock.queue.length === 0) {
                this.locks.delete(lock.name);
            }
        }
    }

    _gc_semaphores(semaphores) {
        for (const semaphore of semaphores) {
            if (semaphore && semaphore.holders.size === 0 && semaphore.queue.length === 0) {
                this.semaphores.delete(semaphore.name);
            }
        }
    }

    _can_grant(lock, mode) {
        if (mode === MODE_WRITE) {
            return lock.holders.size === 0;
        }
        for (const holder of lock.holders.values()) {
            if (holder.mode === MODE_WRITE) return false;
        }
        return true;
    }

    _grant_lock(lock, conn, mode, req) {
        const token = this._issue_token();
        lock.holders.set(conn.conn_id, { mode: mode, token: token, since: this.now() });
        this.tokens.set(token, { kind: 'lock', conn_id: conn.conn_id, name: lock.name, mode: mode });
        conn.tokens.add(token);
        this.counters.granted++;

        // VERBOSE ONLY. This fires on every granted acquire - thousands an hour on a busy
        // box - which is why it is behind the second switch and the contention events above
        // are not. Turn it on to trace one sequence, not to leave running.
        this.log(() => 'ACQUIRE ' + mode.toUpperCase() + ' ' + lock.name
            + ' by ' + this._describe_conn(conn.conn_id, conn), 'verbose');

        return {
            id: req.id,
            status: protocol.STATUS_GRANTED,
            name: lock.name,
            mode: mode,
            token: token,
        };
    }

    _release_hold(lock, conn, holder) {
        lock.holders.delete(conn.conn_id);
        this.tokens.delete(holder.token);
        conn.tokens.delete(holder.token);
        this.counters.released++;

        this.log(() => 'RELEASE ' + holder.mode.toUpperCase() + ' ' + lock.name
            + ' by ' + this._describe_conn(conn.conn_id, conn)
            + ' after ' + this._seconds_since(holder.since), 'verbose');
    }

    /** Put back a hold removed speculatively (the refused-upgrade path). */
    _restore_hold(lock, conn, holder) {
        lock.holders.set(conn.conn_id, holder);
        this.tokens.set(holder.token, {
            kind: 'lock',
            conn_id: conn.conn_id,
            name: lock.name,
            mode: holder.mode,
        });
        conn.tokens.add(holder.token);
        this.counters.released--;
    }

    _grant_semaphore(semaphore, conn, req) {
        const used = new Set();
        for (const holder of semaphore.holders.values()) used.add(holder.slot);
        let slot = 0;
        while (used.has(slot)) slot++;

        const token = this._issue_token();
        semaphore.holders.set(token, { conn_id: conn.conn_id, slot: slot, since: this.now() });
        this.tokens.set(token, { kind: 'sem', conn_id: conn.conn_id, name: semaphore.name, slot: slot });
        conn.tokens.add(token);
        this.counters.granted++;

        this.log(() => 'ACQUIRE semaphore ' + semaphore.name + ' slot ' + slot
            + ' by ' + this._describe_conn(conn.conn_id, conn), 'verbose');

        return {
            id: req.id,
            status: protocol.STATUS_GRANTED,
            name: semaphore.name,
            token: token,
            slot: slot,
        };
    }

    /**
     * Park a request. Every lock-path timer in this daemon is armed here, each only because
     * the request asked for it: `timeout` (the caller's wait budget), `stall` (the progress
     * watchdog) and `notice` (one `waiting` frame). A request carrying none of the three
     * arms nothing at all, which is what "wait forever, with no upper bound anywhere" means
     * in code - and no timer here ever touches a HOLD.
     */
    _enqueue(target, conn, spec) {
        this.waiter_seq++;
        const entry = {
            waiter_id: this.waiter_seq,
            conn_id: conn.conn_id,
            kind: spec.kind,
            name: spec.name,
            mode: spec.mode,
            timeout: spec.timeout,
            stall: spec.stall === undefined ? null : spec.stall,
            notice: spec.notice === undefined ? null : spec.notice,
            req: spec.req,
            upgraded: spec.upgraded === true,
            since: this.now(),
            timer: null,
            stall_timer: null,
            notice_timer: null,
        };

        target.queue.push(entry);
        conn.waits.push(entry);

        // CONTENTION. Rare by construction - the overwhelming majority of acquires are
        // granted outright and never reach here - so this is cheap to leave on and is the
        // first half of the only report that answers "who is holding up whom".
        this.log(() => 'WAIT ' + this._describe_want(entry)
            + ' by ' + this._describe_conn(conn.conn_id, conn)
            + ' - held by ' + (entry.kind === 'lock'
                ? this._describe_holders(target)
                : this._describe_slots(target))
            + ', queue depth ' + target.queue.length
            + (entry.timeout === null ? ', waiting forever' : ', timeout ' + entry.timeout + 's')
            + (entry.stall === null ? '' : ', stall watch ' + entry.stall + 's'));

        if (entry.timeout !== null) {
            entry.timer = this.set_timeout(() => {
                this._fire_timeout(entry);
            }, Math.round(entry.timeout * 1000));
        }

        if (entry.stall !== null) {
            entry.stall_timer = this.set_timeout(() => {
                this._check_stall(entry);
            }, Math.round(entry.stall * 1000));
        }

        if (entry.notice !== null) {
            entry.notice_timer = this.set_timeout(() => {
                this._fire_notice(entry);
            }, Math.round(entry.notice * 1000));
        }

        return entry;
    }

    /**
     * Parse a request's `stall` and `notice` keys: each null/absent (off) or a positive
     * number of seconds. Zero is refused rather than read as "off", because a request that
     * says "stall after 0 seconds" is a bug in its sender, not a choice.
     */
    _normalize_watch(req, op) {
        const out = { stall: null, notice: null, error: null };
        for (const key of ['stall', 'notice']) {
            const value = req[key];
            if (value === undefined || value === null) continue;
            if (typeof value !== 'number' || !isFinite(value) || value <= 0) {
                out.error = op + ' ' + key + ' must be null or a positive number of seconds';
                return out;
            }
            out[key] = value;
        }
        return out;
    }

    /**
     * THE PROGRESS WATCHDOG, run when a waiter's stall window may have closed.
     *
     * The waiter's window starts at the LATER of its own enqueue and the most recent
     * progress of what blocks it (_blocking_progress_at): a holder that signalled progress
     * restarts every waiter's window behind it, and a fresh grant is progress, so a queue
     * that is moving never stalls however long any one waiter has been in it. When the
     * window has not closed the check re-arms for exactly the remainder - so a waiter
     * behind a progressing holder costs one timer per window, not a poll.
     *
     * Closed: the waiter is answered `stalled`, naming every holder with how long it has
     * held and since when it last showed progress. The HOLDERS are untouched - the
     * watchdog fails the waiter that would otherwise wait on a silent holder forever; it
     * never takes a lock from anyone.
     */
    _check_stall(entry) {
        entry.stall_timer = null;

        const target = entry.kind === 'lock'
            ? this.locks.get(entry.name)
            : this.semaphores.get(entry.name);
        if (!target || target.queue.indexOf(entry) === -1) {
            return;   // granted, timed out or cancelled meanwhile - nothing to watch
        }

        const window_ms = Math.round(entry.stall * 1000);
        const now = this.now();
        const progress_at = this._blocking_progress_at(target, entry, new Set());
        const deadline = Math.max(entry.since, progress_at) + window_ms;

        if (now < deadline) {
            entry.stall_timer = this.set_timeout(() => {
                this._check_stall(entry);
            }, deadline - now);
            return;
        }

        const holders = this._holder_report(target);

        // Named BEFORE the waiter is removed, while the holders are still in place.
        this.log(() => 'STALLED ' + this._describe_want(entry)
            + ' for ' + this._describe_conn(entry.conn_id, this.connections.get(entry.conn_id))
            + ' after waiting ' + this._seconds_since(entry.since)
            + ' - held by ' + (entry.kind === 'lock'
                ? this._describe_holders(target)
                : this._describe_slots(target))
            + ', no progress for ' + ((now - progress_at) / 1000).toFixed(1) + 's');

        this._remove_waiter(entry, false);
        this.counters.stalled++;

        const what = entry.kind === 'lock'
            ? entry.mode.toUpperCase() + ' lock ' + entry.name
            : 'semaphore slot ' + entry.name;

        this.deliver(entry.conn_id, {
            id: entry.req.id,
            status: protocol.STATUS_STALLED,
            name: entry.name,
            mode: entry.mode,
            stall: entry.stall,
            waited_ms: now - entry.since,
            holders: holders,
            message: 'Stalled: waited ' + ((now - entry.since) / 1000).toFixed(1) + 's for ' + what
                + ' while its holder showed no progress for ' + entry.stall + 's - held by '
                + (entry.kind === 'lock' ? this._describe_holders(target) : this._describe_slots(target)),
        });

        // Removing a blocked waiter can unblock the ones behind it, as a timeout does.
        if (entry.kind === 'lock') {
            this._run_grants(target);
            this._gc_locks([target]);
        } else {
            this._run_semaphore_grants(target);
            this._gc_semaphores([target]);
        }
    }

    /**
     * The one `waiting` notice a request asked for (its `notice` key): sent once, carrying
     * the holders as _check_stall() would name them, and the wait goes on. Silent when the
     * request has meanwhile been answered.
     */
    _fire_notice(entry) {
        entry.notice_timer = null;

        const target = entry.kind === 'lock'
            ? this.locks.get(entry.name)
            : this.semaphores.get(entry.name);
        if (!target || target.queue.indexOf(entry) === -1) {
            return;
        }

        this.deliver(entry.conn_id, {
            id: entry.req.id,
            status: protocol.STATUS_WAITING,
            name: entry.name,
            mode: entry.mode,
            waited_ms: this.now() - entry.since,
            holders: this._holder_report(target),
        });
    }

    /**
     * Every holder of a lock or semaphore as a waiter's stalled or waiting frame reports
     * it: connection, host, pid, mode (a lock) or slot (a semaphore), how long it has held,
     * and how long since it last showed progress.
     */
    _holder_report(target) {
        const now = this.now();
        const out = [];
        const add = (conn_id, extra, since) => {
            const conn = this.connections.get(conn_id);
            out.push(Object.assign({
                conn_id: conn_id,
                host: conn && conn.meta.host ? conn.meta.host : null,
                pid: conn && conn.meta.pid ? conn.meta.pid : null,
                held_ms: now - since,
                progress_ms: now - this._own_progress_at(conn, since),
            }, extra));
        };

        if (target.max_slots === undefined) {
            for (const [conn_id, holder] of target.holders) {
                add(conn_id, { mode: holder.mode }, holder.since);
            }
        } else {
            for (const holder of target.holders.values()) {
                add(holder.conn_id, { slot: holder.slot }, holder.since);
            }
        }
        return out;
    }

    /** A connection's own latest progress for one of its holds: its grant, or a later frame. */
    _own_progress_at(conn, since) {
        if (!conn || conn.progress_at === null || conn.progress_at < since) {
            return since;
        }
        return conn.progress_at;
    }

    /**
     * The most recent moment the holders blocking `entry` showed progress, as the stall
     * rule reads it.
     *
     * A LOCK waits on EVERY holder (a writer needs all of them gone), so it is the
     * LEAST-recent holder's progress: one silent reader stalls a writer however busy the
     * other readers are. A SEMAPHORE waits on ANY slot freeing, so it is the MOST-recent
     * slot holder's progress: a pool of working slot holders keeps its queue waiting.
     * No holders at all (the waiter sits behind other waiters only, an instant before a
     * grant pass) reads as progress now.
     *
     * A holder that is ITSELF parked in a wait is not silent by its own fault - it cannot
     * send a frame while blocked - so its progress is the later of its own and that of
     * what it waits on, followed transitively (_conn_progress_at). `visited` guards the
     * walk; a cycle cannot exist (deadlock is refused at enqueue) and is read as no
     * progress if one ever did.
     */
    _blocking_progress_at(target, entry, visited) {
        const now = this.now();

        if (target.max_slots === undefined) {
            let least = null;
            for (const [conn_id, holder] of target.holders) {
                if (entry && conn_id === entry.conn_id) continue;
                const at = this._conn_progress_at(conn_id, holder.since, visited);
                if (least === null || at < least) least = at;
            }
            return least === null ? now : least;
        }

        let most = null;
        for (const holder of target.holders.values()) {
            if (entry && holder.conn_id === entry.conn_id) continue;
            const at = this._conn_progress_at(holder.conn_id, holder.since, visited);
            if (most === null || at > most) most = at;
        }
        return most === null ? now : most;
    }

    /** One holder connection's effective progress - see _blocking_progress_at(). */
    _conn_progress_at(conn_id, since, visited) {
        const conn = this.connections.get(conn_id);
        let at = this._own_progress_at(conn, since);
        if (!conn || visited.has(conn_id)) {
            return at;
        }
        visited.add(conn_id);

        for (const wait of conn.waits) {
            const target = wait.kind === 'lock'
                ? this.locks.get(wait.name)
                : this.semaphores.get(wait.name);
            const waited_from = Math.max(wait.since, target ? this._blocking_progress_at(target, wait, visited) : wait.since);
            if (waited_from > at) at = waited_from;
        }
        return at;
    }

    _fire_timeout(entry) {
        const target = entry.kind === 'lock'
            ? this.locks.get(entry.name)
            : this.semaphores.get(entry.name);

        // Named BEFORE the waiter is removed and while the holders are still in place, so
        // the line reports the state that actually caused the timeout. A client is told
        // only that its wait failed; this line names who held the lock while it waited,
        // or says that nobody did.
        this.log(() => 'TIMEOUT ' + this._describe_want(entry)
            + ' for ' + this._describe_conn(entry.conn_id, this.connections.get(entry.conn_id))
            + ' after ' + entry.timeout + 's'
            + ' (waited ' + this._seconds_since(entry.since) + ')'
            + ' - still held by ' + (entry.kind === 'lock'
                ? this._describe_holders(target)
                : this._describe_slots(target)));

        this._remove_waiter(entry, true);
        this.counters.timed_out++;

        const message = entry.kind === 'lock'
            ? protocol.timeout_message(entry.name, entry.mode, entry.timeout)
            : 'Failed to acquire semaphore slot for ' + entry.name + ' after ' + entry.timeout + ' seconds';

        this.deliver(entry.conn_id, {
            id: entry.req.id,
            status: protocol.STATUS_TIMEOUT,
            name: entry.name,
            mode: entry.mode,
            message: message,
        });

        // Removing a blocked waiter can unblock the ones behind it (a timed-out head
        // writer no longer holds back a run of readers).
        if (target) {
            if (entry.kind === 'lock') {
                this._run_grants(target);
                this._gc_locks([target]);
            } else {
                this._run_semaphore_grants(target);
                this._gc_semaphores([target]);
            }
        }
    }

    _remove_waiter(entry, already_fired) {
        if (entry.timer !== null && !already_fired) {
            this.clear_timeout(entry.timer);
        }
        entry.timer = null;

        // The watchdog and notice timers are never the one that fired here (each clears
        // its own handle before acting), so they are always cleared.
        if (entry.stall_timer) {
            this.clear_timeout(entry.stall_timer);
        }
        entry.stall_timer = null;
        if (entry.notice_timer) {
            this.clear_timeout(entry.notice_timer);
        }
        entry.notice_timer = null;

        const target = entry.kind === 'lock'
            ? this.locks.get(entry.name)
            : this.semaphores.get(entry.name);
        if (target) {
            const index = target.queue.indexOf(entry);
            if (index !== -1) target.queue.splice(index, 1);
        }

        const conn = this.connections.get(entry.conn_id);
        if (conn) {
            const index = conn.waits.indexOf(entry);
            if (index !== -1) conn.waits.splice(index, 1);
        }
    }

    _find_wait(conn, kind, name) {
        for (const entry of conn.waits) {
            if (entry.kind === kind && entry.name === name) return entry;
        }
        return null;
    }

    /**
     * FIFO grant pass. Walks the queue from the head only:
     *   - head is a writer: granted iff there are no holders at all; otherwise stop.
     *   - head is a reader: granted iff no writer holds; then keep going, so a run of
     *     consecutive readers grants as one batch.
     * Stopping at the first ungrantable head is what prevents writer starvation.
     */
    _run_grants(lock) {
        while (lock.queue.length > 0) {
            const entry = lock.queue[0];
            if (!this._can_grant(lock, entry.mode)) break;

            const conn = this.connections.get(entry.conn_id);
            this._remove_waiter(entry, false);
            if (!conn) continue;   // connection vanished between enqueue and grant

            const frame = this._grant_lock(lock, conn, entry.mode, entry.req);
            if (entry.upgraded) frame.upgraded = true;

            // The other half of the WAIT line: how long the queue actually cost. A WAIT
            // with no matching GRANT is the shape that means somebody is still stuck.
            this.log(() => 'GRANT ' + this._describe_want(entry)
                + ' to ' + this._describe_conn(entry.conn_id, conn)
                + ' after waiting ' + this._seconds_since(entry.since));

            this.deliver(entry.conn_id, frame);
        }
    }

    _run_semaphore_grants(semaphore) {
        while (semaphore.queue.length > 0 && semaphore.holders.size < semaphore.max_slots) {
            const entry = semaphore.queue[0];
            const conn = this.connections.get(entry.conn_id);
            this._remove_waiter(entry, false);
            if (!conn) continue;

            const frame = this._grant_semaphore(semaphore, conn, entry.req);

            this.log(() => 'GRANT ' + this._describe_want(entry)
                + ' to ' + this._describe_conn(entry.conn_id, conn)
                + ' after waiting ' + this._seconds_since(entry.since));

            this.deliver(entry.conn_id, frame);
        }
    }

    // ---- Deadlock detection ---------------------------------------------------------

    /**
     * Who stands between `want` and the requester, right now.
     *
     * For a lock: every conflicting HOLDER, plus every connection already QUEUED on that
     * lock. The queued ones count because FIFO forbids bypassing them - a request behind
     * them cannot be granted until they are, so they are genuinely blocking.
     *
     * For a semaphore: every slot holder plus everyone queued, same reasoning.
     */
    _blockers(want, requester) {
        const out = new Set();

        if (want.kind === 'lock') {
            const lock = this.locks.get(want.name);
            if (!lock) return out;
            for (const [conn_id, holder] of lock.holders) {
                if (conn_id === requester) continue;
                if (want.mode === MODE_WRITE || holder.mode === MODE_WRITE) {
                    out.add(conn_id);
                }
            }
            for (const entry of lock.queue) {
                if (entry.conn_id !== requester) out.add(entry.conn_id);
            }
            return out;
        }

        const semaphore = this.semaphores.get(want.name);
        if (!semaphore) return out;
        for (const holder of semaphore.holders.values()) {
            if (holder.conn_id !== requester) out.add(holder.conn_id);
        }
        for (const entry of semaphore.queue) {
            if (entry.conn_id !== requester) out.add(entry.conn_id);
        }
        return out;
    }

    /**
     * Would parking this request close a wait-for cycle? Breadth-first reachability from
     * the requester back to itself over "waits for -> blocked by" edges. A global visited
     * set is complete here because reachability from a node does not depend on the path
     * taken to reach it.
     *
     * Returns an array of human-readable hop strings describing the cycle, or null.
     * This runs on EVERY enqueue precisely because wait-forever is the default: without
     * it, one lock-ordering mistake is an unrecoverable hang instead of an error message.
     */
    _detect_deadlock(requester, want) {
        const parent = new Map();   // conn_id -> { from, want }
        const frontier = [];
        let expansions = 0;
        let closing = null;

        const expand = (from, wants) => {
            for (const w of wants) {
                for (const blocker of this._blockers(w, from)) {
                    expansions++;
                    if (expansions > MAX_GRAPH_EXPANSIONS) return { aborted: true };
                    if (blocker === requester) {
                        return { closing: { from: from, want: w } };
                    }
                    if (!parent.has(blocker)) {
                        parent.set(blocker, { from: from, want: w });
                        frontier.push(blocker);
                    }
                }
            }
            return null;
        };

        let result = expand(requester, [want]);
        while (!result && frontier.length > 0) {
            const conn_id = frontier.shift();
            const conn = this.connections.get(conn_id);
            result = expand(conn_id, conn ? conn.waits.map((e) => ({
                kind: e.kind,
                name: e.name,
                mode: e.mode,
            })) : []);
        }

        if (!result || result.aborted || !result.closing) return null;
        closing = result.closing;

        // Walk parent pointers back to the requester, then render the cycle in request
        // order so a human reads it as a story: A waits on X held by B, B waits on Y held
        // by A.
        const chain = [{ from: closing.from, want: closing.want, to: requester }];
        let node = closing.from;
        while (node !== requester) {
            const link = parent.get(node);
            if (!link) break;
            chain.unshift({ from: link.from, want: link.want, to: node });
            node = link.from;
        }

        return chain.map((hop) => this._describe_hop(hop));
    }

    _describe_hop(hop) {
        const conn = this.connections.get(hop.from);
        const blocker = this.connections.get(hop.to);
        const who = this._describe_conn(hop.from, conn);
        const blocked_by = this._describe_conn(hop.to, blocker);
        const what = hop.want.kind === 'lock'
            ? (hop.want.mode || MODE_WRITE).toUpperCase() + ' lock ' + hop.want.name
            : 'semaphore ' + hop.want.name;
        return who + ' waits for ' + what + ' -> blocked by ' + blocked_by;
    }

    /** A refused request that would have closed a wait-for cycle. */
    _log_deadlock(conn_id, conn, doing, cycle) {
        this.log(() => 'DEADLOCK refused: ' + this._describe_conn(conn_id, conn)
            + ' ' + doing + ' would close a cycle -- ' + cycle.join(' | '));
    }

    /** What a queue entry is waiting for: "WRITE <name>" or "semaphore <name>". */
    _describe_want(entry) {
        return entry.kind === 'lock'
            ? entry.mode.toUpperCase() + ' ' + entry.name
            : 'semaphore ' + entry.name;
    }

    _seconds_since(since) {
        return ((this.now() - since) / 1000).toFixed(1) + 's';
    }

    /**
     * WHO HOLDS THIS LOCK RIGHT NOW, for a log line. This is the fact a contention report
     * is written for: "it timed out" names a symptom, "it timed out and c7 [web-1:3140] has
     * held it WRITE for 62s" names the thing to go and look at.
     */
    _describe_holders(lock) {
        if (!lock || lock.holders.size === 0) return 'nobody';
        const parts = [];
        for (const [holder_conn_id, holder] of lock.holders) {
            parts.push(
                this._describe_conn(holder_conn_id, this.connections.get(holder_conn_id))
                + ' ' + holder.mode.toUpperCase()
                + ' for ' + this._seconds_since(holder.since)
            );
        }
        return parts.join(', ');
    }

    /** The semaphore twin of _describe_holders(): how full it is, and who holds each slot. */
    _describe_slots(semaphore) {
        if (!semaphore || semaphore.holders.size === 0) return 'nobody';
        const parts = [];
        for (const holder of semaphore.holders.values()) {
            parts.push(
                this._describe_conn(holder.conn_id, this.connections.get(holder.conn_id))
                + ' slot ' + holder.slot
                + ' for ' + this._seconds_since(holder.since)
            );
        }
        return semaphore.holders.size + '/' + semaphore.max_slots + ' slots: ' + parts.join(', ');
    }

    _describe_conn(conn_id, conn) {
        if (!conn || !conn.meta || !conn.meta.host) return conn_id;
        return conn_id + ' [' + conn.meta.host + ':' + (conn.meta.pid || '?') + ']';
    }
}

module.exports = { Lock_Table, MODE_READ, MODE_WRITE, MAX_GRAPH_EXPANSIONS };
