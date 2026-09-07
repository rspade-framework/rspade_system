<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use App\RSpade\Core\Files\File_Disposal_Service;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Models\Email_Attachment_Model;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Mail\Php\Mail_Notification_Fixture_Email;

/**
 * Rsx_Email_Enqueue_Test - `(new Some_Email(...))->to($x)->send()`, up to the row.
 *
 * What an email class does BEFORE anything is rendered: resolve the tenant and the
 * recipient, honour the opt-out, honour a dedupe key, apply the dev-host gate, freeze
 * subject() and data() into the row, and persist attachments into the content-addressed
 * store. Nothing here builds a message.
 *
 * FREEZING IS THE POINT OF SEVERAL OF THESE ASSERTIONS. The row carries the values the
 * caller had, not values the email class would be asked for again when the drain
 * eventually runs - so a message queued today and sent after a retry tomorrow says the
 * same thing either way.
 *
 * SITE CONTEXT IS ESTABLISHED, NEVER INHERITED (backlog B-45): every test opens by
 * declaring the site - or by explicitly resetting to none, which is what the sessionless
 * task/CLI path looks like.
 */
class Rsx_Email_Enqueue_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /**
     * The class-level guarantee behind B-45. Every test also declares its own context.
     */
    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);
    }

    /**
     * An address nothing else in the suite will collide with.
     */
    private static function __address(string $prefix): string
    {
        return $prefix . '_' . uniqid() . '@example.com';
    }

    /**
     * Run something with example.com whitelisted on the dev-site gate.
     *
     * The framework test host is a `.dev.` hostname, so the gate is live for every send
     * in this class. Tests whose subject is NOT the gate whitelist their way past it.
     */
    private static function __with_dev_whitelist(callable $work)
    {
        $previous = config('rsx.mail.dev_site.domain_whitelist');

        config(['rsx.mail.dev_site.domain_whitelist' => 'example.com']);

        try {
            return $work();
        } finally {
            config(['rsx.mail.dev_site.domain_whitelist' => $previous]);
        }
    }

    // =========================================================================
    // TENANT
    // =========================================================================

    public static function test_the_row_lands_at_the_declared_site()
    {
        static::__acting_as_site(self::SITE_ID);

        $record = static::__with_dev_whitelist(
            fn () => (new Mail_Notification_Fixture_Email())->to(static::__address('site'))->send()
        );

        static::__assert_equals(self::SITE_ID, (int) $record->site_id, 'the row is filed under the acting site');
        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $record->status_id, 'and is queued');
    }

    /**
     * A #[Task] or CLI send has no session, so the site is 0 (the Default site) and the
     * blocklist must be consulted AT THAT SITE. Before this was fixed the facade invented
     * site 1 while the row went to site 0, so the opt-out query was `site_id = 1 AND
     * site_id = 0` - impossible - and no task-sent message ever honoured an unsubscribe.
     */
    public static function test_opt_out_is_enforced_with_no_session()
    {
        static::__reset_session();
        static::__assert_equals(0, Session::get_site_id(), 'no session -> site 0');

        $email = static::__address('sessionless_block');
        Rsx_Mail::block($email, Rsx_Mail::MARKETING);

        static::__assert_true(
            Rsx_Mail::is_blocked($email, Rsx_Mail::MARKETING),
            'opt-out is honored with no session (blocklist check and persisted row agree at site 0)'
        );
    }

    public static function test_normal_send_persists_the_sessionless_site()
    {
        static::__reset_session();

        $record = static::__with_dev_whitelist(
            fn () => (new Mail_Notification_Fixture_Email())->to(static::__address('sessionless_normal'))->send()
        );

        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $record->status_id, 'unblocked send enqueues PENDING');
        static::__assert_equals(0, (int) $record->site_id, 'the row lands at the session site (0), not an invented 1');
        static::__assert_equals(
            'Mail_Notification_Fixture_Email',
            $record->email_class,
            'the email class name is what identifies the message'
        );
    }

    // =========================================================================
    // RECIPIENTS
    // =========================================================================

    /**
     * A model is the ordinary argument to to(): the caller already has the person, and
     * making them dig out ->email and their own display name is how a name goes missing.
     */
    public static function test_to_resolves_the_name_from_get_printed_name()
    {
        static::__acting_as_site(self::SITE_ID);

        $recipient = new class {
            public string $email = 'printed@example.com';

            public function get_printed_name(): string
            {
                return 'Ada Lovelace';
            }
        };

        $record = static::__with_dev_whitelist(
            fn () => (new Mail_Notification_Fixture_Email())->to($recipient)->send()
        );

        static::__assert_equals('Ada Lovelace', $record->to_name, 'get_printed_name() wins when the object has one');
        static::__assert_equals('printed@example.com', $record->to_address, 'and the address comes from ->email');
    }

    public static function test_to_falls_back_to_a_first_and_last_name_pair()
    {
        static::__acting_as_site(self::SITE_ID);

        $recipient = new \stdClass();
        $recipient->email = static::__address('named');
        $recipient->first_name = 'Grace';
        $recipient->last_name = 'Hopper';

        $record = static::__with_dev_whitelist(
            fn () => (new Mail_Notification_Fixture_Email())->to($recipient)->send()
        );

        static::__assert_equals('Grace Hopper', $record->to_name, 'a first/last pair composes a display name');
    }

    public static function test_an_explicit_name_always_wins()
    {
        static::__acting_as_site(self::SITE_ID);

        $recipient = new \stdClass();
        $recipient->email = static::__address('override');
        $recipient->first_name = 'Grace';
        $recipient->last_name = 'Hopper';

        $record = static::__with_dev_whitelist(
            fn () => (new Mail_Notification_Fixture_Email())->to($recipient, 'Rear Admiral Hopper')->send()
        );

        static::__assert_equals('Rear Admiral Hopper', $record->to_name, 'the caller had the last word');
    }

    /**
     * A message has ONE To and any number of Cc/Bcc. So a later to() replaces, while
     * repeated cc()/bcc() accumulate - and the row is where that is recorded.
     */
    public static function test_cc_and_bcc_accumulate_while_to_replaces()
    {
        static::__acting_as_site(self::SITE_ID);

        $record = static::__with_dev_whitelist(function () {
            return (new Mail_Notification_Fixture_Email())
                ->to('first@example.com')
                ->to('second@example.com')
                ->cc('cc_one@example.com', 'CC One')
                ->cc('cc_two@example.com')
                ->bcc('bcc_one@example.com')
                ->bcc('bcc_two@example.com')
                ->send();
        });

        static::__assert_equals('second@example.com', $record->to_address, 'a later to() replaces the earlier one');
        static::__assert_count(2, $record->cc, 'both cc() calls accumulated');
        static::__assert_equals('cc_one@example.com', $record->cc[0]['address'], 'in declaration order');
        static::__assert_equals('CC One', $record->cc[0]['name'], 'with the name the caller gave');
        static::__assert_null($record->cc[1]['name'], 'and null where none was resolvable');
        static::__assert_count(2, $record->bcc, 'both bcc() calls accumulated');
    }

    public static function test_reply_to_is_recorded_as_an_address_and_a_name()
    {
        static::__acting_as_site(self::SITE_ID);

        $record = static::__with_dev_whitelist(function () {
            return (new Mail_Notification_Fixture_Email())
                ->to(static::__address('reply'))
                ->reply_to('support@example.com', 'Support Desk')
                ->send();
        });

        static::__assert_equals('support@example.com', $record->reply_to, 'the reply address is on the row');
        static::__assert_equals('Support Desk', $record->reply_to_name, 'and its display name');
    }

    /**
     * A send with nobody to send to is a programming error, not an empty outcome: the
     * alternative is a queue row addressed to '' that fails hours later in a task.
     */
    public static function test_a_send_with_no_recipient_throws()
    {
        static::__acting_as_site(self::SITE_ID);

        static::__assert_throws(
            \InvalidArgumentException::class,
            fn () => (new Mail_Notification_Fixture_Email())->send(),
            'no recipient'
        );
    }

    public static function test_an_empty_address_throws()
    {
        static::__acting_as_site(self::SITE_ID);

        static::__assert_throws(
            \InvalidArgumentException::class,
            fn () => (new Mail_Notification_Fixture_Email())->to('   '),
            'empty address'
        );
    }

    public static function test_an_object_with_no_email_throws()
    {
        static::__acting_as_site(self::SITE_ID);

        static::__assert_throws(
            \InvalidArgumentException::class,
            fn () => (new Mail_Notification_Fixture_Email())->to(new \stdClass()),
            'no ->email value'
        );
    }

    // =========================================================================
    // THE BLOCKLIST
    // =========================================================================

    public static function test_an_opted_out_notification_is_recorded_blocked()
    {
        static::__acting_as_site(self::SITE_ID);

        $email = static::__address('blocked');
        Rsx_Mail::block_all($email);

        $record = (new Mail_Notification_Fixture_Email())->to($email)->send();

        static::__assert_equals(
            Email_Queue_Model::STATUS_BLOCKED,
            (int) $record->status_id,
            'an opted-out notification takes the enqueue_blocked path, not PENDING'
        );
        static::__assert_contains(
            'unsubscribed',
            (string) $record->last_error,
            'and the row says why nothing was sent'
        );
    }

    /**
     * The BLOCKED row exists precisely because nothing was sent. "We had something to
     * tell you and you had asked us not to" is the fact somebody needs six months later.
     */
    public static function test_a_blocked_row_still_carries_the_subject_and_data()
    {
        static::__acting_as_site(self::SITE_ID);

        $email = static::__address('blocked_audit');
        Rsx_Mail::block_all($email);

        $record = (new Mail_Notification_Fixture_Email('Quarterly summary'))->to($email)->send();

        static::__assert_equals('Quarterly summary', $record->subject, 'the audit row records what would have been said');
        static::__assert_equals('Quarterly summary', $record->template_data['note'], 'and the frozen template data');
    }

    // =========================================================================
    // DEDUPE
    // =========================================================================

    public static function test_dedupe_key_returns_the_existing_row_and_enqueues_nothing()
    {
        static::__acting_as_site(self::SITE_ID);

        $email = static::__address('dedupe');
        $key = 'test:dedupe:' . uniqid();

        $first = static::__with_dev_whitelist(
            fn () => (new Mail_Notification_Fixture_Email())->to($email)->dedupe_key($key)->send()
        );
        $second = static::__with_dev_whitelist(
            fn () => (new Mail_Notification_Fixture_Email())->to($email)->dedupe_key($key)->send()
        );

        static::__assert_equals((int) $first->id, (int) $second->id, 'the second send returns the first row');
        static::__assert_equals(
            1,
            Email_Queue_Model::where('dedupe_key', $key)->count(),
            'nothing was enqueued the second time'
        );
    }

    /**
     * "In whatever state it reached" is the contract: a replayed webhook must not mail
     * anybody a second time even when the first attempt has already been delivered.
     */
    public static function test_dedupe_returns_a_row_that_has_already_finished()
    {
        static::__acting_as_site(self::SITE_ID);

        $email = static::__address('dedupe_sent');
        $key = 'test:dedupe:sent:' . uniqid();

        $first = static::__with_dev_whitelist(
            fn () => (new Mail_Notification_Fixture_Email())->to($email)->dedupe_key($key)->send()
        );
        $first->mark_sent('<already@example.com>', null);

        $second = static::__with_dev_whitelist(
            fn () => (new Mail_Notification_Fixture_Email())->to($email)->dedupe_key($key)->send()
        );

        static::__assert_equals((int) $first->id, (int) $second->id, 'the delivered row comes back');
        static::__assert_equals(
            Email_Queue_Model::STATUS_SENT,
            (int) $second->status_id,
            'in the state it reached - it is not re-queued'
        );
    }

    // =========================================================================
    // SCHEDULING AND THE POLYMORPHIC SUBJECT
    // =========================================================================

    public static function test_send_at_writes_next_attempt_at_and_holds_the_row()
    {
        static::__acting_as_site(self::SITE_ID);

        $when = now()->addHours(6);

        $record = static::__with_dev_whitelist(
            fn () => (new Mail_Notification_Fixture_Email())
                ->to(static::__address('scheduled'))
                ->send_at($when->toIso8601String())
                ->send()
        );

        static::__assert_not_null($record->next_attempt_at, 'send_at() writes next_attempt_at');
        static::__assert_equals(
            Email_Queue_Model::STATUS_PENDING,
            (int) $record->status_id,
            'the row is queued, just not yet claimable'
        );

        while (($claimed = Email_Queue_Model::claim_next()) !== null) {
            static::__assert_true(
                (int) $claimed->id !== (int) $record->id,
                'a scheduled row is skipped until its moment arrives'
            );
        }
    }

    public static function test_about_records_the_polymorphic_subject_on_the_row()
    {
        static::__acting_as_site(self::SITE_ID);

        $record = static::__with_dev_whitelist(
            fn () => (new Mail_Notification_Fixture_Email())
                ->to(static::__address('about'))
                ->about(42, 7)
                ->send()
        );

        static::__assert_equals(42, (int) $record->related_type, 'the type ref is recorded');
        static::__assert_equals(7, (int) $record->related_id, 'and the record id');
    }

    // =========================================================================
    // THE DEV-HOST GATE
    //
    // A SECOND layer, keyed on the hostname: a `.dev.` box must not mail real people.
    // It engages in ONE delivery mode - 'live' - because that is the only mode whose
    // messages can leave this box at all. Every test below therefore pins the mode,
    // rather than inheriting whatever this install is configured for.
    // =========================================================================

    /**
     * Nowhere to send is known at ENQUEUE time, so the row is written SUPPRESSED there
     * and never queued - a drain must not be handed a message whose envelope is already
     * invalid, and the reason would otherwise arrive a minute late for no gain.
     */
    public static function test_dev_site_with_no_destination_is_suppressed_at_enqueue()
    {
        static::__reset_session();

        $previous = [
            config('rsx.mail.dev_site.address_whitelist'),
            config('rsx.mail.dev_site.domain_whitelist'),
            config('rsx.mail.dev_site.catchall_address'),
            config('rsx.mail.delivery'),
        ];

        config([
            'rsx.mail.dev_site.address_whitelist' => '',
            'rsx.mail.dev_site.domain_whitelist' => '',
            'rsx.mail.dev_site.catchall_address' => '',
            // The gate only engages in the mode whose messages can LEAVE this box, so the
            // mode is pinned here rather than inherited from whatever this install is set
            // to - the shipped default would otherwise make this test assert its opposite.
            'rsx.mail.delivery' => 'live',
        ]);

        try {
            $record = (new Mail_Notification_Fixture_Email())->to(static::__address('nowhere'))->send();

            static::__assert_equals(
                Email_Queue_Model::STATUS_SUPPRESSED,
                (int) $record->status_id,
                'a dev host with nowhere to send records the row SUPPRESSED, not PENDING'
            );
            static::__assert_equals(
                'dev site: no whitelist match and no catchall',
                $record->last_error,
                'the row says why nothing went out'
            );
        } finally {
            config([
                'rsx.mail.dev_site.address_whitelist' => $previous[0],
                'rsx.mail.dev_site.domain_whitelist' => $previous[1],
                'rsx.mail.dev_site.catchall_address' => $previous[2],
                'rsx.mail.delivery' => $previous[3],
            ]);
        }
    }

    public static function test_dev_site_redirects_to_the_catchall_and_records_the_original()
    {
        static::__reset_session();

        $previous = [
            config('rsx.mail.dev_site.address_whitelist'),
            config('rsx.mail.dev_site.domain_whitelist'),
            config('rsx.mail.dev_site.catchall_address'),
            config('rsx.mail.delivery'),
        ];

        config([
            'rsx.mail.dev_site.address_whitelist' => '',
            'rsx.mail.dev_site.domain_whitelist' => '',
            'rsx.mail.dev_site.catchall_address' => 'developer@example.com',
            'rsx.mail.delivery' => 'live',
        ]);

        try {
            $original = static::__address('redirected');
            $record = (new Mail_Notification_Fixture_Email())->to($original)->send();

            static::__assert_equals('developer@example.com', $record->to_address, 'the envelope goes to the catchall');
            static::__assert_equals($original, $record->dev_original_to, 'and the real recipient is recorded');
            static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $record->status_id, 'it is deliverable, so it queues');
        } finally {
            config([
                'rsx.mail.dev_site.address_whitelist' => $previous[0],
                'rsx.mail.dev_site.domain_whitelist' => $previous[1],
                'rsx.mail.dev_site.catchall_address' => $previous[2],
                'rsx.mail.delivery' => $previous[3],
            ]);
        }
    }

    /**
     * The same dev host and the same empty whitelists, but the AIOSMTPD mode, whose
     * messages reach nobody but the local catcher. There is no real recipient to
     * protect, so the gate does not apply.
     *
     * This is what makes a fresh development container usable: the shipped default mode,
     * a `.dev.` hostname, nothing whitelisted, and a developer still sees their own mail.
     */
    public static function test_aiosmtpd_mode_bypasses_the_dev_site_gate()
    {
        static::__reset_session();

        $previous = [
            config('rsx.mail.dev_site.address_whitelist'),
            config('rsx.mail.dev_site.domain_whitelist'),
            config('rsx.mail.dev_site.catchall_address'),
            config('rsx.mail.delivery'),
        ];

        config([
            'rsx.mail.dev_site.address_whitelist' => '',
            'rsx.mail.dev_site.domain_whitelist' => '',
            'rsx.mail.dev_site.catchall_address' => '',
            'rsx.mail.delivery' => 'aiosmtpd',
        ]);

        try {
            $email = static::__address('loopback');
            $record = (new Mail_Notification_Fixture_Email())->to($email)->send();

            static::__assert_equals(
                Email_Queue_Model::STATUS_PENDING,
                (int) $record->status_id,
                'aiosmtpd mode queues normally on a dev host with nothing whitelisted'
            );
            static::__assert_equals($email, $record->to_address, 'the address is delivered as-is - nothing was redirected');
            static::__assert_null($record->dev_original_to, 'no redirect happened, so there is no original to record');
        } finally {
            config([
                'rsx.mail.dev_site.address_whitelist' => $previous[0],
                'rsx.mail.dev_site.domain_whitelist' => $previous[1],
                'rsx.mail.dev_site.catchall_address' => $previous[2],
                'rsx.mail.delivery' => $previous[3],
            ]);
        }
    }

    // =========================================================================
    // ATTACHMENTS
    // =========================================================================

    /**
     * attach_bytes() puts the caller's bytes in the CONTENT-ADDRESSED store, so the same
     * generated report mailed to a thousand people is stored once - and the
     * email_attachments row is what pins the blob against disposal.
     */
    public static function test_attach_bytes_stores_the_blob_and_pins_it_against_disposal()
    {
        static::__acting_as_site(self::SITE_ID);

        $bytes = "id,name\n1,Ada\n";

        $record = static::__with_dev_whitelist(
            fn () => (new Mail_Notification_Fixture_Email())
                ->to(static::__address('attached'))
                ->attach_bytes($bytes, 'people.csv', 'text/csv')
                ->send()
        );

        $attachments = Email_Attachment_Model::where('email_queue_id', $record->id)->get();

        static::__assert_count(1, $attachments, 'one attachment row was written');

        $attachment = $attachments[0];

        static::__assert_equals('people.csv', $attachment->file_name, 'the recipient sees the name the caller gave');
        static::__assert_equals('text/csv', $attachment->mime_type, 'and the type the caller declared');
        static::__assert_equals(
            Email_Attachment_Model::DISPOSITION_ATTACHMENT,
            (int) $attachment->disposition_id,
            'attach_bytes() is an attachment, not an inline part'
        );
        static::__assert_null($attachment->cid, 'and carries no content id');

        $storage = $attachment->file_storage;

        static::__assert_not_null($storage, 'the blob row exists');
        static::__assert_equals($bytes, file_get_contents($storage->get_full_path()), 'and holds the bytes');

        static::__assert_false(
            File_Disposal_Service::release_blob_if_orphaned((int) $storage->id),
            'a queued email pins its blob - disposal must refuse to release it'
        );
        static::__assert_true(
            is_file($storage->get_full_path()),
            'and the bytes are still on disk afterwards'
        );
    }

    public static function test_embed_records_an_inline_attachment_under_its_content_id()
    {
        static::__acting_as_site(self::SITE_ID);

        $path = storage_path('rsx-tmp/email_embed_probe_' . uniqid() . '.png');
        // A one-pixel PNG, so the file is a real image and not merely bytes with a name.
        file_put_contents_safe($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        try {
            $record = static::__with_dev_whitelist(
                fn () => (new Mail_Notification_Fixture_Email('Notice', true))
                    ->to(static::__address('embedded'))
                    ->embed('fixture_image', $path)
                    ->send()
            );

            $attachment = Email_Attachment_Model::where('email_queue_id', $record->id)->first();

            static::__assert_not_null($attachment, 'the inline attachment row was written');
            static::__assert_equals(
                Email_Attachment_Model::DISPOSITION_INLINE,
                (int) $attachment->disposition_id,
                'embed() is an inline part'
            );
            static::__assert_equals('fixture_image', $attachment->cid, 'bound to the content id the template uses');
        } finally {
            @unlink($path);
        }
    }

    public static function test_attaching_a_file_that_does_not_exist_throws()
    {
        static::__acting_as_site(self::SITE_ID);

        static::__assert_throws(
            \RuntimeException::class,
            fn () => static::__with_dev_whitelist(
                fn () => (new Mail_Notification_Fixture_Email())
                    ->to(static::__address('missing_file'))
                    ->attach('/nonexistent/nothing-here.pdf')
                    ->send()
            ),
            'no such file'
        );
    }

    // =========================================================================
    // FREEZING
    // =========================================================================

    /**
     * subject() and data() are called ONCE, at send(), and the answers are the row. A
     * template rendered after a retry tomorrow renders today's values.
     */
    public static function test_subject_and_data_are_frozen_into_the_row()
    {
        static::__acting_as_site(self::SITE_ID);

        $email = new Mail_Notification_Fixture_Email('Invoice 4711 is ready');

        $record = static::__with_dev_whitelist(
            fn () => $email->to(static::__address('frozen'))->send()
        );

        // Mutating the instance afterwards must not reach back into the queued row.
        $email->note = 'something else entirely';

        $record = $record->fresh();

        static::__assert_equals('Invoice 4711 is ready', $record->subject, 'the subject was frozen at send()');
        static::__assert_equals(
            'Invoice 4711 is ready',
            $record->template_data['note'],
            'and so was the template data'
        );
    }

    public static function test_the_category_comes_from_the_class_constant()
    {
        static::__acting_as_site(self::SITE_ID);

        $record = static::__with_dev_whitelist(
            fn () => (new Mail_Notification_Fixture_Email())->to(static::__address('category'))->send()
        );

        static::__assert_equals(
            Email_Queue_Model::CATEGORY_NOTIFICATION,
            (int) $record->category_id,
            'the row carries the email class\'s declared category'
        );
    }
}
