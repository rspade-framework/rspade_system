<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL STATEMENT of the event system; other fragments name only their own events. -->

## EVENTS

Attribute-based hooks discovered by the manifest — **no registration**. A handler is a public static method in `/rsx/handlers/` marked `#[OnEvent('some.event', priority: 10)]`.

Four trigger kinds: **`Rsx::trigger_action()`** (fire-and-forget), **`trigger_filter()`** (each handler transforms and returns the data, chained), **`trigger_gate()`** (first non-`true` return denies; **`true` permits**, and a gate with NO handlers is OPEN), **`trigger_resolve()`** (first NON-NULL result wins; `null` = declined, all-declined = the framework default). **Handlers run inline, in the request** — hand heavy work to `Task::dispatch()`.

Skill `rspade:event-hooks` (return contracts, priority, the manifest-lifecycle family, the event catalog). `rsx:man event_hooks`.

## EMAIL & SMS

**One email is one CLASS**: `X_Email extends Rsx_Email_Abstract` in `/rsx/emails/` beside its blade (`@rsx_id` == class basename), declaring `const CATEGORY` + `subject()` + `data()` + `static sample()` (each missing one is a manifest-build FATAL). Send it fluently — `(new Welcome_Email($user))->to($user)->cc(...)->attach(...)->embed('cid', ...)->dedupe_key(...)->send()` returns the queue row. **Never touch a transport or Laravel's Mail facade yourself.**

**Categories**: `TRANSACTIONAL` (always delivers, ignores opt-out), `NOTIFICATION`, `MARKETING`; blocklist verbs `Rsx_Mail::is_blocked/block/unblock/block_all`. Statuses: Pending, Sending, Sent, Failed, **Blocked** (opted out), **Suppressed** (recorded, deliberately not delivered).

**Queued and drained within seconds, never inline** — a transport outage leaves rows PENDING, attempts unspent, for the minute sweeper; an SMTP error retries on its own clock and FAILS at the cap; a row due over `rsx.mail.stale_after_days` ago is refused and FAILED naming `rsx:mail:resend` (a repaired queue must not flood a month of stale notices). `config('rsx.mail.delivery')` = **`aiosmtpd|live|suppressed|disabled`**, anything else throws: `aiosmtpd` (default) IGNORES `rsx.mail.transport.*` and captures into the dev catcher at `storage/mail-catcher/`, whose greeting must say `aiosmtpd`; `disabled` FREEZES the queue; the `.dev.`-hostname recipient layer applies **in `live` only**. **Every emailed link is `rsx_absolute_url(...)` and every datetime is formatted server-side — `EMAIL-TEMPLATE-01`.** Inspect with `rsx:mail:test|:queue|:show|:resend`.

**`Rsx_Sms::send($to, $body, $category)` is the deliberate mirror** — same queue, same statuses, `delivery` = `suppressed|disabled` only; no provider is wired, so every SMS is recorded Suppressed.

Skill `rspade:email-and-sms`. Details: `rsx:man email`, `rsx:man sms`.
