<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Mail\Rsx_Mail_Test_Email;
use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Mail\Php\Mail_Marketing_Fixture_Email;
use App\RSpade\Tests\Mail\Php\Mail_Notification_Fixture_Email;
use App\RSpade\Tests\Mail\Php\Mail_Security_Fixture_Email;
use App\RSpade\Tests\Mail\Php\Mail_Transport_Stub;

/**
 * Email_Site_Block_List_Test - the SITE's list of addresses no email may reach.
 *
 * The list is not the recipient opt-out: it holds in every category but SECURITY,
 * transactional included; it is checked at enqueue on the original addresses (before the dev-site
 * gate), again when the drain claims a row, and at resend, where no force overrides it.
 * A listed `to` records the whole message Blocked with cause 2 and the entry's reason; a
 * listed cc/bcc entry is removed, recorded on the row, and the message still goes.
 *
 * Delivery is pinned to aiosmtpd so a drain hands messages to the stub transport, and the
 * dev-site gate is whitelisted past except where it is the subject.
 */
class Email_Site_Block_List_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /** @var array the config setup() replaced, put back by teardown() */
    private static array $previous = [];

    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);

        self::$previous = [
            'rsx.mail.delivery' => config('rsx.mail.delivery'),
            'rsx.mail.dev_site.address_whitelist' => config('rsx.mail.dev_site.address_whitelist'),
            'rsx.mail.dev_site.domain_whitelist' => config('rsx.mail.dev_site.domain_whitelist'),
            'rsx.mail.dev_site.catchall_address' => config('rsx.mail.dev_site.catchall_address'),
        ];

        config([
            'rsx.mail.delivery' => 'aiosmtpd',
            'rsx.mail.dev_site.domain_whitelist' => 'example.com',
        ]);
    }

    public static function teardown()
    {
        config(self::$previous);
    }

    /** An address nothing else in the suite will collide with. */
    private static function __address(string $prefix): string
    {
        return $prefix . '_' . uniqid() . '@example.com';
    }

    /** Run the drain with $stub installed; the override is always cleared. */
    private static function __drain(Mail_Transport_Stub $stub): array
    {
        Rsx_Mail_Transport::$override_for_tests = $stub;

        try {
            return Task::internal('Mail_Queue_Service', 'send_pending_queue')->state();
        } finally {
            Rsx_Mail_Transport::$override_for_tests = null;
        }
    }

    /** The row as stored now. */
    private static function __reload(Email_Queue_Model $row): Email_Queue_Model
    {
        return Email_Queue_Model::without_site_scope(fn () => Email_Queue_Model::find($row->id));
    }

    // =========================================================================
    // THE API
    // =========================================================================

    /**
     * blk-01 - block and unblock are idempotent both ways, a re-block takes the new
     * reason, and the list reads back through blocked_addresses().
     */
    public static function test_the_api_is_idempotent_both_ways()
    {
        $email = static::__address('api');

        Rsx_Mail::block_address($email, 'First reason');
        Rsx_Mail::block_address($email, 'Second reason');

        $entries = [];
        foreach (Rsx_Mail::blocked_addresses() as $entry) {
            $entries[$entry->email] = $entry->reason;
        }

        static::__assert_equals('Second reason', $entries[$email] ?? null, 're-adding updates the reason, one row');
        static::__assert_true(Rsx_Mail::is_address_blocked($email));

        Rsx_Mail::unblock_address($email);
        Rsx_Mail::unblock_address($email);

        static::__assert_false(Rsx_Mail::is_address_blocked($email), 'unblocking an absent address is not an error');
        static::__assert_throws(\InvalidArgumentException::class, fn () => Rsx_Mail::block_address($email, '  '), 'A reason is required');
    }

    /**
     * blk-02 - matching is case- and whitespace-insensitive in both directions.
     */
    public static function test_matching_ignores_case_and_whitespace()
    {
        $email = static::__address('casing');

        Rsx_Mail::block_address('  ' . strtoupper($email) . ' ', 'Mixed case entry');

        static::__assert_true(Rsx_Mail::is_address_blocked($email), 'a lowercase lookup finds the uppercase entry');

        $row = (new Mail_Notification_Fixture_Email())->to(' ' . ucfirst($email))->send();

        static::__assert_equals(Email_Queue_Model::STATUS_BLOCKED, (int) $row->status_id);
    }

    // =========================================================================
    // ENQUEUE
    // =========================================================================

    /**
     * blk-03 - a listed `to` writes ONE Blocked row with cause 2 and the reason, in every
     * category - transactional included - and the drain hands nothing to the transport.
     */
    public static function test_a_listed_recipient_is_blocked_in_every_category()
    {
        $email = static::__address('every_category');
        Rsx_Mail::block_address($email, 'Client is marked do-not-email');

        $rows = [
            (new Rsx_Mail_Test_Email())->to($email)->send(),
            (new Mail_Notification_Fixture_Email('Notice probe'))->to($email)->send(),
            (new Mail_Marketing_Fixture_Email('Offer probe'))->to($email)->send(),
        ];

        foreach ($rows as $row) {
            $label = Email_Queue_Model::$enums['category_id'][(int) $row->category_id]['label'];

            static::__assert_equals(Email_Queue_Model::STATUS_BLOCKED, (int) $row->status_id, "{$label} is Blocked");
            static::__assert_equals(Email_Queue_Model::BLOCK_CAUSE_SITE_BLOCK_LIST, (int) $row->block_cause_id, "{$label} names the site list");
            static::__assert_equals(
                "Address is on this site's block list: Client is marked do-not-email",
                $row->last_error,
                "{$label} carries the entry's reason"
            );
        }

        $stub = new Mail_Transport_Stub();
        static::__drain($stub);

        static::__assert_equals(0, $stub->send_count, 'nothing reached the transport');
    }

    /**
     * blk-04 - a listed cc and a listed bcc are removed and recorded, and the message is
     * still delivered to `to` and the unlisted copy.
     */
    public static function test_listed_copies_are_withheld_and_the_message_still_goes()
    {
        $to = static::__address('to');
        $kept = static::__address('kept_cc');
        $listed_cc = static::__address('listed_cc');
        $listed_bcc = static::__address('listed_bcc');

        Rsx_Mail::block_address($listed_cc, 'Former client');
        Rsx_Mail::block_address($listed_bcc, 'Legal hold');

        $row = (new Mail_Notification_Fixture_Email('Copies probe'))
            ->to($to)
            ->cc($kept)
            ->cc($listed_cc, 'Listed Person')
            ->bcc($listed_bcc)
            ->send();

        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $row->status_id, 'the message still queues');
        static::__assert_equals([$kept], array_column($row->cc, 'address'), 'only the unlisted cc remains');
        static::__assert_equals([], $row->bcc, 'the listed bcc is gone');

        $withheld = $row->withheld_recipients;
        static::__assert_count(2, $withheld, 'both removals are recorded');
        static::__assert_equals(['cc', $listed_cc, 'Listed Person'], [$withheld[0]['field'], $withheld[0]['address'], $withheld[0]['name']]);
        static::__assert_contains('Former client', $withheld[0]['reason']);
        static::__assert_equals(['bcc', $listed_bcc], [$withheld[1]['field'], $withheld[1]['address']]);

        $stub = new Mail_Transport_Stub();
        static::__drain($stub);

        static::__assert_equals(1, count($stub->sent_messages), 'the message was delivered');
        $cc = array_map(fn ($address) => $address->getAddress(), $stub->sent_messages[0]->getCc());
        static::__assert_equals([$kept], $cc, 'with only the unlisted copy');
        static::__assert_equals([], $stub->sent_messages[0]->getBcc());
    }

    /**
     * blk-05 - an address blocked on one site is delivered on another.
     */
    public static function test_the_list_is_per_site()
    {
        $email = static::__address('isolation');
        Rsx_Mail::block_address($email, 'Blocked on site 1');

        $site = new Site_Model();
        $site->slug = 'block-list-' . uniqid();
        $site->name = 'Block List Second Site';
        $site->save();

        static::__acting_as_site((int) $site->id);

        try {
            static::__assert_false(Rsx_Mail::is_address_blocked($email), 'the second site has no such entry');

            $row = (new Mail_Notification_Fixture_Email())->to($email)->send();

            static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $row->status_id, 'and mails the address');
        } finally {
            static::__acting_as_site(self::SITE_ID);
        }
    }

    /**
     * blk-06 - on a dev host in live mode with a catchall, a listed address is Blocked on
     * the ORIGINAL address, never redirected; and a cc the dev whitelist does not name is
     * withheld rather than riding along with a redirected message.
     */
    public static function test_the_dev_gate_runs_after_the_list_and_covers_copies()
    {
        config([
            'rsx.mail.delivery' => 'live',
            'rsx.mail.dev_site.domain_whitelist' => '',
            'rsx.mail.dev_site.catchall_address' => 'developer@example.com',
        ]);

        $listed = static::__address('dev_listed');
        Rsx_Mail::block_address($listed, 'Do not contact');

        $blocked = (new Mail_Notification_Fixture_Email())->to($listed)->send();

        static::__assert_equals(Email_Queue_Model::STATUS_BLOCKED, (int) $blocked->status_id, 'the listed address is Blocked');
        static::__assert_equals($listed, $blocked->to_address, 'not redirected to the catchall');

        $redirected = (new Mail_Notification_Fixture_Email())
            ->to(static::__address('dev_to'))
            ->cc('real.person@customer.test')
            ->send();

        static::__assert_equals('developer@example.com', $redirected->to_address, 'to goes to the catchall');
        static::__assert_equals([], $redirected->cc, 'the real cc does not ride along');
        static::__assert_equals('dev site: not whitelisted', $redirected->withheld_recipients[0]['reason'] ?? null);
    }

    /**
     * blk-07 - a Blocked row is the WHOLE message: cc, dedupe key and attachments are
     * recorded, and a replay with the same dedupe key returns it rather than writing a
     * second row.
     */
    public static function test_a_blocked_row_is_whole_and_dedupes()
    {
        $email = static::__address('dedupe');
        $copy = static::__address('dedupe_cc');
        $key = 'block-list-dedupe-' . uniqid();
        Rsx_Mail::block_address($email, 'Do not contact');

        $send = fn () => (new Mail_Notification_Fixture_Email())
            ->to($email)
            ->cc($copy)
            ->dedupe_key($key)
            ->attach_bytes('hello', 'note.txt', 'text/plain')
            ->send();

        $first = $send();
        $replay = $send();

        static::__assert_equals(Email_Queue_Model::STATUS_BLOCKED, (int) $first->status_id);
        static::__assert_equals($first->id, $replay->id, 'the replay returns the Blocked row');
        static::__assert_equals([$copy], array_column($first->cc, 'address'), 'the cc is recorded');
        static::__assert_equals(1, $first->attachments()->count(), 'the attachment is recorded');
    }

    /**
     * blk-08 - an opted-out row records cause 1, and the opt-out still exempts
     * transactional mail.
     */
    public static function test_an_opt_out_records_cause_one()
    {
        $email = static::__address('opted_out');
        Rsx_Mail::block_all($email);

        $notice = (new Mail_Notification_Fixture_Email())->to($email)->send();
        $transactional = (new Rsx_Mail_Test_Email())->to($email)->send();

        static::__assert_equals(Email_Queue_Model::BLOCK_CAUSE_OPTED_OUT, (int) $notice->block_cause_id);
        static::__assert_equals(Email_Queue_Model::OPTED_OUT_ERROR, $notice->last_error);
        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $transactional->status_id, 'transactional ignores an opt-out');
    }

    // =========================================================================
    // DRAIN
    // =========================================================================

    /**
     * blk-09 - an address listed AFTER the row was queued is caught when the drain claims
     * it: Blocked, cause 2, no attempt counted, nothing sent.
     */
    public static function test_the_drain_blocks_an_address_listed_after_enqueue()
    {
        $email = static::__address('late');

        $row = (new Mail_Notification_Fixture_Email('Scheduled probe'))
            ->to($email)
            ->send_at(now()->addDay()->toIso8601String())
            ->send();

        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $row->status_id);

        Rsx_Mail::block_address($email, 'Listed while waiting');

        // The day passes.
        Email_Queue_Model::without_site_scope(fn () => Email_Queue_Model::where('id', $row->id)->update(['next_attempt_at' => now()->subMinute()]));

        $stub = new Mail_Transport_Stub();
        $counts = static::__drain($stub);
        $stored = static::__reload($row);

        static::__assert_equals(Email_Queue_Model::STATUS_BLOCKED, (int) $stored->status_id);
        static::__assert_equals(Email_Queue_Model::BLOCK_CAUSE_SITE_BLOCK_LIST, (int) $stored->block_cause_id);
        static::__assert_equals(0, (int) $stored->attempt_count, 'no attempt counted');
        static::__assert_equals(1, $counts['blocked']);
        static::__assert_false(in_array('Scheduled probe', $stub->sent_subjects, true), 'nothing was sent');
    }

    // =========================================================================
    // RESEND
    // =========================================================================

    /**
     * blk-10 - a list-blocked row is refused while listed, with and without force, and is
     * requeued WITHOUT force once the entry is removed.
     */
    public static function test_resend_honours_the_list_and_needs_no_force_once_unlisted()
    {
        $email = static::__address('resend');
        Rsx_Mail::block_address($email, 'Do not contact');

        $row = (new Mail_Notification_Fixture_Email())->to($email)->send();

        static::__assert_equals(Rsx_Mail::RESEND_ADDRESS_BLOCKED, Rsx_Mail::resend($row));
        static::__assert_equals(Rsx_Mail::RESEND_ADDRESS_BLOCKED, Rsx_Mail::resend($row, true), 'force does not override the list');

        Rsx_Mail::unblock_address($email);

        static::__assert_equals(Rsx_Mail::RESEND_QUEUED, Rsx_Mail::resend($row), 'unlisted: requeued without force');

        $stored = static::__reload($row);
        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $stored->status_id);
        static::__assert_null($stored->block_cause_id, 'the cause is cleared with the status');
    }

    // =========================================================================
    // THE SECURITY EXEMPTION
    // =========================================================================

    /**
     * blk-12 - a SECURITY email (a sign-in code the recipient set in motion) reaches a listed
     * address: queued, not Blocked, and delivered by the drain - and the drain's re-check of
     * a row listed after enqueue passes it too.
     */
    public static function test_a_security_email_reaches_a_listed_address()
    {
        $email = static::__address('security');
        Rsx_Mail::block_address($email, 'Do not contact');

        $row = (new Mail_Security_Fixture_Email('Sign-in code probe'))->to($email)->send();

        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $row->status_id, 'queued, not Blocked');
        static::__assert_null($row->block_cause_id);

        $stub = new Mail_Transport_Stub();
        static::__drain($stub);

        static::__assert_equals(Email_Queue_Model::STATUS_SENT, (int) static::__reload($row)->status_id, 'the drain sends it');
        static::__assert_true(in_array('Sign-in code probe', $stub->sent_subjects, true));
    }

    /**
     * blk-13 - SECURITY ignores the recipient opt-out, resends to a listed address without
     * force, and carries no unsubscribe link.
     */
    public static function test_a_security_email_ignores_the_opt_out_and_resends_to_a_listed_address()
    {
        $email = static::__address('security_opt_out');
        Rsx_Mail::block_all($email);
        Rsx_Mail::block_address($email, 'Do not contact');

        $row = (new Mail_Security_Fixture_Email())->to($email)->send();

        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $row->status_id, 'the opt-out does not apply');

        Email_Queue_Model::without_site_scope(fn () => Email_Queue_Model::where('id', $row->id)->update(['status_id' => Email_Queue_Model::STATUS_SENT]));

        static::__assert_equals(Rsx_Mail::RESEND_QUEUED, Rsx_Mail::resend(static::__reload($row)), 'resend is not refused by the list');
        static::__assert_false(
            str_contains(\App\RSpade\Core\Mail\Rsx_Mail_Builder::render_html(static::__reload($row)), '/unsubscribe'),
            'no unsubscribe link'
        );
    }

    /**
     * blk-11 - an opted-out row keeps today's rule: refused without force, requeued with it.
     */
    public static function test_resend_of_an_opted_out_row_still_needs_force()
    {
        $email = static::__address('resend_opt_out');
        Rsx_Mail::block_all($email);

        $row = (new Mail_Notification_Fixture_Email())->to($email)->send();

        static::__assert_equals(Rsx_Mail::RESEND_BLOCKED, Rsx_Mail::resend($row));
        static::__assert_equals(Rsx_Mail::RESEND_QUEUED, Rsx_Mail::resend($row, true));
    }
}
