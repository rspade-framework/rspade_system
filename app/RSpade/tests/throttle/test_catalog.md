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
| BLB-01 | an anonymous caller gets 30 calls per ten minutes on a sink; the 31st is refused | php | 31 spends on a sink of the test's own | true x30, false | implemented | 2026-10-31 |
| BLB-02 | a signed-in caller has the larger budget, counted apart from the anonymous one | php | anonymous budget spent, then 61 signed-in spends | true x60, false | implemented | 2026-10-31 |
| BLB-03 | past the budget the browser-error sink writes nothing | php | sink budget spent, then a post | no [BROWSER_ERROR] line | implemented | 2026-10-31 |
| BLB-04 | at most 20 entries per call; one line counts the rest | php | 25 errors | 21 lines, the last "5 more error(s)" | implemented | 2026-10-31 |
| BLB-05 | a report cannot forge a log line, and every field is cut | php | newline + fake log prefix in message, stack and url; oversized fields | no line after the first starts with "["; newline -> space; stack indented; truncated marker; bounded length | implemented | 2026-10-31 |
| BLB-06 | an exact entry is written once per address per 30 minutes, and a repeat spends no quota | php | one error twice in a call, then again; then the quota spent to its limit | one line; none; the 30th slot still free | implemented | 2026-10-31 |
| BLB-07 | a counter store that cannot answer writes nothing (fail closed) | php | Framework_Maintenance forced active | spend() and admit() false; no line logged | implemented | 2026-10-31 |
