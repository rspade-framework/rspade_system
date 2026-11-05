<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Support\Source_Cache;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Source_Cache's two bounds per bucket: at most $capacity files, and at most
 * Source_Cache::SOURCE_BYTES_BUDGET bytes of their source - and the file just stored is
 * always kept, however large, so one file larger than the whole budget is still parsed once
 * and held on its own.
 */
class Source_Cache_Bounds_Test extends Rsx_Test_Abstract
{
    // Pure parse work - no database access.
    protected static $use_database_transactions = false;

    private static array $fixtures = [];

    /**
     * A throwaway PHP file of about $bytes bytes: one class of many small methods.
     */
    private static function __fixture(int $bytes): string
    {
        $name = 'Scb_' . str_replace('.', '_', uniqid('', true));
        $method = "    public static function m%d(): int\n    {\n        return %d;\n    }\n\n";
        $body = '';

        for ($i = 0; strlen($body) < $bytes; $i++) {
            $body .= sprintf($method, $i, $i);
        }

        $path = Rsx_Project_Paths::tmp_path() . '/source_cache_bounds_' . $name . '.php';
        file_put_contents($path, "<?php\n\nclass {$name}\n{\n{$body}}\n");
        self::$fixtures[] = $path;

        return $path;
    }

    public static function teardown()
    {
        foreach (self::$fixtures as $path) {
            @unlink($path);
        }

        self::$fixtures = [];
    }

    public static function test_the_byte_budget_evicts_before_the_entry_bound()
    {
        $cache = new Source_Cache(64);
        $quarter = intdiv(Source_Cache::SOURCE_BYTES_BUDGET, 4) + 1024;

        for ($i = 0; $i < 8; $i++) {
            static::__assert_true($cache->ast(static::__fixture($quarter)) !== null, 'fixture ' . $i . ' parses');
        }

        $resident = $cache->resident_counts();
        static::__assert_true($resident['ast'] <= 3, 'eight files of a quarter-budget each leave at most three ASTs resident, got ' . $resident['ast']);
        static::__assert_true($resident['content'] <= 3, 'and at most three contents, got ' . $resident['content']);
    }

    public static function test_a_file_larger_than_the_whole_budget_is_held_on_its_own()
    {
        $cache = new Source_Cache(64);
        $small = static::__fixture(4096);
        $huge = static::__fixture(Source_Cache::SOURCE_BYTES_BUDGET * 2);

        $cache->ast($small);
        static::__assert_true($cache->ast($huge) !== null, 'the oversized file parses');
        static::__assert_equals(1, $cache->resident_counts()['ast'], 'only the oversized file is resident');

        $parses = $cache->work_counts()['ast'];
        $cache->ast($huge);
        static::__assert_equals($parses, $cache->work_counts()['ast'], 'and asking for it again is answered from the cache, not re-parsed');
    }

    public static function test_the_entry_bound_still_holds_for_small_files()
    {
        $cache = new Source_Cache(4);

        for ($i = 0; $i < 10; $i++) {
            $cache->ast(static::__fixture(256));
        }

        static::__assert_equals(4, $cache->resident_counts()['ast']);
    }
}
