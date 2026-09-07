# Test catalog: cache

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| CACHE-04 | `get()` returns the default for a missing key | php | unused key | null / 'none' | implemented | 2026-09-05 |
| CACHE-06 | set/get round trips, incl. serialized `false` and numeric STRINGS | php | array, false, 100, '250' | identical values and types back | implemented | 2026-09-05 |
| CACHE-07 | A payload that is not a serialize() payload fails loud instead of returning false | php | raw poke into redis at the derived key | RuntimeException 'corrupt cache payload' | implemented | 2026-09-05 |
| CACHE-08 | Degraded mode: cache reads miss, writes drop, counters return 0 without reaching redis | php | maintenance flag forced | defaults / false / 0 | implemented (in `maintenance` concern, `Maintenance_Redis_Tolerance_Test`) | 2026-09-05 |
| CACHE-09 | `remember()` builds once under contention (stampede lock) | php | two processes, same key | callback runs once | planned - needs a multi-process harness | 2026-08-05 |
| CACHE-10 | Build-key scoping: a manifest rebuild invalidates `get()` but not `get_persistent()` | php | set, bump build key, get | miss / hit respectively | planned | 2026-08-05 |
| CACHE-20 | `Rsx_Counter::increment()` opens a window on the increment that CREATES the key | php | fresh key, 300s | value 1, redis TTL in (0, 300] | implemented | 2026-09-05 |
| CACHE-21 | Later increments NEVER extend the window (fixed, not sliding) | php | 2nd/3rd increment; TTL poked down to 5s in between | TTL never restored to the full window | implemented | 2026-09-05 |
| CACHE-22 | A creating increment of n seeds at n and still opens the window | php | `increment($k,300,5)` x2 | 5 then 10, TTL set | implemented | 2026-09-05 |
| CACHE-23 | A non-positive window throws and creates nothing | php | window 0, window -60 | InvalidArgumentException x2, key absent | implemented | 2026-09-05 |
| CACHE-24 | `Rsx_Counter::get()` reads the counter and answers 0 when absent | php | fresh key, then 2 increments | int 0, then int 2 | implemented | 2026-09-05 |
| CACHE-25 | A flag carries its integer value and expires on its own | php | `set_flag($k, 900, $expires_at)` | value back, TTL in (0, 900]; unset flag reads 0 | implemented | 2026-09-05 |
| CACHE-26 | A non-positive flag TTL throws and creates nothing | php | ttl 0 | InvalidArgumentException, key absent | implemented | 2026-09-05 |
| CACHE-27 | `reset()` removes the key, not merely its value; the next increment opens a NEW window | php | increment 4, reset, increment | 0 / TTL -2 / then 1 | implemented | 2026-09-05 |
| CACHE-28 | A counter is invisible through RsxCache and survives `RsxCache::clear()` | php | increment, then read via RsxCache, then clear | null / false, count intact | implemented | 2026-09-05 |
| CACHE-29 | Counter degraded mode: 0 back, nothing reaches redis | php | maintenance flag forced | 0 / 0 / 0, key absent afterwards | implemented | 2026-09-05 |
| CACHE-30 | `clear()` drops a plain key and keeps an `_RVC_` key | php | set both, `clear()` | plain null, `_RVC_` intact | implemented | 2026-09-05 |
| CACHE-31 | An `_RVC_` key is physically on the reduced-volatility database, not DB 0 | php | set, probe both databases by raw key | absent in 0, present in 2 | implemented | 2026-09-05 |
| CACHE-32 | An `_RVC_` key is still BUILD-SCOPED (a rebuild misses cleanly) | php | derive the key under another build key | current build hits, other build absent | implemented | 2026-09-05 |
| CACHE-33 | `clear_reduced_volatility()` empties DB 2 only (DB 0 key and DB 3 counter survive) | php | set plain + `_RVC_` + counter, call it | `_RVC_` null, plain and counter intact | implemented | 2026-09-05 |
| CACHE-33 | A rolled-back type ref is not resolvable afterwards (the regression) | php | nested transaction, `class_to_id()` for an unregistered model, rollback | row gone, `id_to_class()` throws 'not found in registry' | implemented | 2026-09-05 |
| CACHE-34 | Any rollback empties the volatile cache | php | set, `beginTransaction()` + `rollBack()` | the key reads null | implemented | 2026-09-05 |
| CACHE-40 | Every cache key is prefixed `cache:<Rsx_Connection_Scope::token()>:`, still folds in the build key, and the token moves with the connection | php | reflect both key builders under each connection | prefix present in both; build key present; the two tokens differ | implemented | 2026-09-07 |
| CACHE-41 | A cached value is invisible under another database scope, and the other scope's write to the same user key does not overwrite it | php | set, swap default connection, get + set, swap back | null there, unchanged here | implemented | 2026-09-07 |
| CACHE-42 | `clear()` in another database scope leaves this scope's keys intact (and still empties its own) | php | set, `clear()` under the other connection, then here | key intact, then null | implemented | 2026-09-07 |
| CACHE-43 | `Rsx_Counter` increments and resets do not cross database scopes | php | increment 3, swap, read/increment 50/reset, swap back | 0 there; still 3 here | implemented | 2026-09-07 |
