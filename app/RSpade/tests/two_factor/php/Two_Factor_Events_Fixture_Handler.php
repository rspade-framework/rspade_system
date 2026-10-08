<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TwoFactor\Php;

/**
 * Recording fixture for the second-factor engine's events: it listens on the real
 * `two_factor.challenge.spent` and `portal.two_factor.challenge.spent` and records what it
 * is handed - but only while a test has turned $recording on. Otherwise it is a no-op.
 */
class Two_Factor_Events_Fixture_Handler
{
    public static bool $recording = false;

    /** ['event' => name, 'data' => payload], in order, while recording. */
    public static array $recorded = [];

    #[OnEvent('two_factor.challenge.spent', priority: 5)]
    public static function on_staff_challenge_spent($data)
    {
        if (static::$recording) {
            static::$recorded[] = ['event' => 'two_factor.challenge.spent', 'data' => $data];
        }
    }

    #[OnEvent('portal.two_factor.challenge.spent', priority: 5)]
    public static function on_portal_challenge_spent($data)
    {
        if (static::$recording) {
            static::$recorded[] = ['event' => 'portal.two_factor.challenge.spent', 'data' => $data];
        }
    }
}
