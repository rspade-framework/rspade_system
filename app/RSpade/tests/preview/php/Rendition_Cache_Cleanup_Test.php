<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Preview\Php;

use App\RSpade\Core\Files\File_Rendition_Service;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The rendition cache is ONE cache of two kinds of file - a PDF rendition ({hash}.pdf) and a
 * spreadsheet's HTML grid ({hash}.grid.html) - under one quota (rsx.preview.quota_max_bytes),
 * evicted oldest-first by File_Rendition_Service::cleanup_renditions.
 *
 * The rendition directory follows the tmp root, so every test here points the tmp root at a
 * scratch directory of its own (Rsx_Project_Paths::_override): the sweep under test deletes
 * files, and the box's real rendition cache must never be in its reach.
 */
class Rendition_Cache_Cleanup_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** @var string|null The scratch tmp root of the running class. */
    private static $scratch = null;

    /** @var mixed The configured quota, restored after the class. */
    private static $quota = null;

    public static function setup()
    {
        static::$quota = config('rsx.preview.quota_max_bytes');
        static::$scratch = sys_get_temp_dir() . '/rendition_cache_cleanup_test_' . bin2hex(random_bytes(6));
        mkdir(static::$scratch . '/tmp', 0755, true);
        Rsx_Project_Paths::_override(['tmp' => static::$scratch . '/tmp']);
        mkdir(Rsx_File_Paths::renditions_root(), 0755, true);
    }

    public static function teardown()
    {
        Rsx_Project_Paths::_clear_overrides();
        if (static::$scratch !== null) {
            exec_safe('rm -rf ' . escapeshellarg(static::$scratch));
            static::$scratch = null;
        }
        config(['rsx.preview.quota_max_bytes' => static::$quota]);
    }

    // PREVIEW-CLEANUP-LRU: over quota, the oldest files are removed until under it - the two
    // kinds evicted together, by age alone.
    public static function test_over_quota_evicts_oldest_of_both_kinds()
    {
        $dir = static::__empty_cache();
        $now = time();

        // Four 1000-byte files, oldest first, the kinds interleaved.
        static::__write($dir . 'aaa.grid.html', $now - 400);
        static::__write($dir . 'bbb.pdf', $now - 300);
        static::__write($dir . 'ccc.grid.html', $now - 200);
        static::__write($dir . 'ddd.pdf', $now - 100);

        config(['rsx.preview.quota_max_bytes' => 2000]);
        Task::internal('File_Rendition_Service', 'cleanup_renditions');

        static::__assert_false(file_exists($dir . 'aaa.grid.html'), 'the oldest file - a spreadsheet grid - is evicted');
        static::__assert_false(file_exists($dir . 'bbb.pdf'), 'the next oldest is evicted');
        static::__assert_true(file_exists($dir . 'ccc.grid.html'), 'a newer grid survives once the cache is within quota');
        static::__assert_true(file_exists($dir . 'ddd.pdf'), 'the newest PDF survives');
    }

    // PREVIEW-CLEANUP-STATS: the statistics count both kinds.
    public static function test_statistics_count_both_kinds()
    {
        $dir = static::__empty_cache();
        static::__write($dir . 'eee.pdf', time());
        static::__write($dir . 'fff.grid.html', time());

        $stats = File_Rendition_Service::get_statistics();

        static::__assert_equals(2, $stats['file_count'], 'a PDF and a grid are both counted');
        static::__assert_equals(2000, $stats['total_bytes'], 'their sizes are both summed');
    }

    private static function __empty_cache(): string
    {
        $dir = Rsx_File_Paths::renditions_root() . '/';
        foreach (glob($dir . '*') ?: [] as $file) {
            unlink($file);
        }

        return $dir;
    }

    private static function __write(string $path, int $mtime): void
    {
        file_put_contents($path, str_repeat('x', 1000));
        touch($path, $mtime);
    }
}
