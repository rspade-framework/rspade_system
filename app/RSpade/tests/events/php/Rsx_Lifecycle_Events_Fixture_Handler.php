<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Events\Php;

/**
 * Recording fixture for Rsx_Lifecycle_Events_Test.
 *
 * These #[OnEvent] handlers listen on the REAL framework lifecycle events
 * (rsx.rebuilt / rsx.rebuilt.dev / rsx.rebuilt.prod / rsx.ready) - which fire on
 * EVERY boot from Manifest::__fire_lifecycle_events(). To stay side-effect-free in
 * production/dev boots, they record NOTHING unless $recording is explicitly turned on
 * by the test. With $recording false (the default, and the state on every real boot)
 * each handler is a pure no-op, so being manifest-discovered and live is harmless.
 *
 * The test toggles $recording on, resets $recorded, drives the firing seam
 * (Manifest::__fire_lifecycle_events()), inspects the ordered $recorded log, then
 * toggles $recording back off.
 */
class Rsx_Lifecycle_Events_Fixture_Handler
{
    // OFF by default (and on every real boot) - handlers no-op unless a test enables this.
    public static bool $recording = false;

    // Ordered log of ['event' => name, 'data' => payload] appended while recording.
    public static array $recorded = [];

    public static function reset(): void
    {
        static::$recorded = [];
    }

    private static function __record(string $event, $data): void
    {
        if (!static::$recording) {
            return;
        }
        static::$recorded[] = ['event' => $event, 'data' => $data];
    }

    #[OnEvent('rsx.rebuilt', priority: 10)]
    public static function on_rebuilt($data)
    {
        static::__record('rsx.rebuilt', $data);
    }

    #[OnEvent('rsx.rebuilt.dev', priority: 10)]
    public static function on_rebuilt_dev($data)
    {
        static::__record('rsx.rebuilt.dev', $data);
    }

    #[OnEvent('rsx.rebuilt.prod', priority: 10)]
    public static function on_rebuilt_prod($data)
    {
        static::__record('rsx.rebuilt.prod', $data);
    }

    #[OnEvent('rsx.ready', priority: 10)]
    public static function on_ready($data)
    {
        static::__record('rsx.ready', $data);
    }
}
