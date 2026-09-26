# rsx-lockd - agent notes

Standalone lock daemon. **Zero npm dependencies** (node core only) so this directory can
be lifted into its own repo unchanged. Do not add one. `system/bin/` is a framework-owned
hard-synced zone, so anything here self-distributes downstream on `rsx:framework:pull`.

Read `README.md` first - it is the user-facing spec (protocol table, config keys, CLI,
exit codes). This file is the map of the implementation.

## File map

| File | Owns | Side effects |
|---|---|---|
| `lockd.js` | CLI dispatch, `.env` load, pidfile, daemonize, `dump` rendering, `exec` | Only inside `main()`, only under `require.main === module` |
| `lib/protocol.js` | Frame encode/decode, the newline splitter, HMAC hello, mode/timeout normalization, THE timeout message | **None. Pure.** |
| `lib/locktable.js` | The entire lock state machine: holders, queues, grants, timeouts, semaphores, deadlock detection. Owns the `Pool_Table` instance (`table.pool`) so connection cleanup cannot forget it | **None.** Delivery and timers are injected. |
| `lib/pool.js` | Worker pool accounting (`pool.*` ops): per-pool FIFO mutex + member set, random member ids, per-connection cleanup. Bespoke - shares no code with locks/semaphores | **None.** Delivery and the member-id source are injected. |
| `lib/server.js` | `net.Server` over unix and/or tcp, per-connection state, hello gate, frame dispatch | Binds sockets |
| `lib/config.js` | Config search order, JSON parse, total validation | Reads the file it is told to read |
| `lockd-run.sh` | Supervisor entry: wait for `lockd.js`, then `exec node` | Invoke as `bash lockd-run.sh` - never rely on the exec bit |
| `lockd.conf` | Shipped defaults | Never edit downstream; the project-root copy wins |

## Export seams (how to test without a live daemon)

Every module is `module.exports`-ed and importing it starts nothing. `lockd.js` binds no
port and reads no config until `main()` runs, and `main()` only runs under
`require.main === module` - the same seam `realtime-server.js` uses.

```js
const p = require('.../lib/protocol.js');
const { Lock_Table } = require('.../lib/locktable.js');

// Frame + HMAC parity, no socket:
p.decode_frame(p.encode_frame({op:'ping'}).trim());
p.verify_hello(p.build_hello('h', 7, 1000, 'key'), 'key', 1000);
p.timeout_message('SITE_1', 'write', 30);

// The whole state machine on a fake clock with a collector:
const out = [];
const table = new Lock_Table({
    deliver: (conn_id, frame) => out.push([conn_id, frame.status]),
    now: () => 0, set_timeout: () => null, clear_timeout: () => {},
});
table.register_connection('a', {host:'h', pid:1});
table.register_connection('b', {host:'h', pid:2});
table.acquire('a', {name:'N', mode:'write', timeout:null});   // -> granted frame
table.acquire('b', {name:'N', mode:'write', timeout:null});   // -> null (parked)
table.drop_connection('a');                                   // -> out has b granted
```

`acquire`/`upgrade`/`sem_acquire` return a frame to send **now**, or `null` meaning the
request parked and the answer will arrive through `deliver`. Everything else returns a
frame synchronously.

The pool machine is reached the same way, through the table that owns it:

```js
const table = new Lock_Table({ deliver, now: () => 0, set_timeout: () => null,
    clear_timeout: () => {}, pool_random_id: () => 'pm_test' + (++n) });
table.pool.lock('a', {id: 1, pool: 'P'});   // -> granted frame
table.pool.lock('b', {id: 2, pool: 'P'});   // -> null (parked)
table.drop_connection('a');                 // -> deliver(b, granted)
```

`pool.lock` returns a frame or `null` (parked); every other pool op returns a frame.

`lockd.js` also exports `parse_argv` (pure) and `load_env_file`.

## Invariants a change must not break

1. **The connection is the lock.** `drop_connection()` must release every hold, remove
   every wait, and re-run grants. If you add a new kind of held thing, it must be released
   there and in `release_all()`. This is the whole correctness model. Worker pool state is
   such a thing: both call `this.pool.drop_connection(conn_id)`, which removes the
   connection's memberships in every pool, releases every pool lock it holds (granting
   the next FIFO waiter) and removes its queued pool waits - answering them with an error
   frame under `release_all`, and with nothing on a real drop (the socket is gone).
2. **No clock on a lock.** No TTL, no lease, no renewal, no heartbeat, no sweeper. The
   only `set_timeout` on a lock path is the one armed in `_enqueue` for a caller-supplied
   timeout, and `timeout: null` must arm nothing.
3. **Waiting is silence.** A parked request gets no frame until granted, timed out,
   cancelled, or its connection dies. Never send progress, acks or keepalive frames - the
   server sends nothing unsolicited, which is what lets a client block on read.
4. **FIFO, head only.** `_run_grants` walks from the head and stops at the first
   ungrantable entry. Never scan the queue for a grantable entry further back: that is
   writer starvation.
5. **Deadlock is refused at enqueue, before parking.** `_detect_deadlock` runs on every
   path that would park. Blockers include queued waiters, not just holders, because FIFO
   forbids bypassing them.
6. **Never throw out of a frame path.** One shared process serves every lock holder on the
   box; an uncaught throw drops them all. `_dispatch` wraps everything in try/catch, the
   process-level `uncaughtException`/`unhandledRejection` handlers log and keep serving,
   and `decode_frame`/`verify_hello` return results instead of throwing.
7. **The timeout message is verbatim.** `protocol.timeout_message()` produces
   `Failed to acquire {WRITE|READ} lock for {name} after {N} seconds`.
   `system/bin/framework-pull-upstream.sh` greps `Failed to acquire.*lock` to classify a
   retryable pull failure. Do not reword it.
8. **ASCII only.** `[OK]` / `[WARNING]` / `[ERROR]`, no emoji, no unicode box drawing.
9. **Reentrancy stays client-side.** The server answers `error` to a second acquire of the
   same lock on the same connection. Do not "helpfully" make it a counter. Group
   inheritance (below) is a different thing entirely: a different CONNECTION, same tree.
10. **Group inheritance never releases someone else's grant.** Each connection gets its
    own holder entry, so `drop_connection()` frees only what that connection acquired.
    A child dying must leave the parent holding what the parent took.
11. **Every pool op answers exactly one frame echoing the request id.** `pool.lock` answers
    when granted; every other pool op answers at once; a refusal is an `error` frame. A
    pool client waits for the answer on every call, so a missing frame is a hung worker.
12. **Pool waits never enter `conn.waits`.** The deadlock detector walks `conn.waits`;
    pool waits live only in `lib/pool.js`. See "Worker pools" for why that is correct and
    what keeps it correct. Membership is never keyed by conn id outside the connection's
    lifetime: a member id is random (`pm_` + 32 hex), because conn ids restart at `c1`.

## Lock groups (subprocess inheritance)

A connection may declare a `group_id` in its `hello`. Absent one, it is its own group, so
"the connection is the lock" remains the default and nothing about an existing client
changes.

The rule in `acquire()`: **if this connection's group already holds the lock, grant
immediately** - including past a queued foreign waiter. That bypass is not an optimization,
it is the whole point. A group is a process tree: the holder is this connection's parent,
blocked in `waitpid` on it. Queueing (behind anyone) is a deadlock the detector cannot see,
because the parent's half of the cycle is an OS wait, not a lock wait.

Bypassing costs the foreign waiter nothing. It was already excluded by the parent, and it
stays excluded until the parent releases either way.

Two boundaries, both tested in `tests/locks/http/lockd_group_boundaries.sh`:

- **Read-held + write-wanted is NOT inherited.** That is a cross-process upgrade and would
  let one group hold read and write simultaneously. A parent that will spawn a writer takes
  the WRITE lock first.
- **A different group is a stranger.** Groups narrow nothing else.

The id is not part of the signed hello payload: the client signs its own claim either way,
so covering it would add ceremony and no security. The HMAC key is the trust boundary -
anything that can say hello is already trusted to name a group. The charset bound in
`normalize_group_id()` exists so a group id is safe to print, not as a security control.

## Worker pools (`lib/pool.js`)

A pool is a named FIFO mutex (the pool lock) plus a set of member connections. The task
system uses one per application (the pool name is the app's connection scope token) as its
worker accountant: a worker takes the pool lock, counts the other members, joins if there
is room, claims a task row, and releases the lock; a crashed worker stops being a member
the moment its connection closes. Owner requirements it exists to meet: membership and
the pool lock are released on disconnect whether or not the worker sent leave/unlock, and
every pool command is acknowledged so a worker never sends one and quits unanswered.

**It is bespoke on purpose.** No modes, no timeout, no group inheritance, no deadlock
detection. Do not "unify" it with the lock or semaphore code - each of those features is
wrong here (a timeout would let a slow claim fail; group inheritance would let a worker's
subprocess count as the worker).

**THE RULE - the reason pool waits are invisible to the deadlock detector.** While holding
a pool lock, a process runs only pool ops and reads/writes of the rows the pool
coordinates (the `_tasks` rows). It takes **no other blocking lock** (a non-blocking try
is allowed), **waits on no subprocess**, and **makes no outbound call**. A pool-lock
holder therefore never waits on anything but the database, so no wait-for cycle can pass
through a pool lock, and there is nothing for the detector to find. Putting pool waits in
`conn.waits` would make the detector walk edges that cannot close a cycle - and it could
not see a real one anyway: the PHP worker holds its pool lock on a second, dedicated
connection, and the detector has no notion of two connections belonging to one process.
THE RULE is the only thing that makes pool waits safe. A change
that needs to do more under the pool lock breaks THE RULE and must be redesigned, not
accommodated by teaching the detector about pools.

## Design notes worth knowing before editing

- **Upgrade drops the read hold first**, then queues for write at the tail. That is why
  concurrent upgrades cannot deadlock (each has already released) and why the operation is
  explicitly non-atomic. A refused upgrade restores the read hold (`_restore_hold`) so a
  refusal never silently downgrades a caller to holding nothing.
- **Deadlock reachability uses a global visited set**, which is complete here: reachability
  from a node does not depend on the path taken to it. `MAX_GRAPH_EXPANSIONS` bounds a
  pathological state; it is unreachable at real connection counts.
- **`_gc_locks`/`_gc_semaphores`** drop empty records so a box that has touched a million
  distinct names does not accumulate them.
- **`stats`/`dump` carry lifetime counters** (granted, released, timed_out, deadlocked,
  dropped_connections). They cost nothing and are what you actually want at 3am.
- **`ping` is answered pre-hello** on purpose: it discloses nothing and lets a health check
  work without the key. Everything else requires the handshake.
- **Unix socket bind probes before unlinking.** A stale file is removed; a live one is
  refused, so a second daemon can never steal a running instance's path.
