<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\Manifest_Store;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The build key identifies the BUILD, so it carries the mode the build was produced in.
 *
 * One tree builds three different artifacts (development, debug, production), and the key is
 * the namespace of the full-page cache, the build-scoped RsxCache prefix, the browser storage
 * scope and the development cache-buster. A downstream field report (2026-10-05) found a
 * development box, rebuilt after `rsx:prod:disable`, answering with the deleted production
 * seal's key: the key was a hash of the source tree only, the tree had not changed, so the
 * development build recomputed exactly the production value.
 *
 * Asserted at the decision level - Manifest_Store::_compute_hash() and
 * Manifest_Store::index_mode_stale_reason() over synthetic input - plus the validation seam
 * driven against the live index with its mode stamp swapped in memory. Nothing here switches
 * the box's mode or writes the build tree.
 */
class Manifest_Build_Key_Mode_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * A small manifest body: two file records and one derived section.
     */
    private static function __body(): array
    {
        return [
            'files' => [
                'rsx/app/fixture/fixture.js' => ['hash' => 'aaaa1111', 'mtime' => 1000, 'size' => 64],
                'rsx/models/fixture_model.php' => ['hash' => 'bbbb2222', 'mtime' => 2000, 'size' => 120],
            ],
            'php_classes' => ['Fixture_Model' => ['file' => 'rsx/models/fixture_model.php']],
        ];
    }

    /**
     * The same tree keys differently in each mode - a development build after a production
     * seal can no longer answer with the seal's key.
     */
    public static function test_the_same_tree_keys_differently_in_each_mode()
    {
        $body = self::__body();

        $development = Manifest_Store::_compute_hash($body, Rsx::MODE_DEVELOPMENT);
        $debug = Manifest_Store::_compute_hash($body, Rsx::MODE_DEBUG);
        $production = Manifest_Store::_compute_hash($body, Rsx::MODE_PRODUCTION);

        static::__assert_not_equals($production, $development, 'a development build of the production tree reuses the production key');
        static::__assert_not_equals($production, $debug, 'a debug build shares the strict production key');
        static::__assert_not_equals($debug, $development, 'a development build shares the debug key');
    }

    /**
     * THE CLUSTER CONTRACT is intact: the same tree in the same mode is the same key, and a
     * content change still moves it within a mode.
     */
    public static function test_the_same_tree_in_the_same_mode_is_the_same_key()
    {
        $a = self::__body();
        $b = self::__body();
        $b['files']['rsx/app/fixture/fixture.js']['mtime'] = 999999;

        foreach ([Rsx::MODE_DEVELOPMENT, Rsx::MODE_DEBUG, Rsx::MODE_PRODUCTION] as $mode) {
            static::__assert_equals(
                Manifest_Store::_compute_hash($a, $mode),
                Manifest_Store::_compute_hash($b, $mode),
                "two builds of one tree disagree in {$mode} mode"
            );
        }

        $c = self::__body();
        $c['files']['rsx/app/fixture/fixture.js']['hash'] = 'cccc3333';
        static::__assert_not_equals(
            Manifest_Store::_compute_hash($a, Rsx::MODE_DEVELOPMENT),
            Manifest_Store::_compute_hash($c, Rsx::MODE_DEVELOPMENT),
            'a content change did not move the development key'
        );
    }

    /**
     * The decision, pure: an index belongs to the mode that built it, and an index recorded
     * under another mode - or under none - is stale.
     */
    public static function test_an_index_from_another_mode_is_stale()
    {
        static::__assert_null(Manifest_Store::index_mode_stale_reason(Rsx::MODE_DEVELOPMENT, Rsx::MODE_DEVELOPMENT));
        static::__assert_null(Manifest_Store::index_mode_stale_reason(Rsx::MODE_PRODUCTION, Rsx::MODE_PRODUCTION));

        $reason = Manifest_Store::index_mode_stale_reason(Rsx::MODE_PRODUCTION, Rsx::MODE_DEVELOPMENT);
        static::__assert_not_null($reason, 'a production index was accepted by a development boot');
        static::__assert_contains('built in production mode', $reason);
        static::__assert_contains('RSX_MODE is development', $reason);

        static::__assert_not_null(
            Manifest_Store::index_mode_stale_reason(null, Rsx::MODE_DEVELOPMENT),
            'an index with no mode stamp was accepted'
        );
    }

    /**
     * The live index records the mode it was built in, and its key is exactly what the hash
     * says for that mode over the body it carries.
     */
    public static function test_the_live_index_key_is_computed_for_its_mode()
    {
        static::__assert_equals(Rsx::get_mode(), Manifest::$data['mode'] ?? null, 'the loaded index carries no mode stamp');

        // The full files map lives across both halves of the index.
        Manifest_Store::_load_cold_files();

        static::__assert_equals(
            Manifest_Store::_compute_hash(Manifest::$data['data'], Manifest::$data['mode']),
            Manifest::get_build_key(),
            'the build key is not the hash of the loaded index in its recorded mode'
        );
    }

    /**
     * THE TRANSITION. A development boot that loads an index stamped by a production build
     * refuses it whole - however current its files are - and empties the data, so init()
     * performs a full rebuild that keys the tree for development. The swap is in memory only,
     * and the live data is restored whatever happens.
     */
    public static function test_a_development_boot_refuses_a_production_index()
    {
        if (!Rsx::is_development()) {
            static::__skip('the validation path under test is the development one');
        }

        $live = Manifest::$data;

        try {
            Manifest::$data['mode'] = Rsx::MODE_PRODUCTION;

            static::__assert_false(Manifest_Store::_validate_cached_data(), 'a production index was accepted by a development boot');
            static::__assert_equals('', Manifest::$data['hash'] ?? null, 'the refused index was not discarded');
            static::__assert_equals([], Manifest::$data['data']['files'] ?? null, 'the refused index kept its files - the rebuild would be incremental and find nothing to do');
        } finally {
            Manifest::$data = $live;
        }
    }
}
