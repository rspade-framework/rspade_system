<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use App\RSpade\Core\Mail\Mail_Transport_Unavailable_Exception;
use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Email_Recipient_Model;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Mail\Php\Mail_Notification_Fixture_Email;
use App\RSpade\Tests\Mail\Php\Mail_Transport_Stub;

/**
 * Mail_Queue_Runner_Test - the drain's state machine, driven by a stub transport.
 *
 * THE TWO FAILURE CLASSES ARE NOT THE SAME FAILURE, and conflating them is how a queue
 * quietly destroys mail. These tests exist to hold that line:
 *
 *   The server answered with an error FOR THIS MESSAGE. The connection is fine, this
 *   message is the problem, so it gets its own clock - attempt counted, next_attempt_at
 *   pushed out, FAILED at the cap with the server's own words recorded.
 *
 *   The transport could not be reached. Nothing about the message was rejected, so the
 *   attempt is NOT counted; the drain reconnects and retries the same message once, and
 *   if that fails too it dies loudly leaving every remaining row PENDING. A mail host
 *   being down is an outage to see, not a budget for messages to burn.
 *
 * A build error is neither: no retry can fix a code bug, so the row is FAILED at once.
 *
 * The drain is driven with Task::internal(), which runs it synchronously in THIS process
 * and re-throws whatever it throws. The test runner suppresses the automatic drain kick
 * (config('database.default') === 'test'), so nothing races these assertions.
 */
class Mail_Queue_Runner_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);
    }

    /**
     * Queue one fixture message and return its row.
     *
     * The dev-host gate is whitelisted past - the test host is a `.dev.` hostname, and
     * the gate is Rsx_Email_Enqueue_Test's subject, not this class's.
     */
    private static function __queue(string $note = 'Runner probe'): Email_Queue_Model
    {
        static::__acting_as_site(self::SITE_ID);

        $previous = config('rsx.mail.dev_site.domain_whitelist');
        config(['rsx.mail.dev_site.domain_whitelist' => 'example.com']);

        try {
            return (new Mail_Notification_Fixture_Email($note))
                ->to('runner_' . uniqid() . '@example.com')
                ->send();
        } finally {
            config(['rsx.mail.dev_site.domain_whitelist' => $previous]);
        }
    }

    /**
     * Run the drain with $stub installed, and hand back what the task returned.
     *
     * The override is ALWAYS cleared, including when the drain throws - a transport stub
     * surviving into another test class would make its failures unattributable.
     */
    private static function __drain(Mail_Transport_Stub $stub): array
    {
        Rsx_Mail_Transport::$override_for_tests = $stub;

        try {
            return Task::internal('Mail_Queue_Service', 'send_pending_queue');
        } finally {
            Rsx_Mail_Transport::$override_for_tests = null;
        }
    }

    /**
     * Drain expecting the transport to be declared unavailable, and return the exception.
     */
    private static function __drain_expecting_outage(Mail_Transport_Stub $stub): \Throwable
    {
        Rsx_Mail_Transport::$override_for_tests = $stub;

        try {
            return static::__assert_throws(
                Mail_Transport_Unavailable_Exception::class,
                fn () => Task::internal('Mail_Queue_Service', 'send_pending_queue')
            );
        } finally {
            Rsx_Mail_Transport::$override_for_tests = null;
        }
    }

    private static function __recipient(Email_Queue_Model $row): Email_Recipient_Model
    {
        return Email_Recipient_Model::find_or_create_by_email(
            (int) $row->site_id,
            $row->dev_original_to ?: $row->to_address
        );
    }

    // =========================================================================
    // THE HAPPY PATH
    // =========================================================================

    public static function test_an_accepted_message_is_sent_and_fully_recorded()
    {
        $row = static::__queue('Accepted probe');

        $stub = new Mail_Transport_Stub(Mail_Transport_Stub::MODE_ACCEPT);
        $counts = static::__drain($stub);

        static::__assert_greater_than(0, $counts['sent'], 'the drain reports what it sent');
        static::__assert_true(
            in_array('Accepted probe', $stub->sent_subjects, true),
            'the transport was handed this message'
        );

        $row = $row->fresh();

        static::__assert_equals(Email_Queue_Model::STATUS_SENT, (int) $row->status_id, 'the row is SENT');
        static::__assert_equals(1, (int) $row->attempt_count, 'a first send is an attempt, and it is counted');
        static::__assert_not_empty($row->message_id_header, 'the Message-ID is recorded so a bounce can be traced back');
        static::__assert_not_null($row->sent_at, 'and when it went');
        static::__assert_null($row->last_error, 'nothing went wrong');
        static::__assert_equals('smtp', $row->transport, 'the configured driver is recorded on the row');
    }

    public static function test_a_sent_message_counts_against_its_recipient()
    {
        $row = static::__queue();

        $before = (int) static::__recipient($row)->total_sent;

        static::__drain(new Mail_Transport_Stub(Mail_Transport_Stub::MODE_ACCEPT));

        static::__assert_equals(
            $before + 1,
            (int) static::__recipient($row->fresh())->total_sent,
            'the per-recipient history counts the delivery'
        );
    }

    // =========================================================================
    // SERVER ERROR: the message is the problem
    // =========================================================================

    /**
     * Three attempts, three minutes apart, then FAILED with the server's own reply. The
     * drain does not loop on the row: mark_server_error() pushes next_attempt_at out, so
     * each attempt needs its own pass - which is exactly what the once-a-minute sweeper
     * provides in production.
     */
    public static function test_a_server_error_retries_to_the_cap_then_fails_with_the_reply()
    {
        $row = static::__queue('Rejected probe');

        $attempts = (int) config('rsx.mail.retry.attempts', 3);
        $delay_minutes = (int) config('rsx.mail.retry.delay_minutes', 3);

        for ($attempt = 1; $attempt < $attempts; $attempt++) {
            $counts = static::__drain(new Mail_Transport_Stub(Mail_Transport_Stub::MODE_SERVER_ERROR));

            static::__assert_greater_than(0, $counts['server_errors'], "attempt {$attempt} was a server error");

            $row = $row->fresh();

            static::__assert_equals(
                Email_Queue_Model::STATUS_PENDING,
                (int) $row->status_id,
                "attempt {$attempt} of {$attempts} goes back to PENDING for a later retry"
            );
            static::__assert_equals($attempt, (int) $row->attempt_count, 'and the attempt is counted');
            static::__assert_greater_than(
                now()->addMinutes($delay_minutes - 1)->toDateTimeString(),
                (string) $row->next_attempt_at,
                "the retry is held off by about {$delay_minutes} minutes"
            );

            // The sweeper would arrive after the delay; this stands in for the wait, so
            // the test never sleeps and never depends on the clock advancing.
            $row->next_attempt_at = null;
            $row->save();
        }

        static::__drain(new Mail_Transport_Stub(Mail_Transport_Stub::MODE_SERVER_ERROR));

        $row = $row->fresh();

        static::__assert_equals(Email_Queue_Model::STATUS_FAILED, (int) $row->status_id, 'FAILED at the attempt cap');
        static::__assert_equals($attempts, (int) $row->attempt_count, 'every attempt was counted');
        static::__assert_contains('550', (string) $row->last_error, "the server's own reply is what is recorded");
        static::__assert_null($row->next_attempt_at, 'and nothing more is scheduled');
    }

    public static function test_a_server_error_counts_against_its_recipient()
    {
        $row = static::__queue();

        $before = (int) static::__recipient($row)->total_failed;

        static::__drain(new Mail_Transport_Stub(Mail_Transport_Stub::MODE_SERVER_ERROR));

        static::__assert_equals(
            $before + 1,
            (int) static::__recipient($row->fresh())->total_failed,
            'the per-recipient history counts the rejection'
        );
    }

    // =========================================================================
    // TRANSPORT FAILURE: the connection is the problem
    // =========================================================================

    /**
     * The mail host blinked. The drain rebuilds the transport and retries THE SAME
     * message - not whatever happens to be at the head of the queue - and the message
     * goes out on the second try having spent one attempt, not two.
     */
    public static function test_one_reconnect_retries_the_same_message_and_succeeds()
    {
        $row = static::__queue('Reconnect probe');

        $stub = new Mail_Transport_Stub(Mail_Transport_Stub::MODE_UNREACHABLE_ONCE);
        static::__drain($stub);

        static::__assert_equals(2, $stub->send_count, 'the message was offered twice - once, then once after reconnecting');
        static::__assert_equals(
            ['Reconnect probe', 'Reconnect probe'],
            $stub->sent_subjects,
            'and it was the SAME message both times'
        );

        $row = $row->fresh();

        static::__assert_equals(Email_Queue_Model::STATUS_SENT, (int) $row->status_id, 'it went on the retry');
        static::__assert_equals(1, (int) $row->attempt_count, 'the outage did not burn an attempt');
    }

    /**
     * Still unreachable after reconnecting: the runner THROWS. The task ends failed and
     * visible, the queue is intact and PENDING, and the next kick or the next minute's
     * sweep tries again - forever, which is what an outage deserves.
     */
    public static function test_a_persistent_outage_throws_and_leaves_the_row_pending()
    {
        $row = static::__queue();

        $stub = new Mail_Transport_Stub(Mail_Transport_Stub::MODE_UNREACHABLE);
        $exception = static::__drain_expecting_outage($stub);

        static::__assert_equals(2, $stub->send_count, 'exactly one reconnect was attempted, not a retry loop');
        static::__assert_contains(
            'Mail transport',
            $exception->getMessage(),
            'the exception names the transport as the thing that is unavailable'
        );

        $row = $row->fresh();

        static::__assert_equals(
            Email_Queue_Model::STATUS_PENDING,
            (int) $row->status_id,
            'the message was handed back, not lost and not failed'
        );
        static::__assert_equals(
            0,
            (int) $row->attempt_count,
            'and an outage never burns a message\'s retry budget'
        );
    }

    /**
     * An outage stops the WHOLE drain. Everything still queued keeps its budget too -
     * the alternative is a host that is down for five minutes failing every message in
     * the queue three times.
     */
    public static function test_an_outage_leaves_the_rest_of_the_queue_untouched()
    {
        $first = static::__queue('First');
        $second = static::__queue('Second');

        static::__drain_expecting_outage(new Mail_Transport_Stub(Mail_Transport_Stub::MODE_UNREACHABLE));

        foreach ([$first, $second] as $row) {
            $row = $row->fresh();

            static::__assert_equals(
                Email_Queue_Model::STATUS_PENDING,
                (int) $row->status_id,
                'every row is still queued after the drain died'
            );
            static::__assert_equals(0, (int) $row->attempt_count, 'with its attempts unspent');
        }
    }

    // =========================================================================
    // BUILD FAILURE: a code bug, and no retry can fix it
    // =========================================================================

    public static function test_a_row_whose_template_is_missing_fails_immediately()
    {
        static::__acting_as_site(self::SITE_ID);

        $row = Email_Queue_Model::enqueue([
            'site_id' => self::SITE_ID,
            'to_address' => 'broken_' . uniqid() . '@example.com',
            'subject' => 'Broken',
            // No blade in this build declares this @rsx_id, so rendering throws.
            'email_class' => 'No_Such_Email_Template_Exists',
            'template_data' => [],
            'category_id' => Email_Queue_Model::CATEGORY_TRANSACTIONAL,
        ]);

        $counts = static::__drain(new Mail_Transport_Stub(Mail_Transport_Stub::MODE_ACCEPT));

        static::__assert_greater_than(0, $counts['failed'], 'the drain reports the build failure');

        $row = $row->fresh();

        static::__assert_equals(
            Email_Queue_Model::STATUS_FAILED,
            (int) $row->status_id,
            'a code bug is terminal at once - no retry can fix it'
        );
        static::__assert_null($row->next_attempt_at, 'so nothing is scheduled');
        static::__assert_not_empty($row->last_error, 'and the reason is recorded');
    }

    /**
     * One broken row does not stop the queue. The catch is per message, so the drain
     * carries on and the good message still goes out.
     */
    public static function test_a_build_failure_does_not_stop_the_drain()
    {
        static::__acting_as_site(self::SITE_ID);

        Email_Queue_Model::enqueue([
            'site_id' => self::SITE_ID,
            'to_address' => 'broken_' . uniqid() . '@example.com',
            'subject' => 'Broken',
            'email_class' => 'No_Such_Email_Template_Exists',
            'template_data' => [],
            'category_id' => Email_Queue_Model::CATEGORY_TRANSACTIONAL,
        ]);

        $good = static::__queue('Survivor');

        static::__drain(new Mail_Transport_Stub(Mail_Transport_Stub::MODE_ACCEPT));

        static::__assert_equals(
            Email_Queue_Model::STATUS_SENT,
            (int) $good->fresh()->status_id,
            'the healthy message was still delivered'
        );
    }

    // =========================================================================
    // SUPPRESSED DELIVERY
    // =========================================================================

    /**
     * Suppressed still BUILDS. The rendered bodies are recorded, so a suppressed send is
     * reviewable - which is the whole reason to record a row nobody received.
     */
    public static function test_suppressed_delivery_records_the_bodies_and_sends_nothing()
    {
        $row = static::__queue('Suppressed probe');

        $previous = config('rsx.mail.delivery');
        config(['rsx.mail.delivery' => 'suppressed']);

        try {
            $stub = new Mail_Transport_Stub(Mail_Transport_Stub::MODE_ACCEPT);
            $counts = static::__drain($stub);

            static::__assert_greater_than(0, $counts['suppressed'], 'the drain reports what it suppressed');
            static::__assert_equals(0, $stub->send_count, 'the transport was never offered the message');
        } finally {
            config(['rsx.mail.delivery' => $previous]);
        }

        $row = $row->fresh();

        static::__assert_equals(Email_Queue_Model::STATUS_SUPPRESSED, (int) $row->status_id, 'the row is SUPPRESSED');
        static::__assert_equals('delivery mode is suppressed', $row->last_error, 'and says why');
        static::__assert_not_empty($row->rendered_html, 'the html body was still rendered and recorded');
        static::__assert_not_empty($row->rendered_text, 'and the text body');
        static::__assert_contains('Suppressed probe', $row->rendered_html, 'it is this message');
    }

    // =========================================================================
    // STRANDED ROWS
    // =========================================================================

    /**
     * The drain is #[Exclusive], so when it starts there is no other runner and a row in
     * SENDING was claimed by one that died mid-message. Nothing else can ever free it -
     * claim_next() only looks at PENDING - so the reclaim is the FIRST thing the drain
     * does, and the message goes out on that same pass.
     */
    public static function test_a_stranded_sending_row_is_reclaimed_and_then_sent()
    {
        $row = static::__queue('Stranded probe');
        $row->claim();

        static::__assert_equals(
            Email_Queue_Model::STATUS_SENDING,
            (int) $row->fresh()->status_id,
            'the row is stranded, as a killed runner would have left it'
        );

        $counts = static::__drain(new Mail_Transport_Stub(Mail_Transport_Stub::MODE_ACCEPT));

        static::__assert_greater_than(0, $counts['reclaimed'], 'the drain reports the reclaim');
        static::__assert_equals(
            Email_Queue_Model::STATUS_SENT,
            (int) $row->fresh()->status_id,
            'and the rescued message goes out on the same pass'
        );
    }

    public static function test_a_clean_queue_reclaims_nothing()
    {
        static::__queue();

        $counts = static::__drain(new Mail_Transport_Stub(Mail_Transport_Stub::MODE_ACCEPT));

        static::__assert_equals(0, $counts['reclaimed'], 'nothing was stranded, so nothing is narrated');
    }

    // =========================================================================
    // RETENTION
    // =========================================================================

    public static function test_cleanup_deletes_old_rows_and_prunes_the_catcher_maildir()
    {
        static::__acting_as_site(self::SITE_ID);

        $old = static::__queue('Ancient');
        $old->mark_sent('<ancient@example.com>', null);
        $old->created_at = now()->subDays(120);
        $old->save();

        $recent = static::__queue('Recent');
        $recent->mark_sent('<recent@example.com>', null);

        $maildir = storage_path('rsx-tmp/mail_catcher_probe_' . uniqid());
        ensure_directory($maildir . '/new');
        ensure_directory($maildir . '/cur');

        $stale_file = $maildir . '/new/stale.eml';
        $fresh_file = $maildir . '/new/fresh.eml';
        file_put_contents_safe($stale_file, 'stale captured message');
        file_put_contents_safe($fresh_file, 'fresh captured message');
        touch($stale_file, time() - (120 * 86400));

        $previous_maildir = config('rsx.mail.catcher_maildir');
        config(['rsx.mail.catcher_maildir' => $maildir]);

        try {
            $result = Task::internal('Mail_Queue_Service', 'cleanup', ['days' => 30]);

            static::__assert_greater_than(0, $result['deleted'], 'something past the window was deleted');
            static::__assert_equals(1, $result['catcher_pruned'], 'exactly the stale captured message was pruned');
        } finally {
            config(['rsx.mail.catcher_maildir' => $previous_maildir]);
        }

        static::__assert_null(Email_Queue_Model::find($old->id), 'the old row is gone');
        static::__assert_not_null(Email_Queue_Model::find($recent->id), 'a recent row is kept');
        static::__assert_false(is_file($stale_file), 'the stale capture was pruned');
        static::__assert_true(is_file($fresh_file), 'a recent capture is kept');

        @unlink($fresh_file);
        @rmdir($maildir . '/new');
        @rmdir($maildir . '/cur');
        @rmdir($maildir);
    }

    /**
     * A production host has no catcher. An absent directory is nothing to do, not an
     * error - the daily cleanup must not start failing on every box that never had one.
     */
    public static function test_cleanup_tolerates_an_absent_catcher_maildir()
    {
        static::__acting_as_site(self::SITE_ID);

        $previous_maildir = config('rsx.mail.catcher_maildir');
        config(['rsx.mail.catcher_maildir' => storage_path('rsx-tmp/no_such_catcher_' . uniqid())]);

        try {
            $result = Task::internal('Mail_Queue_Service', 'cleanup', ['days' => 30]);

            static::__assert_equals(0, $result['catcher_pruned'], 'no catcher means nothing to prune');
        } finally {
            config(['rsx.mail.catcher_maildir' => $previous_maildir]);
        }
    }
}
