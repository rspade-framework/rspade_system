<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dispatch\Php;

use Illuminate\Http\Request;
use RuntimeException;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Dispatch\Php\Dispatch_Abort_Fixture_Controller;

/**
 * abort() INSIDE AN RSX-DISPATCHED ACTION.
 *
 * RSX routes are dispatched from inside Laravel's exception handling: Laravel throws
 * NotFoundHttpException for a URL its router does not know, and the bootstrapper catches
 * that and calls the Dispatcher. An action that then called abort(404) threw a SECOND
 * HttpException while the first was still being handled, which escaped as an uncaught
 * fatal - so every abort() in RSX code produced HTTP 500 instead of the status it asked
 * for (/_preview/pdf/<unknown> was 500 on the wire).
 *
 * The Dispatcher now answers a coded HTTP outcome at the seam that invoked the action.
 * Proved here: 404 and 403 land on the same Error_Screens the Web_Exception_Handler uses,
 * any other status keeps its own status and message, the asset channel gets a plain body
 * rather than a themed page, and a non-HTTP exception still propagates untouched.
 */
class Dispatch_Abort_Test extends Rsx_Test_Abstract
{
    /**
     * Dispatch a fixture route as a BROWSED page (no Accept header, which Laravel reads
     * as "anything, including html").
     */
    private static function __browse(string $path)
    {
        return Dispatcher::dispatch($path, 'GET', [], Request::create($path, 'GET'));
    }

    /**
     * Dispatch a fixture route the way an <img> or fetch() would - an Accept header that
     * does not want html.
     */
    private static function __fetch_as_asset(string $path)
    {
        $request = Request::create($path, 'GET', [], [], [], ['HTTP_ACCEPT' => 'image/webp,image/*']);

        return Dispatcher::dispatch($path, 'GET', [], $request);
    }

    public static function test_abort_404_is_a_404_not_a_500()
    {
        $response = static::__browse('/_test/dispatch/abort-404');

        static::__assert_equals(404, $response->getStatusCode());
    }

    /**
     * Signed in, because a denial is only a 403 for somebody the application knows: an
     * ANONYMOUS denial is a login redirect, which is the framework's existing split and
     * is not what this test is about.
     */
    public static function test_abort_403_is_a_403()
    {
        static::__acting_as_user(1);

        $response = static::__browse('/_test/dispatch/abort-403');

        static::__assert_equals(403, $response->getStatusCode());

        static::__reset_session();
    }

    /**
     * The other half of that split, proved here so the 403 test's precondition is not a
     * silent assumption: an anonymous abort(403) is sent to login.
     */
    public static function test_abort_403_sends_an_anonymous_caller_to_login()
    {
        static::__reset_session();

        $response = static::__browse('/_test/dispatch/abort-403');

        static::__assert_equals(302, $response->getStatusCode());
        static::__assert_contains('/login', (string) $response->headers->get('Location'));
    }

    public static function test_any_other_status_keeps_its_status_and_message()
    {
        $response = static::__browse('/_test/dispatch/abort-418');

        static::__assert_equals(418, $response->getStatusCode());
        static::__assert_contains(
            Dispatch_Abort_Fixture_Controller::MESSAGE,
            $response->getContent()
        );
    }

    /**
     * The file routes are fetched by markup, not browsed - a themed HTML document is not
     * something an <img> can use, so that channel gets the bare status and one line.
     */
    public static function test_asset_channel_gets_a_plain_body_not_a_page()
    {
        $response = static::__fetch_as_asset('/_test/dispatch/abort-404');

        static::__assert_equals(404, $response->getStatusCode());
        static::__assert_contains(
            Dispatch_Abort_Fixture_Controller::MESSAGE,
            $response->getContent()
        );
        static::__assert_false(
            str_contains(strtolower((string) $response->getContent()), '<html'),
            'The asset channel must not receive a themed HTML page.'
        );
    }

    /**
     * The catch is narrow on purpose: only HttpExceptionInterface is converted, and
     * everything else reaches the handler chain that is built to report it.
     */
    public static function test_a_non_http_exception_still_propagates()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => static::__browse('/_test/dispatch/throw')
        );
    }
}
