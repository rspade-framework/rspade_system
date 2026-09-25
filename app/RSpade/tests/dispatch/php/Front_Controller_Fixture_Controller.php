<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dispatch\Php;

use Illuminate\Http\Request;
use Throwable;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;

/**
 * Real, dispatchable surfaces for the front-controller tests (Front_Controller_Test,
 * Default_Route_Test). The behavior under test only exists when a request is dispatched,
 * so these are routes, under the framework-owned /_test/front/ prefix.
 *
 * $invocations counts how many times an action body ran, which is how a test proves a
 * request was dispatched exactly once.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth]; the inline-check
 * heuristic this rule pattern-matches is retired by the auth-gates epic (W8).
 */
class Front_Controller_Fixture_Controller extends Rsx_Controller_Abstract
{
    public const MARKER = 'front_controller_fixture_marker';

    /** How many times an action body ran since the test last zeroed it. */
    public static int $invocations = 0;

    /**
     * The action returns normally; the 404 is raised while its result is turned into a
     * response - outside the action's own HttpException seam.
     */
    #[Route('/_test/front/abort-after-action')]
    #[Auth('public')]
    public static function abort_after_action(Request $request, array $params = [])
    {
        static::$invocations++;

        return ['type' => 'error', 'code' => 404, 'message' => self::MARKER];
    }

    /**
     * Enters the front controller again from inside a dispatch, and reports what that
     * second entry did.
     */
    #[Route('/_test/front/reenter')]
    #[Auth('public')]
    public static function reenter(Request $request, array $params = [])
    {
        static::$invocations++;

        try {
            Rsx_Front_Controller::handle(Request::create('/_test/front/get-only', 'GET'));
        } catch (Throwable $e) {
            return ['type' => 'json', 'data' => ['reentry' => $e->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['reentry' => null]];
    }

    #[Route('/_test/front/get-only', methods: ['GET'])]
    #[Auth('public')]
    public static function get_only(Request $request, array $params = [])
    {
        static::$invocations++;

        return ['type' => 'json', 'data' => ['ran' => 'get_only']];
    }

    #[Route('/_test/front/get-post/:id', methods: ['GET', 'POST'])]
    #[Auth('public')]
    public static function get_post(Request $request, array $params = [])
    {
        static::$invocations++;

        return ['type' => 'json', 'data' => ['ran' => 'get_post', 'id' => $params['id'] ?? null]];
    }

    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function ajax_probe(Request $request, array $params = [])
    {
        static::$invocations++;

        return ['ran' => 'ajax_probe'];
    }
}
