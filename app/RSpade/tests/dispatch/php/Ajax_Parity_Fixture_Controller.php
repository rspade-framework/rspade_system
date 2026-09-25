<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dispatch\Php;

use Illuminate\Http\Request;
use RuntimeException;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Ajax endpoints and one page route for the transport tests (Ajax_Transport_Parity_Test,
 * Coded_Failure_Page_Test): one endpoint per outcome an endpoint can have, so the direct
 * and the batched transport can be compared outcome by outcome.
 *
 * $invocations counts endpoint bodies run, which is how a refused batch proves nothing in
 * it ran.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth]; the inline-check
 * heuristic this rule pattern-matches is retired by the auth-gates epic (W8).
 */
class Ajax_Parity_Fixture_Controller extends Rsx_Controller_Abstract
{
    /** How many endpoint bodies ran since the test last zeroed it. */
    public static int $invocations = 0;

    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function succeed(Request $request, array $params = [])
    {
        static::$invocations++;

        return ['echo' => $params['value'] ?? null];
    }

    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function invalid(Request $request, array $params = [])
    {
        static::$invocations++;

        return response_form_error('Fix the probe', ['name' => 'Name is required']);
    }

    /**
     * Denied the way Permission::require_permission() denies: by THROWING the coded
     * unauthorized exception.
     */
    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function denied(Request $request, array $params = [])
    {
        static::$invocations++;

        throw new AjaxUnauthorizedException('Probe permission denied');
    }

    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function missing(Request $request, array $params = [])
    {
        static::$invocations++;

        return response_not_found('No such probe record');
    }

    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function aborted(Request $request, array $params = [])
    {
        static::$invocations++;

        abort(404);
    }

    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function crash(Request $request, array $params = [])
    {
        static::$invocations++;

        throw new RuntimeException('probe crash');
    }

    /**
     * What the endpoint's request says about the caller and the call.
     */
    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function caller(Request $request, array $params = [])
    {
        static::$invocations++;

        return [
            'ip' => $request->ip(),
            'probe_header' => $request->header('X-Parity-Probe'),
            'input_value' => $request->input('value'),
        ];
    }

    /**
     * A page action denied by the thrown coded exception.
     */
    #[Route('/_test/front/thrown-denial')]
    #[Auth('public')]
    public static function thrown_denial(Request $request, array $params = [])
    {
        static::$invocations++;

        throw new AjaxUnauthorizedException('Probe permission denied');
    }
}
