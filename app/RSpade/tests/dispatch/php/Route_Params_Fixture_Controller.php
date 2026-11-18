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
 * Real, dispatchable surfaces for Route_Params_Test: each action answers the $params array
 * the dispatcher assembled, and the submitted body field `name` read off the Request, so a
 * test can see exactly which value won and that the POST body stayed out of $params.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth]; the inline-check
 * heuristic this rule pattern-matches is retired by the auth-gates epic (W8).
 */
class Route_Params_Fixture_Controller extends Rsx_Controller_Abstract
{
    #[Route('/_test/params/:id', methods: ['GET', 'POST'])]
    #[Auth('public')]
    public static function echo_params(Request $request, array $params = [])
    {
        return ['type' => 'json', 'data' => ['params' => $params, 'body_name' => $request->input('name')]];
    }

    #[Route('/_test/params/:id/optional/:extra?')]
    #[Auth('public')]
    public static function echo_optional(Request $request, array $params = [])
    {
        return ['type' => 'json', 'data' => ['params' => $params]];
    }
}
