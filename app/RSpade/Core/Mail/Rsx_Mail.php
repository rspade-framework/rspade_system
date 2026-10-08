<?php

namespace App\RSpade\Core\Mail;

use App\RSpade\Core\Database\Rsx_Result_Set;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Mail\Rsx_Email_Abstract;
use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Models\Email_Attachment_Model;
use App\RSpade\Core\Models\Email_Blocked_Address_Model;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Email_Recipient_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;

/**
 * Rsx_Mail - the framework side of outbound email.
 *
 * Application code never calls this class to SEND. An email is a class:
 *
 *     (new Welcome_Email($user, $login_url))->to($user)->send();
 *
 * and send() lands here, in enqueue(). What Rsx_Mail owns is everything that is true
 * of every email regardless of which one it is: the tenant it belongs to, the
 * site block list, the recipient opt-out, the dev-site recipient gating, the unsubscribe signature, and
 * getting the row onto the queue and the drain kicked.
 *
 * ALL EMAIL IS QUEUED - never sent inline. A slow or down mail host can therefore
 * never slow a user's action.
 */
class Rsx_Mail
{
    // Email categories - match Email_Queue_Model::CATEGORY_* and Rsx_Email_Abstract::*.
    const TRANSACTIONAL = 1;
    const NOTIFICATION = 2;
    const MARKETING = 3;
    const SECURITY = 4;

    /**
     * Queue an email built by an Rsx_Email_Abstract subclass.
     *
     * The order of operations is load-bearing:
     *
     *   1. Resolve the tenant (the realm being served, not the identity logged in).
     *   2. Dedupe: an already-used key returns the EXISTING row, whatever its status, and
     *      enqueues nothing.
     *   3. Site block list (every category but SECURITY, transactional included): a listed `to` records
     *      the whole message BLOCKED; a listed cc/bcc entry is removed and recorded on the
     *      row, and the message still goes to everybody else. Checked BEFORE the opt-out,
     *      because it is the stronger rule and its reason is the one worth recording, and
     *      before the dev-site gate, on the ORIGINAL addresses - after it, a dev host would
     *      look up its own catchall and the list would only ever be exercised in production.
     *   4. Recipient opt-out: a notification or marketing email to an address that unsubscribed is
     *      RECORDED as BLOCKED, never sent - the audit row is the point.
     *   5. Dev-site gate (a second, independent safety layer keyed on hostname), on `to`
     *      AND on every cc/bcc entry.
     *   6. Freeze subject() and data() into the row. From here the email's own class
     *      is never asked anything again: a template that renders next Tuesday renders
     *      the values the caller had TODAY.
     *   7. Persist attachments into the content-addressed blob store - for a Blocked or
     *      Suppressed row too, so the row is the whole message and a resend sends it whole.
     *   8. Kick the drain.
     *
     * @param Rsx_Email_Abstract $email
     * @return Email_Queue_Model The queued (or pre-existing, when deduped) record.
     */
    public static function enqueue(Rsx_Email_Abstract $email): Email_Queue_Model
    {
        $site_id = static::__current_site_id();
        $envelope = $email->_envelope();
        $category = $email::category();

        if ($envelope['to'] === null) {
            throw new \InvalidArgumentException(
                get_class($email) . '::send() was called with no recipient - call ->to(...) first.'
            );
        }

        // A key already used by this tenant means this email has been queued before.
        // Return that row in whatever state it reached - re-running an import or
        // replaying a webhook must not mail anybody twice.
        if ($envelope['dedupe_key'] !== null) {
            $existing = Email_Queue_Model::find_by_dedupe_key($site_id, $envelope['dedupe_key']);

            if ($existing !== null) {
                return $existing;
            }
        }

        $descriptor = [
            'site_id' => $site_id,
            'to_address' => $envelope['to']['address'],
            'to_name' => $envelope['to']['name'],
            'subject' => $email->subject(),
            'email_class' => $email::view_id(),
            'template_data' => $email->data(),
            'category_id' => $category,
            'reply_to' => $envelope['reply_to']['address'] ?? null,
            'reply_to_name' => $envelope['reply_to']['name'] ?? null,
            'cc' => $envelope['cc'],
            'bcc' => $envelope['bcc'],
            'withheld_recipients' => [],
            'dedupe_key' => $envelope['dedupe_key'],
            'next_attempt_at' => $envelope['send_at'],
            'dev_original_to' => null,
            'related_type' => $envelope['related_type'],
            'related_id' => $envelope['related_id'],
        ];

        $to = $descriptor['to_address'];

        // THE SITE BLOCK LIST. One query for every address on the envelope. A SECURITY email
        // is exempt: the list stops correspondence the site initiates, and a sign-in code the
        // recipient asked for is not that.
        $listed = $category === self::SECURITY
            ? []
            : Email_Blocked_Address_Model::listed_among($site_id, static::__envelope_addresses($descriptor));
        $to_reason = $listed[Email_Blocked_Address_Model::normalize($to)] ?? null;

        if ($to_reason !== null) {
            return static::__record_blocked(
                $descriptor,
                $envelope['attachments'],
                Email_Queue_Model::BLOCK_CAUSE_SITE_BLOCK_LIST,
                Email_Queue_Model::site_block_list_error($to_reason)
            );
        }

        static::__withhold_copies(
            $descriptor,
            fn (string $address) => isset($listed[$address])
                ? Email_Queue_Model::site_block_list_error($listed[$address])
                : null
        );

        // THE RECIPIENT OPT-OUT. Transactional and security email ignore it.
        if ($category !== self::TRANSACTIONAL
            && $category !== self::SECURITY
            && Email_Recipient_Model::is_blocked($site_id, $to, $category)
        ) {
            return static::__record_blocked(
                $descriptor,
                $envelope['attachments'],
                Email_Queue_Model::BLOCK_CAUSE_OPTED_OUT,
                Email_Queue_Model::OPTED_OUT_ERROR
            );
        }

        // The dev-site layer exists to keep a development box from mailing REAL people,
        // so it applies to the ONE mode that can reach one: MODE_LIVE. Every other mode
        // has already made the message unable to leave - aiosmtpd files it in a Maildir
        // here, suppressed never opens a transport, disabled never drains - and gating
        // there would mean a fresh install recorded every message SUPPRESSED and a
        // developer never saw their own mail. Set MAIL_DELIVERY=live and the gating is
        // exactly what it always was.
        //
        // A COPY IS A RECIPIENT TOO. A cc/bcc entry the whitelist does not name is removed
        // and recorded: redirecting `to` to the catchall while the real copies rode along
        // would mail exactly the people this layer exists to protect.
        $dev_undeliverable = false;
        if (self::is_dev_mode() && Rsx_Mail_Transport::delivery_mode() === Rsx_Mail_Transport::MODE_LIVE) {
            [$descriptor['to_address'], $descriptor['dev_original_to'], $dev_undeliverable] = self::_apply_dev_redirect($to);

            static::__withhold_copies(
                $descriptor,
                fn (string $address) => self::_dev_whitelisted($address) ? null : 'dev site: not whitelisted'
            );
        }

        // A dev host with no whitelist match and no catchall has nowhere to send this.
        // That is known NOW, so the row is written SUPPRESSED now - queueing it and
        // letting the drain discover it would render a message whose envelope was
        // already invalid, and the reason would arrive a minute late for no gain.
        if ($dev_undeliverable) {
            $record = Email_Queue_Model::enqueue_suppressed(
                $descriptor,
                'dev site: no whitelist match and no catchall'
            );

            static::_persist_attachments($record, $envelope['attachments']);

            return $record;
        }

        $record = Email_Queue_Model::enqueue($descriptor);

        static::_persist_attachments($record, $envelope['attachments']);

        static::_kick_drain();

        return $record;
    }

    /**
     * Write the whole message as a BLOCKED row, attachments included. The drain is not
     * kicked: there is nothing for it to do.
     */
    private static function __record_blocked(array $descriptor, array $attachments, int $block_cause_id, string $reason): Email_Queue_Model
    {
        $record = Email_Queue_Model::enqueue_blocked($descriptor, $block_cause_id, $reason);

        static::_persist_attachments($record, $attachments);

        return $record;
    }

    /**
     * Every address on a descriptor or row's envelope - the real `to` and every copy.
     *
     * @return array<int, string>
     */
    private static function __envelope_addresses(array $envelope): array
    {
        $addresses = [($envelope['dev_original_to'] ?? null) ?: $envelope['to_address']];

        foreach (['cc', 'bcc'] as $field) {
            foreach ($envelope[$field] ?? [] as $entry) {
                $addresses[] = $entry['address'];
            }
        }

        return $addresses;
    }

    /**
     * Remove every cc/bcc entry $reason_for names a reason for, and record each one in
     * withheld_recipients with the field it was on and why. A recipient never vanishes
     * from a message without the row saying so.
     *
     * @param array    $envelope   A descriptor (or a row's envelope as an array), edited in place
     * @param callable $reason_for fn (string $normalized_address): ?string - null keeps the entry
     * @return bool Whether anything was withheld
     */
    private static function __withhold_copies(array &$envelope, callable $reason_for): bool
    {
        $withheld_any = false;

        foreach (['cc', 'bcc'] as $field) {
            $kept = [];

            foreach ($envelope[$field] ?? [] as $entry) {
                $reason = $reason_for(Email_Blocked_Address_Model::normalize($entry['address']));

                if ($reason === null) {
                    $kept[] = $entry;
                    continue;
                }

                $envelope['withheld_recipients'][] = [
                    'field' => $field,
                    'address' => $entry['address'],
                    'name' => $entry['name'] ?? null,
                    'reason' => $reason,
                ];
                $withheld_any = true;
            }

            $envelope[$field] = $kept;
        }

        return $withheld_any;
    }

    /**
     * The drain's re-check of the site block list, for a row it has just claimed.
     *
     * A row can wait PENDING for days - send_at(), a retry delay, an outage - and an
     * address listed in that window must not be mailed by a decision made before the
     * listing existed. A listed `to` (the ORIGINAL recipient, not a dev catchall) marks
     * the row BLOCKED without counting an attempt; a listed copy is removed and recorded,
     * and the row goes on to be sent.
     *
     * Framework-internal: Mail_Queue_Service is the one caller.
     *
     * @return bool true when the row is now BLOCKED and must not be sent
     */
    public static function _recheck_block_list(Email_Queue_Model $row): bool
    {
        // SECURITY email is exempt from the site block list - see enqueue().
        if ((int) $row->category_id === Email_Queue_Model::CATEGORY_SECURITY) {
            return false;
        }

        $envelope = [
            'to_address' => $row->to_address,
            'dev_original_to' => $row->dev_original_to,
            'cc' => $row->cc ?? [],
            'bcc' => $row->bcc ?? [],
            'withheld_recipients' => $row->withheld_recipients ?? [],
        ];

        $listed = Email_Blocked_Address_Model::listed_among((int) $row->site_id, static::__envelope_addresses($envelope));

        if (empty($listed)) {
            return false;
        }

        $to_reason = $listed[Email_Blocked_Address_Model::normalize($row->dev_original_to ?: $row->to_address)] ?? null;

        if ($to_reason !== null) {
            $row->mark_blocked(
                Email_Queue_Model::BLOCK_CAUSE_SITE_BLOCK_LIST,
                Email_Queue_Model::site_block_list_error($to_reason)
            );

            return true;
        }

        $withheld = static::__withhold_copies(
            $envelope,
            fn (string $address) => isset($listed[$address])
                ? Email_Queue_Model::site_block_list_error($listed[$address])
                : null
        );

        if ($withheld) {
            $row->cc = $envelope['cc'];
            $row->bcc = $envelope['bcc'];
            $row->withheld_recipients = $envelope['withheld_recipients'];
            $row->save();
        }

        return false;
    }

    /**
     * Store each declared attachment's bytes in the blob store and record the row.
     *
     * The bytes are content-addressed, so the same file mailed to a thousand people is
     * stored once; the _email_attachments row is what pins the blob against disposal.
     *
     * @param Email_Queue_Model $record
     * @param array<int, array> $specs
     * @return void
     */
    private static function _persist_attachments(Email_Queue_Model $record, array $specs): void
    {
        $sort_order = 0;

        foreach ($specs as $spec) {
            $position = $sort_order++;

            static::_with_attachment_blob(
                $spec,
                function (File_Storage_Model $storage, string $file_name, string $mime_type) use ($record, $spec, $position) {
                    Email_Attachment_Model::record_part(
                        $record,
                        $storage,
                        $file_name,
                        $mime_type,
                        (int) $spec['disposition'],
                        $spec['cid'],
                        $position
                    );
                }
            );
        }
    }

    /**
     * Get one attachment spec's bytes into the blob store and hand the storage row, file
     * name and mime to $record, which writes the row that pins them.
     *
     * A File_Attachment_Model REUSES its existing blob - nothing is copied and nothing
     * is re-hashed; record_part()'s save holds that blob's lock and refuses a row that was
     * released in the meantime. A path or raw bytes go through store_blob(), which calls
     * $record inside its reference scope - store_blob() hashes and byte-compares a file on
     * disk, so raw bytes go through a temp file (see File_Attachment_Model::create_from_string,
     * which does the same dance for the same reason).
     *
     * @param array    $spec
     * @param callable $record fn (File_Storage_Model $storage, string $file_name, string $mime_type)
     * @return void
     */
    private static function _with_attachment_blob(array $spec, callable $record): void
    {
        $source = $spec['source'];

        if ($source instanceof File_Attachment_Model) {
            if ($source->file_storage_id === null) {
                throw new \RuntimeException(
                    "Cannot attach file attachment #{$source->id} to an email: its bytes have been released."
                );
            }

            $storage = File_Storage_Model::find($source->file_storage_id);

            if ($storage === null) {
                throw new \RuntimeException(
                    "Cannot attach file attachment #{$source->id} to an email: storage row is gone."
                );
            }

            $record($storage, $spec['name'] ?? $source->file_name, $spec['mime'] ?? $source->mime_type);

            return;
        }

        if (is_string($source)) {
            if (!is_file($source)) {
                throw new \RuntimeException("Cannot attach '{$source}' to an email: no such file.");
            }

            $file_name = $spec['name'] ?? basename($source);
            $mime_type = $spec['mime'] ?? (mime_content_type($source) ?: 'application/octet-stream');

            File_Storage_Model::store_blob(
                $source,
                fn (File_Storage_Model $storage) => $record($storage, $file_name, $mime_type)
            );

            return;
        }

        // Raw bytes the caller generated.
        File_Storage_Model::store_bytes(
            $spec['bytes'],
            fn (File_Storage_Model $storage) => $record($storage, $spec['name'], $spec['mime'])
        );
    }

    /**
     * Ask a worker to drain the queue now.
     *
     * Every context does this - a web request, a CLI importer, a task. The old guard
     * skipped the console, which meant a nightly import queued a thousand emails that
     * nothing sent until the next sweep. #[Exclusive] coalesces the dispatches, so
     * kicking on every send is cheap.
     *
     * THE ONE EXCEPTION IS A TEST RUN: rsx:test swaps the default connection to 'test'
     * for the whole run (the same signal RsxCache keys its namespace off), and a
     * detached worker booting against the DEVELOPER's database would drain rows it
     * cannot see and race the test's own assertions. A test that wants the drain runs
     * it explicitly.
     *
     * @return void
     */
    private static function _kick_drain(): void
    {
        if (config('database.default') === 'test') {
            return;
        }

        Task::dispatch('Mail_Queue_Service', 'send_pending_queue');
    }

    /**
     * The tenant this send/blocklist call belongs to, resolved from the realm that is
     * actually being served.
     *
     * The template app's portal endpoints send mail (password reset, request access,
     * request threads), and those run as PORTAL requests where the staff facade knows
     * nothing: the queue row would be filed under the wrong site and - worse - the
     * recipient's unsubscribe state for their REAL site would never be consulted, so a
     * blocked address would still be mailed. CLI (the queue drain, tests) is not a portal
     * request, so it keeps the staff path unchanged.
     *
     * See docs.dev/audits/portal_realm_session_audit_2026_08_09.md.
     */
    private static function __current_site_id(): int
    {
        if (Rsx_Portal::is_portal_request()) {
            return (int) Portal_Session::get_site_id();
        }

        return (int) Session::get_site_id();
    }

    // =========================================================================
    // DISPLAYING A STORED MESSAGE
    // =========================================================================

    /**
     * The row's rendered_html with every cid: reference made loadable by a browser - the
     * ONE way a stored message body is displayed, by the /_sys Email screen and by any
     * application's own email-history viewer alike.
     *
     * A cid: URI only resolves inside the MIME message that carried the part, so the
     * stored body is unrenderable as it stands. Each `cid:<id>` in an attribute value or a
     * CSS url() is replaced by a data: URI of the bytes recorded under that cid on THIS
     * row's own _email_attachments rows - what was sent, never the asset path's current
     * contents, and never anything read off the filesystem by name. Nothing else in the
     * HTML changes.
     *
     * A data: URI is self-contained, so the result renders anywhere a page allows
     * `img-src data:` - which includes the framework's own sandboxed preview document
     * (default-src 'none', no network at all) and the framework's default page CSP.
     *
     * A cid: with no recorded row, or whose blob is no longer on disk, becomes a VISIBLE
     * placeholder image saying so: a message whose inline bytes were never captured
     * (anything built before the builder recorded them) is a fact worth seeing, not a
     * silent broken image.
     *
     * Never derive a content id by hand to patch the body some other way: the cid an
     * asset is embedded under is the builder's private business.
     *
     * @return string|null null when the row has not been rendered
     */
    public static function displayable_html(Email_Queue_Model $row): ?string
    {
        $html = $row->rendered_html;

        if ($html === null) {
            return null;
        }

        $parts = [];

        foreach ($row->attachments()->whereNotNull('cid')->get() as $attachment) {
            $parts[(string) $attachment->cid] = $attachment;
        }

        $resolved = [];

        $resolve = static function (string $encoded_cid) use ($parts, &$resolved): string {
            $cid = html_entity_decode(rawurldecode($encoded_cid), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (!array_key_exists($cid, $resolved)) {
                $resolved[$cid] = isset($parts[$cid])
                    ? static::_inline_part_data_uri($parts[$cid], $cid)
                    : static::_missing_inline_part_data_uri($cid, 'Inline image not recorded');
            }

            return $resolved[$cid];
        };

        // An attribute value: src="cid:x", background='cid:x', src=cid:x.
        $html = preg_replace_callback(
            '/(=\s*["\']?)cid:([^"\'\s>]+)/i',
            fn ($m) => $m[1] . $resolve($m[2]),
            $html
        );

        // A CSS url(): url(cid:x), url("cid:x"), url('cid:x') - quoted with an entity
        // inside a style attribute too.
        return preg_replace_callback(
            '/(url\(\s*(?:["\']|&quot;|&#0?39;)?)cid:([^"\'\s)&]+)/i',
            fn ($m) => $m[1] . $resolve($m[2]),
            $html
        );
    }

    /**
     * One recorded inline part as a data: URI, or the placeholder when its blob is gone.
     */
    private static function _inline_part_data_uri(Email_Attachment_Model $attachment, string $cid): string
    {
        $storage = $attachment->file_storage_id === null
            ? null
            : File_Storage_Model::find($attachment->file_storage_id);

        $path = $storage === null ? null : $storage->get_full_path();

        if ($path === null || !is_file($path)) {
            return static::_missing_inline_part_data_uri($cid, 'Inline image unavailable');
        }

        $bytes = file_get_contents($path);

        if ($bytes === false) {
            throw new \RuntimeException("Could not read the blob for email attachment #{$attachment->id} at {$path}.");
        }

        // The type is written into an attribute value, so only a well-formed media type is
        // passed through; anything else is served as opaque bytes.
        $mime_type = preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#i', (string) $attachment->mime_type)
            ? strtolower((string) $attachment->mime_type)
            : 'application/octet-stream';

        return 'data:' . $mime_type . ';base64,' . base64_encode($bytes);
    }

    /**
     * A visible stand-in for an inline part whose bytes cannot be shown: a bordered SVG
     * naming the cid, as a data: URI.
     */
    private static function _missing_inline_part_data_uri(string $cid, string $label): string
    {
        $caption = htmlspecialchars('cid:' . (strlen($cid) > 48 ? substr($cid, 0, 45) . '...' : $cid), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $label = htmlspecialchars($label, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="64" viewBox="0 0 240 64">'
            . '<rect x="1" y="1" width="238" height="62" fill="#f8f9fa" stroke="#adb5bd" stroke-dasharray="4 3"/>'
            . '<text x="120" y="28" font-family="sans-serif" font-size="13" fill="#495057" text-anchor="middle">' . $label . '</text>'
            . '<text x="120" y="46" font-family="monospace" font-size="10" fill="#6c757d" text-anchor="middle">' . $caption . '</text>'
            . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    // =========================================================================
    // RESEND
    // =========================================================================

    /** resend() put the row back on the queue and kicked the drain. */
    const RESEND_QUEUED = 'queued';

    /** resend() did nothing: the row is PENDING or SENDING, so the queue already has it. */
    const RESEND_ALREADY_QUEUED = 'already_queued';

    /** resend() did nothing: the row is BLOCKED by the recipient's opt-out and $force was not given. */
    const RESEND_BLOCKED = 'blocked';

    /**
     * resend() did nothing: the row's recipient is on the site block list NOW. $force does
     * not override this - the entry is removed where it lives (Rsx_Mail::unblock_address()).
     */
    const RESEND_ADDRESS_BLOCKED = 'address_blocked';

    /**
     * Put a finished queue row back on the queue - the ONE implementation of the resend
     * rules, shared by rsx:mail:resend and the /_sys Email screen.
     *
     *   PENDING / SENDING  refused (RESEND_ALREADY_QUEUED): the queue already has it, and
     *                      resetting a row a drain is mid-way through would send it twice.
     *   recipient listed   refused (RESEND_ADDRESS_BLOCKED) whatever $force says, whatever
     *                      the row's status: the original recipient is on the site block
     *                      list right now. A site block is lifted by removing the entry,
     *                      where that removal is itself recorded - never by a resend.
     *   BLOCKED, opt-out   refused (RESEND_BLOCKED) unless $force: the recipient asked not
     *                      to receive this category. That is a consent record, and a
     *                      resend that quietly overrode it would make the unsubscribe link
     *                      a lie - so it is possible, never accidental.
     *   BLOCKED, list      requeued WITHOUT $force: the entry that held it is gone, so the
     *                      reason it was held no longer exists.
     *   anything else      reset_for_resend() (status PENDING, attempts 0, error and block
     *                      cause cleared, due now) and the drain kicked (RESEND_QUEUED).
     *
     * A cc/bcc entry withheld when the row was queued stays withheld: withheld_recipients
     * records it, and a resend sends the message the row holds.
     *
     * SCOPE: the row is saved under whatever site scope the caller runs in. An operator
     * surface acting on any tenant's row (the command, the panel) runs this inside
     * Email_Queue_Model::without_site_scope().
     *
     * @return string One of the RESEND_* constants
     */
    public static function resend(Email_Queue_Model $record, bool $force = false): string
    {
        $status_id = (int) $record->status_id;

        if ($status_id === Email_Queue_Model::STATUS_PENDING || $status_id === Email_Queue_Model::STATUS_SENDING) {
            return self::RESEND_ALREADY_QUEUED;
        }

        // SECURITY email is exempt from the site block list - see enqueue().
        $listed_reason = (int) $record->category_id === Email_Queue_Model::CATEGORY_SECURITY
            ? null
            : Email_Blocked_Address_Model::reason_for(
                (int) $record->site_id,
                $record->dev_original_to ?: $record->to_address
            );

        if ($listed_reason !== null) {
            return self::RESEND_ADDRESS_BLOCKED;
        }

        if ($status_id === Email_Queue_Model::STATUS_BLOCKED
            && (int) $record->block_cause_id !== Email_Queue_Model::BLOCK_CAUSE_SITE_BLOCK_LIST
            && !$force
        ) {
            return self::RESEND_BLOCKED;
        }

        $record->reset_for_resend();
        static::_kick_drain();

        return self::RESEND_QUEUED;
    }

    // =========================================================================
    // SITE BLOCK LIST API - the SITE's rule, every category but SECURITY
    // =========================================================================

    /**
     * Every entry on the current site's block list: email, reason, created_at.
     *
     * The list is the SITE's rule that no email it initiates - transactional included - may
     * reach an address. SECURITY email (a notice the recipient set in motion) is exempt. It is not the recipient opt-out (is_blocked() / block() below):
     * nothing a recipient can reach writes it, and the framework never writes it on its own,
     * so an application may treat the whole list for a site as its own and reconcile it.
     *
     * Iterated under the current site's scope, which is the site it lists.
     */
    public static function blocked_addresses(): Rsx_Result_Set
    {
        return Email_Blocked_Address_Model::all_for_site(static::__current_site_id());
    }

    /**
     * Is $email on the current site's block list? Case- and whitespace-insensitive.
     */
    public static function is_address_blocked(string $email): bool
    {
        return Email_Blocked_Address_Model::reason_for(static::__current_site_id(), $email) !== null;
    }

    /**
     * Put $email on the current site's block list, with the sentence a Blocked row and its
     * tooltip will show. IDEMPOTENT: an address already listed takes the new reason.
     */
    public static function block_address(string $email, string $reason): void
    {
        Email_Blocked_Address_Model::block(static::__current_site_id(), $email, $reason);
    }

    /**
     * Take $email off the current site's block list. IDEMPOTENT: an address that is not
     * listed is not an error.
     */
    public static function unblock_address(string $email): void
    {
        Email_Blocked_Address_Model::unblock(static::__current_site_id(), $email);
    }

    // =========================================================================
    // RECIPIENT OPT-OUT API - the RECIPIENT's choice, per category
    // =========================================================================

    /**
     * Check if an email address is blocked for a category
     */
    public static function is_blocked(string $email, int $category): bool
    {
        $site_id = static::__current_site_id();
        return Email_Recipient_Model::is_blocked($site_id, $email, $category);
    }

    /**
     * Block a specific category for an email address
     */
    public static function block(string $email, int $category): void
    {
        $site_id = static::__current_site_id();
        Email_Recipient_Model::block($site_id, $email, $category);
    }

    /**
     * Unblock a specific category for an email address
     */
    public static function unblock(string $email, int $category): void
    {
        $site_id = static::__current_site_id();
        Email_Recipient_Model::unblock($site_id, $email, $category);
    }

    /**
     * Block all non-transactional email for an address
     */
    public static function block_all(string $email): void
    {
        $site_id = static::__current_site_id();
        Email_Recipient_Model::block_all($site_id, $email);
    }

    // =========================================================================
    // UNSUBSCRIBE URL
    // =========================================================================

    /**
     * Generate a signed unsubscribe URL for use in email templates.
     *
     * The signature covers the SITE too: the blocklist is per tenant, so a link minted
     * for one site must not be replayable to unsubscribe the same address from another.
     *
     * @param string $email Recipient email address
     * @param int $category Category to unsubscribe from
     * @param int $site_id The tenant the blocklist row belongs to
     * @return string Signed URL
     */
    public static function unsubscribe_url(string $email, int $category, int $site_id): string
    {
        $signature = static::_unsubscribe_signature($email, $category, $site_id);

        return rsx_absolute_url('/_mail/unsubscribe?' . http_build_query([
            'email' => $email,
            'category' => $category,
            'site' => $site_id,
            'sig' => $signature,
        ]));
    }

    /**
     * Verify an unsubscribe signature
     */
    public static function verify_unsubscribe_signature(string $email, int $category, int $site_id, string $signature): bool
    {
        $expected = static::_unsubscribe_signature($email, $category, $site_id);

        return hash_equals($expected, $signature);
    }

    /**
     * The HMAC over the tuple an unsubscribe link authorizes.
     */
    private static function _unsubscribe_signature(string $email, int $category, int $site_id): string
    {
        $secret = config('rsx.mail.unsubscribe_secret') ?: config('app.key');
        $payload = $email . '|' . $category . '|' . $site_id;

        return hash_hmac('sha256', $payload, $secret);
    }

    // =========================================================================
    // DEV SITE EMAIL SAFETY
    // =========================================================================

    /**
     * Does the dev-site whitelist (an address, or the address's domain) name $address?
     */
    private static function _dev_whitelisted(string $address): bool
    {
        $address = strtolower(trim($address));

        $address_whitelist = config('rsx.mail.dev_site.address_whitelist', '');
        if ($address_whitelist) {
            $addresses = array_map('trim', array_map('strtolower', explode(',', $address_whitelist)));
            if (in_array($address, $addresses, true)) {
                return true;
            }
        }

        $domain_whitelist = config('rsx.mail.dev_site.domain_whitelist', '');
        if ($domain_whitelist) {
            $domains = array_map('trim', array_map('strtolower', explode(',', $domain_whitelist)));
            $domain = substr($address, strpos($address, '@') + 1);
            if (in_array($domain, $domains, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if dev site email gating is active
     *
     * Uses Rsx::is_dev_site() - hostname-based detection. This is a SECOND layer,
     * independent of config('rsx.mail.delivery'): a dev host redirects or drops
     * recipients even when delivery is live.
     */
    public static function is_dev_mode(): bool
    {
        return \App\RSpade\Core\Rsx::is_dev_site();
    }

    /**
     * Apply dev site email redirect logic
     *
     * On dev sites:
     * 1. If recipient is in address whitelist -> deliver normally
     * 2. If recipient domain is in domain whitelist -> deliver normally
     * 3. If catchall address is set -> redirect to catchall
     * 4. Otherwise -> NOTHING IS DELIVERABLE from this host. The third return value
     *    says so, and enqueue() records the row SUPPRESSED instead of queueing it.
     *    Note that this is NOT the same as case 1 even though both leave the address
     *    untouched: one means "send it", the other means "there is nowhere to send it",
     *    and a caller that cannot tell them apart mails a developer's inbox by accident.
     *
     * @param string $to Original recipient
     * @return array{0: string, 1: ?string, 2: bool} [actual_to, original_to_or_null, undeliverable]
     */
    private static function _apply_dev_redirect(string $to): array
    {
        if (self::_dev_whitelisted($to)) {
            return [$to, null, false];
        }

        // Redirect to catchall address
        $catchall = config('rsx.mail.dev_site.catchall_address');
        if ($catchall) {
            return [$catchall, $to, false];
        }

        // No catchall configured - the address is not deliverable from this host.
        // The envelope was not rewritten, so there is no 'original' to record.
        return [$to, null, true];
    }
}
