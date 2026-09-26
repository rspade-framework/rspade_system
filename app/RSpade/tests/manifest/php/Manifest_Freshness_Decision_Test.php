<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Manifest\Manifest_Store;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The freshness decision - does a loaded index still describe the scanned tree - asserted
 * over synthetic data through Manifest_Store::stale_reason().
 *
 * No child process, no real index and no file on disk: every input is a literal, so the
 * answer depends on the rule and on nothing else the suite or the box happens to be doing.
 */
class Manifest_Freshness_Decision_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * An index of two source files plus one generated stub entry, and a scan that returns
     * exactly the two source files with their recorded size and mtime.
     *
     * @return array{0: array, 1: array, 2: array} [file_index, live_files, observed]
     */
    private static function __matching_tree(): array
    {
        $stub_key = Rsx_Project_Paths::stub_key(Rsx_Project_Paths::STUBS_MODEL, 'Fixture_Model.js');

        $file_index = [
            'rsx/models/fixture_model.php' => [120, 1700000000],
            'rsx/app/fixture/fixture.js' => [64, 1700000100],
            $stub_key => [999, 1800000000],
        ];

        $observed = [
            'rsx/models/fixture_model.php' => [120, 1700000000],
            'rsx/app/fixture/fixture.js' => [64, 1700000100],
        ];

        return [$file_index, [], $observed];
    }

    /**
     * A generated entry the scan never returns is not a deleted file: the index is fresh.
     */
    public static function test_an_index_with_generated_entries_matching_the_scan_is_fresh()
    {
        [$file_index, $live_files, $observed] = self::__matching_tree();

        $generated = array_filter(array_keys($file_index), [Rsx_Project_Paths::class, 'is_generated_key']);
        static::__assert_count(1, $generated, 'the synthetic index carries one generated entry');

        static::__assert_null(Manifest_Store::stale_reason($file_index, $live_files, $observed));
    }

    /**
     * A source file whose size or mtime moved makes the index stale.
     */
    public static function test_a_changed_source_file_is_stale()
    {
        [$file_index, $live_files, $observed] = self::__matching_tree();

        $resized = $observed;
        $resized['rsx/app/fixture/fixture.js'] = [65, 1700000100];
        static::__assert_contains('has changed size', (string) Manifest_Store::stale_reason($file_index, $live_files, $resized));

        $touched = $observed;
        $touched['rsx/app/fixture/fixture.js'] = [64, 1700000101];
        static::__assert_contains('has changed mtime', (string) Manifest_Store::stale_reason($file_index, $live_files, $touched));
    }

    /**
     * A source file the index records and the scan no longer returns makes the index stale.
     */
    public static function test_a_removed_source_file_is_stale()
    {
        [$file_index, $live_files, $observed] = self::__matching_tree();

        unset($observed['rsx/models/fixture_model.php']);

        static::__assert_contains(
            'Deleted file rsx/models/fixture_model.php',
            (string) Manifest_Store::stale_reason($file_index, $live_files, $observed)
        );
    }

    /**
     * A scanned file the index does not record, and a scanned file gone from disk, are stale.
     */
    public static function test_a_new_or_vanished_scanned_file_is_stale()
    {
        [$file_index, $live_files, $observed] = self::__matching_tree();

        $added = $observed;
        $added['rsx/app/fixture/added.js'] = [10, 1700000200];
        static::__assert_contains('New file rsx/app/fixture/added.js', (string) Manifest_Store::stale_reason($file_index, $live_files, $added));

        $vanished = $observed;
        $vanished['rsx/app/fixture/fixture.js'] = null;
        static::__assert_contains('appears to be deleted', (string) Manifest_Store::stale_reason($file_index, $live_files, $vanished));
    }

    /**
     * The live files map wins over the index entry for the same path.
     */
    public static function test_the_live_entry_wins_over_the_index_entry()
    {
        [$file_index, , $observed] = self::__matching_tree();

        $observed['rsx/app/fixture/fixture.js'] = [80, 1700000500];
        $live_files = ['rsx/app/fixture/fixture.js' => ['size' => 80, 'mtime' => 1700000500]];

        static::__assert_null(Manifest_Store::stale_reason($file_index, $live_files, $observed));
    }
}
