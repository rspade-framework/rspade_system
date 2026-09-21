<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Errors\Php;

use Illuminate\Http\Request;
use RuntimeException;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * The application error pages the funnel tests render, plus two ordinary routes
 * that end on a coded outcome.
 *
 * NOT DECLARED UNDER /error/. Error-page patterns are one global namespace per
 * realm, so a fixture at /error/404 would collide with the application's real
 * page for as long as the suite is indexed. The tests point
 * Error_Pages::_testing_set_resolver() at these surfaces instead, which exercises
 * the same facade with none of the collision.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth]; the inline-check
 * heuristic this rule pattern-matches is retired by the auth-gates epic (W8).
 */
class Error_Pages_Fixture_Controller extends Rsx_Controller_Abstract
{
    /** Present in the body of the page that renders, and nowhere else. */
    public const MARKER = 'ERROR-PAGE-FIXTURE-MARKER';

    /** The reason the validation fixture answers with. */
    public const VALIDATION_REASON = 'the fixture rejected this request';

    /** A page that renders the context it was handed. */
    #[Route('/test-error-pages/page')]
    #[Auth('public')]
    public static function page(Request $request, array $params = [])
    {
        $error = $params['error'];

        return response(sprintf(
            '%s status=%d title=%s realm=%s preview=%s',
            self::MARKER,
            $error->status,
            $error->title,
            $error->realm,
            $error->preview ? 'yes' : 'no'
        ));
    }

    /** A page that fails while rendering. */
    #[Route('/test-error-pages/throws')]
    #[Auth('public')]
    public static function throws(Request $request, array $params = [])
    {
        throw new RuntimeException('the fixture error page failed');
    }

    /** A page that answers with a coded response instead of a page. */
    #[Route('/test-error-pages/coded')]
    #[Auth('public')]
    public static function coded(Request $request, array $params = [])
    {
        return response_unauthorized('the fixture declined');
    }

    /** An ordinary GET route whose record does not exist. */
    #[Route('/test-error-pages/missing-record')]
    #[Auth('public')]
    public static function missing_record(Request $request, array $params = [])
    {
        return response_not_found('no such fixture record');
    }

    /** An ordinary GET route that rejects its input. */
    #[Route('/test-error-pages/rejected')]
    #[Auth('public')]
    public static function rejected(Request $request, array $params = [])
    {
        return response_form_error(self::VALIDATION_REASON);
    }
}
