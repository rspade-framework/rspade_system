<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dispatch\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Exceptions\AjaxNotFoundException;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Exceptions\Web_Exception_Handler;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Dispatch\Php\Ajax_Parity_Fixture_Controller;

/**
 * The coded exception family on a PAGE request is the page its code deserves - never the
 * 500 it used to be.
 *
 * AjaxUnauthorizedException is what Permission::require_permission() and the guarded
 * Session::terminate_*() calls THROW. On a page it is the unauthorized screen: the login
 * redirect for an anonymous caller, a 403 for a signed-in one - the same answer a returned
 * response_unauthorized() gets, at the dispatcher's seam and in the page policy alike. (On
 * the AJAX channel the same exception is the 'unauthorized' envelope:
 * Ajax_Transport_Parity_Test.)
 *
 * Behavior of record: php artisan rsx:man error_handling.
 */
class Coded_Failure_Page_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = true;

    public static function teardown()
    {
        // The container must hold an ordinary console request again: a later class reads
        // the ambient request (its peer address decides who sees diagnostics).
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
        Ajax_Parity_Fixture_Controller::$invocations = 0;
    }

    public static function test_a_thrown_denial_sends_an_anonymous_caller_to_login()
    {
        static::__reset_session();

        $response = static::__handle('/_test/front/thrown-denial');

        static::__assert_equals(302, $response->getStatusCode());
        static::__assert_contains('/login', (string) $response->headers->get('Location'));
    }

    public static function test_a_thrown_denial_is_a_403_for_a_signed_in_caller()
    {
        static::__reset_session();
        static::__acting_as_user(1);

        $response = static::__handle('/_test/front/thrown-denial');

        static::__assert_equals(403, $response->getStatusCode());
    }

    /**
     * The page policy gives the same answer for a coded exception raised outside the
     * dispatcher's seam: 403 for a denial, 404 for a missing record.
     */
    public static function test_the_page_policy_maps_the_coded_family()
    {
        static::__reset_session();
        static::__acting_as_user(1);

        $request = Request::create('/_test/front/anything', 'GET');
        Rsx_Request_Channel::classify($request);
        $handler = new Web_Exception_Handler();

        static::__assert_equals(403, $handler->handle(new AjaxUnauthorizedException('denied'), $request)->getStatusCode());
        static::__assert_equals(404, $handler->handle(new AjaxNotFoundException('gone'), $request)->getStatusCode());
    }

    /**
     * A HEAD request's failure keeps its status and headers and carries no body.
     */
    public static function test_a_head_failure_has_no_body()
    {
        static::__reset_session();
        static::__acting_as_user(1);

        $response = static::__handle('/_test/front/thrown-denial', 'HEAD');

        static::__assert_equals(403, $response->getStatusCode());
        static::__assert_equals('', (string) $response->getContent());
    }

    private static function __handle(string $url, string $method = 'GET')
    {
        $request = Request::create($url, $method);
        app()->instance('request', $request);

        return Rsx_Front_Controller::handle($request);
    }
}
