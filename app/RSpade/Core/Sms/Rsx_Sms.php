<?php

namespace App\RSpade\Core\Sms;

use App\RSpade\Core\Models\Sms_Queue_Model;
use App\RSpade\Core\Models\Sms_Recipient_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;

/**
 * Rsx_Sms - Framework SMS API (mirrors Rsx_Mail).
 *
 * One-call SMS sending. All messages are queued - never sent inline. Dev sites can
 * redirect outbound SMS to a catchall. THERE IS NO SMS PROVIDER: config
 * rsx.sms.delivery is 'suppressed' and Sms_Queue_Service records each message
 * SUPPRESSED. The queue, the blocklist and the dev gating are all real.
 *
 * Usage:
 *   Rsx_Sms::send('+15555550123', 'Your code is 4821', Rsx_Sms::TRANSACTIONAL);
 */
class Rsx_Sms
{
    // SMS categories - match Sms_Queue_Model::CATEGORY_* constants.
    const TRANSACTIONAL = 1;
    const NOTIFICATION = 2;
    const MARKETING = 3;

    /** Every claimed row is recorded SUPPRESSED - there is nothing to hand it to. */
    const MODE_SUPPRESSED = 'suppressed';

    /** The queue is frozen: the drain does nothing at all and rows stay PENDING. */
    const MODE_DISABLED = 'disabled';

    /**
     * Every value rsx.sms.delivery may hold.
     *
     * The mail modes' vocabulary MINUS the two that need a transport. Keeping the two
     * shared spellings identical is the point - an operator who knows what `disabled`
     * means for mail knows what it means here - and refusing 'live' and 'aiosmtpd' out
     * loud is better than accepting a word that would quietly do nothing.
     */
    const DELIVERY_MODES = [
        self::MODE_SUPPRESSED,
        self::MODE_DISABLED,
    ];

    /**
     * What this install does with an SMS. One of the two MODE_* constants.
     */
    public static function delivery_mode(): string
    {
        $mode = strtolower(trim((string) config('rsx.sms.delivery', self::MODE_SUPPRESSED)));

        if (!in_array($mode, self::DELIVERY_MODES, true)) {
            throw new \RuntimeException(
                "rsx.sms.delivery is '{$mode}' - THERE IS NO SMS PROVIDER, so the only modes are '"
                . implode("', '", self::DELIVERY_MODES) . "'."
            );
        }

        return $mode;
    }

    /**
     * Queue an SMS for delivery.
     *
     * @param string $to Destination phone number (E.164 recommended)
     * @param string $body Message text
     * @param int $category One of self::TRANSACTIONAL, NOTIFICATION, MARKETING
     * @param int|null $related_type Polymorphic type ref for what triggered this SMS
     * @param int|null $related_id Polymorphic ID
     * @return Sms_Queue_Model The queued record
     */
    public static function send(
        string $to,
        string $body,
        int $category = self::NOTIFICATION,
        ?int $related_type = null,
        ?int $related_id = null
    ): Sms_Queue_Model {
        $site_id = static::__current_site_id();
        $to = trim($to);

        // Blocklist: transactional SMS always delivers; other categories are skipped
        // (recorded BLOCKED) for opted-out numbers.
        if ($category !== self::TRANSACTIONAL) {
            if (Sms_Recipient_Model::is_blocked($site_id, $to, $category)) {
                return Sms_Queue_Model::enqueue_blocked($site_id, $to, $body, $category);
            }
        }

        // Dev-site redirect (mirror of the email dev safety): capture the original
        // destination in dev_original_to when redirected/suppressed.
        $dev_original_to = null;
        $dev_undeliverable = false;
        if (self::is_dev_mode()) {
            [$actual_to, $dev_original_to, $dev_undeliverable] = self::_apply_dev_redirect($to);
            $to = $actual_to;
        }

        // A dev host with no whitelist match and no catchall has nowhere to send this,
        // and that is known NOW - the row is recorded SUPPRESSED rather than queued.
        // (The mail side does exactly this; the two facades stay one shape.)
        if ($dev_undeliverable) {
            return Sms_Queue_Model::enqueue_suppressed(
                $site_id,
                $to,
                $body,
                'dev site: no whitelist match and no catchall',
                $category,
                $related_type,
                $related_id
            );
        }

        $record = Sms_Queue_Model::enqueue(
            $site_id, $to, $body, $category, $dev_original_to, $related_type, $related_id
        );

        // Drain the queue promptly via a background worker (coalesced by #[Exclusive]).
        // Every context does this - a web request, a CLI importer, a task. The one
        // exception is a test run: rsx:test swaps the default connection to 'test' for
        // the whole run, and a detached worker booting against the DEVELOPER's database
        // would drain rows it cannot see. A test that wants the drain runs it itself.
        if (config('database.default') !== 'test') {
            Task::dispatch('Sms_Queue_Service', 'send_pending_queue');
        }

        return $record;
    }

    // =========================================================================
    // BLOCKLIST API (opt-out / STOP handling)
    // =========================================================================

    /**
     * Whether a number is blocked for a category.
     */
    public static function is_blocked(string $number, int $category): bool
    {
        $site_id = static::__current_site_id();
        return Sms_Recipient_Model::is_blocked($site_id, $number, $category);
    }

    /**
     * Block a category for a number.
     */
    public static function block(string $number, int $category): void
    {
        $site_id = static::__current_site_id();
        Sms_Recipient_Model::block($site_id, $number, $category);
    }

    /**
     * Unblock a category for a number.
     */
    public static function unblock(string $number, int $category): void
    {
        $site_id = static::__current_site_id();
        Sms_Recipient_Model::unblock($site_id, $number, $category);
    }

    /**
     * Block all non-transactional SMS for a number (a STOP reply).
     */
    public static function block_all(string $number): void
    {
        $site_id = static::__current_site_id();
        Sms_Recipient_Model::block_all($site_id, $number);
    }

    /**
     * The tenant this send/blocklist call belongs to, resolved from the EXPERIENCE that
     * is actually being served (the Rsx_Mail fork, byte for byte - these two facades have
     * the same shape and the same defect).
     *
     * The site stamps the _sms_queue row AND drives the
     * Sms_Recipient_Model::is_blocked($site_id, ...) opt-out lookup, so asking the staff
     * facade on a portal request files the message under the wrong tenant and consults the
     * wrong blocklist - a recipient who sent STOP could still be messaged. No portal
     * caller exists in the template today (a portal 2FA / verification code is the obvious
     * first one); the fork is here so the first one is correct. CLI (the queue drain,
     * tests) is not a portal request and keeps the staff path unchanged.
     *
     * @return int
     */
    private static function __current_site_id(): int
    {
        if (Rsx_Portal::is_portal_request()) {
            return (int) Portal_Session::get_site_id();
        }

        return (int) Session::get_site_id();
    }

    // =========================================================================
    // DEV SITE SMS SAFETY (mirror of Rsx_Mail)
    // =========================================================================

    /**
     * Whether dev-site SMS gating is active (hostname-based, via Rsx::is_dev_site()).
     */
    public static function is_dev_mode(): bool
    {
        return \App\RSpade\Core\Rsx::is_dev_site();
    }

    /**
     * Apply dev-site SMS redirect logic. On dev sites:
     *   1. Number in whitelist -> deliver as-is
     *   2. Catchall number set  -> redirect to catchall (original captured)
     *   3. Otherwise            -> NOTHING IS DELIVERABLE from this host; the third
     *                              return value says so and send() records SUPPRESSED
     *
     * @return array{0: string, 1: ?string, 2: bool} [actual_to, original_to_or_null, undeliverable]
     */
    private static function _apply_dev_redirect(string $to): array
    {
        $whitelist = config('rsx.sms.dev_site.number_whitelist', '');
        if ($whitelist) {
            $numbers = array_map('trim', explode(',', $whitelist));
            if (in_array($to, $numbers)) {
                return [$to, null, false];
            }
        }

        $catchall = config('rsx.sms.dev_site.catchall_number');
        if ($catchall) {
            return [$catchall, $to, false];
        }

        // The destination was not rewritten, so there is no 'original' to record.
        return [$to, null, true];
    }
}
