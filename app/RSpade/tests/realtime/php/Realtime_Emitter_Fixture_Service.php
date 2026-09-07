<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

/**
 * Test fixture: a class carrying an #[Emitter] method. Discovery is attribute-based
 * (manifest scan), so this is found without registering anywhere. Its value is a
 * controllable static so a test can seed, hold, or change what the emitter returns.
 *
 * The emitter publishes to Realtime_Test_Public_Topic (anonymous-subscribable), so
 * the manual Node subscribe test can watch the same topic without a login.
 *
 * NOTE: #[Emitter] is reflection metadata only — no backing attribute class, and the
 * linter intentionally removes any `use` for it.
 */
class Realtime_Emitter_Fixture_Service
{
    /**
     * The value the fixture emitter returns on its next call. Tests mutate this to
     * drive seed / unchanged / changed behavior through the hash-diff engine.
     */
    public static mixed $next_value = 0;

    #[Emitter('Realtime_Test_Public_Topic')]
    public static function fixture_value(int $site_id, array $filter): mixed
    {
        return self::$next_value;
    }
}
