<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dispatch\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Two Ajax endpoints for Ajax_Priority_Transport_Test: one declaring #[Priority] (callable
 * through /_ajax/_priority) and one ordinary endpoint (refused there).
 *
 * $invocations counts endpoint bodies run, which is how a refused request proves nothing ran.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth].
 */
class Ajax_Priority_Fixture_Controller extends Rsx_Controller_Abstract
{
    /** How many endpoint bodies ran since the test last zeroed it. */
    public static int $invocations = 0;

    #[Ajax_Endpoint]
    #[Priority]
    #[Auth('public')]
    public static function urgent(Request $request, array $params = [])
    {
        static::$invocations++;

        return ['echo' => $params['value'] ?? null];
    }

    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function ordinary(Request $request, array $params = [])
    {
        static::$invocations++;

        return ['echo' => $params['value'] ?? null];
    }
}
