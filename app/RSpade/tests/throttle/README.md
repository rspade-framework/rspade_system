# Concern: throttle

## Domain

`App\RSpade\Core\Throttle\Rsx_Throttle` - the per (site, user, action) rate limiter for periodic
work that rides along on a user request (notification expiry sweeps, cache refreshes). The API is
two static methods: `check($action_key, $user_id, $minutes)` claims the interval and returns
whether the caller should run; `reset($action_key, $user_id)` drops the claim.

The claim is ONE atomic upsert against `_throttle`, serialized by the table's
`uk_throttle_site_user_action` unique key:

```
INSERT ... VALUES (..., NOW(3), NOW(3), NOW(3))
ON DUPLICATE KEY UPDATE last_executed_at = IF(last_executed_at < NOW(3) - INTERVAL ? MINUTE, NOW(3), last_executed_at)
```

Affected rows decide the answer: 1 = inserted (first ever), 2 = updated (interval elapsed),
0 = the row was left alone (still throttled). `updated_at` is left to the column's
`ON UPDATE CURRENT_TIMESTAMP(3)` so an untouched row is a true no-change row.

Two properties follow, and both are covered here:

- **Race-free without a lock.** Concurrent callers either lose the unique-key race on insert or
  see the already-advanced timestamp on update.
- **Transaction-safe.** The claim participates in the caller's transaction. The retired
  implementation bracketed the read-modify-write in `LOCK TABLES` / `UNLOCK TABLES`, each of which
  implicitly COMMITs the enclosing transaction - so a caller's later rollback silently did nothing.

## Source under test

- `system/app/RSpade/Core/Throttle/Rsx_Throttle.php`
- `system/database/migrations/2026_01_29_081808_create_throttle_table.php` (the unique key the
  atomicity rests on, and the TIMESTAMP(3) precision)

## Man pages

None - `Rsx_Throttle` is an internal utility with no dedicated man page. Behavior of record is the
class docblock plus this concern.

## Testable surface

- php: first-call allow, within-interval throttle, elapsed-interval allow, per-call interval
  evaluation, transaction participation, reset.
- Not covered: true multi-process contention (would need concurrent workers; the unique key makes
  the outcome structural rather than timing-dependent, so the single-process tests pin the
  contract that matters).
