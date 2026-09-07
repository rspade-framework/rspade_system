# Concern: locks

## Domain

`App\RSpade\Core\Locks\RsxLocks` - advisory locking. A lock is held from the moment it is
granted until it is released or **the holder dies**. There is no lease, no TTL, no renewal and
no heartbeat; the only clock anywhere is the optional per-waiter timeout a caller explicitly
asks for, and `?int $timeout = null` (the default everywhere) means wait forever.

**Two kinds of lock, and the choice is not a tuning knob:**

1. **`CLUSTER_LOCK`** - spans every web server in the cluster, the way the cluster shares one
   database. Served by the **rsx-lockd** daemon (`system/bin/rsx-lockd`) over a socket:
   readers-writer semantics, strict FIFO grant order, deadlock detection at enqueue, and
   counting semaphores. `named_*_lock()`, `site_*_lock()` and `acquire_semaphore()` are all
   cluster. **The connection IS the lock** - a socket close (clean exit, crash, `kill -9`, or a
   partitioned peer) releases everything that connection held, immediately.
2. **`SYSTEM_LOCK`** - this box only, backed by `flock()` over files in `storage/flock/`, and
   **exclusive only** (there is no system READ lock: `flock()` is per open file description, so
   a READ-then-WRITE nesting would open a second descriptor and block against itself forever).
   `system_lock()`. Rare by design - build artifacts, a local helper process, this machine's
   environment.

**Lock groups** are the one sanctioned hole in "the connection is the lock". A connection may
name a `group_id` at hello; a connection whose group already holds a lock is granted it
immediately, past any queue. That models a process tree - a parent that holds a lock and is
blocked in `waitpid` on a subprocess it spawned. Without it the child queues behind its own
parent forever, and the deadlock detector cannot see the cycle because the parent's half of it
is an OS wait rather than a lock wait. Group ids reach a subprocess through the
`--_lock-group=<id>` internal flag, which `Rsx_Artisan` attaches to every synchronous spawn.
The asymmetry that keeps it safe: a member releases only what IT acquired, so a dying child
never drops the parent's grant; read-held is not inherited as write; and a different group is
an ordinary stranger.

**Maintenance mode** stops rsx-lockd along with the rest of the fleet, so cluster locks degrade
to the flock backend for the window. The backend is chosen at ACQUISITION time from the
per-process `RSPADE_MAINT_MODE` snapshot and recorded per lock, so a lock can never be taken on
one backend and released through another. That degradation is tested in the `maintenance`
concern (`Maintenance_Flock_Locks_Test`), not here.

## Source under test

- `system/app/RSpade/Core/Locks/RsxLocks.php` - the API, reentrancy counts, backend selection
- `system/app/RSpade/Core/Locks/Lockd_Client.php` - socket transport, hello handshake, the
  `#[Health_Check('Lock Server')]` probe
- `system/bin/rsx-lockd/lib/protocol.js` - frames, the newline splitter, hello HMAC, THE
  timeout message
- `system/bin/rsx-lockd/lib/locktable.js` - holders, queues, grants, semaphores, deadlock
- `system/bin/rsx-lockd/lib/server.js`, `lib/client.js`, `lib/config.js`
- `system/bin/rsx-lockd/lockd.js` - CLI (`run`/`start`/`stop`/`dump`/`exec`)

## Man pages

- `php artisan rsx:man locks`
- `system/bin/rsx-lockd/README.md` (the daemon's own protocol/config/CLI spec)

## Testable surface

- **php** - the API surface in one process: each helper maps to the right domain/name/type,
  release frees for reacquire, reentrancy, `release_lock()`'s boolean answer (including the
  "the daemon no longer attributes this lock to us" case), system locks being flock-backed and
  write-only, wait-forever defaults, semaphore quotas, and the deadlock refusal (which needs a
  second process, spawned as a background helper).
- **http** (shell, against a SCRATCH daemon) - everything that only exists between processes:
  cross-process mutual exclusion, release-on-`kill -9`, FIFO grant order, deadlock cycles, and
  the wait-forever regression. Plus the wire protocol through the daemon's export seam and
  PHP/node HMAC parity, and lock-group inheritance with all four of its boundaries
  (foreign group, read-vs-write, dying member, malformed id).
- **cli** (shell) - `lockd exec`: exit-code contract (child code, 124 timeout), `--quiet`,
  release-on-child-exit, and two concurrent execs serializing.

## Testing notes

**Never disturb the supervised daemon.** Every shell test that needs a live daemon starts its
OWN via `_lib/lockd_test_lib.sh`: a temp config, a scratch port picked at or above a per-test
preferred number (6291-6297), and an EXIT trap that kills it and removes the scratch directory.
No test binds the configured port, and nothing calls `supervisorctl`. The node-side helpers
live in `resource/` (`lockd_test_harness.js`, `lockd_holder.js`) because that basename is
excluded from manifest scanning - a `module.exports` file anywhere the JS indexer looks is a
build error.

Shell tests skip cleanly (`SKIP:` + exit 0) when `node`, the daemon, or `APP_KEY` is missing.

The PHP tests run against the REAL daemon on the configured port, because that is the daemon
the framework talks to; they use test-scoped lock names and `teardown()` force-clears every one
of them.

`lockd_no_timeout.sh` deliberately runs for ~35 seconds: proving a lock outlives the retired
30-second lease requires holding one for longer than 30 seconds.
