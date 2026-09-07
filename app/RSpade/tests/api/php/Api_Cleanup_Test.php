<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Api\Api_Cleanup_Service;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Api_Cleanup_Service::cleanup_request_log - the daily retention prune.
 *
 * Rows older than config('rsx.api.log_retention_days') are chunk-deleted; newer rows
 * survive. The task is invoked directly with an immediate Task_Instance (info() buffers,
 * heartbeat() no-ops off a worker). Rows are tagged with a distinctive ip so assertions
 * target exactly the inserted rows regardless of anything else in the table. Default
 * transaction isolation - the task DELETE is visible on the same connection and rolls
 * back afterward. A config override is restored in the same test so it never bleeds.
 */
class Api_Cleanup_Test extends Rsx_Test_Abstract
{
    private const MARK_IP = '198.51.100.77';

    private static function __insert_log(int $age_days): void
    {
        $when = now()->subDays($age_days);
        DB::table('_api_request_log')->insert([
            'api_key_id'  => null,
            'user_id'     => null,
            'site_id'     => null,
            'verb'        => 'GET',
            'path'        => '/api/v1/contacts',
            'handler'     => null,
            'status'      => 200,
            'duration_ms' => 1,
            'ip'          => self::MARK_IP,
            'created_at'  => $when,
            'updated_at'  => $when,
        ]);
    }

    private static function __marked_count(): int
    {
        return DB::table('_api_request_log')->where('ip', self::MARK_IP)->count();
    }

    private static function __task(): Task_Instance
    {
        return new Task_Instance(Api_Cleanup_Service::class, 'cleanup_request_log');
    }

    public static function test_deletes_old_keeps_new_at_default_retention()
    {
        // Default retention is 30 days.
        static::__insert_log(40); // beyond cutoff
        static::__insert_log(40); // beyond cutoff
        static::__insert_log(1);  // within cutoff
        static::__assert_equals(3, static::__marked_count(), 'three marked rows inserted');

        $result = Api_Cleanup_Service::cleanup_request_log(static::__task());

        static::__assert_greater_than(1, $result['deleted'], 'both old rows pruned');
        static::__assert_equals(30, $result['retention_days']);
        static::__assert_equals(1, static::__marked_count(), 'only the recent row survives');
    }

    public static function test_respects_configured_retention_window()
    {
        $original = config('rsx.api.log_retention_days');

        try {
            config(['rsx.api.log_retention_days' => 7]);

            static::__insert_log(10); // beyond the 7-day cutoff
            static::__insert_log(1);  // within it
            static::__assert_equals(2, static::__marked_count());

            $result = Api_Cleanup_Service::cleanup_request_log(static::__task());

            static::__assert_equals(7, $result['retention_days']);
            static::__assert_equals(1, static::__marked_count(), 'only the row inside the 7-day window survives');
        } finally {
            config(['rsx.api.log_retention_days' => $original]);
        }
    }

    public static function test_chunked_delete_clears_a_backlog()
    {
        // More deletable rows than one chunk forces multiple DELETE statements; every
        // row must still go.
        for ($i = 0; $i < 12; $i++) {
            static::__insert_log(40);
        }
        static::__assert_equals(12, static::__marked_count());

        $result = Api_Cleanup_Service::cleanup_request_log(static::__task(), ['chunk_size' => 5]);

        static::__assert_true($result['deleted'] >= 12, 'all backlog rows deleted across chunks');
        static::__assert_equals(0, static::__marked_count(), 'no backlog row survives');
    }
}
