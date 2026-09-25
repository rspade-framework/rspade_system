<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Sms\Php;

use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\Sms_Queue_Model;
use App\RSpade\Core\Models\Sms_Recipient_Model;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Sms_Drain_All_Sites_Test - the outbound SMS queue is drained for EVERY site, exactly as
 * the mail queue is (Mail_Drain_All_Sites_Test): a row queued under a second site is
 * handled by a worker declaring site 1, and keeps its own site_id.
 *
 * Runs in the default per-test transaction; the second site is created inside it.
 */
class Sms_Drain_All_Sites_Test extends Rsx_Test_Abstract
{
    private const WORKER_SITE_ID = 1;

    public static function setup()
    {
        static::__acting_as_site(self::WORKER_SITE_ID);
    }

    private static function __queue_under_second_site(): Sms_Queue_Model
    {
        $site = new Site_Model();
        $site->slug = 'sms-drain-' . uniqid();
        $site->name = 'SMS Drain Second Site';
        $site->save();

        static::__acting_as_site((int) $site->id);

        try {
            return Sms_Queue_Model::enqueue(
                (int) $site->id,
                '+1555555' . random_int(1000, 9999),
                'All sites probe',
                Sms_Queue_Model::CATEGORY_TRANSACTIONAL
            );
        } finally {
            static::__acting_as_site(self::WORKER_SITE_ID);
        }
    }

    private static function __stored(int $id): ?Sms_Queue_Model
    {
        return Sms_Queue_Model::without_site_scope(fn () => Sms_Queue_Model::find($id));
    }

    public static function test_the_drain_reaches_another_sites_message()
    {
        $row = static::__queue_under_second_site();
        $other_site_id = (int) $row->site_id;

        static::__assert_true($other_site_id !== self::WORKER_SITE_ID, 'the row belongs to another site');

        $counts = Task::internal('Sms_Queue_Service', 'send_pending_queue');

        static::__assert_greater_than(0, $counts['suppressed'], 'a worker declaring site 1 processed it');

        $stored = static::__stored((int) $row->id);
        static::__assert_equals(Sms_Queue_Model::STATUS_SUPPRESSED, (int) $stored->status_id, 'the row reached its terminal status');
        static::__assert_equals($other_site_id, (int) $stored->site_id, 'and still belongs to its own site');
    }

    public static function test_the_stranded_row_reclaim_reaches_another_site()
    {
        $row = static::__queue_under_second_site();

        Sms_Queue_Model::without_site_scope(function () use ($row) {
            Sms_Queue_Model::where('id', $row->id)->update(['status_id' => Sms_Queue_Model::STATUS_SENDING]);
        });

        $counts = Task::internal('Sms_Queue_Service', 'send_pending_queue');

        static::__assert_greater_than(0, $counts['reclaimed'], 'the other site\'s stranded row was reclaimed');
        static::__assert_equals(
            Sms_Queue_Model::STATUS_SUPPRESSED,
            (int) static::__stored((int) $row->id)->status_id,
            'and processed on the same pass'
        );
    }

    public static function test_retention_prunes_another_sites_rows()
    {
        $row = static::__queue_under_second_site();

        Sms_Queue_Model::without_site_scope(function () use ($row) {
            Sms_Queue_Model::where('id', $row->id)->update([
                'status_id' => Sms_Queue_Model::STATUS_SUPPRESSED,
                'created_at' => now()->subDays(90),
            ]);
        });

        Task::internal('Sms_Queue_Service', 'cleanup', ['days' => 30]);

        static::__assert_null(static::__stored((int) $row->id), 'a 90-day-old row of another site is gone');
    }

    public static function test_a_named_site_recipient_is_found_or_created_whatever_the_ambient_site()
    {
        $row = static::__queue_under_second_site();
        $other_site_id = (int) $row->site_id;

        $recipient = Sms_Recipient_Model::find_or_create_by_number($other_site_id, $row->to_number);

        static::__assert_equals($other_site_id, (int) $recipient->site_id, 'created under the site named, not the ambient site 1');
        static::__assert_equals(
            (int) $recipient->id,
            (int) Sms_Recipient_Model::find_or_create_by_number($other_site_id, $row->to_number)->id,
            'and found again rather than duplicated'
        );
    }
}
