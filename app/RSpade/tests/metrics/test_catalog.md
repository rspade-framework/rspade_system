# metrics - test catalog

## Rsx_Metrics_Test (php) - the performance record

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| METRICS-01 | the recent list is newest first, carries what was recorded, keeps 100, and kinds are apart | php | 105 Ajax entries | 3 newest; 100 kept; no page entries | implemented | 2026-10-31 |
| METRICS-02 | the summary averages the kept entries and names the largest | php | two page entries | count, avg/max peak, avg/max ms | implemented | 2026-10-31 |
| METRICS-03 | a run is spread over the minutes it spanned, counted and peaked where it ended; a run past an hour counts its last hour | php | a 120 s task across three minutes; a 2 h span | busy 0.5/1/0.5, memory 60/120/60, count in the end minute; 60 minutes | implemented | 2026-10-31 |
| METRICS-04 | a minute's peak is the largest of the entries ending in it | php | 40, 90, 25 MB | 90 | implemented | 2026-10-31 |
| METRICS-05 | disabled writes nothing; an unavailable store is a silent no-op with empty readers; recording resumes | php | `rsx.metrics.enabled` false; maintenance forced | no entries, then one | implemented | 2026-10-31 |
| METRICS-06 | a label cannot break the line format; an unknown kind is ignored | php | `A\|B\nC`; kind `nonsense` | `A B C`, one entry | implemented | 2026-10-31 |
| METRICS-07 | a php-fpm request and a worker-run task record with their names | manual | browse /_sys, run a task through a worker | page/Ajax entries named by action and call; a task entry `Service::method` | verified by hand 2026-10-31 (an Ajax call named by its endpoint; Sms_Queue_Service::send_pending_queue from a dispatched worker) | 2026-10-31 |
