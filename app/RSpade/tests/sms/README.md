# sms

Outbound SMS. **There is no SMS provider**, and that is the concern's defining fact
rather than a gap in it.

## Domain

`Rsx_Sms::send($to, $body, $category)` queues a row exactly as the mail facade does -
same tenant resolution, same per-category blocklist, same `.dev.`-host recipient gate,
same statuses - and `Sms_Queue_Service::send_pending_queue` claims each row and records
it **SUPPRESSED** saying no provider is configured. That is the whole runner, and it is
honest: a message nobody can deliver is not "pending", it is not going.

The value of that shape is that wiring a provider means replacing the body of the try and
adding two catches, not designing a queue. So these tests hold the SHAPE, and they are
deliberately the mail concern's tests with the transport removed:

- **The queue is a mirror.** `enqueue` / `claim_next` / `mark_sent` / `mark_suppressed` /
  `mark_server_error` / `release_to_pending` / `mark_failed` / `reclaim_stranded` /
  `cleanup_old` exist on both models with the same semantics. A divergence here is a
  divergence a provider integration would have to discover the hard way.
- **The claim is atomic** - a conditional UPDATE, so two drains can never take one row.
- **A row stranded in SENDING is reclaimed** by the next drain. The drain is
  `#[Exclusive]`, so when it starts no other runner exists and a SENDING row was left by
  one that died mid-message; nothing else could ever free it, because `claim_next()` only
  looks at PENDING.
- **The opt-out is enforced from a task with no session**, where the site the blocklist is
  read at and the site the row is written to must agree. TRANSACTIONAL always delivers.
- **A dev host with nothing whitelisted and no catchall records SUPPRESSED at enqueue**,
  not at drain time - the mail side does exactly this, and the two facades stay one shape.
- **Retention deletes terminal rows only.** A row nobody has decided about survives any
  window.

## Source under test

| File | Role |
|------|------|
| `Core/Sms/Rsx_Sms.php` | The send facade: tenant, blocklist, dev-host gate, enqueue, and the two delivery modes |
| `Core/Sms/Sms_Queue_Service.php` | The drain (frozen in `disabled`; otherwise reclaim, then SUPPRESSED per row) and the daily cleanup |
| `Core/Models/Sms_Queue_Model.php` | The row's state machine, the claim, `reclaim_stranded()`, retention |
| `Core/Models/Sms_Recipient_Model.php` | Per-number blocklist and counters |

Behavior of record: `php artisan rsx:man sms`. Config: `rsx:man config_rsx`.

## Testable surface

- **php** - `php/Sms_Queue_Test`: enqueue, the atomic claim, the SUPPRESSED terminal
  status, the exclusivity policy, blocklist behaviour with and without a session, the
  dev-host gate, the stranded-row reclaim (model-level and through the drain), and
  retention.
- **cli / http / asset / playwright** - none. There is no SMS command, no HTTP surface and
  no browser involvement.

## When a provider is wired

The rows this concern will need are already named in `test_catalog.md` as `planned`: a
delivered message reaching SENT with the provider's id recorded, a provider error taking
the retry clock to the cap, an unreachable provider releasing the row and killing the
drain, and the per-recipient counters. Adding a provider without adding those is adding a
delivery path with no coverage at all - the queue tests below would still pass, because
they pass today with nothing being delivered.
