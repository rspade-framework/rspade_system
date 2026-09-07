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
 * A REAL, dispatchable surface whose actions do nothing but abort().
 *
 * The behavior under test only exists at the dispatch seam - an action running inside
 * Laravel's handling of its own NotFoundHttpException - so it cannot be reached by
 * calling a method directly. These routes are that seam.
 *
 * The /_test/dispatch/ prefix is framework-owned and deliberately obscure: these ARE
 * live routes in a development install, and they exist so abort() can be exercised
 * without temporarily annotating application code.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth]; the inline-check
 * heuristic this rule pattern-matches is retired by the auth-gates epic (W8).
 */
class Dispatch_Abort_Fixture_Controller extends Rsx_Controller_Abstract
{
    /** The message each abort carries, asserted on the plain-status channel. */
    public const MESSAGE = 'dispatch_abort_fixture_message';

    #[Route('/_test/dispatch/abort-404')]
    #[Auth('public')]
    public static function abort_404(Request $request, array $params = [])
    {
        abort(404, self::MESSAGE);
    }

    #[Route('/_test/dispatch/abort-403')]
    #[Auth('public')]
    public static function abort_403(Request $request, array $params = [])
    {
        abort(403, self::MESSAGE);
    }

    #[Route('/_test/dispatch/abort-418')]
    #[Auth('public')]
    public static function abort_418(Request $request, array $params = [])
    {
        abort(418, self::MESSAGE);
    }

    /**
     * A non-HTTP exception from the same seam - the control proving the new catch is
     * narrow and does not swallow ordinary failures.
     */
    #[Route('/_test/dispatch/throw')]
    #[Auth('public')]
    public static function throw_runtime(Request $request, array $params = [])
    {
        throw new \RuntimeException(self::MESSAGE);
    }
}
