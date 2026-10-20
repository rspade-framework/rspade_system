<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Preview\Php;

use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\Rsx_Mime_Map;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Mime_Map precedence: framework broad < framework specific < application broad <
 * application specific; inside a layer an exact mime beats a glob, a longer literal glob beats a
 * shorter one and a tie goes to the later line; '*' is the fallback consulted last (the
 * application's replacing the framework's, never beating a specific entry).
 *
 * Most cases run against a fixture config root of their own (FIXTURE_ROOT), whose framework layer
 * the test records and whose live value is the deep merge an application config file produces -
 * so the layers are exactly what each case states, independent of the shipped maps. The last
 * case exercises the real rsx.preview.viewers through a runtime config() override.
 */
class Rsx_Mime_Map_Test extends Rsx_Test_Abstract
{
    private const FIXTURE_ROOT = 'rsx_mime_map_fixture';
    private const FIXTURE_KEY = 'rsx_mime_map_fixture.map';

    protected static $use_database_transactions = false;

    public static function teardown()
    {
        Rsx_Mime_Map::record_framework_layer(self::FIXTURE_ROOT, []);
        config([self::FIXTURE_ROOT => []]);
    }

    /**
     * Install a framework layer and an application layer for the fixture map, merged the way
     * Rsx_Framework_Provider merges an application config file.
     *
     * @param array $framework Pattern => class, as the framework ships it.
     * @param array $application Pattern => class, as an application config file declares it.
     * @return void
     */
    private static function __layers(array $framework, array $application): void
    {
        $framework_root = ['map' => $framework];
        $application_root = ['map' => $application];
        Rsx_Mime_Map::record_framework_layer(self::FIXTURE_ROOT, $framework_root);
        config([self::FIXTURE_ROOT => array_merge_deep($framework_root, $application_root)]);
    }

    private static function __match(string $mime): ?string
    {
        return Rsx_Mime_Map::match(self::FIXTURE_KEY, $mime);
    }

    public static function test_application_specific_beats_framework_specific()
    {
        static::__layers(['image/png' => 'Fw_Png', '*' => 'Fw_Any'], ['image/png' => 'App_Png']);
        static::__assert_equals('App_Png', static::__match('image/png'), 'an app entry restating a framework pattern wins');
    }

    public static function test_application_broad_beats_framework_specific()
    {
        static::__layers(['image/png' => 'Fw_Png', '*' => 'Fw_Any'], ['image/*' => 'App_Image']);
        static::__assert_equals('App_Image', static::__match('image/png'), 'an app glob beats an exact framework mime');
    }

    public static function test_framework_specific_beats_framework_broad()
    {
        static::__layers(['image/*' => 'Fw_Image', 'image/png' => 'Fw_Png'], ['video/*' => 'App_Video']);
        static::__assert_equals('Fw_Png', static::__match('image/png'), 'framework exact beats framework glob when no app entry matches');
        static::__assert_equals('Fw_Image', static::__match('image/gif'), 'framework glob answers what its exact sibling does not');
    }

    public static function test_application_specific_beats_application_broad()
    {
        static::__layers([], ['image/png' => 'App_Png', 'image/*' => 'App_Image']);
        static::__assert_equals('App_Png', static::__match('image/png'), 'app exact beats app glob, whatever the order');
        static::__assert_equals('App_Image', static::__match('image/jpeg'), 'app glob answers the rest');
    }

    public static function test_exact_beats_any_glob()
    {
        // The glob has far more literal characters than the exact mime is long; exact still wins.
        static::__layers(['application/vnd.ms-excel.sheet.macroEnabled.12' => 'Fw_Exact', 'application/vnd.ms-excel.sheet.macroEnabled.1?' => 'Fw_Glob'], []);
        static::__assert_equals('Fw_Exact', static::__match('application/vnd.ms-excel.sheet.macroEnabled.12'), 'exact beats a near-literal glob');
    }

    public static function test_longer_literal_glob_beats_shorter()
    {
        static::__layers([
            'application/vnd.openxmlformats-officedocument.spreadsheetml.*' => 'Fw_Sheet',
            'application/vnd.openxmlformats-officedocument.*' => 'Fw_Office',
        ], []);
        static::__assert_equals(
            'Fw_Sheet',
            static::__match('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            'the longer literal glob wins even when declared first'
        );
        static::__assert_equals(
            'Fw_Office',
            static::__match('application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            'the shorter glob answers what the longer does not match'
        );
    }

    public static function test_bracket_expression_counts_as_no_literal()
    {
        // 'text/[px]lain' has 9 literals ('text/' + 'lain'); 'text/pla*' has 8. In the second pair
        // 'text/plai*' has 9 and 'text/[px]lai?' has 8 - counting the bracket would reverse it.
        static::__layers(['text/pla*' => 'Fw_Pla', 'text/[px]lain' => 'Fw_Bracket'], []);
        static::__assert_equals('Fw_Bracket', static::__match('text/plain'), '9 literals beat 8');
        static::__layers(['text/plai*' => 'Fw_Plai', 'text/[px]lai?' => 'Fw_Bracket'], []);
        static::__assert_equals('Fw_Plai', static::__match('text/plain'), 'brackets and ? contribute no literal characters');
    }

    public static function test_tie_goes_to_the_later_declaration()
    {
        static::__layers(['text/p*' => 'Fw_First', 'text/*n' => 'Fw_Second'], []);
        static::__assert_equals('Fw_Second', static::__match('text/plain'), 'equal literal counts: the later line wins');

        static::__layers([], ['text/*n' => 'App_First', 'text/p*' => 'App_Second']);
        static::__assert_equals('App_Second', static::__match('text/plain'), 'the same rule inside the application layer');
    }

    public static function test_star_fallback_order()
    {
        static::__layers(['image/*' => 'Fw_Image', '*' => 'Fw_Any'], []);
        static::__assert_equals('Fw_Any', static::__match('video/mp4'), 'framework * answers an unmatched mime');

        static::__layers(['image/*' => 'Fw_Image', '*' => 'Fw_Any'], ['*' => 'App_Any']);
        static::__assert_equals('App_Any', static::__match('video/mp4'), 'an app * replaces the framework *');
        static::__assert_equals('Fw_Image', static::__match('image/png'), 'an app * never beats a specific framework entry');

        static::__layers(['*' => 'Fw_Any', 'image/*' => 'Fw_Image'], ['video/*' => 'App_Video']);
        static::__assert_equals('Fw_Image', static::__match('image/png'), 'a * declared first is still consulted last');
    }

    public static function test_no_match_is_null()
    {
        static::__layers(['image/*' => 'Fw_Image'], ['video/*' => 'App_Video']);
        static::__assert_null(static::__match('application/x-unknown'), 'nothing matches and no * -> null');

        static::__layers([], []);
        static::__assert_null(static::__match('image/png'), 'an empty map -> null');
    }

    public static function test_runtime_override_of_a_shipped_map()
    {
        $shipped = config('rsx.preview.viewers');

        static::__assert_equals('Image_Viewer', File_Preview_Controller::viewer_for_mime('image/png'), 'shipped: image/png -> Image_Viewer');
        static::__assert_equals('Pdf_Viewer', File_Preview_Controller::viewer_for_mime('application/pdf'), 'shipped: pdf -> Pdf_Viewer');
        static::__assert_equals(
            'Spreadsheet_Viewer',
            File_Preview_Controller::viewer_for_mime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            'shipped: the spreadsheet glob outranks the generic Office glob'
        );
        static::__assert_equals('Markdown_Viewer', File_Preview_Controller::viewer_for_mime('text/markdown'), 'shipped: text/markdown outranks text/*');

        try {
            // A runtime write is the live value: an entry that differs from the framework's is the
            // application's, an entry removed is gone.
            $override = $shipped;
            $override['image/*'] = 'Fixture_Image_Viewer';
            $override['application/*'] = 'Fixture_Application_Viewer';
            unset($override['text/markdown']);
            config(['rsx.preview.viewers' => $override]);

            static::__assert_equals('Fixture_Image_Viewer', File_Preview_Controller::viewer_for_mime('image/png'), 'a changed framework pattern is an app entry');
            static::__assert_equals('Fixture_Application_Viewer', File_Preview_Controller::viewer_for_mime('application/pdf'), 'an app glob beats the framework exact pdf entry');
            static::__assert_equals('Text_Viewer', File_Preview_Controller::viewer_for_mime('text/markdown'), 'a removed entry no longer answers');
        } finally {
            config(['rsx.preview.viewers' => $shipped]);
        }

        static::__assert_equals('Image_Viewer', File_Preview_Controller::viewer_for_mime('image/png'), 'restoring the config restores the framework answer');
    }
}
