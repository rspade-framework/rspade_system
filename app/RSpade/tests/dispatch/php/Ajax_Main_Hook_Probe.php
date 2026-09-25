<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dispatch\Php;

use Illuminate\Http\Request;

/**
 * Stands in for the application's Main during Ajax_Transport_Parity_Test.
 *
 * It deliberately does NOT extend Main_Abstract: an application has exactly one Main and
 * the framework refuses a second at boot. The test points the manifest's Main_Abstract
 * subclass entry at this class for the duration of one request and restores it after,
 * so the dispatcher's own lookup is what finds it.
 */
class Ajax_Main_Hook_Probe
{
    /** When non-null, the hook halts the request with this value. */
    public static $halt_with = null;

    /** The params of every call. */
    public static array $seen = [];

    public static function pre_dispatch(Request $request, array $params)
    {
        static::$seen[] = $params;

        return static::$halt_with;
    }
}
