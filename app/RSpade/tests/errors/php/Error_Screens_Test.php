<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Errors\Php;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Errors\Error_Screens;
use App\RSpade\Core\Exceptions\Web_Exception_Handler;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Error_Screens - the three server-rendered terminal outcomes, and the split that
 * decides between a login redirect and a 403.
 *
 * Behavior of record: php artisan rsx:man auth_gates (ERROR SCREENS).
 */
class Error_Screens_Test extends Rsx_Test_Abstract
{
    // =========================================================================
    // UNAUTHORIZED - THE SPLIT
    // =========================================================================

    /**
     * No session: the caller is sent to login, because a 403 tells someone who
     * has not identified themselves nothing they can act on.
     */
    public static function test_unauthorized_redirects_an_anonymous_caller_to_login()
    {
        static::__reset_session();

        $response = Error_Screens::unauthorized(Request::create('/clients/view/5', 'GET'));

        static::__assert_equals(302, $response->getStatusCode());
        static::__assert_contains('/login', $response->headers->get('Location'));
    }

    /**
     * The intended URL rides along, so the user lands where they were going after
     * authenticating (Login_Redirect owns the validation; this proves the thread).
     */
    public static function test_unauthorized_threads_the_intended_url_through_login_redirect()
    {
        static::__reset_session();

        $response = Error_Screens::unauthorized(Request::create('/clients/view/5', 'GET'));

        static::__assert_contains('redirect=', $response->headers->get('Location'));
        static::__assert_contains('clients', $response->headers->get('Location'));
    }

    /**
     * Authenticated but denied: a genuine 403 page. Re-authenticating cannot fix
     * this, so bouncing to login would be a loop with extra steps.
     */
    public static function test_unauthorized_renders_a_themed_403_for_an_authenticated_caller()
    {
        $user_id = static::__first_user_id();
        if ($user_id === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        static::__acting_as_user($user_id);

        $response = Error_Screens::unauthorized(Request::create('/clients/view/5', 'GET'));

        static::__assert_equals(403, $response->getStatusCode());
        static::__assert_contains('Access Denied', $response->getContent());
        static::__assert_contains('ERROR 403', $response->getContent());

        static::__reset_session();
    }

    /**
     * A caller that knows its realm says so, and gets that realm's login route -
     * the portal dispatcher never has to hope the ambient request detection
     * agrees with it.
     */
    public static function test_unauthorized_honors_an_explicit_portal_realm()
    {
        static::__reset_session();

        $response = Error_Screens::unauthorized(
            Request::create('/dashboard', 'GET'),
            Auth_Gates::REALM_PORTAL
        );

        static::__assert_equals(302, $response->getStatusCode());

        $location = $response->headers->get('Location');
        static::__assert_contains('login', $location);
        static::__assert_contains('_portal', $location);
    }

    // =========================================================================
    // NOT FOUND
    // =========================================================================

    /**
     * A themed body with the right status - not Laravel's unthemed default.
     */
    public static function test_not_found_renders_a_themed_404()
    {
        $response = Error_Screens::not_found(Request::create('/no-such-url', 'GET'));

        static::__assert_equals(404, $response->getStatusCode());
        static::__assert_contains('Page Not Found', $response->getContent());
        static::__assert_contains('ERROR 404', $response->getContent());
    }

    /**
     * The dispatcher's own no-route path produces that page, so an unmatched URL
     * is a terminal outcome RSpade renders rather than one it hands back to
     * Laravel. Main::unhandled_route still declines first (nothing in this
     * template claims the URL).
     */
    public static function test_dispatcher_renders_the_screen_for_an_unmatched_url()
    {
        $url = '/no-such-url-for-the-error-screens-test';

        // The no-route path reads the AMBIENT request (request()->all()) for the
        // Main hooks, so bind one - a sibling test class may have forgotten the
        // container's instance.
        app()->instance('request', Request::create($url, 'GET'));

        $response = Dispatcher::dispatch($url, 'GET', [], Request::create($url, 'GET'));

        static::__assert_not_empty($response);
        static::__assert_equals(404, $response->getStatusCode());
        static::__assert_contains('Page Not Found', $response->getContent());
    }

    // =========================================================================
    // FATAL - AND THE REDACTION RULE
    // =========================================================================

    /**
     * Outside production the page carries the detail a developer needs.
     */
    public static function test_fatal_renders_detail_outside_production()
    {
        static::__with_mode(Rsx::MODE_DEVELOPMENT, function () {
            $e = new RuntimeException('probe exception message');

            $response = Error_Screens::fatal(Request::create('/anything', 'GET'), $e);
            $content = $response->getContent();

            static::__assert_equals(500, $response->getStatusCode());
            static::__assert_contains('probe exception message', $content);
            static::__assert_contains('RuntimeException', $content);
            static::__assert_contains('Error_Screens_Test.php', $content);
        });
    }

    /**
     * Production renders NOTHING about the exception - not the message, not the
     * class, not the file. An error page is fully inspectable with curl, so the
     * detail must never leave the server (same rule as the ajax channel).
     */
    public static function test_fatal_redacts_everything_in_production()
    {
        static::__with_mode(Rsx::MODE_PRODUCTION, function () {
            $e = new RuntimeException('probe exception message');

            $response = Error_Screens::fatal(Request::create('/anything', 'GET'), $e);
            $content = $response->getContent();

            static::__assert_equals(500, $response->getStatusCode());
            static::__assert_true(
                !str_contains($content, 'probe exception message'),
                'the exception message must not reach a production error page'
            );
            static::__assert_true(
                !str_contains($content, 'RuntimeException'),
                'the exception class must not reach a production error page'
            );
            static::__assert_true(
                !str_contains($content, 'Error_Screens_Test.php'),
                'the exception origin must not reach a production error page'
            );
            static::__assert_contains('Something Went Wrong', $content);
        });
    }

    /**
     * Debug mode is production for redaction purposes - it is a sealed, shipped
     * build, and it agrees with Ajax_Exception_Handler's predicate.
     */
    public static function test_fatal_redacts_in_debug_mode_too()
    {
        static::__with_mode(Rsx::MODE_DEBUG, function () {
            $response = Error_Screens::fatal(
                Request::create('/anything', 'GET'),
                new RuntimeException('probe exception message')
            );

            static::__assert_true(
                !str_contains($response->getContent(), 'probe exception message'),
                'debug-mode builds redact with production'
            );
        });
    }

    /**
     * fatal() is callable without an exception (a failure with no Throwable in
     * hand still needs a page).
     */
    public static function test_fatal_renders_without_an_exception()
    {
        $response = Error_Screens::fatal(Request::create('/anything', 'GET'));

        static::__assert_equals(500, $response->getStatusCode());
        static::__assert_contains('Something Went Wrong', $response->getContent());
    }

    // =========================================================================
    // THE EXCEPTION-CHAIN SEAM
    // =========================================================================

    /**
     * An abort(404) raised by application code lands on the same screen the
     * dispatcher renders - a page never depends on WHICH layer decided.
     */
    public static function test_handler_maps_a_404_http_exception_to_the_screen()
    {
        $handler = new Web_Exception_Handler();

        $response = $handler->handle(
            new NotFoundHttpException('nothing here'),
            Request::create('/anything', 'GET')
        );

        static::__assert_not_empty($response);
        static::__assert_equals(404, $response->getStatusCode());
        static::__assert_contains('Page Not Found', $response->getContent());
    }

    /**
     * abort(403) goes through the unauthorized split, so an anonymous caller is
     * still offered the login it needs.
     */
    public static function test_handler_maps_a_403_http_exception_through_the_split()
    {
        static::__reset_session();

        $handler = new Web_Exception_Handler();

        $response = $handler->handle(
            new HttpException(403, 'denied'),
            Request::create('/clients/view/5', 'GET')
        );

        static::__assert_not_empty($response);
        static::__assert_equals(302, $response->getStatusCode());
        static::__assert_contains('/login', $response->headers->get('Location'));
    }

    /**
     * Statuses Error_Screens does not define keep their own meaning and their own
     * Laravel view.
     */
    public static function test_handler_declines_other_http_statuses()
    {
        $handler = new Web_Exception_Handler();

        static::__assert_null($handler->handle(
            new HttpException(429, 'slow down'),
            Request::create('/anything', 'GET')
        ));
    }

    /**
     * Development with app.debug on keeps the interactive debug error page: the
     * themed screen would be a downgrade while writing code.
     */
    public static function test_handler_declines_in_development_with_debug_on()
    {
        $original_debug = config('app.debug');
        config(['app.debug' => true]);

        try {
            static::__with_mode(Rsx::MODE_DEVELOPMENT, function () {
                $handler = new Web_Exception_Handler();

                static::__assert_null($handler->handle(
                    new RuntimeException('probe exception message'),
                    Request::create('/anything', 'GET')
                ));
            });
        } finally {
            config(['app.debug' => $original_debug]);
        }
    }

    /**
     * A production build renders the redacted screen instead of whatever Laravel
     * would have shown.
     */
    public static function test_handler_renders_the_fatal_screen_in_production()
    {
        static::__with_mode(Rsx::MODE_PRODUCTION, function () {
            $handler = new Web_Exception_Handler();

            $response = $handler->handle(
                new RuntimeException('probe exception message'),
                Request::create('/anything', 'GET')
            );

            static::__assert_not_empty($response);
            static::__assert_equals(500, $response->getStatusCode());
            static::__assert_true(
                !str_contains($response->getContent(), 'probe exception message'),
                'production redaction applies through the handler too'
            );
        });
    }

    /**
     * The handler runs AFTER the dispatch bootstrapper. If it ever claimed a 404
     * first, every RSX route would go offline - RSX routing IS a Laravel 404 the
     * bootstrapper catches.
     */
    public static function test_handler_runs_after_the_dispatch_bootstrapper()
    {
        static::__assert_greater_than(
            \App\RSpade\Core\Providers\Rsx_Dispatch_Bootstrapper_Handler::get_priority(),
            Web_Exception_Handler::get_priority()
        );

        static::__assert_true(
            in_array(Web_Exception_Handler::class, config('rsx.exception_handlers', []), true),
            'the handler must be registered in config/rsx.php exception_handlers'
        );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Run a closure with the application mode forced, restoring it afterwards.
     */
    private static function __with_mode(string $mode, callable $fn): void
    {
        Rsx::_testing_set_mode($mode);

        try {
            $fn();
        } finally {
            Rsx::clear_mode_cache();
        }
    }

    /**
     * The lowest User_Model id in the test database, or null when there is none.
     */
    private static function __first_user_id(): ?int
    {
        $user = User_Model::without_site_scope(function () {
            return User_Model::orderBy('id')->first();
        });

        return $user ? (int) $user->id : null;
    }
}
