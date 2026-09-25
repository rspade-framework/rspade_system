<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Http\Request;

/**
 * Stands in for the application's Main during Api_Main_Pre_Dispatch_Test.
 *
 * It deliberately does NOT extend Main_Abstract: an application has exactly one Main and
 * the framework refuses a second at boot. The test points the manifest's Main_Abstract
 * subclass entry at this class for the duration of one dispatch and restores it after,
 * so the dispatcher's own lookup is what finds it.
 */
class Api_Main_Hook_Probe
{
    /** When non-null, the hook refuses with this value. */
    public static $refuse_with = null;

    /** The params of every call. */
    public static array $seen = [];

    public static function pre_dispatch(Request $request, array $params)
    {
        static::$seen[] = $params;

        return static::$refuse_with;
    }
}
