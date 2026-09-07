<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Events\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Events\Php\Rsx_Lifecycle_Events_Fixture_Handler;

/**
 * Manifest lifecycle events: rsx.rebuilt / rsx.rebuilt.dev|.prod / rsx.ready.
 *
 * Driven through the real firing seam Manifest::__fire_lifecycle_events(), observed via
 * the guarded recording fixture (Rsx_Lifecycle_Events_Fixture_Handler). We CANNOT assert
 * against a live boot's rebuild flag deterministically (the test-runner process boots once,
 * warm or cold depending on cache state), so we drive the seam directly with a controlled
 * $_rebuild_occurred / $_changed_files and assert firing set, ORDER, and payloads.
 *
 * Real-rebuild coverage of the accessor itself: rebuild_occurred() is a pure read of the
 * public flag, so we assert the accessor tracks the flag rather than forcing an in-process
 * rescan (the runner already booted; forcing a second full scan here is wasteful and does
 * not exercise anything the seam test does not already cover).
 */
class Rsx_Lifecycle_Events_Test extends Rsx_Test_Abstract
{
    // Pure event-dispatch / static-flag logic - no database.
    protected static $use_database_transactions = false;

    // Saved process state, restored in teardown so the test never corrupts the live flags.
    private static bool $__saved_rebuild_occurred = false;
    private static array $__saved_changed_files = [];

    public static function setup(): void
    {
        static::$__saved_rebuild_occurred = Manifest::$_rebuild_occurred;
        static::$__saved_changed_files = Manifest::$_changed_files;
    }

    public static function teardown(): void
    {
        Manifest::$_rebuild_occurred = static::$__saved_rebuild_occurred;
        Manifest::$_changed_files = static::$__saved_changed_files;
        Rsx_Lifecycle_Events_Fixture_Handler::$recording = false;
        Rsx_Lifecycle_Events_Fixture_Handler::reset();
    }

    public static function test_rebuild_occurred_accessor_tracks_flag()
    {
        Manifest::$_rebuild_occurred = true;
        static::__assert_true(Manifest::rebuild_occurred(), 'accessor reflects a true flag');

        Manifest::$_rebuild_occurred = false;
        static::__assert_false(Manifest::rebuild_occurred(), 'accessor reflects a false flag');
    }

    public static function test_ready_only_on_warm_boot()
    {
        // Warm boot: no rebuild happened. rsx.ready MUST still fire; no rsx.rebuilt* events.
        Manifest::$_rebuild_occurred = false;
        Rsx_Lifecycle_Events_Fixture_Handler::reset();
        Rsx_Lifecycle_Events_Fixture_Handler::$recording = true;

        Manifest::__fire_lifecycle_events();

        Rsx_Lifecycle_Events_Fixture_Handler::$recording = false;
        $recorded = Rsx_Lifecycle_Events_Fixture_Handler::$recorded;

        static::__assert_count(1, $recorded, 'exactly one lifecycle event fires on a warm boot');
        static::__assert_equals('rsx.ready', $recorded[0]['event'], 'the one event is rsx.ready');
        static::__assert_false($recorded[0]['data']['rebuilt'], 'rsx.ready payload reports rebuilt=false on a warm boot');
    }

    public static function test_rebuilt_family_fires_in_order()
    {
        // Rebuild happened (dev mode - the test runner boots in development).
        Manifest::$_rebuild_occurred = true;
        Manifest::$_changed_files = ['rsx/models/example_model.php'];
        Rsx_Lifecycle_Events_Fixture_Handler::reset();
        Rsx_Lifecycle_Events_Fixture_Handler::$recording = true;

        Manifest::__fire_lifecycle_events();

        Rsx_Lifecycle_Events_Fixture_Handler::$recording = false;
        $recorded = Rsx_Lifecycle_Events_Fixture_Handler::$recorded;

        static::__assert_count(3, $recorded, 'three lifecycle events fire when a rebuild occurred');

        // Exact order: generic rebuilt, then the mode-specific variant, then ready.
        static::__assert_equals('rsx.rebuilt', $recorded[0]['event'], 'rsx.rebuilt fires first');
        static::__assert_false(Rsx::is_production(), 'test runner boots in development mode');
        static::__assert_equals('rsx.rebuilt.dev', $recorded[1]['event'], 'the dev variant fires second in development');
        static::__assert_equals('rsx.ready', $recorded[2]['event'], 'rsx.ready fires last');

        // Payloads: the rebuilt* events carry the changed-file list; rsx.ready reports rebuilt=true.
        static::__assert_equals(['rsx/models/example_model.php'], $recorded[0]['data']['files'], 'rsx.rebuilt carries the changed file list');
        static::__assert_equals(['rsx/models/example_model.php'], $recorded[1]['data']['files'], 'the mode variant carries the same file list');
        static::__assert_true($recorded[2]['data']['rebuilt'], 'rsx.ready payload reports rebuilt=true after a rebuild');
    }
}
