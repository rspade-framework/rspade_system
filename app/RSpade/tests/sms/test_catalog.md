# sms - test catalog

The SMS queue, via `Sms_Queue_Test`. There is no SMS provider, so the drain records every
claimed row SUPPRESSED; what is under test is the queue SHAPE, which is deliberately the
mail concern's with the transport removed.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| SMS-01 | a queued row starts PENDING and carries its body | php | `Sms_Queue_Model::enqueue()` | status PENDING, body stored | implemented | 2026-08-31 |
| SMS-02 | SUPPRESSED is its own terminal status, not a failure and not a send | php | `mark_suppressed()` | status SUPPRESSED, `sent_at` stamped, label "Suppressed" | implemented | 2026-08-31 |
| SMS-03 | the claim is atomic: a claimed row is SENDING and never claimed twice | php | `claim_next()` twice | first returns the row as SENDING, second never returns it | implemented | 2026-08-31 |
| SMS-04 | the drain is single-instance | php | `Task_Concurrency::get_policy()` | mode `exclusive` | implemented | 2026-08-31 |
| SMS-05 | the blocklist stops non-transactional only - a code somebody just requested still goes | php | `block_all()`, then MARKETING and TRANSACTIONAL sends | BLOCKED, then PENDING | implemented | 2026-08-31 |
| SMS-06 | the opt-out is enforced with NO session (site 0), where the check and the row must agree | php | no session, `block()` then `is_blocked()` | blocked at site 0 | implemented | 2026-08-31 |
| SMS-07 | an opted-out send takes the blocked path rather than queueing | php | no session, blocked number, MARKETING | status BLOCKED | implemented | 2026-08-31 |
| SMS-08 | a sessionless send persists site 0 rather than inventing site 1 | php | no session, whitelisted number | PENDING, `site_id` 0 | implemented | 2026-08-31 |
| SMS-09 | a dev host with nothing whitelisted and no catchall records SUPPRESSED AT ENQUEUE | php | empty whitelist and catchall | SUPPRESSED, reason "dev site: no whitelist match and no catchall" | implemented | 2026-08-31 |
| SMS-09b | a dev-host catchall rewrites the destination and records the real one | php | catchall configured, nothing whitelisted | `to_number` = catchall, `dev_original_to` = the real number, PENDING | implemented | 2026-08-31 |
| SMS-10 | a stranded SENDING row is returned to PENDING with a note saying why | php | row forced to SENDING, `reclaim_stranded()` | count > 0, PENDING, `last_error` = the reclaim note | implemented | 2026-08-31 |
| SMS-11 | the reclaim is not a retry - it never spends an attempt | php | stranded row with `attempt_count` 1 | `attempt_count` still 1 | implemented | 2026-08-31 |
| SMS-12 | the reclaim touches nothing but SENDING | php | PENDING + SUPPRESSED rows | both unchanged | implemented | 2026-08-31 |
| SMS-13 | the drain reclaims first and then processes, so a rescued row reaches a terminal status on the same pass | php | row forced to SENDING, `Task::internal` drain | `reclaimed` > 0, row SUPPRESSED | implemented | 2026-08-31 |
| SMS-14 | a clean queue reports zero reclaimed | php | no stranded rows | `reclaimed` 0 | implemented | 2026-08-31 |
| SMS-15 | the daily cleanup deletes terminal rows past the window and keeps recent ones | php | 120-day-old SUPPRESSED row and a fresh one | old gone, recent kept | implemented | 2026-08-31 |
| SMS-16 | retention never deletes a row nobody has decided about | php | 120-day-old PENDING row | survives | implemented | 2026-08-31 |

Waiting on a provider. These are the rows the mail concern already has and this one
cannot: without a transport there is no delivery, no provider error and no outage, so
the runner's three outcomes collapse into one.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| SMS-30 | a delivered message is SENT with the provider's id recorded | php | stub provider accepting | SENT, `transport_response` recorded, `attempt_count` 1 | planned - no provider exists | 2026-08-31 |
| SMS-31 | a provider error takes the retry clock to the cap and then FAILS with the reply | php | stub provider rejecting | PENDING with `next_attempt_at` until the cap, then FAILED | planned - no provider exists | 2026-08-31 |
| SMS-32 | an unreachable provider releases the row without counting the attempt and kills the drain | php | stub provider unreachable | row PENDING, `attempt_count` 0, drain throws | planned - no provider exists | 2026-08-31 |
| SMS-33 | delivery and failure count against the per-number history | php | stub provider | `total_sent` / `total_failed` incremented | planned - `Sms_Recipient_Model` counters are never called today | 2026-08-31 |

## Delivery modes (`php/Sms_Delivery_Mode_Test.php`)

`rsx.sms.delivery` mirrors the mail vocabulary MINUS the two modes that need a transport.
There is no provider, so accepting `live` would be a silent no-op - the failure this
subsystem is most likely to hide. There is deliberately no `stale_after_days` mirror.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| SMS-40 | a mode that needs a transport is REFUSED out loud, not accepted and ignored | php | `live`, `aiosmtpd`, `sortof` | `RuntimeException` saying THERE IS NO SMS PROVIDER | implemented | 2026-08-31 |
| SMS-41 | both supported modes are accepted | php | `suppressed`, `disabled` | `Rsx_Sms::delivery_mode()` returns each | implemented | 2026-08-31 |
| SMS-42 | `suppressed` records every claimed row as undeliverable | php | drain in suppressed | `suppressed` > 0; row SUPPRESSED | implemented | 2026-08-31 |
| SMS-43 | `disabled` FREEZES the queue - freezing is not suppressing | php | drain in disabled | counts 0; row still PENDING with no `last_error` | implemented | 2026-08-31 |
