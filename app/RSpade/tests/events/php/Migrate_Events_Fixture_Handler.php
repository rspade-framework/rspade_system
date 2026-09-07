<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Events\Php;

/**
 * Recording fixture for Migrate_Events_Test.
 *
 * This #[OnEvent] handler listens on the REAL framework event
 * migrate.normalize_schema.complete, which fires at the end of EVERY `php artisan
 * migrate` on this box. Following the Rsx_Lifecycle_Events_Fixture_Handler pattern, it
 * records NOTHING unless $recording is explicitly turned on by the test: with
 * $recording false (the default, and the state during every real migrate) the handler
 * is a pure no-op, so being manifest-discovered and live is harmless.
 *
 * Note that it IS a registered handler regardless - Event_Registry::has_handlers()
 * counts it - so a real migrate on a tree carrying this fixture prints the
 * "[OK] migrate.normalize_schema.complete fired (N handlers)" line. That is the
 * framework reporting a live registration, not the fixture doing anything.
 */
class Migrate_Events_Fixture_Handler
{
    // OFF by default (and during every real migrate) - the handler no-ops unless a test enables this.
    public static bool $recording = false;

    // Ordered log of ['event' => name, 'data' => payload] appended while recording.
    public static array $recorded = [];

    public static function reset(): void
    {
        static::$recorded = [];
    }

    #[OnEvent('migrate.normalize_schema.complete', priority: 10)]
    public static function on_normalize_schema_complete($data)
    {
        if (!static::$recording) {
            return;
        }

        static::$recorded[] = ['event' => 'migrate.normalize_schema.complete', 'data' => $data];
    }
}
