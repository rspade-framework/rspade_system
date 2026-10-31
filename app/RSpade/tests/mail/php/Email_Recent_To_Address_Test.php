<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Mail\Rsx_Mail_Test_Email;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Mail::recent_to_address(): the newest messages to one address on the current site, by
 * the ORIGINAL recipient, and each of its two queries reads an index of its own, so a rarely
 * mailed address does not scan the site's queue.
 */
class Email_Recent_To_Address_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        static::__acting_as_site(1);
    }

    /** A queued message to $address, dated $minutes_ago, optionally rerouted from $original. */
    private static function __message(string $address, int $minutes_ago, ?string $original = null): int
    {
        $id = (int) (new Rsx_Mail_Test_Email())->to($address)->send()->id;

        DB::table('_email_queue')->where('id', $id)->update([
            'created_at' => now()->subMinutes($minutes_ago)->format('Y-m-d H:i:s.v'),
            'to_address' => $address,
            'dev_original_to' => $original,
        ]);

        return $id;
    }

    /** MAIL-RECENT-01: newest first across both readings, cut to the limit. */
    public static function test_it_answers_the_newest_by_original_recipient()
    {
        $address = 'recent_' . uniqid() . '@example.com';

        $oldest = static::__message($address, 30);
        $rerouted = static::__message('catchall@example.com', 20, $address);
        $newest = static::__message($address, 10);

        $ids = fn (array $rows) => array_map(fn ($row) => (int) $row->id, $rows);

        static::__assert_equals([$newest, $rerouted, $oldest], $ids(Rsx_Mail::recent_to_address($address, 10)));
        static::__assert_equals([$newest, $rerouted], $ids(Rsx_Mail::recent_to_address($address, 2)), 'the limit');
        static::__assert_equals([$newest, $rerouted, $oldest], $ids(Rsx_Mail::recent_to_address('  ' . $address . ' ', 10)), 'trimmed');
    }

    /** MAIL-RECENT-02: a rerouted message belongs to who it was for, not the catchall. */
    public static function test_a_rerouted_message_does_not_count_for_the_catchall()
    {
        $catchall = 'catchall_' . uniqid() . '@example.com';
        static::__message($catchall, 5, 'someone_' . uniqid() . '@example.com');

        static::__assert_count(0, Rsx_Mail::recent_to_address($catchall, 10));
    }

    /** MAIL-RECENT-03: another site's messages are not this site's history. */
    public static function test_another_sites_messages_are_not_listed()
    {
        $address = 'tenant_' . uniqid() . '@example.com';
        $id = static::__message($address, 5);
        DB::table('_email_queue')->where('id', $id)->update(['site_id' => 0]);

        static::__assert_count(0, Rsx_Mail::recent_to_address($address, 10));
    }

    /** MAIL-RECENT-04: each reading is served by its own index. */
    public static function test_each_query_reads_its_index()
    {
        $indexes = array_column(array_map(fn ($row) => (array) $row, DB::select('SHOW INDEX FROM _email_queue')), 'Key_name');

        static::__assert_true(in_array('idx_email_queue_recipient_history', $indexes, true));
        static::__assert_true(in_array('idx_email_queue_original_recipient_history', $indexes, true));

        foreach (['to_address' => 'idx_email_queue_recipient_history', 'dev_original_to' => 'idx_email_queue_original_recipient_history'] as $column => $index) {
            $plan = (array) DB::select(
                "EXPLAIN SELECT id FROM _email_queue WHERE site_id = 1 AND {$column} = 'a@example.com' ORDER BY created_at DESC, id DESC LIMIT 10"
            )[0];

            static::__assert_equals($index, $plan['key'], "{$column} is read through its index");
        }
    }
}
