# Test Catalog: throttle

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| THR-01 | A never-claimed action runs and the claim row is created | php | check() on a fresh key | true, row present with created_at/last_executed_at | implemented | 2026-08-05 |
| THR-02 | A second call inside the interval is throttled and does not advance the claim | php | check() twice, 30 min | true then false, last_executed_at unchanged | implemented | 2026-08-05 |
| THR-03 | A claim older than the interval runs again and advances | php | check(), backdate 31 min, check() | true, last_executed_at advanced | implemented | 2026-08-05 |
| THR-04 | The interval is a per-call argument, not a stored row property | php | backdate 5 min, check(30) then check(1) | false then true | implemented | 2026-08-05 |
| THR-05 | The claim participates in the caller's transaction (no implicit COMMIT) | php | begin, check(), rollback | claim row gone after rollback | implemented | 2026-08-05 |
| THR-06 | reset() drops the claim and the next call takes the insert path | php | check(), check(), reset(), check() | true, false, row gone, true | implemented | 2026-08-05 |
| THR-07 | Concurrent callers: exactly one wins a contested interval | php | two processes calling check() simultaneously | one true, one false | deferred (needs multi-process harness; the unique key makes this structural) | 2026-08-05 |
