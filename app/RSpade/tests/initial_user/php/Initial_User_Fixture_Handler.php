<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\InitialUser\Php;

use App\RSpade\Core\Locks\Site_Lock;

/**
 * Recording fixture for Initial_User_Test.
 *
 * This is a REAL #[OnEvent] handler on the REAL event - manifest-discovered like any
 * other, and live on every boot. It records nothing unless a test turns $recording on,
 * so being live is harmless: the initial user is created once per database, and outside
 * a test nobody is reading $recorded.
 */
class Initial_User_Fixture_Handler
{
    // OFF by default (and on every real boot) - the handler no-ops unless a test enables it.
    public static bool $recording = false;

    // Ordered log of the payloads seen while recording.
    public static array $recorded = [];

    // The site lock this process held when each recorded payload arrived (Site_Lock::_held()).
    public static array $held = [];

    public static function reset(): void
    {
        static::$recorded = [];
        static::$held = [];
    }

    // Literal event name, never the class constant: attribute arguments are read by
    // reflection during the manifest scan, before the autoloader can resolve a class.
    #[OnEvent('user.initial.created', priority: 5)]
    public static function on_initial_user_created($data)
    {
        if (!static::$recording) {
            return;
        }

        static::$recorded[] = $data;
        static::$held[] = Site_Lock::_held();
    }
}
