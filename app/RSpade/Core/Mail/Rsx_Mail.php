<?php

namespace App\RSpade\Core\Mail;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Mail\Rsx_Email_Abstract;
use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Models\Email_Attachment_Model;
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
 * recipient blocklist, the dev-site recipient gating, the unsubscribe signature, and
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

    /**
     * Queue an email built by an Rsx_Email_Abstract subclass.
     *
     * The order of operations is load-bearing:
     *
     *   1. Resolve the tenant (the realm being served, not the identity logged in).
     *   2. Dedupe: an already-used key returns the EXISTING row and enqueues nothing.
     *   3. Blocklist: a non-transactional email to an opted-out address is RECORDED
     *      as BLOCKED, never sent - the audit row is the point.
     *   4. Dev-site redirect (a second, independent safety layer keyed on hostname).
     *   5. Freeze subject() and data() into the row. From here the email's own class
     *      is never asked anything again: a template that renders next Tuesday renders
     *      the values the caller had TODAY.
     *   6. Persist attachments into the content-addressed blob store.
     *   7. Kick the drain.
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

        $to = $envelope['to']['address'];
        $to_name = $envelope['to']['name'];

        // A key already used by this tenant means this email has been queued before.
        // Return that row in whatever state it reached - re-running an import or
        // replaying a webhook must not mail anybody twice.
        if ($envelope['dedupe_key'] !== null) {
            $existing = Email_Queue_Model::find_by_dedupe_key($site_id, $envelope['dedupe_key']);

            if ($existing !== null) {
                return $existing;
            }
        }

        // Transactional email always delivers; the other categories honour the opt-out.
        if ($category !== self::TRANSACTIONAL && Email_Recipient_Model::is_blocked($site_id, $to, $category)) {
            return Email_Queue_Model::enqueue_blocked(
                $site_id,
                $to,
                $email->subject(),
                $email::view_id(),
                $email->data(),
                $category,
                $to_name
            );
        }

        // The dev-site layer exists to keep a development box from mailing REAL people,
        // so it applies to the ONE mode that can reach one: MODE_LIVE. Every other mode
        // has already made the message unable to leave - aiosmtpd files it in a Maildir
        // here, suppressed never opens a transport, disabled never drains - and gating
        // there would mean a fresh install recorded every message SUPPRESSED and a
        // developer never saw their own mail. Set MAIL_DELIVERY=live and the gating is
        // exactly what it always was.
        $dev_original_to = null;
        $dev_undeliverable = false;
        if (self::is_dev_mode() && Rsx_Mail_Transport::delivery_mode() === Rsx_Mail_Transport::MODE_LIVE) {
            [$to, $dev_original_to, $dev_undeliverable] = self::_apply_dev_redirect($to);
        }

        $descriptor = [
            'site_id' => $site_id,
            'to_address' => $to,
            'to_name' => $to_name,
            'subject' => $email->subject(),
            'email_class' => $email::view_id(),
            'template_data' => $email->data(),
            'category_id' => $category,
            'reply_to' => $envelope['reply_to']['address'] ?? null,
            'reply_to_name' => $envelope['reply_to']['name'] ?? null,
            'cc' => $envelope['cc'],
            'bcc' => $envelope['bcc'],
            'dedupe_key' => $envelope['dedupe_key'],
            'next_attempt_at' => $envelope['send_at'],
            'dev_original_to' => $dev_original_to,
            'related_type' => $envelope['related_type'],
            'related_id' => $envelope['related_id'],
        ];

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
     * Store each declared attachment's bytes in the blob store and record the row.
     *
     * The bytes are content-addressed, so the same file mailed to a thousand people is
     * stored once; the email_attachments row is what pins the blob against disposal.
     *
     * @param Email_Queue_Model $record
     * @param array<int, array> $specs
     * @return void
     */
    private static function _persist_attachments(Email_Queue_Model $record, array $specs): void
    {
        $sort_order = 0;

        foreach ($specs as $spec) {
            [$storage, $file_name, $mime_type] = static::_resolve_attachment_blob($spec);

            $attachment = new Email_Attachment_Model();
            $attachment->email_queue_id = $record->id;
            $attachment->file_storage_id = $storage->id;
            $attachment->file_name = $file_name;
            $attachment->mime_type = $mime_type;
            $attachment->disposition_id = $spec['disposition'];
            $attachment->cid = $spec['cid'];
            $attachment->sort_order = $sort_order++;
            $attachment->save();
        }
    }

    /**
     * Get one attachment spec's bytes into the blob store.
     *
     * A File_Attachment_Model REUSES its existing blob - nothing is copied and nothing
     * is re-hashed. A path or raw bytes go through a temp file, because store_blob()
     * hashes and byte-compares a file on disk (see File_Attachment_Model::create_from_string,
     * which does the same dance for the same reason).
     *
     * @param array $spec
     * @return array{0: File_Storage_Model, 1: string, 2: string}
     */
    private static function _resolve_attachment_blob(array $spec): array
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

            return [
                $storage,
                $spec['name'] ?? $source->file_name,
                $spec['mime'] ?? $source->mime_type,
            ];
        }

        if (is_string($source)) {
            if (!is_file($source)) {
                throw new \RuntimeException("Cannot attach '{$source}' to an email: no such file.");
            }

            return [
                File_Storage_Model::store_blob($source),
                $spec['name'] ?? basename($source),
                $spec['mime'] ?? (mime_content_type($source) ?: 'application/octet-stream'),
            ];
        }

        // Raw bytes the caller generated.
        $temp_path = sys_get_temp_dir() . '/rspade_email_attachment_' . random_hash() . '.bin';

        if (file_put_contents_safe($temp_path, $spec['bytes']) === false) {
            throw new \RuntimeException('Failed to write email attachment bytes to a temporary file.');
        }

        try {
            $storage = File_Storage_Model::store_blob($temp_path);
        } finally {
            if (file_exists($temp_path)) {
                @unlink($temp_path);
            }
        }

        return [$storage, $spec['name'], $spec['mime']];
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
    // BLOCKLIST API
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
        $to_lower = strtolower($to);

        // Check address whitelist
        $address_whitelist = config('rsx.mail.dev_site.address_whitelist', '');
        if ($address_whitelist) {
            $addresses = array_map('trim', array_map('strtolower', explode(',', $address_whitelist)));
            if (in_array($to_lower, $addresses)) {
                return [$to, null, false];
            }
        }

        // Check domain whitelist
        $domain_whitelist = config('rsx.mail.dev_site.domain_whitelist', '');
        if ($domain_whitelist) {
            $domains = array_map('trim', array_map('strtolower', explode(',', $domain_whitelist)));
            $to_domain = substr($to_lower, strpos($to_lower, '@') + 1);
            if (in_array($to_domain, $domains)) {
                return [$to, null, false];
            }
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
