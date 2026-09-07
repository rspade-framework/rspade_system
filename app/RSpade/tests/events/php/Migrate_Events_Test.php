<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Events\Php;

use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Events\Php\Migrate_Events_Fixture_Handler;

/**
 * The migrate.normalize_schema.complete action event.
 *
 * Driven through the real trigger (Rsx::trigger_action) and observed via the guarded
 * recording fixture. We do NOT run a migration here - the position of the trigger
 * inside Maint_Migrate::execute_migrations() is pinned by the source-structure test in
 * the migrate concern (Migrate_Normalize_Complete_Event_Test); what this test proves is
 * that the event name is discovered, that an app handler reached through the ordinary
 * manifest discovery path receives it, and that the payload is empty as contracted.
 *
 * No database access - skip the per-test transaction.
 */
class Migrate_Events_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    const EVENT_NAME = 'migrate.normalize_schema.complete';

    public static function teardown(): void
    {
        Migrate_Events_Fixture_Handler::$recording = false;
        Migrate_Events_Fixture_Handler::reset();
    }

    public static function test_handler_is_discovered_from_the_manifest()
    {
        static::__assert_true(
            Event_Registry::has_handlers(self::EVENT_NAME),
            'the #[OnEvent] fixture handler is manifest-discovered for ' . self::EVENT_NAME
        );
    }

    public static function test_trigger_action_reaches_the_handler_once_with_an_empty_payload()
    {
        Migrate_Events_Fixture_Handler::reset();
        Migrate_Events_Fixture_Handler::$recording = true;

        Rsx::trigger_action(self::EVENT_NAME, []);

        Migrate_Events_Fixture_Handler::$recording = false;
        $recorded = Migrate_Events_Fixture_Handler::$recorded;

        static::__assert_count(1, $recorded, 'the handler records exactly once per trigger');
        static::__assert_equals(self::EVENT_NAME, $recorded[0]['event'], 'the recorded event is the migrate event');
        static::__assert_equals([], $recorded[0]['data'], 'the event carries no payload');
    }

    public static function test_handler_is_inert_when_not_recording()
    {
        // The fixture is a LIVE manifest-discovered handler: it fires during every real
        // migrate on this box. Inertness is what makes that harmless.
        Migrate_Events_Fixture_Handler::reset();
        Migrate_Events_Fixture_Handler::$recording = false;

        Rsx::trigger_action(self::EVENT_NAME, []);

        static::__assert_count(0, Migrate_Events_Fixture_Handler::$recorded, 'an inert fixture records nothing');
    }
}
