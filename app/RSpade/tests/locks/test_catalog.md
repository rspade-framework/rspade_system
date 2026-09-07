# Test Catalog: locks

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| SEM-01 | Quota enforced: Nth+1 acquire denied; release frees a slot | php | max_slots 2, 3 acquires | 2 granted, 3rd null, reusable after release | implemented | 2026-08-10 |
| SEM-02 | 0 means unlimited, and reports no usage | php | max_slots 0, 25 acquires | all granted; usage 0 | implemented | 2026-08-10 |
| SEM-03 | Usage reporting is accurate | php | acquire/release + get_semaphore_usage | 0->2->1->0 | implemented | 2026-08-10 |
| SEM-04 | null / sentinel / repeated release is a safe no-op | php | release(null), release(unlimited) twice | no throw | implemented | 2026-08-10 |
| SEM-05 | A dead holder's slot is freed immediately and is reusable | http | connection holding a slot destroyed | slots_used 0; another connection is granted | implemented | 2026-08-10 |
| RWL-01 | Readers-writer lock: one writer excludes another writer ACROSS PROCESSES | http | two processes, one WRITE lock | the second parks while the first holds; granted on release | implemented | 2026-08-10 |
| RWL-02 | Readers share; a writer waits for the readers to drain | http | 2 read holders + 1 writer, separate processes | both readers concurrent; writer granted after the last release | implemented | 2026-08-10 |
| RWL-03 | Each helper maps to the right domain/name/type | php | named/site read+write locks + get_lock_stats | writer_active / readers_active as declared | implemented | 2026-08-10 |
| RWL-04 | Release frees the lock for reacquisition (new token) | php | acquire, release, acquire | second acquire succeeds with a different token | implemented | 2026-08-10 |
| RWL-05 | Reentrancy is client-side: nested acquire returns the same token, releases unwind | php | two acquires, two releases | same token; held until the final release | implemented | 2026-08-10 |
| RWL-06 | `release_lock()` reports whether it was still held | php | unknown token / double release / force_clear'd lock | false, false, false; true for a genuine hold | implemented | 2026-08-10 |
| RWL-07 | `force_clear_lock()` drops the holder and frees the lock | php | held lock + force_clear | writer_active false | implemented | 2026-08-10 |
| SYS-01 | A system lock is flock-backed, exclusive, and its file is domain-named | php | `system_lock('x')` | `flock:` token; `storage/flock/system__<db-scope-md5>__x.lock`; another process is BLOCKED | implemented | 2026-08-10 |
| SYS-02 | There is no system READ lock | php | `get_lock(SYSTEM_LOCK, x, READ)` | throws "System locks are exclusive only" | implemented | 2026-08-10 |
| SYS-03 | System and cluster locks of the same name are independent | php | both, same name | distinct tokens/backends; only the cluster one reaches the daemon | implemented | 2026-08-10 |
| API-01 | Every timeout argument defaults to null (wait forever) | php | reflection over the 8 public entry points | each `?int $timeout = null` | implemented | 2026-08-10 |
| API-02 | An invalid domain and a negative timeout are refused | php | `get_lock('database', ...)`, `named_write_lock(x, -5)` | throws naming the problem | implemented | 2026-08-10 |
| API-03 | A deadlock THROWS with the cycle described, and costs the caller nothing | php | background helper holds B and waits for A; this process holds A and asks for B | RuntimeException naming both locks and the cycle; not worded as a timeout; A still held | implemented | 2026-08-10 |
| PROTO-01 | Frame encode/decode round-trip; hostile shapes rejected without throwing | http | `lib/protocol.js` via require() | ok round-trip; `null`/array/malformed rejected | implemented | 2026-08-10 |
| PROTO-02 | The newline splitter reassembles partial frames and caps a newline-less peer | http | chunked pushes; 200 bytes over a 64-byte cap | 2 lines; then a sticky error | implemented | 2026-08-10 |
| PROTO-03 | hello sign/verify: right key, wrong key, replay window, non-hex sig | http | `build_hello`/`verify_hello` | accepted / rejected; NEVER throws | implemented | 2026-08-10 |
| PROTO-04 | THE timeout message is verbatim and matches the updater's grep | http | `timeout_message()` | `Failed to acquire WRITE lock for X after N seconds` | implemented | 2026-08-10 |
| PROTO-05 | PHP and node sign a hello identically, byte for byte | http | `hash_hmac` under the framework's `env('APP_KEY')` vs `sign_hello()` | identical hex; the daemon's verifier accepts the PHP signature | implemented | 2026-08-10 |
| PROTO-06 | LIVE interop: the real PHP client handshakes and locks against a real daemon | http | `Lockd_Client::_use_endpoint()` + `named_write_lock` on a scratch daemon | hello ok; held; release true; free after | implemented | 2026-08-10 |
| PROTO-07 | Requiring the daemon's modules starts nothing (the export seam) | http | `require(lockd.js)` | no bind, `parse_argv` usable | implemented | 2026-08-10 |
| DEATH-01 | A SIGKILLed holder's lock is granted to the next waiter immediately | http | `kill -9` a holder process with a waiter parked behind it | granted in well under a second, with no timeout involved | implemented | 2026-08-10 |
| DEATH-02 | Death releases EVERYTHING that connection held | http | one connection holding 2 locks + a semaphore slot, socket destroyed | all three free | implemented | 2026-08-10 |
| DEATH-03 | The daemon accounts for it as a dropped connection, not a release | http | stats counters before/after the kill | `dropped_connections` +1 | implemented | 2026-08-10 |
| FIFO-01 | Grant order is request order | http | holder + queued W1, R1, R2, W2 | W1, then R1+R2, then W2 | implemented | 2026-08-10 |
| FIFO-02 | Consecutive readers grant as ONE batch | http | R1, R2 adjacent in the queue | both granted within 100ms of each other | implemented | 2026-08-10 |
| FIFO-03 | Nothing bypasses a queued waiter (writer starvation) | http | read holder, queued writer, then a late reader | the late reader waits behind the writer | implemented | 2026-08-10 |
| DEAD-01 | An AB/BA cycle is refused at enqueue with status `deadlock` | http | A holds L1 waits L2, B holds L2 waits L1 | refused, never parked, never granted | implemented | 2026-08-10 |
| DEAD-02 | The cycle text names both parties (host:pid) and both locks | http | the refusal frame's `cycle` | 2 hops naming both conn ids and both lock names | implemented | 2026-08-10 |
| DEAD-03 | A deadlock is textually DISTINCT from a timeout (not retryable) | http | the refusal message | does not match `Failed to acquire.*lock` | implemented | 2026-08-10 |
| DEAD-04 | A refused caller keeps what it already held, and does not join the queue | http | stats after the refusal | still the holder; queue_length 0 | implemented | 2026-08-10 |
| DEAD-05 | A cycle running through a QUEUED waiter is caught (FIFO forbids bypassing) | http | C holds L1, D queued on L1 holding L2, C asks for L2 | refused | implemented | 2026-08-10 |
| DEAD-06 | CONTROL: ordinary contention PARKS, it is not refused | http | one holder, one plain contended acquire | parks, then granted on release; deadlock counter unchanged | implemented | 2026-08-10 |
| NOTO-01 | A lock held past the retired 30s lease is STILL held by the same connection | http | 33s hold, sampled every 3s | same writer_conn throughout; release reports held=true | implemented | 2026-08-10 |
| NOTO-02 | A waiter parked past 30s is still waiting, and is granted on the RELEASE | http | waiter with timeout null behind that holder | no frame for 33s; granted after the release | implemented | 2026-08-10 |
| NOTO-03 | Wait-forever arms no timer at all | http | stats counters after the window | `timed_out` 0 | implemented | 2026-08-10 |
| NOTO-04 | An EXPLICIT timeout is still honoured, with the verbatim message | http | contended acquire with timeout 1 | status timeout; canonical message | implemented | 2026-08-10 |
| EXEC-01 | `exec` passes the child's exit code through (0, 7, 128+N) | cli | `exec -- bash -c 'exit 7'`, and a self-SIGTERM | 0, 7, 143 | implemented | 2026-08-10 |
| EXEC-02 | `exec` exits 124 on acquire timeout and never runs the child | cli | held lock + `--timeout=1` | 124; no child output; canonical message | implemented | 2026-08-10 |
| EXEC-03 | `--quiet` suppresses lockd's chatter and never the child's output | cli | with and without `--quiet` | `[lockd]` only without it; child output always | implemented | 2026-08-10 |
| EXEC-04 | The lock is released when the child exits | cli | two sequential execs on one name | the second runs immediately | implemented | 2026-08-10 |
| EXEC-05 | Two concurrent execs SERIALIZE | cli | two backgrounded execs on one name, 2s children | start/end/start/end, never interleaved | implemented | 2026-08-10 |
| EXEC-06 | Bad usage fails loudly with exit 1 | cli | no command, bad `--mode`, no `--name` | exit 1 with `[ERROR]` | implemented | 2026-08-10 |
| GRP-01 | A same-group connection is granted a lock its group already holds | http | parent holds SITE_1 write, child joins the group and asks | granted, not parked | implemented | 2026-08-11 |
| GRP-02 | Inheritance bypasses a QUEUED foreign waiter (the deadlock this exists to prevent) | http | parent holds, stranger queued, then the child asks | child granted; stranger still waiting | implemented | 2026-08-11 |
| GRP-03 | A foreign waiter is not granted until the WHOLE group has released | http | child releases, then the parent | granted only after the parent lets go | implemented | 2026-08-11 |
| GRP-04 | A dead group member does NOT release a lock it inherited | http | SIGKILL a child that inherited SHARED | parent still the sole holder; the waiter stays parked | implemented | 2026-08-11 |
| GRP-05 | A dead group member DOES release a lock it acquired itself | http | SIGKILL a child holding CHILD_ONLY | the waiter is granted immediately | implemented | 2026-08-11 |
| GRP-06 | A DIFFERENT group is a stranger and queues normally | http | TREE_C holds, TREE_D asks | parked until TREE_C is gone | implemented | 2026-08-11 |
| GRP-07 | Read-held is NOT inherited as write (no cross-process upgrade) | http | group holds READ, member asks WRITE | parked | implemented | 2026-08-11 |
| GRP-08 | Read-held IS inherited as read | http | group holds READ, member asks READ | granted | implemented | 2026-08-11 |
| GRP-09 | Reentrancy on ONE connection is still refused, group or not | http | two acquires on a single grouped socket | second answers `error` | implemented | 2026-08-11 |
| GRP-10 | A malformed group id is rejected at hello, not ignored | http | `group_id` with spaces and punctuation | hello rejected, connection closed | implemented | 2026-08-11 |
| GRP-11 | Ungrouped connections still exclude each other (the default is unchanged) | http | two holders with no `group_id` | the second parks | implemented | 2026-08-11 |
| CFG-01 | Config search order and total validation (unknown key, wrong type, both listeners off) | http | `lib/config.js` with fixture files | throws naming every problem | planned | 2026-08-10 |
| UPG-01 | `upgrade_lock` drops the read hold first and requeues at the tail (non-atomic by design) | http | two readers upgrading at once | one granted, the other waits behind it; neither deadlocks | planned | 2026-08-10 |
| HEALTH-01 | `#[Health_Check('Lock Server')]` reports OK / FAIL / INFO-under-maintenance | php | probe against a live, a dead, and a maintenance-flagged endpoint | three statuses with the remediation naming the supervisor program | planned | 2026-08-10 |
| CLI-01 | `lockd dump` renders holders, queues and semaphores | cli | held + queued state on a scratch daemon | ASCII report; `--json` emits the raw frame | planned | 2026-08-10 |
| STRAG-01 | A straggler process (booted pre-maintenance) degrades to flock instead of fatalling | php | daemon stopped mid-process with the flag on disk | flock backend engages | deferred (cannot stop the daemon from inside a test run) | 2026-08-10 |
| FD-01 | The lock-fd wrapper shell accepts a MULTI-DIGIT fd close (POSIX sh does not) | php | wrapper shape with `exec 11>&-`, run through Symfony Process | bash exits 0 and echoes OK; the sh spelling fails (asserted only where /bin/sh is dash) | implemented | 2026-08-13 |
