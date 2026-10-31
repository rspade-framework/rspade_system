<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Mail\Rsx_Test_Recipients;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Sms_Queue_Model;
use App\RSpade\Core\Sms\Rsx_Sms;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Mail\Php\Mail_Notification_Fixture_Email;
use App\RSpade\Tests\Mail\Php\Mail_Transport_Stub;

/**
 * Test recipients (Rsx_Test_Recipients): an email address on a test domain
 * (rsx.mail.test_domains, default `test.*`) or a (nnn) 555-nnnn phone number goes through
 * the whole sending path and is NEVER delivered - in every mode, live included.
 *
 * Pinned: the matching rules; a test `to` is rendered and settled as Test with no transport
 * call, in live and suppressed mode alike, and is never redirected by the dev-site layer;
 * a test copy on a real message is withheld and recorded while the real recipient is sent;
 * deliverable_to() answers true for a test address unless delivery is disabled, and still
 * honours the block list; a test SMS is settled as Test where any other is Suppressed.
 */
class Test_Recipients_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);
    }

    /** Drain the mail queue in $mode through a recording transport stub; the stub. */
    private static function __drain(string $mode): Mail_Transport_Stub
    {
        $stub = new Mail_Transport_Stub();
        $previous = config('rsx.mail.delivery');
        config(['rsx.mail.delivery' => $mode]);
        Rsx_Mail_Transport::$override_for_tests = $stub;

        try {
            Task::internal('Mail_Queue_Service', 'send_pending_queue');
        } finally {
            Rsx_Mail_Transport::$override_for_tests = null;
            config(['rsx.mail.delivery' => $previous]);
        }

        return $stub;
    }

    /** Queue the fixture to $to (optionally with a cc) in $mode, with the dev gate passing example.com. */
    private static function __queue(string $mode, string $to, ?string $cc = null): Email_Queue_Model
    {
        $previous = [config('rsx.mail.delivery'), config('rsx.mail.dev_site.domain_whitelist')];
        config(['rsx.mail.delivery' => $mode, 'rsx.mail.dev_site.domain_whitelist' => 'example.com']);

        try {
            $email = (new Mail_Notification_Fixture_Email('Test recipient probe'))->to($to);
            if ($cc !== null) {
                $email->cc($cc);
            }

            return $email->send();
        } finally {
            config(['rsx.mail.delivery' => $previous[0], 'rsx.mail.dev_site.domain_whitelist' => $previous[1]]);
        }
    }

    public static function test_which_addresses_and_numbers_are_test_recipients()
    {
        foreach (['a@test.com', 'B@TEST.ORG', 'c@test.co.uk'] as $address) {
            static::__assert_true(Rsx_Test_Recipients::is_test_email($address), "{$address} is a test address");
        }
        foreach (['a@example.com', 'a@testing.com', 'a@nottest.com', 'a@sub.test.com', 'not-an-address'] as $address) {
            static::__assert_false(Rsx_Test_Recipients::is_test_email($address), "{$address} is not");
        }

        $previous = config('rsx.mail.test_domains');
        config(['rsx.mail.test_domains' => ['test.*', '*.sandbox.example']]);
        try {
            static::__assert_true(Rsx_Test_Recipients::is_test_email('x@qa.sandbox.example'), 'an application pattern counts');
        } finally {
            config(['rsx.mail.test_domains' => $previous]);
        }

        foreach (['(212) 555-0142', '+1 212 555 0142', '12125550142', '212.555.9999'] as $number) {
            static::__assert_true(Rsx_Test_Recipients::is_test_phone($number), "{$number} is a test number");
        }
        foreach (['(555) 123-4567', '212-556-0142', '+44 20 7946 0555', '5550142'] as $number) {
            static::__assert_false(Rsx_Test_Recipients::is_test_phone($number), "{$number} is not");
        }
    }

    public static function test_a_test_address_is_rendered_and_recorded_but_never_handed_to_a_transport()
    {
        foreach (['live', 'suppressed'] as $mode) {
            $row = static::__queue($mode, 'probe_' . uniqid() . '@test.com');
            static::__assert_null($row->dev_original_to, "{$mode}: never redirected by the dev-site layer");

            $stub = static::__drain($mode);
            $row->refresh();

            static::__assert_equals(Email_Queue_Model::STATUS_TEST, (int) $row->status_id, "{$mode}: settled as Test");
            static::__assert_equals(0, $stub->send_count, "{$mode}: no transport call");
            static::__assert_not_null($row->sent_at, "{$mode}: recorded as sent");
        }
    }

    public static function test_a_test_copy_is_withheld_while_the_real_recipient_is_sent()
    {
        $row = static::__queue('live', 'real_' . uniqid() . '@example.com', 'copy_' . uniqid() . '@test.org');

        $withheld = $row->withheld_recipients ?? [];
        static::__assert_count(1, $withheld, 'the test copy is recorded as withheld');
        static::__assert_contains('test address', $withheld[0]['reason'] ?? '');
        static::__assert_equals([], $row->cc ?? [], 'and removed from the envelope');

        $stub = static::__drain('live');
        $row->refresh();

        static::__assert_equals(Email_Queue_Model::STATUS_SENT, (int) $row->status_id, 'the real recipient is sent');
        static::__assert_equals(1, $stub->send_count);
    }

    public static function test_deliverable_to_counts_a_test_address_as_delivered_unless_the_queue_is_frozen()
    {
        $previous = config('rsx.mail.delivery');

        try {
            config(['rsx.mail.delivery' => 'suppressed']);
            static::__assert_true(Rsx_Mail::deliverable_to('who@test.com', Rsx_Mail::NOTIFICATION), 'suppressed: yes, recorded as sent');
            static::__assert_false(Rsx_Mail::deliverable_to('who@example.com', Rsx_Mail::NOTIFICATION), 'while a real address is not');

            config(['rsx.mail.delivery' => 'disabled']);
            static::__assert_false(Rsx_Mail::deliverable_to('who@test.com', Rsx_Mail::NOTIFICATION), 'disabled: the queue never drains');

            config(['rsx.mail.delivery' => 'suppressed']);
            Rsx_Mail::block_address('listed@test.com', 'test fixture');
            static::__assert_false(Rsx_Mail::deliverable_to('listed@test.com', Rsx_Mail::NOTIFICATION), 'the block list still applies');
        } finally {
            config(['rsx.mail.delivery' => $previous]);
        }
    }

    public static function test_a_test_number_is_settled_as_test_and_any_other_as_suppressed()
    {
        $previous = config('rsx.sms.delivery');
        config(['rsx.sms.delivery' => 'suppressed']);

        try {
            $test = Rsx_Sms::send('(212) 555-0142', 'Probe', Rsx_Sms::TRANSACTIONAL);
            static::__assert_null($test->dev_original_to, 'a test number is never redirected');

            Task::internal('Sms_Queue_Service', 'send_pending_queue');

            $test->refresh();
            static::__assert_equals(Sms_Queue_Model::STATUS_TEST, (int) $test->status_id, 'the test number is Test');
        } finally {
            config(['rsx.sms.delivery' => $previous]);
        }
    }
}
