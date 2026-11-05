<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL STATEMENT of the event system; other fragments name only their own events. -->

## EVENTS

Attribute hooks discovered by the manifest, no registration: a public static method in `/rsx/handlers/` marked `#[OnEvent('some.event', priority: 10)]`. Four triggers: **`Rsx::trigger_action()`** (fire-and-forget), **`trigger_filter()`** (each handler returns the transformed data), **`trigger_gate()`** (first non-`true` denies; **a gate with NO handlers is OPEN**), **`trigger_resolve()`** (first non-null wins; all-declined = the framework default). **Handlers run inline, in the request** - hand heavy work to `Task::dispatch()`. Skill `rspade:event-hooks`; `rsx:man event_hooks`.

## EMAIL & SMS

**One email is one CLASS**: `X_Email extends Rsx_Email_Abstract` in `/rsx/emails/` beside its blade (`@rsx_id` = the class basename), declaring `const CATEGORY`, `subject()`, `data()` and `static sample()`; sent fluently, `(new Welcome_Email($user))->to($user)->send()`, queued and drained within seconds, never inline. **Never touch a transport or Laravel's Mail facade, and never hand-render the blade** (`Rsx_Mail::preview()`). Categories `TRANSACTIONAL` / `NOTIFICATION` / `MARKETING` / `SECURITY` (only for a notice the recipient set in motion). **A feature that depends on a message arriving asks `Rsx_Mail::deliverable_to($email, $category)` first**, so it degrades instead of locking somebody out. **`rsx.mail.delivery` defaults to `suppressed`** - every message is rendered and recorded, nothing leaves (`aiosmtpd` | `live` | `disabled` are the others) - and test recipients (`test.*` domains, `555` numbers) are never delivered in any mode. Emailed links are `rsx_absolute_url(...)` and datetimes are formatted server-side (`EMAIL-TEMPLATE-01`). `Rsx_Sms::send($to, $body, $category)` is the mirror; no provider is wired, so every SMS is recorded Suppressed.

Skill `rspade:email-and-sms`. Details: `rsx:man email`, `rsx:man sms`.
