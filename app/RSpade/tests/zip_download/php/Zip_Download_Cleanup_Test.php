<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ZipDownload\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\Zip_Download_Cleanup_Service;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Zip_Download_Cleanup_Service::cleanup_expired_requests - the six-hourly retention prune.
 *
 * Rows older than config('rsx.attachments.zip_request_retention_hours') are chunk-deleted;
 * newer rows survive. The task is invoked directly with an immediate Task_Instance (info()
 * buffers, heartbeat() no-ops off a worker). Rows are tagged with a distinctive zip_name
 * so assertions target exactly the inserted rows. Default transaction isolation - the task
 * DELETE is visible on the same connection and rolls back afterward. A config override is
 * restored in the same test so it never bleeds.
 */
class Zip_Download_Cleanup_Test extends Rsx_Test_Abstract
{
    private const MARK_NAME = 'cleanup-mark-198x.zip';

    private static function __insert_request(int $age_hours): void
    {
        $when = now()->subHours($age_hours);
        DB::table('_zip_download_requests')->insert([
            'download_key' => random_hash(32),
            'files'        => json_encode([['key' => 'abc123']]),
            'zip_name'     => self::MARK_NAME,
            'created_at'   => $when,
            'updated_at'   => $when,
        ]);
    }

    private static function __marked_count(): int
    {
        return DB::table('_zip_download_requests')->where('zip_name', self::MARK_NAME)->count();
    }

    private static function __task(): Task_Instance
    {
        return new Task_Instance(Zip_Download_Cleanup_Service::class, 'cleanup_expired_requests');
    }

    public static function test_deletes_expired_keeps_fresh_at_default_retention()
    {
        // Default retention is 24 hours.
        static::__insert_request(30); // beyond cutoff
        static::__insert_request(30); // beyond cutoff
        static::__insert_request(1);  // within cutoff
        static::__assert_equals(3, static::__marked_count(), 'three marked rows inserted');

        $result = Zip_Download_Cleanup_Service::cleanup_expired_requests(static::__task());

        static::__assert_greater_than(1, $result['deleted'], 'both stale rows pruned');
        static::__assert_equals(24, $result['retention_hours']);
        static::__assert_equals(1, static::__marked_count(), 'only the fresh row survives');
    }

    public static function test_respects_configured_retention_window()
    {
        $original = config('rsx.attachments.zip_request_retention_hours');

        try {
            config(['rsx.attachments.zip_request_retention_hours' => 6]);

            static::__insert_request(10); // beyond the 6-hour cutoff
            static::__insert_request(1);  // within it
            static::__assert_equals(2, static::__marked_count());

            $result = Zip_Download_Cleanup_Service::cleanup_expired_requests(static::__task());

            static::__assert_equals(6, $result['retention_hours']);
            static::__assert_equals(1, static::__marked_count(), 'only the row inside the 6-hour window survives');
        } finally {
            config(['rsx.attachments.zip_request_retention_hours' => $original]);
        }
    }

    public static function test_chunked_delete_clears_a_backlog()
    {
        // More deletable rows than one chunk forces multiple DELETE statements; every
        // row must still go.
        for ($i = 0; $i < 12; $i++) {
            static::__insert_request(30);
        }
        static::__assert_equals(12, static::__marked_count());

        $result = Zip_Download_Cleanup_Service::cleanup_expired_requests(static::__task(), ['chunk_size' => 5]);

        static::__assert_true($result['deleted'] >= 12, 'all backlog rows deleted across chunks');
        static::__assert_equals(0, static::__marked_count(), 'no backlog row survives');
    }
}
