# mail - test catalog

The queue row's own state machine, retention, and the stranded-row reclaim, via
`Email_Queue_Test`.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MAIL-01 | a queued row starts PENDING | php | `Email_Queue_Model::enqueue()` | status PENDING | implemented | 2026-08-31 |
| MAIL-02 | SUPPRESSED is its own terminal status, not a failure and not a send | php | `mark_suppressed()` | status SUPPRESSED, `sent_at` stamped, label "Suppressed" | implemented | 2026-08-31 |
| MAIL-03 | the claim is atomic: a claimed row is SENDING and never claimed twice | php | `claim_next()` twice | first returns the row as SENDING, second never returns it | implemented | 2026-08-31 |
| MAIL-04 | a row serving a retry delay or a `send_at()` is skipped | php | row with future `next_attempt_at` | never returned by `claim_next()` | implemented | 2026-08-31 |
| MAIL-05 | a server error retries to the cap, then FAILS with the server's own reply | php | `mark_server_error()` x `rsx.mail.retry.attempts` | PENDING with `next_attempt_at` until the cap, then FAILED, reply recorded | implemented | 2026-08-31 |
| MAIL-06 | a transport outage does not burn the message's retry budget | php | `release_to_pending()` on a SENDING row | PENDING, `attempt_count` 0 | implemented | 2026-08-31 |
| MAIL-07 | the drain is single-instance | php | `Task_Concurrency::get_policy()` | mode `exclusive` | implemented | 2026-08-31 |
| MAIL-08 | a stranded SENDING row is returned to PENDING with a note saying why | php | row forced to SENDING, `reclaim_stranded()` | count > 0, status PENDING, `last_error` = the reclaim note | implemented | 2026-08-31 |
| MAIL-09 | the reclaim is not a retry - it never spends an attempt | php | stranded row with `attempt_count` 1 | `attempt_count` still 1 | implemented | 2026-08-31 |
| MAIL-10 | there is no age threshold: exclusivity is the signal, not elapsed time | php | row claimed one statement earlier | reclaimed anyway | implemented | 2026-08-31 |
| MAIL-11 | the reclaim touches nothing but SENDING | php | PENDING + SENT + FAILED rows | all three unchanged, no note written | implemented | 2026-08-31 |
| MAIL-12 | a clean queue reclaims nothing, so the drain narrates nothing | php | no stranded rows | returns 0 | implemented | 2026-08-31 |
| MAIL-13 | retention deletes terminal rows past the window and keeps recent ones | php | `cleanup_old(30)` over a 90-day-old SENT row and a fresh one | old gone, recent kept | implemented | 2026-08-31 |
| MAIL-14 | retention never deletes a row nobody has decided about | php | 90-day-old PENDING row | survives | implemented | 2026-08-31 |

The enqueue path - `(new Some_Email(...))->to($x)->send()` up to the row - via
`Rsx_Email_Enqueue_Test`.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MAIL-20 | the row is filed under the acting site | php | `__acting_as_site(1)` + send | `site_id` 1, PENDING | implemented | 2026-08-31 |
| MAIL-21 | the opt-out is enforced from a task with NO session (site 0), where the check and the row must agree | php | no session, `block()` then `is_blocked()` | blocked at site 0 | implemented | 2026-08-31 |
| MAIL-22 | a sessionless send persists site 0 rather than inventing site 1 | php | no session, send | PENDING, `site_id` 0, `email_class` set | implemented | 2026-08-31 |
| MAIL-23 | `to()` resolves the display name from `get_printed_name()` | php | object exposing `->email` + `get_printed_name()` | `to_name` from the method, `to_address` from `->email` | implemented | 2026-08-31 |
| MAIL-24 | `to()` composes a first/last pair when there is no `get_printed_name()` | php | object with `first_name`/`last_name` | `to_name` = "First Last" | implemented | 2026-08-31 |
| MAIL-25 | an explicit name always wins over a resolved one | php | `to($object, 'Explicit')` | `to_name` = the explicit one | implemented | 2026-08-31 |
| MAIL-26 | a message has one To; cc/bcc accumulate in declaration order | php | two `to()`, two `cc()`, two `bcc()` | last To wins; 2 cc + 2 bcc, names carried, null where unresolved | implemented | 2026-08-31 |
| MAIL-27 | `reply_to()` is recorded as address + name | php | `reply_to('support@...', 'Support Desk')` | both columns set | implemented | 2026-08-31 |
| MAIL-28 | a send with no recipient throws rather than queueing a row addressed to nobody | php | `send()` with no `to()` | `InvalidArgumentException` naming the missing recipient | implemented | 2026-08-31 |
| MAIL-29 | an empty address throws | php | `to('   ')` | `InvalidArgumentException` | implemented | 2026-08-31 |
| MAIL-30 | an object with no `->email` throws | php | `to(new stdClass)` | `InvalidArgumentException` | implemented | 2026-08-31 |
| MAIL-31 | an opted-out NOTIFICATION is recorded BLOCKED, never sent | php | `block_all()` then send | status BLOCKED, reason says "unsubscribed" | implemented | 2026-08-31 |
| MAIL-32 | the BLOCKED row still carries the subject and frozen data - the audit row is the point | php | blocked send | `subject` and `template_data` populated | implemented | 2026-08-31 |
| MAIL-33 | a `dedupe_key` returns the existing row and enqueues nothing | php | two sends, same key | same id, one row in the table | implemented | 2026-08-31 |
| MAIL-34 | dedupe returns a row in whatever state it reached, delivered included | php | second send after the first was marked SENT | same id, status SENT | implemented | 2026-08-31 |
| MAIL-35 | `send_at()` writes `next_attempt_at` and holds the row back from the claim | php | `send_at(+6h)` | PENDING with `next_attempt_at`, never claimed | implemented | 2026-08-31 |
| MAIL-36 | `about()` records the polymorphic subject on the row | php | `about(42, 7)` | `related_type` 42, `related_id` 7 | implemented | 2026-08-31 |
| MAIL-37 | a dev host with nothing whitelisted and no catchall records the row SUPPRESSED AT ENQUEUE, never queued | php | empty whitelists, non-loopback host | status SUPPRESSED, reason "dev site: no whitelist match and no catchall" | implemented | 2026-08-31 |
| MAIL-38 | a dev-host catchall rewrites the envelope and records the real recipient | php | catchall set, non-loopback host | `to_address` = catchall, `dev_original_to` = the real address, PENDING | implemented | 2026-08-31 |
| MAIL-39 | a LOOPBACK transport bypasses the gate - the catcher reaches nobody to protect | php | empty whitelists, host 127.0.0.1 | PENDING, address untouched, no `dev_original_to` | implemented | 2026-08-31 |
| MAIL-40 | `attach_bytes()` stores the blob content-addressed and PINS it against disposal | php | attach a CSV, then `release_blob_if_orphaned()` | attachment row correct, blob on disk, disposal returns false, bytes survive | implemented | 2026-08-31 |
| MAIL-41 | `embed()` records an inline attachment under its content id | php | `embed('fixture_image', $png)` | disposition INLINE, `cid` = the name | implemented | 2026-08-31 |
| MAIL-42 | attaching a file that does not exist throws at send time, not at drain time | php | `attach('/nonexistent/x.pdf')` | `RuntimeException` "no such file" | implemented | 2026-08-31 |
| MAIL-43 | `subject()` and `data()` are frozen at `send()` - mutating the instance afterwards changes nothing | php | send, then mutate the email object | row keeps the values the caller had | implemented | 2026-08-31 |
| MAIL-44 | the row's category comes from the class's `const CATEGORY` | php | NOTIFICATION fixture | `category_id` = NOTIFICATION | implemented | 2026-08-31 |

Row -> MIME message, via `Rsx_Mail_Builder_Test`.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MAIL-50 | a plain message is multipart/alternative, text first then html | php | row with no attachments | body `multipart/alternative`, parts `[text/plain, text/html]` | implemented | 2026-08-31 |
| MAIL-51 | an inline image and an attachment nest mixed > related > alternative - the only arrangement a client renders correctly | php | row with an `embed()` and an `attach_bytes()` | mixed[related[alternative[text,html], image/png], text/csv] | implemented | 2026-08-31 |
| MAIL-52 | the stylesheet is inlined onto elements AND kept in a `<style>` block | php | build a row | `<style>` present; the `.email-button` anchor carries `style=` | implemented | 2026-08-31 |
| MAIL-53 | a root-relative public image becomes a `cid:` part rather than a URL a client would refuse to fetch | php | `<img src="/favicon.ico">` | src rewritten to `cid:`, one part attached | implemented | 2026-08-31 |
| MAIL-54 | an absolute, protocol-relative or `data:` image is left exactly as written | php | three fixture srcs | unchanged, nothing embedded | implemented | 2026-08-31 |
| MAIL-55 | an unresolvable local image throws - a broken image is a broken email | php | `<img src="/img/missing.png">` | `RuntimeException` "does not resolve to a public asset" | implemented | 2026-08-31 |
| MAIL-56 | an unbound `cid:` reference throws and names the `embed()` call that is missing | php | `<img src="cid:chart">` with nothing embedded | `RuntimeException` naming `->embed('chart', ...)` | implemented | 2026-08-31 |
| MAIL-57 | a link becomes "text (url)" so a text reader can still act on it | php | `<a href="...">pay the invoice</a>` | `pay the invoice (https://...)` | implemented | 2026-08-31 |
| MAIL-58 | a link whose label is already the url is not repeated | php | label == href | the url once | implemented | 2026-08-31 |
| MAIL-59 | an in-document anchor contributes only its label | php | `<a href="#top">` | the label alone | implemented | 2026-08-31 |
| MAIL-60 | list items become bullets on their own lines | php | `<ul><li>..</li></ul>` | `- First\n- Second` | implemented | 2026-08-31 |
| MAIL-61 | `<br>` and paragraph closes become line breaks | php | `<p>One<br>Two</p><p>Three</p>` | three lines | implemented | 2026-08-31 |
| MAIL-62 | a run of block closes collapses to ONE blank line | php | empty divs between paragraphs | `One\n\nTwo` | implemented | 2026-08-31 |
| MAIL-63 | entities are decoded | php | `&amp;`, `&rsquo;`, `&lt;`, `&quot;` | literal characters | implemented | 2026-08-31 |
| MAIL-64 | head, style, script and title contribute nothing | php | full document | only the body text | implemented | 2026-08-31 |
| MAIL-65 | an image contributes its alt text, or nothing | php | `<img alt="...">` and `<img>` | the alt, then the empty string | implemented | 2026-08-31 |
| MAIL-66 | the derived text part carries the message and its links, with no markup | php | build a row | body text present, button spelled out, no `<` | implemented | 2026-08-31 |
| MAIL-67 | Reply-To and Cc reach the message with their display names | php | row with both | one Reply-To, one Cc, addresses and names correct | implemented | 2026-08-31 |
| MAIL-68 | the Message-ID is minted HERE and `X-RSX-Email-Id` names the queue row, both recorded on the row | php | build a row | headers present; `headers` column carries them | implemented | 2026-08-31 |
| MAIL-69 | a NOTIFICATION carries `List-Unsubscribe` + `List-Unsubscribe-Post` (RFC 8058) | php | NOTIFICATION row | both headers, pointing at `/_mail/unsubscribe` | implemented | 2026-08-31 |
| MAIL-70 | a TRANSACTIONAL message offers no unsubscribe, in the header or the footer | php | TRANSACTIONAL row | no header, no link in the html | implemented | 2026-08-31 |
| MAIL-71 | on a dev host the unsubscribe link names the ORIGINAL recipient, not the catchall | php | row with `dev_original_to` | the link carries the original address | implemented | 2026-08-31 |
| MAIL-72 | the rendered bodies are persisted on the row before the transport is offered anything | php | build a row | `rendered_html`/`rendered_text` empty before, populated after | implemented | 2026-08-31 |

The drain's state machine, via `Mail_Queue_Runner_Test` and the `Mail_Transport_Stub`.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MAIL-80 | an accepted message is SENT and fully recorded | php | stub ACCEPT | SENT, `attempt_count` 1, Message-ID, `sent_at`, no error, `transport` recorded | implemented | 2026-08-31 |
| MAIL-81 | a delivery counts against its recipient's history | php | stub ACCEPT | `total_sent` +1 | implemented | 2026-08-31 |
| MAIL-82 | a server error retries to the cap across passes, then FAILS with the reply | php | stub SERVER_ERROR x3 | PENDING + delay between passes, FAILED at the cap, "550" recorded, nothing scheduled | implemented | 2026-08-31 |
| MAIL-83 | a rejection counts against its recipient's history | php | stub SERVER_ERROR | `total_failed` +1 | implemented | 2026-08-31 |
| MAIL-84 | one reconnect retries THE SAME message and it goes out on the second try | php | stub UNREACHABLE_ONCE | 2 sends, same subject both times, SENT, `attempt_count` 1 | implemented | 2026-08-31 |
| MAIL-85 | a persistent outage THROWS and leaves the row PENDING with its attempts unspent | php | stub UNREACHABLE | `Mail_Transport_Unavailable_Exception`, exactly 2 sends, PENDING, `attempt_count` 0 | implemented | 2026-08-31 |
| MAIL-86 | an outage stops the whole drain without touching the rest of the queue | php | two queued rows, stub UNREACHABLE | both PENDING with 0 attempts | implemented | 2026-08-31 |
| MAIL-87 | a row whose template does not exist FAILS immediately - no retry can fix a code bug | php | row with an unknown `email_class` | FAILED, no `next_attempt_at`, reason recorded | implemented | 2026-08-31 |
| MAIL-88 | one broken row does not stop the queue | php | broken row + healthy row | the healthy one is SENT | implemented | 2026-08-31 |
| MAIL-89 | suppressed delivery still BUILDS and records both bodies, and offers the transport nothing | php | `rsx.mail.delivery = suppressed` | SUPPRESSED, reason recorded, bodies present, stub never called | implemented | 2026-08-31 |
| MAIL-90 | the drain reclaims a stranded SENDING row and sends it on the same pass | php | row forced to SENDING, stub ACCEPT | `reclaimed` > 0, row SENT | implemented | 2026-08-31 |
| MAIL-91 | a clean queue reports zero reclaimed | php | no stranded rows | `reclaimed` 0 | implemented | 2026-08-31 |
| MAIL-92 | the daily cleanup deletes old rows and prunes the catcher Maildir by mtime | php | old + recent rows, temp Maildir with a stale and a fresh file | old row deleted, recent kept, exactly the stale file pruned | implemented | 2026-08-31 |
| MAIL-93 | the cleanup tolerates a host with no catcher at all | php | `catcher_maildir` pointing nowhere | `catcher_pruned` 0, no error | implemented | 2026-08-31 |

The signed unsubscribe endpoint, via `Mail_Unsubscribe_Test` (in-process
`Dispatcher::dispatch()`).

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MAIL-100 | a valid signed link renders the confirmation page naming the address and the category | php | GET the signed URL | 200, address and "notification email" in the body | implemented | 2026-08-31 |
| MAIL-101 | a GET decides nothing - a link scanner's prefetch must not opt anybody out | php | GET only | no `email_recipients` row | implemented | 2026-08-31 |
| MAIL-102 | posting the form blocks the named category ONLY | php | POST `scope=category` | 200; notification blocked, marketing not | implemented | 2026-08-31 |
| MAIL-103 | `scope=all` blocks every opt-outable category and stamps the moment | php | POST `scope=all` | both blocked, `unsubscribed_at` set | implemented | 2026-08-31 |
| MAIL-104 | a one-click POST (RFC 8058) answers 200 plain text, no redirect, and blocks the category only | php | POST `List-Unsubscribe=One-Click` | 200, `text/plain`, "Unsubscribed"; category blocked, marketing not | implemented | 2026-08-31 |
| MAIL-105 | a tampered signature is a 404 | php | zeroed `sig` | 404 | implemented | 2026-08-31 |
| MAIL-106 | a link replayed at another site is a 404 - the blocklist is per tenant | php | `site` changed | 404 | implemented | 2026-08-31 |
| MAIL-107 | swapping the address or the category is a 404 | php | `email` changed, then `category` changed | 404 each | implemented | 2026-08-31 |
| MAIL-108 | a TRANSACTIONAL category is a 404 even with a valid signature | php | signed TRANSACTIONAL link | 404 | implemented | 2026-08-31 |
| MAIL-109 | a link missing any of the four parameters is a 404 | php | each parameter dropped in turn | 404 each | implemented | 2026-08-31 |
| MAIL-110 | an unknown scope is a 404 and records nothing | php | POST `scope=everything_everywhere` | 404, no recipient row | implemented | 2026-08-31 |
| MAIL-111 | the round trip: following the link from a message stops the NEXT one | php | send, POST the link, send again | first PENDING, second BLOCKED | implemented | 2026-08-31 |
| MAIL-112 | an opt-out never silences a TRANSACTIONAL message | php | `scope=all`, then a transactional send | PENDING | implemented | 2026-08-31 |

The build-time email table and its FATALs, via `Mail_Manifest_Support_Test` (a pure
function over a synthetic `$manifest_data`, so a bad declaration is proved to break the
build without one ever existing in the tree).

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MAIL-120 | a well-formed email class is baked into `data['emails']` with its category, view id and file | php | synthetic manifest + fixture source | entry present and correct | implemented | 2026-08-31 |
| MAIL-121 | all three category spellings are recognized | php | `self::TRANSACTIONAL/NOTIFICATION/MARKETING` | 1 / 2 / 3 | implemented | 2026-08-31 |
| MAIL-122 | an abstract intermediate class owes nothing - only what can be instantiated owes the contract | php | abstract entry with no CATEGORY | no entry, no FATAL | implemented | 2026-08-31 |
| MAIL-123 | a class that does not descend from `Rsx_Email_Abstract` is ignored | php | entry extending a model | no entry | implemented | 2026-08-31 |
| MAIL-124 | a missing `const CATEGORY` is a FATAL naming the class, the file and the three choices | php | fixture with no CATEGORY | `RuntimeException` "has no CATEGORY" | implemented | 2026-08-31 |
| MAIL-125 | an unrecognized CATEGORY is a FATAL | php | `const CATEGORY = 'urgent'` | `RuntimeException` "unrecognized CATEGORY" | implemented | 2026-08-31 |
| MAIL-126 | a missing `sample()` is a FATAL showing the signature to add | php | no `sample` in `public_static_methods` | `RuntimeException` "has no sample()" | implemented | 2026-08-31 |
| MAIL-127 | a missing blade is a FATAL naming the exact `@rsx_id` required | php | no view entry | `RuntimeException` "has no template" naming the id | implemented | 2026-08-31 |
| MAIL-128 | two classes sharing a basename are a FATAL - one basename names one email | php | duplicate entries | `RuntimeException` "Duplicate email class" | implemented | 2026-08-31 |
| MAIL-129 | this install's REAL manifest carries the table, so the module actually runs | php | `Manifest::get_full_manifest()` | `Rsx_Mail_Test_Email` present, category 1 | implemented | 2026-08-31 |

`rsx_absolute_url()` with no request to read a host from, via `Absolute_Url_Test`. Lives
in this concern because email is the only subsystem rendered entirely inside a background
task, which is where a wrong answer does silent damage.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MAIL-140 | a path becomes the APP_URL origin plus the path | php | `rsx_absolute_url('/x')` | scheme + authority + `/x` | implemented | 2026-08-31 |
| MAIL-141 | the scheme comes from APP_URL and is never hardcoded - development may serve http | php | `rsx_absolute_url('/x')` | starts with APP_URL's scheme | implemented | 2026-08-31 |
| MAIL-142 | a non-default port survives (and a default one is not spelled out) | php | `rsx_absolute_url('/x')` | port present iff non-default | implemented | 2026-08-31 |
| MAIL-143 | a path without a leading slash still produces one | php | `rsx_absolute_url('x')` | identical to the `/x` form | implemented | 2026-08-31 |
| MAIL-144 | a query string arrives byte-identical - a signed query must not be re-encoded | php | the unsubscribe query | unchanged | implemented | 2026-08-31 |

End to end, with nothing stubbed, via `Mail_Catcher_Delivery_Test`.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MAIL-160 | a real send over a real SMTP conversation lands in the catcher Maildir, and the file names the queue row and the envelope recipient | php | host pinned to 127.0.0.1:1025, real transport | SENT; a captured file carrying `X-RSX-Email-Id: <id>` and `X-RcptTo: <address>` | implemented | 2026-08-31 |
| MAIL-161 | the captured bytes are a complete multipart message, not merely something that crossed the socket | php | the same send | Subject, To, `multipart/alternative`, `text/plain`, `text/html`, Message-ID | implemented | 2026-08-31 |

`rsx:mail:test`, via `cli/Mail_Test_Command_Cli_Test` (in-process `Artisan::call()`, so the
transport is pinned at the catcher and the row lands in the TEST database - a spawned
artisan would boot against the developer's database and prove nothing about the
configuration under test).

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MAIL-180 | `--json` reports a real send as parseable JSON, including a `captured_file` that exists | cli | `rsx:mail:test <addr> --json` | exit 0; status "Sent", address, class, delivery, 1 attempt, Message-ID, no transport error, existing captured file | implemented | 2026-08-31 |
| MAIL-181 | the reported queue id names the row, and the status printed is the one it actually reached | cli | the same run | the row exists and is SENT | implemented | 2026-08-31 |
| MAIL-182 | the human report names the transport, the outcome and the `cat` command | cli | `rsx:mail:test <addr>` | "Delivery mode:", "smtp 127.0.0.1:1025", "Status:", "[OK] Sent", "cat " | implemented | 2026-08-31 |
| MAIL-183 | an unknown `--email` exits 1 and lists the classes that would have worked | cli | `--email=Not_An_Email` | exit 1, "is not an email class", "Known email classes:", the framework probe listed | implemented | 2026-08-31 |
| MAIL-184 | a refused send queues nothing | cli | the same run | no row for that address | implemented | 2026-08-31 |
| MAIL-185 | `--email=<Class>` sends that class's `sample()` | cli | `--email=Rsx_Mail_Test_Email` | exit 0, that class, "Sent" | implemented | 2026-08-31 |
| MAIL-186 | suppressed delivery is reported and still exits 0 - a setting somebody chose is not a fault | cli | `rsx.mail.delivery = suppressed` | exit 0, status "Suppressed", delivery "suppressed" | implemented | 2026-08-31 |

Not implemented.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MAIL-200 | the `Mail` health rows: delivery mode, a TCP probe of the transport, and the sender-domain DNS check | php | `Rsx_Mail_Transport::mail_health()` under each configuration | delivery INFO for suppressed/disabled, OK for aiosmtpd/live; transport FAIL naming `[program:mail-catcher]` when the catcher is closed, and FAIL "server did not advertise aiosmtpd" on a greeting mismatch; sender domain INFO in any mode but live, WARN when SPF/DMARC are missing | planned - the DNS rows reach the network, so they need a resolver stub or a fixture domain before they can be asserted deterministically | 2026-08-31 |
| MAIL-201 | a `<Class>_Text` blade is used verbatim in place of the derived text part | php | an email class shipping a text template | the text part is the blade's output, not the converted html | planned - no email in this tree ships a text template yet; the fixture would exist only for the test | 2026-08-31 |
| MAIL-202 | a `sendmail` transport builds the right DSN in `live` mode | php | `rsx.mail.delivery = live`, `transport.driver = sendmail` | `sendmail://default?command=...` | planned | 2026-08-31 |
| MAIL-203 | two concurrent drains never take the same row | php | two processes racing `claim_next()` | one claim matches, the other does not | deferred - the conditional UPDATE is asserted single-threaded (MAIL-03); a real race needs two processes against a committed database, which the transaction-isolated runner cannot provide | 2026-08-31 |

## Delivery modes, the greeting, and the stale sweep (`php/Mail_Delivery_Mode_Test.php`)

`rsx.mail.delivery` is the one setting that decides whether an email reaches a person, and
the four modes are four DIFFERENT promises - not four levels of one. A mode that quietly
degraded into another would be the worst defect this subsystem could have, so each promise
is asserted separately. The stale sweep lives here because it is a property of the drain
that only some modes run.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MAIL-210 | an unrecognised mode THROWS rather than being read as "not live" | php | `rsx.mail.delivery = 'sortof'` | `RuntimeException` naming the value and the four modes | implemented | 2026-08-31 |
| MAIL-211 | every documented mode is accepted | php | each of aiosmtpd/live/suppressed/disabled | `delivery_mode()` returns it | implemented | 2026-08-31 |
| MAIL-212 | aiosmtpd mode IGNORES the whole transport block - a stale MAIL_HOST cannot mail anybody | php | mode aiosmtpd + a real-looking host/port/credentials | DSN is `127.0.0.1:1025` with `auto_tls=false` and none of the configured values; `describe()` says the same | implemented | 2026-08-31 |
| MAIL-213 | live mode is the mode the transport block is for | php | mode live + a configured host | the DSN names it | implemented | 2026-08-31 |
| MAIL-214 | a greeting that does not say `aiosmtpd` is reported, naming what answered | php | banner `220 ... ESMTP Postfix` | error contains "expected server aiosmtpd" and "Postfix" | implemented | 2026-08-31 |
| MAIL-215 | the catcher's own greeting is trusted, and no other mode ever asks | php | catcher banner under aiosmtpd; Postfix banner under live | null both times | implemented | 2026-08-31 |
| MAIL-216 | nothing answering is an OUTAGE, not a banner problem (so the retry budget is not burned) | php | empty banner under aiosmtpd | null - the transport-failure path handles it | implemented | 2026-08-31 |
| MAIL-217 | a bad greeting fails the MESSAGE as a server error, on its ordinary clock | php | drain in aiosmtpd with an imposter banner | server_errors > 0; row PENDING, attempt 1, `last_error` "expected server aiosmtpd" | implemented | 2026-08-31 |
| MAIL-218 | `disabled` leaves every row exactly as it was - freezing is not suppressing | php | drain in disabled with a queued row | all counts 0; row PENDING, 0 attempts, no `last_error` | implemented | 2026-08-31 |
| MAIL-219 | `disabled` ages nothing out, so a week of downtime does not become a queue of failures | php | a month-old PENDING row, drain in disabled | still PENDING | implemented | 2026-08-31 |
| MAIL-220 | a message due longer ago than the window is REFUSED and names its remedy | php | a row aged past `stale_after_days` | `stale` > 0; row FAILED with `STALE_ERROR`, naming `rsx:mail:resend`, `next_attempt_at` null | implemented | 2026-08-31 |
| MAIL-221 | a message scheduled for the future is never stale - the DUE date decides, not `created_at` | php | `send_at()` +30d on a row created 30d ago | `stale` = 0; still PENDING | implemented | 2026-08-31 |
| MAIL-222 | a fresh message is processed normally, not aged out | php | a new row, drain in suppressed | SUPPRESSED | implemented | 2026-08-31 |
| MAIL-223 | resending a stale row actually works - the remedy the error names is not a lie | php | stale row -> `reset_for_resend()` -> drain | processed, not immediately re-staled (`next_attempt_at` is NOW, not null) | implemented | 2026-08-31 |
| MAIL-224 | the real catcher advertises `aiosmtpd`, so the mode's promise holds on this box | php | `probe_banner()` against 127.0.0.1:1025 | greeting contains "aiosmtpd"; `aiosmtpd_banner_error()` null | implemented | 2026-08-31 |

## Queue inspection commands (`cli/Mail_Queue_Commands_Cli_Test.php`)

These three are what an operator has instead of a database client when mail did not
arrive, so what is under test is their ANSWERS. Run in-process via `Artisan::call()`.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MAIL-230 | the summary names the mode first and counts every status, with an oldest-pending age | cli | `rsx:mail:queue --json` | exit 0; `delivery`, a count per status, `oldest_pending` | implemented | 2026-08-31 |
| MAIL-231 | `--recipient` finds the message by address | cli | `--recipient=<addr> --json` | exactly the one row, with its status | implemented | 2026-08-31 |
| MAIL-232 | `--status` lists only that status | cli | `--status=pending --json` | every row Pending | implemented | 2026-08-31 |
| MAIL-233 | an unknown `--status` is refused WITH the vocabulary | cli | `--status=nearly` | exit 1, the status list printed | implemented | 2026-08-31 |
| MAIL-234 | `show` reports the whole row, the bodies by LENGTH, and carries them in full under `--json` | cli | `rsx:mail:show <id> --json` | id/to/status/template_data/attachments/`rendered_html_length`/`rendered_html` | implemented | 2026-08-31 |
| MAIL-235 | `show` refuses an id that does not exist | cli | a missing id | exit 1, the id named | implemented | 2026-08-31 |
| MAIL-236 | `resend` resets a Failed row and sets `next_attempt_at` to NOW (not null - a nulled column re-stales immediately) | cli | `rsx:mail:resend <failed id>` | exit 0; PENDING, 0 attempts, no `last_error`, `next_attempt_at` set | implemented | 2026-08-31 |
| MAIL-237 | `resend` does nothing to a row the queue already has | cli | a PENDING row | exit 0, "already", row untouched | implemented | 2026-08-31 |
| MAIL-238 | resending a BLOCKED row requires `--force` - an opt-out is a consent record, not a delivery failure | cli | a Blocked row, with and without `--force` | exit 1 + "unsubscribed" + "--force", row untouched; then exit 0 and PENDING | implemented | 2026-08-31 |
| MAIL-239 | `resend` refuses an id that does not exist | cli | a missing id | exit 1, the id named | implemented | 2026-08-31 |
