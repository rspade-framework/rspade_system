---
name: email-and-sms
description: "Sending mail and SMS in RSpade - writing an email class (Rsx_Email_Abstract in rsx/emails/ with const CATEGORY, subject(), data(), sample()), the fluent to/cc/reply_to/attach/embed/dedupe_key envelope, the queue statuses and the drain's failure policy, the four delivery modes (aiosmtpd | live | suppressed | disabled) and the live-only dev-site recipient gate, the development SMTP catcher and its aiosmtpd greeting, rsx:mail:test / rsx:mail:queue / rsx:mail:show / rsx:mail:resend, /_mail/unsubscribe, and the Rsx_Sms mirror. Use when a feature must notify somebody by email or text, when writing or styling an email template, when a queued message did not arrive, when inspecting or resending the mail queue, or when hitting EMAIL-TEMPLATE-01, Mail_Transport_Unavailable_Exception, STATUS_SUPPRESSED, \"expected server aiosmtpd\", \"Timed out - email was queued too far in the past\", or \"dev site: no whitelist match and no catchall\"."
---

# Email and SMS

**One email is one CLASS**, in `rsx/emails/`, beside the blade that renders it. **Every message is QUEUED**, never sent inline — `send()` writes a row and kicks the drain, which runs within seconds. Nothing in a request path talks to SMTP.

```php
(new Welcome_Email($user, $login_url))->to($user)->send();   // -> Email_Queue_Model
```

---

## Writing one

Two files, one name:

```
rsx/emails/welcome_email.php
rsx/emails/welcome_email.blade.php
```

```php
namespace Rsx\Emails;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;

class Welcome_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::TRANSACTIONAL;          // REQUIRED - build FATAL without it

    public function __construct(public User_Model $user) {}

    public function subject(): string
    {
        return 'Welcome to ' . config('rsx.name');
    }

    public function data(): array                   // plain scalars/arrays - this is JSON
    {
        return [
            'name' => $this->user->get_printed_name(),
            'login_url' => rsx_absolute_url(Rsx::Route('Login_Controller::index')),
        ];
    }

    public static function sample(): static         // REQUIRED - previews, tests, rsx:mail:test
    {
        $user = new User_Model();
        $user->first_name = 'Ada';
        $user->last_name = 'Lovelace';
        $user->email = 'ada@example.com';

        return new static($user);
    }
}
```

```blade
@rsx_id('Welcome_Email')                            {{-- EQUALS the class basename --}}
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? 'Welcome') !!}

<p>Hello {{ $name }},</p>

<p style="text-align: center;">
    <a href="{{ $login_url }}" class="email-button">Sign in</a>
</p>

{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
```

`$subject`, `$branding` and `$unsubscribe_url` (null for transactional) are injected; everything else is `data()`. Classes the shipped stylesheet defines: `.email-button`, `.email-muted`, `.email-divider`, plus the wrapper/container/header/body/footer scaffolding.

**Three manifest-build FATALs**: no `const CATEGORY`, no `static sample()`, or a blade whose `@rsx_id` is not the class basename.

**A text part** is optional: a second blade with `@rsx_id('Welcome_Email_Text')` wins; otherwise the text is derived from the HTML.

---

## Sending

```php
(new Invoice_Email($invoice))
    ->to($client, 'Accounts Payable')      // address string, or an object with ->email
    ->cc($manager)->bcc('archive@example.com')     // cc/bcc ACCUMULATE
    ->reply_to($staff)                     // to()/reply_to() REPLACE
    ->attach($file_attachment)             // File_Attachment_Model, or an absolute path
    ->attach_bytes($csv, 'lines.csv', 'text/csv')  // name + mime REQUIRED
    ->embed('chart', $chart_attachment)    // template: <img src="cid:chart">
    ->dedupe_key("invoice:{$invoice->id}:v{$invoice->version}")
    ->send_at($iso)                        // earliest it may go
    ->about($related_type, $related_id)
    ->send();
```

A recipient object's display name comes from `get_printed_name()`, else `first_name`/`last_name`, else nothing. **A `dedupe_key` already used by this site returns THAT row and enqueues nothing** — a replayed webhook cannot double-mail anybody.

Enqueue order (load-bearing): site (portal-aware) -> dedupe -> blocklist (non-transactional to an opted-out address becomes a **BLOCKED** row) -> dev-site gate -> freeze `subject()`/`data()` -> persist attachments -> kick the drain.

**Categories**: `TRANSACTIONAL` (always delivers, ignores the blocklist), `NOTIFICATION`, `MARKETING`. Blocklist: `Rsx_Mail::is_blocked/block/unblock/block_all($email, $category)`.

A root-relative `<img src="/img/logo.png">` is embedded as a `cid:` part automatically. A remote `https://` src is left alone.

---

## Statuses, the drain, failure policy

| Id | Status | Meaning |
|---|---|---|
| 1 | Pending | queued; sendable when `next_attempt_at` is null or past |
| 2 | Sending | claimed right now |
| 3 | Sent | the transport accepted it |
| 4 | Failed | attempt cap reached, or the build failed |
| 5 | Blocked | never queued: the recipient opted out |
| 6 | Suppressed | rendered and recorded, deliberately not delivered |

`Mail_Queue_Service::send_pending_queue` is `#[Exclusive] #[Schedule('every minute')]`. It reclaims anything stranded in SENDING, then claims rows one at a time.

- **SMTP answered with an error for THIS message** -> `attempt_count++`, `next_attempt_at` +`rsx.mail.retry.delay_minutes`, FAILED at `rsx.mail.retry.attempts` with the server's reply in `last_error`.
- **The transport could not be reached** -> the row goes back to PENDING with its attempt NOT counted, one reconnect + retry of the same message, then `Mail_Transport_Unavailable_Exception` ends the drain. Rows stay PENDING; the minute sweeper retries forever. An outage is to be seen, not to burn retries on.
- **A build/render error** -> FAILED immediately. No retry fixes a code bug.

Retention: whole rows (attachments cascade) deleted after `rsx.mail.retention_days` (30) by the daily cleanup.

**The stale sweep — queue hygiene, NOT a timeout.** At the start of every drain (never in `disabled`), any PENDING row due more than `rsx.mail.stale_after_days` (2) ago is marked FAILED. The scenario it guards against: the queue silently stops working — a dead transport, a misconfigured host, a stopped worker — and nobody notices for a month; the moment it is repaired the drain would flood a month of stale notices, out of date, irrelevant and impossible to retract. So the drain refuses, and `last_error` tells the operator how to resend it. **The decision to send old mail belongs to a human.** Due date is `COALESCE(next_attempt_at, created_at)`, so a `send_at()` five days out is not late until five days from now.

---

## Delivery modes, the dev gate, the catcher

`config('rsx.mail.delivery')` has **four** values; anything else throws (`Rsx_Mail_Transport::delivery_mode()`):

| Mode | What happens |
|---|---|
| `aiosmtpd` | **The shipped default.** Captured by the development catcher on `127.0.0.1:1025`. The whole `rsx.mail.transport.*` block is **ignored**, so a stale `MAIL_HOST` cannot mail anybody by accident. |
| `live` | Real delivery through `rsx.mail.transport.*` — **the only mode that reads it, and the only one the dev-site gate applies to**. |
| `suppressed` | Rendered and recorded, handed to nobody. Rows end **Suppressed**. |
| `disabled` | **The queue is frozen.** `send()` still queues; the drain logs one line and returns. Rows stay Pending, untouched, and are never set aside as stale. |

The **dev-site layer** is separate, keyed on the hostname containing `.dev.`, and applies **in `live` mode only**: address whitelist -> domain whitelist -> catchall (`dev_original_to` records the real address) -> otherwise the row is written **Suppressed at enqueue** with `"dev site: no whitelist match and no catchall"`. No other mode can reach a real person, so gating there would just hide a developer's own mail.

The dev container runs `system/bin/mail_catcher.py` as `[program:mail-catcher]` on `127.0.0.1:1025`, SMTP only, no web UI, writing `storage/mail-catcher/`. **It advertises itself** — its greeting is `220 <host> aiosmtpd <version> (RSpade dev mail catcher)`, and `aiosmtpd` mode refuses any connection whose greeting does not say `aiosmtpd` (something else on that port could be a real relay). aiosmtpd's own CLI says `Python SMTP <version>` and has no flag for it, which is why the catcher is a module.

```bash
php artisan rsx:mail:test you@example.com          # --email=Some_Email, --json
cat "$(ls -t storage/mail-catcher/new/* | head -1)"
```

`rsx:mail:test` drains synchronously and reports the row's real status (exit 0 on Sent, Suppressed, or Pending under `disabled` — where it does not drain at all). `rsx:health` carries **Mail delivery**, **Mail transport** and **Mail sender domain** rows.

## Inspecting and resending

```bash
php artisan rsx:mail:queue                                  # counts per status + oldest pending age
php artisan rsx:mail:queue --status=failed --recipient=@acme.com --limit=50
php artisan rsx:mail:show 412                               # every column; bodies by length (--json has them)
php artisan rsx:mail:resend 412                             # back to Pending, attempts 0, drain kicked
```

`--recipient` matches `to_address` **and** `dev_original_to`, so a message redirected to a dev catchall is still found by who it was really for. `rsx:mail:resend` works for Failed, Suppressed and Blocked — but a **Blocked** row requires `--force`, because Blocked is a consent record and overriding it quietly would make the unsubscribe link a lie.

---

## Unsubscribe

Every non-transactional message carries a signed footer link and `List-Unsubscribe` / `List-Unsubscribe-Post` headers pointing at `GET|POST /_mail/unsubscribe?email&category&site&sig`. The HMAC over `email|category|site_id` is the entire authorization — no login, no session, the site read from the signature. GET confirms and records nothing; POST blocks the category (or `scope=all`); an RFC 8058 one-click POST answers `200 text/plain`. Anything invalid is one indistinguishable 404.

---

## Gotcha catalog

- **`<img src="cid:chart">` with no `->embed('chart', ...)`** — build failure, row FAILED. The cid names must match exactly.
- **A root-relative image that resolves to nothing** — build failure too. A broken image is a broken email, not a warning.
- **Missing `const CATEGORY` / `sample()` / matching `@rsx_id`** — the build FATALs with a worklist. There is no default category on purpose.
- **`sample()` must not need the database.** Build an unsaved model with the fields the template reads.
- **Every link goes through `rsx_absolute_url()`**, and every datetime is formatted server-side (best: in `data()`, so the frozen row carries the string). `EMAIL-TEMPLATE-01` (high) enforces both over `/emails/` blades; the escape is `@EMAIL-TEMPLATE-01-EXCEPTION <rationale>` on the line or the one above.
- **`data()` is frozen at `send()`.** Passing a model and reading it at render time is impossible by design — the drain never asks the class anything again.
- **`dedupe_key` returns the old row in ANY status**, including Failed. It means "this send already happened", not "retry it".
- **Tests do not auto-drain** (the runner's connection is swapped). Call `Task::internal('Mail_Queue_Service', 'send_pending_queue')`. The stub seam is `Rsx_Mail_Transport::$override_for_tests`, cleared in a `finally`.
- **The tenant follows the EXPERIENCE**, not the identity: a portal request files under the portal's site. A sessionless task resolves to site 0 — `Session::set_site_id()` first when it must target a tenant.

---

## Troubleshooting

| Symptom | Where to look |
|---|---|
| "Nothing arrives" | `php artisan rsx:mail:test <you>` — it prints the mode, the transport, the row status and the catcher file. Then `rsx:health` (Mail rows). |
| Row is **Suppressed** | Either `MAIL_DELIVERY=suppressed`, or the dev gate had no whitelist match and no catchall (`last_error` says which). |
| Row is **Failed** with `"Timed out - email was queued too far in the past"` | The stale sweep refused it: it was due more than `rsx.mail.stale_after_days` ago, which means the queue was not running. Fix why, then `rsx:mail:resend <id>` anything still worth sending — that decision is yours, not the drain's. |
| Rows pile up **Pending** and nothing is even attempted | `MAIL_DELIVERY=disabled` — the queue is deliberately frozen. `rsx:mail:queue` prints the mode first. |
| Every row Failed with `"expected server aiosmtpd"` | Something other than the dev catcher holds `127.0.0.1:1025`. `supervisorctl status mail-catcher`, or set `MAIL_DELIVERY=live` if this box really is meant to send. |
| Row is **Blocked** | The recipient opted out of that category. Transactional never blocks — check the categorisation. |
| Row is **Failed** | `last_error` carries the SMTP reply or the build error. A build error is a code bug; no retry helps. |
| Rows stay **Pending** with attempts unspent | The transport is unreachable — the drain threw. Fix the host and the next sweep sends them. |
| Pending with `next_attempt_at` in the future | A server error is serving its retry delay, or `send_at()` held it. |
| Mail arrives, links are dead | A template built a URL without `rsx_absolute_url()`, or `APP_URL` is wrong on the sending host. |
| Styles missing | One stylesheet wins: `rsx/emails/email.scss` if present, else the framework default. There is no merge. |

---

## SMS

`Rsx_Sms` mirrors `Rsx_Mail` deliberately — same statuses, same claim, same retention, no templates.

```php
Rsx_Sms::send('+15555550123', 'Your code is 4821', Rsx_Sms::TRANSACTIONAL);
Rsx_Sms::block_all($number);        // what a carrier STOP reply calls
```

`send($to, $body, $category = NOTIFICATION, $related_type = null, $related_id = null): Sms_Queue_Model`. **No provider is wired**, so `rsx.sms.delivery` is `suppressed` (the only other value is `disabled`, which freezes the queue exactly as it does for mail; `live` and `aiosmtpd` are refused out loud, and there is no `stale_after_days` mirror) and the drain records each row **Suppressed** with `"no SMS provider configured"` — the body is stored verbatim for an SMS log. Dev gating: `SMS_DEV_NUMBER_WHITELIST`, `SMS_DEV_CATCHALL_NUMBER`. Models: `Sms_Queue_Model`, `Sms_Recipient_Model`.

Details: `php artisan rsx:man email`, `rsx:man sms`. Related: `rspade:background-tasks`, `rspade:portal-core`, `rspade:file-attachments`.
