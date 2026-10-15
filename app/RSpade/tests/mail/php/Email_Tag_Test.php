<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Mail\Php\Mail_Notification_Fixture_Email;
use App\RSpade\Tests\Mail\Php\Mail_Preview_Fixture_Email;

/**
 * Email_Tag_Test - an email class's tag() is read once at send() and frozen on the row.
 *
 * The tag is how one email class that sends several kinds of message keeps their
 * histories apart, so what matters is that it reaches EVERY row the send writes - a
 * Blocked or Suppressed row is history too - and that a value the column cannot hold is
 * refused loudly rather than truncated into a kind nobody declared.
 */
class Email_Tag_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __address(string $label): string
    {
        return 'tag_' . $label . '_' . uniqid() . '@example.com';
    }

    /**
     * Send past the dev-host gate, which is not this class's subject.
     */
    private static function __send(callable $build): Email_Queue_Model
    {
        static::__acting_as_site(self::SITE_ID);

        $previous = config('rsx.mail.dev_site.domain_whitelist');
        config(['rsx.mail.dev_site.domain_whitelist' => 'example.com']);

        try {
            return $build()->send();
        } finally {
            config(['rsx.mail.dev_site.domain_whitelist' => $previous]);
        }
    }

    public static function test_an_email_declares_no_tag_by_default()
    {
        static::__assert_null((new Mail_Notification_Fixture_Email())->tag(), 'the default hook answers null');

        $row = static::__send(fn () => (new Mail_Notification_Fixture_Email('Untagged'))->to(static::__address('none')));

        static::__assert_null(Email_Queue_Model::find($row->id)->tag, 'and an untagged send stores NULL');
    }

    public static function test_an_overriding_email_stores_its_tag()
    {
        $row = static::__send(
            fn () => (new Mail_Preview_Fixture_Email('Tagged', 'task_assigned'))->to(static::__address('stored'))
        );

        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $row->status_id, 'an ordinary queued send');
        static::__assert_equals('task_assigned', Email_Queue_Model::find($row->id)->tag, 'carrying the kind its class named');
    }

    /**
     * A site-block-list refusal is written whole, tag included.
     */
    public static function test_a_blocked_row_keeps_its_tag()
    {
        $address = static::__address('blocked');

        static::__acting_as_site(self::SITE_ID);
        Rsx_Mail::block_address($address, 'tag test');

        $row = static::__send(fn () => (new Mail_Preview_Fixture_Email('Blocked', 'blocked_kind'))->to($address));

        static::__assert_equals(Email_Queue_Model::STATUS_BLOCKED, (int) $row->status_id, 'the block list refused it');
        static::__assert_equals('blocked_kind', Email_Queue_Model::find($row->id)->tag, 'and the row still says which kind it was');
    }

    /**
     * A dev host with nowhere to send writes the row SUPPRESSED at enqueue - tag included.
     */
    public static function test_a_suppressed_row_keeps_its_tag()
    {
        static::__acting_as_site(self::SITE_ID);

        $previous = [
            config('rsx.mail.dev_site.address_whitelist'),
            config('rsx.mail.dev_site.domain_whitelist'),
            config('rsx.mail.dev_site.catchall_address'),
            config('rsx.mail.delivery'),
        ];

        // The dev-site gate engages only in 'live', and with no whitelist and no catchall
        // there is nowhere to send: the enqueue-time Suppressed path.
        config([
            'rsx.mail.dev_site.address_whitelist' => '',
            'rsx.mail.dev_site.domain_whitelist' => '',
            'rsx.mail.dev_site.catchall_address' => '',
            'rsx.mail.delivery' => 'live',
        ]);

        try {
            $row = (new Mail_Preview_Fixture_Email('Suppressed', 'suppressed_kind'))->to(static::__address('suppressed'))->send();
        } finally {
            config([
                'rsx.mail.dev_site.address_whitelist' => $previous[0],
                'rsx.mail.dev_site.domain_whitelist' => $previous[1],
                'rsx.mail.dev_site.catchall_address' => $previous[2],
                'rsx.mail.delivery' => $previous[3],
            ]);
        }

        static::__assert_equals(Email_Queue_Model::STATUS_SUPPRESSED, (int) $row->status_id, 'written suppressed at enqueue');
        static::__assert_equals('suppressed_kind', Email_Queue_Model::find($row->id)->tag, 'with its tag');
    }

    public static function test_a_tag_of_exactly_sixty_four_characters_is_stored()
    {
        $tag = str_repeat('k', 64);

        $row = static::__send(fn () => (new Mail_Preview_Fixture_Email('Long', $tag))->to(static::__address('max')));

        static::__assert_equals($tag, Email_Queue_Model::find($row->id)->tag, 'the column holds 64 characters');
    }

    /**
     * Longer than the column THROWS, naming the class, and writes nothing.
     */
    public static function test_a_tag_longer_than_sixty_four_characters_throws_and_writes_nothing()
    {
        $address = static::__address('too_long');

        static::__assert_throws(
            \InvalidArgumentException::class,
            fn () => static::__send(fn () => (new Mail_Preview_Fixture_Email('Too long', str_repeat('k', 65)))->to($address)),
            'Mail_Preview_Fixture_Email::tag()'
        );

        static::__assert_equals(
            0,
            (int) DB::table('_email_queue')->where('to_address', $address)->count(),
            'nothing was queued'
        );
    }
}
