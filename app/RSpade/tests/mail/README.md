# mail

Outbound email: an `Rsx_Email_Abstract` subclass becomes a queue row, the row becomes a
MIME message, and the message reaches a transport. Every step is covered here, and the
last test in the concern does all three with nothing stubbed.

## Domain

An email in RSpade is a CLASS, co-located with the blade that renders it. The chain the
tests follow is:

1. **Enqueue** - `(new Some_Email($x))->to($person)->send()` resolves the tenant, honours
   the blocklist and a `dedupe_key`, applies the `.dev.`-hostname recipient gate, FREEZES
   `subject()` and `data()` into an `email_queue` row, persists attachments into the
   content-addressed blob store, and kicks the drain.
2. **Claim** - `Mail_Queue_Service::send_pending_queue` (`#[Exclusive]`,
   `#[Schedule('every minute')]`) reclaims anything stranded in SENDING, then takes rows
   one at a time with a conditional UPDATE.
3. **Build** - `Rsx_Mail_Builder` renders the blade, inlines the compiled stylesheet,
   turns local images into `cid:` parts, derives the text part, and writes both rendered
   bodies plus the header map back onto the row.
4. **Transport** - a Symfony mailer built from `rsx.mail.transport`, and one of three
   outcomes the runner keeps strictly apart.
5. **Unsubscribe** - the signed link every non-transactional footer and `List-Unsubscribe`
   header carries, and the blocklist row it writes.

Invariants the tests exist to hold:

- **A queued message is never lost and never silently duplicated.** The claim is a
  conditional UPDATE, so two drains cannot take one row; `reclaim_stranded()` is what
  rescues a row whose runner died mid-send, and it is safe precisely because
  `#[Exclusive]` proves no other runner exists when the drain starts.
- **The two failure classes are not the same failure.** A server error FOR THIS MESSAGE
  gets the message's own retry clock and eventually FAILS it; a transport that cannot be
  reached gets one reconnect and then kills the whole drain, leaving every row PENDING
  with its attempts unspent. Conflating them is how a queue quietly destroys mail.
- **Values are frozen at send(), not at drain time.** A message queued today and sent
  after a retry tomorrow says the same thing.
- **A row that was never delivered still records why.** BLOCKED, SUPPRESSED and FAILED
  all exist so "we had something to tell them and this is what happened" is answerable
  six months later - which is why the builder runs even in suppressed mode.
- **The opt-out is honoured everywhere, including from a task with no session.** The site
  the blocklist is read at and the site the row is written to must agree, or the check is
  an impossible query that always says "not blocked".
- **The unsubscribe signature is the entire authorization**, and every refusal is one
  indistinguishable 404.
- **An email has no browser and no origin.** Datetimes are formatted server-side and
  every link is absolute - enforced by the `EMAIL-TEMPLATE-01` code-quality rule, whose
  own tests live in the `code_quality` concern.

## Source under test

| File | Role |
|------|------|
| `Core/Mail/Rsx_Email_Abstract.php` | The email class contract and the fluent envelope |
| `Core/Mail/Rsx_Mail.php` | Tenant resolution, blocklist, dev-site gate, unsubscribe signature, attachment persistence, drain kick |
| `Core/Mail/Email_ManifestSupport.php` | The build-time email table and its three FATALs |
| `Core/Mail/Rsx_Mail_Builder.php` | Row -> `Symfony\Component\Mime\Email` |
| `Core/Mail/Rsx_Mail_Text.php` | The derived plain-text part |
| `Core/Mail/Rsx_Mail_Transport.php` | The four delivery modes, DSN construction, the aiosmtpd banner probe, `$override_for_tests` / `$banner_for_tests`, the `Mail` health rows |
| `Core/Mail/Mail_Queue_Service.php` | The drain and the daily cleanup |
| `Core/Mail/Mail_Unsubscribe_Controller.php` | `GET`/`POST /_mail/unsubscribe`, incl. RFC 8058 one-click |
| `Core/Mail/Rsx_Mail_Test_Email.php` | The framework's own smoke-test email |
| `Core/Models/Email_Queue_Model.php` | The row's state machine, the claim, `reclaim_stranded()`, retention |
| `Core/Models/Email_Attachment_Model.php` | Attachment and inline-image rows |
| `Core/Models/Email_Recipient_Model.php` | Per-recipient blocklist and counters |
| `Commands/Rsx/Mail_Test_Command.php` | `rsx:mail:test` |
| `Commands/Rsx/Mail_Queue_Command.php` | `rsx:mail:queue` - the summary and the filtered listing |
| `Commands/Rsx/Mail_Show_Command.php` | `rsx:mail:show` - one row in full |
| `Commands/Rsx/Mail_Resend_Command.php` | `rsx:mail:resend` - the escape hatch, and the Blocked `--force` gate |
| `system/bin/mail_catcher.py` | The development catcher, and the `aiosmtpd` greeting the mode verifies |
| `helpers.php` (`rsx_absolute_url`) | The origin every emailed link needs |

Behavior of record: `php artisan rsx:man email`. Config: `rsx:man config_rsx`.

## Fixtures

- `php/Mail_Notification_Fixture_Email.{php,blade.php}` - a NOTIFICATION-category email.
  The framework ships exactly one real email and it is TRANSACTIONAL by design, so it can
  never exercise the opt-out path. The blade also carries an `.email-button` link and an
  optional `cid:` image, so the CSS-inlining and embed paths have something to act on.
- `php/Mail_Transport_Stub.php` - a `TransportInterface` that answers ACCEPT,
  SERVER_ERROR, UNREACHABLE or UNREACHABLE_ONCE, installed through
  `Rsx_Mail_Transport::$override_for_tests`. The drain constructs its own transport, so
  this is the only seam a test has for watching the loop react to an SMTP outcome.

## Testable surface

- **php** - the queue row's state machine and retention (`Email_Queue_Test`); the enqueue
  path, tenant resolution, recipients, blocklist, dedupe, scheduling, the dev-host gate
  and attachments (`Rsx_Email_Enqueue_Test`); MIME structure, CSS inlining, image
  embedding, text derivation and headers (`Rsx_Mail_Builder_Test`); the drain's three
  outcomes plus reclaim and cleanup, driven by the stub transport
  (`Mail_Queue_Runner_Test`); the signed unsubscribe round trip (`Mail_Unsubscribe_Test`);
  the build-time FATALs over a synthetic manifest (`Mail_Manifest_Support_Test`);
  `rsx_absolute_url()` with no request (`Absolute_Url_Test`).
- **cli** - `rsx:mail:test`, run in-process through `Artisan::call()` so the transport can
  be pinned at the catcher and the row lands in the test database
  (`cli/Mail_Test_Command_Cli_Test`).
- **The end-to-end test** - `Mail_Catcher_Delivery_Test` sends a real message over a real
  SMTP conversation to the development catcher and asserts against the file the catcher
  wrote. It NEVER skips: if the catcher is not listening it FAILS and names
  `[program:mail-catcher]`, because a green suite that quietly stopped proving delivery is
  worse than a red one.
- **http** - not needed. The only HTTP surface is `/_mail/unsubscribe`, and it is fully
  exercised in-process through `Dispatcher::dispatch()`.
- **playwright** - not applicable; email is not rendered in this application's browser.

## Notes for anyone adding to this concern

- The test runner suppresses the automatic drain kick (`config('database.default') ===
  'test'`), so a test that wants the queue drained runs it explicitly with
  `Task::internal('Mail_Queue_Service', 'send_pending_queue')`.
- The framework test host is a `.dev.` hostname, so the dev-site recipient gate is LIVE
  for every send. A test whose subject is not the gate whitelists `example.com` past it;
  a test whose subject IS the gate pins `rsx.mail.transport.host` to a non-loopback value,
  because the gate does not engage on a transport that cannot leave this box.
- Every test that sets `Rsx_Mail_Transport::$override_for_tests` clears it in a `finally`.
  A stub surviving into another class would make that class's failures unattributable.
- Site context is ESTABLISHED, never inherited (backlog B-45, closed by this concern):
  each class has a `setup()` declaring its site, and each test declares its own too.
