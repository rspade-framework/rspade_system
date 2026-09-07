<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Sms\Php;

use App\RSpade\Core\Models\Sms_Queue_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Sms\Rsx_Sms;
use App\RSpade\Core\Sms\Sms_Queue_Service;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Sms_Queue_Test - the core SMS queue model (mirrors Email_Queue_Test). There is no
 * SMS provider, so the send task records every claimed row SUPPRESSED.
 */
class Sms_Queue_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /**
     * The class establishes its own site context rather than inheriting one from
     * whatever ran before it - the same self-containment the mail concern adopted for
     * backlog B-45. Every test below also declares its own.
     */
    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __enqueue(): Sms_Queue_Model
    {
        // Site-scoped models persist site_id from the session; set it so inserts satisfy the
        // sms_queue site FK.
        Session::set_site_id(self::SITE_ID);

        return Sms_Queue_Model::enqueue(
            self::SITE_ID,
            '+1555555' . random_int(1000, 9999),
            'Test message body',
            Sms_Queue_Model::CATEGORY_TRANSACTIONAL
        );
    }

    public static function test_enqueue_is_pending()
    {
        $sms = static::__enqueue();
        static::__assert_equals(Sms_Queue_Model::STATUS_PENDING, (int) $sms->status_id);
        static::__assert_equals('Test message body', $sms->body);
    }

    public static function test_mark_suppressed_is_distinct_and_terminal()
    {
        $sms = static::__enqueue();
        $sms->mark_suppressed('no SMS provider');

        static::__assert_equals(Sms_Queue_Model::STATUS_SUPPRESSED, (int) $sms->fresh()->status_id);
        static::__assert_not_null($sms->fresh()->sent_at, 'sent_at stamped (processed)');
        static::__assert_equals('Suppressed', $sms->status_id__label);
    }

    public static function test_claim_next_takes_a_pending_row_once()
    {
        $sms = static::__enqueue();

        while (($claimed = Sms_Queue_Model::claim_next()) !== null) {
            if ((int) $claimed->id === (int) $sms->id) {
                static::__assert_equals(
                    Sms_Queue_Model::STATUS_SENDING,
                    (int) $claimed->status_id,
                    'a claimed row is SENDING, so no second drain can take it'
                );

                $again = Sms_Queue_Model::claim_next();
                static::__assert_true(
                    $again === null || (int) $again->id !== (int) $sms->id,
                    'a claimed row is never claimed twice'
                );

                return;
            }
        }

        static::__assert_true(false, 'the enqueued row was never claimed');
    }

    public static function test_send_task_is_exclusive()
    {
        $policy = Task_Concurrency::get_policy(Sms_Queue_Service::class, 'send_pending_queue');
        static::__assert_equals('exclusive', $policy['mode'], 'the SMS send task is single-instance');
    }

    public static function test_blocklist_skips_non_transactional_only()
    {
        Session::set_site_id(self::SITE_ID);

        $number = '+1555000' . random_int(1000, 9999);
        Rsx_Sms::block_all($number);

        $marketing = Rsx_Sms::send($number, 'promo', Rsx_Sms::MARKETING);
        static::__assert_equals(Sms_Queue_Model::STATUS_BLOCKED, (int) $marketing->status_id, 'opted-out marketing is blocked');

        $transactional = static::__with_dev_whitelist(
            $number,
            fn () => Rsx_Sms::send($number, 'code 123', Rsx_Sms::TRANSACTIONAL)
        );
        static::__assert_equals(Sms_Queue_Model::STATUS_PENDING, (int) $transactional->status_id, 'transactional always delivers');
    }

    // =========================================================================
    // SESSIONLESS SITE RESOLUTION (B3.4 security fix)
    //
    // A #[Task]/CLI send has no session, so Session::get_site_id() is 0 and the send
    // facade resolves to site 0 (the Default site). Before the fix the facade invented
    // site 1 while the persisted row went to site 0, so is_blocked(1, ...) ran WHERE
    // site_id=1 AND site_id=0 (the site scope) - impossible - and opt-out was never
    // enforced from a task. These tests run with NO session (site 0).
    // =========================================================================

    public static function test_opt_out_is_enforced_with_no_session()
    {
        static::__reset_session();
        static::__assert_equals(0, Session::get_site_id(), 'no session -> site 0');

        $number = '+1555111' . random_int(1000, 9999);
        Rsx_Sms::block($number, Rsx_Sms::MARKETING);

        // Before the fix this returned false (impossible site_id=1 AND site_id=0 query).
        static::__assert_true(
            Rsx_Sms::is_blocked($number, Rsx_Sms::MARKETING),
            'opt-out is honored with no session (blocklist check and persisted row agree at site 0)'
        );
    }

    public static function test_blocked_send_is_recorded_blocked_with_no_session()
    {
        static::__reset_session();

        $number = '+1555222' . random_int(1000, 9999);
        Rsx_Sms::block_all($number);

        $marketing = Rsx_Sms::send($number, 'promo', Rsx_Sms::MARKETING);
        static::__assert_equals(
            Sms_Queue_Model::STATUS_BLOCKED,
            (int) $marketing->status_id,
            'opted-out marketing takes the enqueue_blocked path, not PENDING'
        );
    }

    public static function test_normal_send_persists_session_site_zero()
    {
        static::__reset_session();

        $number = '+1555333' . random_int(1000, 9999);

        // The test host is a .dev. hostname, so the dev-site gate is live. Whitelist
        // this number: what is under test is the ORDINARY enqueue, and the gate has
        // its own test below.
        $record = static::__with_dev_whitelist(
            $number,
            fn () => Rsx_Sms::send($number, 'hello', Rsx_Sms::NOTIFICATION)
        );

        static::__assert_equals(Sms_Queue_Model::STATUS_PENDING, (int) $record->status_id, 'unblocked send enqueues PENDING');
        static::__assert_equals(0, (int) $record->site_id, 'persisted row lands at the session site (0), not an invented 1');
    }

    /**
     * A dev host with no whitelist match and no catchall has nowhere to send. The row
     * is recorded SUPPRESSED at enqueue time rather than queued - the mail side does
     * exactly this, and the two facades stay one shape.
     */
    public static function test_dev_site_with_no_destination_is_suppressed_at_enqueue()
    {
        static::__reset_session();

        $previous = [
            config('rsx.sms.dev_site.number_whitelist'),
            config('rsx.sms.dev_site.catchall_number'),
        ];

        config([
            'rsx.sms.dev_site.number_whitelist' => '',
            'rsx.sms.dev_site.catchall_number' => '',
        ]);

        try {
            $number = '+1555444' . random_int(1000, 9999);
            $record = Rsx_Sms::send($number, 'nowhere', Rsx_Sms::TRANSACTIONAL);

            static::__assert_equals(
                Sms_Queue_Model::STATUS_SUPPRESSED,
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
                'rsx.sms.dev_site.number_whitelist' => $previous[0],
                'rsx.sms.dev_site.catchall_number' => $previous[1],
            ]);
        }
    }

    /**
     * A dev host with a catchall rewrites the destination and records the real one, so a
     * developer sees the message without the intended recipient's phone ringing. The
     * mail twin does exactly this.
     */
    public static function test_dev_site_redirects_to_the_catchall_and_records_the_original()
    {
        static::__reset_session();

        $previous = [
            config('rsx.sms.dev_site.number_whitelist'),
            config('rsx.sms.dev_site.catchall_number'),
        ];

        config([
            'rsx.sms.dev_site.number_whitelist' => '',
            'rsx.sms.dev_site.catchall_number' => '+15550001111',
        ]);

        try {
            $number = '+1555666' . random_int(1000, 9999);
            $record = Rsx_Sms::send($number, 'redirected', Rsx_Sms::TRANSACTIONAL);

            static::__assert_equals('+15550001111', $record->to_number, 'the message goes to the catchall');
            static::__assert_equals($number, $record->dev_original_to, 'and the real destination is recorded');
            static::__assert_equals(
                Sms_Queue_Model::STATUS_PENDING,
                (int) $record->status_id,
                'it is deliverable, so it queues'
            );
        } finally {
            config([
                'rsx.sms.dev_site.number_whitelist' => $previous[0],
                'rsx.sms.dev_site.catchall_number' => $previous[1],
            ]);
        }
    }

    // =========================================================================
    // STRANDED SENDING ROWS
    //
    // The mail twin, for the same reason: the drain is #[Exclusive], so a row still in
    // SENDING when it STARTS was claimed by a runner that no longer exists. Nothing else
    // can ever free it - claim_next() only looks at PENDING - so it would sit there
    // forever, neither sent nor failed.
    // =========================================================================

    public static function test_reclaim_stranded_returns_a_sending_row_to_pending()
    {
        $sms = static::__enqueue();
        $sms->status_id = Sms_Queue_Model::STATUS_SENDING;
        $sms->save();

        $reclaimed = Sms_Queue_Model::reclaim_stranded();

        static::__assert_greater_than(0, $reclaimed, 'the stranded row was counted');

        $sms = $sms->fresh();

        static::__assert_equals(
            Sms_Queue_Model::STATUS_PENDING,
            (int) $sms->status_id,
            'a stranded row goes back to PENDING so the queue can reach it again'
        );
        static::__assert_equals(
            Sms_Queue_Model::STRANDED_RECLAIM_NOTE,
            $sms->last_error,
            'and says why it moved'
        );
    }

    /**
     * The reclaim is not a retry - nothing about the message was rejected, a process
     * died - so it never spends an attempt.
     */
    public static function test_reclaim_stranded_does_not_count_an_attempt()
    {
        $sms = static::__enqueue();
        $sms->status_id = Sms_Queue_Model::STATUS_SENDING;
        $sms->attempt_count = 1;
        $sms->save();

        Sms_Queue_Model::reclaim_stranded();

        static::__assert_equals(1, (int) $sms->fresh()->attempt_count, 'the attempt count is untouched');
    }

    public static function test_reclaim_stranded_leaves_every_other_status_alone()
    {
        $pending = static::__enqueue();

        $suppressed = static::__enqueue();
        $suppressed->mark_suppressed('no SMS provider');

        Sms_Queue_Model::reclaim_stranded();

        static::__assert_equals(
            Sms_Queue_Model::STATUS_PENDING,
            (int) $pending->fresh()->status_id,
            'PENDING is untouched'
        );
        static::__assert_equals(
            Sms_Queue_Model::STATUS_SUPPRESSED,
            (int) $suppressed->fresh()->status_id,
            'and a terminal row stays terminal'
        );
    }

    /**
     * The drain reclaims as its FIRST act, then processes the queue - so a rescued
     * message reaches a terminal status on the same pass.
     */
    public static function test_the_drain_reclaims_a_stranded_row_and_then_processes_it()
    {
        $sms = static::__enqueue();
        $sms->status_id = Sms_Queue_Model::STATUS_SENDING;
        $sms->save();

        $counts = Task::internal('Sms_Queue_Service', 'send_pending_queue');

        static::__assert_greater_than(0, $counts['reclaimed'], 'the drain reports the reclaim');
        static::__assert_equals(
            Sms_Queue_Model::STATUS_SUPPRESSED,
            (int) $sms->fresh()->status_id,
            'and the rescued message reaches its terminal status - there is no SMS provider, '
                . 'so every claimed row is recorded SUPPRESSED saying so'
        );
    }

    public static function test_a_clean_queue_reclaims_nothing()
    {
        static::__enqueue();

        $counts = Task::internal('Sms_Queue_Service', 'send_pending_queue');

        static::__assert_equals(0, $counts['reclaimed'], 'nothing was stranded, so nothing is narrated');
    }

    // =========================================================================
    // RETENTION
    // =========================================================================

    public static function test_cleanup_deletes_terminal_rows_past_the_window()
    {
        $old = static::__enqueue();
        $old->mark_suppressed('no SMS provider');
        $old->created_at = now()->subDays(120);
        $old->save();

        $recent = static::__enqueue();
        $recent->mark_suppressed('no SMS provider');

        $result = Task::internal('Sms_Queue_Service', 'cleanup', ['days' => 30]);

        static::__assert_greater_than(0, $result['deleted'], 'something past the window was deleted');
        static::__assert_null(Sms_Queue_Model::find($old->id), 'the old terminal row is gone');
        static::__assert_not_null(Sms_Queue_Model::find($recent->id), 'a recent row is kept');
    }

    /**
     * A row nobody has decided about is not old enough to drop, whatever its created_at
     * says - deleting it would destroy a message still waiting to go.
     */
    public static function test_cleanup_never_deletes_an_unfinished_row()
    {
        $old_pending = static::__enqueue();
        $old_pending->created_at = now()->subDays(120);
        $old_pending->save();

        Sms_Queue_Model::cleanup_old(30);

        static::__assert_not_null(
            Sms_Queue_Model::find($old_pending->id),
            'an ancient PENDING row survives retention - it has not been decided'
        );
    }

    /**
     * Run something with this number whitelisted on the dev-site gate.
     */
    private static function __with_dev_whitelist(string $number, callable $work)
    {
        $previous = config('rsx.sms.dev_site.number_whitelist');

        config(['rsx.sms.dev_site.number_whitelist' => $number]);

        try {
            return $work();
        } finally {
            config(['rsx.sms.dev_site.number_whitelist' => $previous]);
        }
    }
}
