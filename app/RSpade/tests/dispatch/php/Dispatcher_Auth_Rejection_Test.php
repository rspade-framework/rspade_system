<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dispatch\Php;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Dispatch\Php\Dispatch_Page_Fixture_Controller;

/**
 * Dispatcher auth-rejection surface - the two adjacent bugs on the full-page path.
 *
 * B4.4: __call_pre_dispatch used to wrap the non-controller static pre_dispatch
 * invocation in a blanket try/catch that logged and returned null. Returning null
 * means "hook passed, proceed to the action", so a pre_dispatch that THROWS to deny
 * access was downgraded to a log line and the request proceeded AS IF AUTHORIZED.
 * The fix removes the swallow; a throwing hook now propagates.
 *
 * B4.6 (closes B-31): __handle_special_response (the full-page path, NOT the ajax
 * path) only redirected when Error_Response carried a redirect url - but
 * Error_Response hard-codes redirect = null, so both auth error types hit
 * `throw new Exception($reason)` -> HTTP 500 for every unauthorized full-page GET.
 * The fix routes: not-authenticated -> a login redirect threading the intended URL
 * via Login_Redirect; authenticated-but-forbidden -> a genuine 403. Both terminal
 * outcomes are produced by Error_Screens now, so the 403 arrives as a rendered
 * page rather than an abort() exception (same status, real body).
 *
 * The ajax/API channel is separate (Ajax::_handle_special_response throws
 * AjaxUnauthorizedException / AjaxAuthRequiredException -> JSON error_code); the
 * last test pins that the two channels diverge for the SAME rejection.
 */
class Dispatcher_Auth_Rejection_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        Rsx_Portal::_clear_cache();
    }

    public static function teardown()
    {
        // Rebind a fresh request rather than forgetInstance(): an UNBOUND 'request'
        // makes every later test whose code path calls request() fatal with
        // "Target class [request] does not exist" (Rsx_Session_Cookie::is_secure()
        // is one such path). The container must always hold SOME request.
        app()->instance('request', \Illuminate\Http\Request::create('/'));
        Rsx_Portal::_clear_cache();
        static::__reset_session();
    }

    // ---------------------------------------------------------------------
    // Reflection accessors for the two protected methods under test.
    // ---------------------------------------------------------------------

    private static function __invoke_pre_dispatch(string $class_name)
    {
        $params = ['_handler' => $class_name];
        $method = new ReflectionMethod(Dispatcher::class, '__call_pre_dispatch');
        $method->setAccessible(true);
        $args = [$class_name, 'index', &$params, request()];

        return $method->invokeArgs(null, $args);
    }

    private static function __invoke_handle_special_response(Error_Response $response)
    {
        $method = new ReflectionMethod(Dispatcher::class, '__handle_special_response');
        $method->setAccessible(true);

        return $method->invokeArgs(null, [$response]);
    }

    private static function __bind_page_request(string $uri = Dispatch_Page_Fixture_Controller::PAGE): void
    {
        app()->instance('request', Request::create($uri, 'GET'));
        Rsx_Portal::_clear_cache();
    }

    // =====================================================================
    // B4.4 - a throwing non-controller pre_dispatch is NOT swallowed
    // =====================================================================

    public static function test_pre_dispatch_denial_exception_propagates()
    {
        static::__bind_page_request();

        // A non-controller handler (not a Rsx_Controller_Abstract subclass, so it
        // is not in the manifest) whose static pre_dispatch throws to deny access.
        $handler = new class {
            public static function pre_dispatch(Request $request, array $params = [])
            {
                throw new \RuntimeException('DENIED_BY_HOOK');
            }
        };

        $handler_class = get_class($handler);

        // Before the fix this returned null (swallowed) and dispatch proceeded to
        // the action as if authorized. It must now throw.
        static::__assert_throws(
            \RuntimeException::class,
            function () use ($handler_class) {
                static::__invoke_pre_dispatch($handler_class);
            },
            'DENIED_BY_HOOK'
        );
    }

    public static function test_pre_dispatch_null_still_proceeds()
    {
        static::__bind_page_request();

        // A non-controller handler whose pre_dispatch returns null must produce
        // null ("proceed to the action") - the normal path is intact.
        $handler = new class {
            public static function pre_dispatch(Request $request, array $params = [])
            {
                return null;
            }
        };

        static::__assert_null(
            static::__invoke_pre_dispatch(get_class($handler)),
            'A null-returning pre_dispatch must let dispatch proceed'
        );
    }

    // =====================================================================
    // B4.6 - full-page auth rejection routing (no more 500)
    // =====================================================================

    public static function test_auth_required_full_page_redirects_to_login_with_intended_url()
    {
        static::__reset_session();
        static::__bind_page_request(Dispatch_Page_Fixture_Controller::PAGE);

        $result = static::__invoke_handle_special_response(new Error_Response(Ajax::ERROR_AUTH_REQUIRED));

        static::__assert_instance_of(
            RedirectResponse::class,
            $result,
            'AUTH_REQUIRED on a full-page GET must 302 to login, not 500'
        );

        $target = $result->getTargetUrl();
        static::__assert_contains('/login', $target, 'redirect target must be the login route');
        static::__assert_contains('redirect=', $target, 'the intended URL must be threaded as ?redirect=');
        static::__assert_contains(
            Dispatch_Page_Fixture_Controller::PAGE_ENCODED,
            $target,
            'the intended path must be captured'
        );
    }

    public static function test_unauthorized_logged_out_redirects_to_login()
    {
        static::__reset_session();
        static::__bind_page_request(Dispatch_Page_Fixture_Controller::PAGE);

        // Sanity: the branch is gated on !Session::is_logged_in().
        static::__assert_false(Session::is_logged_in(), 'precondition: logged out');

        $result = static::__invoke_handle_special_response(new Error_Response(Ajax::ERROR_UNAUTHORIZED));

        static::__assert_instance_of(
            RedirectResponse::class,
            $result,
            'UNAUTHORIZED while logged out must 302 to login'
        );
        static::__assert_contains('/login', $result->getTargetUrl());
    }

    public static function test_unauthorized_logged_in_is_403_not_redirect()
    {
        $user = DB::selectOne('SELECT id, site_id FROM users ORDER BY id LIMIT 1');
        if (!$user) {
            static::__skip('no seeded user to establish a logged-in session');

            return;
        }

        Session::set_site_id((int) $user->site_id);
        static::__acting_as_user((int) $user->id);
        static::__bind_page_request(Dispatch_Page_Fixture_Controller::PAGE);

        static::__assert_true(Session::is_logged_in(), 'precondition: logged in');

        // Authenticated but lacking permission is a genuine 403 - not a login
        // redirect (re-authenticating cannot fix it) and not a 500. Since
        // Error_Screens landed it is a RENDERED 403 page rather than an abort()
        // exception: same status, an actual body instead of Laravel's default.
        $result = static::__invoke_handle_special_response(new Error_Response(Ajax::ERROR_UNAUTHORIZED));

        static::__assert_equals(403, $result->getStatusCode(), 'must answer with 403');
        static::__assert_contains('Access Denied', $result->getContent());
    }

    // =====================================================================
    // Channel split - the ajax path is unchanged (JSON contract, no redirect)
    // =====================================================================

    public static function test_ajax_channel_throws_unauthorized_not_redirect()
    {
        static::__reset_session();
        static::__bind_page_request(Dispatch_Page_Fixture_Controller::PAGE);

        // The SAME rejection through the ajax handler yields the error_code JSON
        // contract (via the thrown exception the Ajax_Exception_Handler formats),
        // never a 302 - pinning the full-page vs ajax channel split.
        $method = new ReflectionMethod(Ajax::class, '_handle_special_response');
        $method->setAccessible(true);

        static::__assert_throws(
            AjaxUnauthorizedException::class,
            function () use ($method) {
                $method->invokeArgs(null, [new Error_Response(Ajax::ERROR_UNAUTHORIZED)]);
            }
        );
    }
}
