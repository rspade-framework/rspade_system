<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use App\RSpade\Core\Mail\Mail_Queue_Service;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Email_Queue_Test - the queue ROW's own state machine.
 *
 * Scope is deliberately narrow: what one row does when the drain drives it, with no
 * email class, no builder and no transport in the picture. The enqueue path is
 * Rsx_Email_Enqueue_Test's; the loop that calls these methods is
 * Mail_Queue_Runner_Test's; building a message is Rsx_Mail_Builder_Test's.
 *
 * SITE CONTEXT IS ESTABLISHED, NEVER INHERITED (backlog B-45). Every test opens by
 * declaring the site it operates in. Before that, this class read whatever site_id an
 * earlier-running class happened to leave in the CLI session, which meant running it in
 * isolation - or a shift in class order - silently changed which rows it saw.
 */
class Email_Queue_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /**
     * The class-level guarantee behind B-45. Every test also declares its own site, so
     * neither the class nor any single method depends on ambient state.
     */
    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __enqueue(): Email_Queue_Model
    {
        static::__acting_as_site(self::SITE_ID);

        return Email_Queue_Model::enqueue([
            'site_id' => self::SITE_ID,
            'to_address' => 'queue_test_' . uniqid() . '@example.com',
            'subject' => 'Subject',
            'email_class' => 'Rsx_Mail_Test_Email',
            'template_data' => ['x' => 1],
            'category_id' => Email_Queue_Model::CATEGORY_TRANSACTIONAL,
        ]);
    }

    public static function test_enqueue_is_pending()
    {
        $email = static::__enqueue();
        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $email->status_id);
    }

    public static function test_mark_suppressed_is_distinct_and_terminal()
    {
        $email = static::__enqueue();
        $email->mark_suppressed('recorded, not delivered');

        static::__assert_equals(Email_Queue_Model::STATUS_SUPPRESSED, (int) $email->fresh()->status_id, 'suppressed status set');
        static::__assert_not_null($email->fresh()->sent_at, 'sent_at stamped (processed)');
        static::__assert_equals('Suppressed', $email->status_id__label, 'suppressed has its own label');
    }

    public static function test_claim_next_takes_a_pending_row_once()
    {
        $email = static::__enqueue();

        // Drain everything else first so the row under test is the one we get back.
        while (($claimed = Email_Queue_Model::claim_next()) !== null) {
            if ((int) $claimed->id === (int) $email->id) {
                static::__assert_equals(
                    Email_Queue_Model::STATUS_SENDING,
                    (int) $claimed->status_id,
                    'a claimed row is SENDING, so no second drain can take it'
                );

                // Claiming moved it out of PENDING - it must not come back.
                $again = Email_Queue_Model::claim_next();
                static::__assert_true(
                    $again === null || (int) $again->id !== (int) $email->id,
                    'a claimed row is never claimed twice'
                );

                return;
            }
        }

        static::__assert_true(false, 'the enqueued row was never claimed');
    }

    public static function test_claim_next_skips_a_future_next_attempt_at()
    {
        $future = static::__enqueue();
        $future->next_attempt_at = now()->addMinutes(10);
        $future->save();

        while (($claimed = Email_Queue_Model::claim_next()) !== null) {
            static::__assert_true(
                (int) $claimed->id !== (int) $future->id,
                'a row waiting on next_attempt_at is not claimable yet'
            );
        }
    }

    public static function test_server_error_retries_then_fails_at_the_cap()
    {
        $email = static::__enqueue();
        $attempts = (int) config('rsx.mail.retry.attempts', 3);

        for ($i = 1; $i < $attempts; $i++) {
            $email->mark_server_error('550 mailbox unavailable');
            $email = $email->fresh();

            static::__assert_equals(
                Email_Queue_Model::STATUS_PENDING,
                (int) $email->status_id,
                "attempt {$i} of {$attempts} goes back to PENDING for a later retry"
            );
            static::__assert_not_null($email->next_attempt_at, 'a retry is scheduled');
        }

        $email->mark_server_error('550 mailbox unavailable');
        $email = $email->fresh();

        static::__assert_equals(Email_Queue_Model::STATUS_FAILED, (int) $email->status_id, 'FAILED at the attempt cap');
        static::__assert_equals($attempts, (int) $email->attempt_count, 'every attempt was counted');
        static::__assert_equals('550 mailbox unavailable', $email->last_error, "the server's own reply is recorded");
    }

    public static function test_release_to_pending_does_not_count_the_attempt()
    {
        $email = static::__enqueue();
        $email->status_id = Email_Queue_Model::STATUS_SENDING;
        $email->save();

        $email->release_to_pending('mail host unreachable');
        $email = $email->fresh();

        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $email->status_id, 'released back to PENDING');
        static::__assert_equals(
            0,
            (int) $email->attempt_count,
            'an outage does not burn this message\'s retry budget'
        );
    }

    public static function test_send_task_is_exclusive()
    {
        $policy = Task_Concurrency::get_policy(Mail_Queue_Service::class, 'send_pending_queue');
        static::__assert_equals('exclusive', $policy['mode'], 'the send task is single-instance');
    }

    // =========================================================================
    // STRANDED SENDING ROWS
    //
    // A row in SENDING when a drain STARTS was claimed by a runner that no longer
    // exists - #[Exclusive] guarantees no second runner, so there is nobody else it
    // could belong to. Nothing else can ever free it: claim_next() only looks at
    // PENDING, so the message sits there forever, invisible, neither sent nor failed.
    // That is what stranded two real rows on the development box while this epic was
    // being built.
    // =========================================================================

    public static function test_reclaim_stranded_returns_a_sending_row_to_pending()
    {
        $email = static::__enqueue();
        $email->status_id = Email_Queue_Model::STATUS_SENDING;
        $email->save();

        $reclaimed = Email_Queue_Model::reclaim_stranded();

        static::__assert_greater_than(0, $reclaimed, 'the stranded row was counted');

        $email = $email->fresh();

        static::__assert_equals(
            Email_Queue_Model::STATUS_PENDING,
            (int) $email->status_id,
            'a stranded row goes back to PENDING so the queue can reach it again'
        );
        static::__assert_equals(
            Email_Queue_Model::STRANDED_RECLAIM_NOTE,
            $email->last_error,
            'and says why it moved'
        );
    }

    /**
     * The reclaim is not a retry. Nothing about the message was rejected - a process
     * died - so counting an attempt would spend a message's budget on an outage that
     * had nothing to do with it, exactly as release_to_pending() refuses to.
     */
    public static function test_reclaim_stranded_does_not_count_an_attempt()
    {
        $email = static::__enqueue();
        $email->status_id = Email_Queue_Model::STATUS_SENDING;
        $email->attempt_count = 1;
        $email->save();

        Email_Queue_Model::reclaim_stranded();

        static::__assert_equals(1, (int) $email->fresh()->attempt_count, 'the attempt count is untouched');
    }

    /**
     * There is no age threshold and there is no timeout. Exclusivity is the proof that
     * a SENDING row is abandoned, so a row claimed one millisecond ago by a runner that
     * is now gone is reclaimed exactly like one claimed last week.
     */
    public static function test_reclaim_stranded_has_no_age_threshold()
    {
        $email = static::__enqueue();
        $email->claim();

        static::__assert_equals(
            Email_Queue_Model::STATUS_SENDING,
            (int) $email->fresh()->status_id,
            'the row was claimed just now'
        );

        Email_Queue_Model::reclaim_stranded();

        static::__assert_equals(
            Email_Queue_Model::STATUS_PENDING,
            (int) $email->fresh()->status_id,
            'a freshly-claimed row is reclaimed too - age is not the signal, exclusivity is'
        );
    }

    public static function test_reclaim_stranded_leaves_every_other_status_alone()
    {
        $pending = static::__enqueue();

        $sent = static::__enqueue();
        $sent->mark_sent('<probe@example.com>', null);

        $failed = static::__enqueue();
        $failed->mark_failed('a code bug');

        Email_Queue_Model::reclaim_stranded();

        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $pending->fresh()->status_id, 'PENDING is untouched');
        static::__assert_equals(Email_Queue_Model::STATUS_SENT, (int) $sent->fresh()->status_id, 'SENT is untouched');
        static::__assert_equals(Email_Queue_Model::STATUS_FAILED, (int) $failed->fresh()->status_id, 'FAILED is untouched');
        static::__assert_null($pending->fresh()->last_error, 'and no note was written onto them');
    }

    public static function test_reclaim_stranded_reports_zero_when_nothing_is_stranded()
    {
        static::__acting_as_site(self::SITE_ID);

        Email_Queue_Model::reclaim_stranded();

        static::__assert_equals(
            0,
            Email_Queue_Model::reclaim_stranded(),
            'a clean queue reclaims nothing, so the drain narrates nothing'
        );
    }

    // =========================================================================
    // RETENTION
    // =========================================================================

    public static function test_cleanup_old_deletes_terminal_rows_past_the_window()
    {
        $old_sent = static::__enqueue();
        $old_sent->mark_sent('<old@example.com>', null);
        $old_sent->created_at = now()->subDays(90);
        $old_sent->save();

        $recent_sent = static::__enqueue();
        $recent_sent->mark_sent('<recent@example.com>', null);

        $deleted = Email_Queue_Model::cleanup_old(30);

        static::__assert_greater_than(0, $deleted, 'something past the window was deleted');
        static::__assert_null(Email_Queue_Model::find($old_sent->id), 'the old terminal row is gone');
        static::__assert_not_null(Email_Queue_Model::find($recent_sent->id), 'a recent row is kept');
    }

    /**
     * A row that has not finished is not old enough to drop, whatever its created_at
     * says - deleting it would destroy a message nobody has decided about yet.
     */
    public static function test_cleanup_old_never_deletes_an_unfinished_row()
    {
        $old_pending = static::__enqueue();
        $old_pending->created_at = now()->subDays(90);
        $old_pending->save();

        Email_Queue_Model::cleanup_old(30);

        static::__assert_not_null(
            Email_Queue_Model::find($old_pending->id),
            'an ancient PENDING row survives retention - it has not been decided'
        );
    }
}
