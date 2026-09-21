<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Errors\Php;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Errors\Error_Pages;
use App\RSpade\Core\Errors\Error_Screens;
use App\RSpade\Core\Exceptions\Web_Exception_Handler;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Rsx_Csrf;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Errors\Php\Error_Pages_Fixture_Controller;

/**
 * Application-defined error pages: which route answers a status, what the funnel
 * does with it, and what happens when it fails.
 *
 * Behavior of record: php artisan rsx:man error_pages.
 *
 * The RESOLUTION tests drive Error_Pages::resolve() against synthetic route
 * tables spliced into the live manifest and restored afterwards. The RENDERING
 * tests replace resolution wholesale with Error_Pages::_testing_set_resolver()
 * pointing at Error_Pages_Fixture_Controller, because a fixture declared under
 * /error/ would collide with the application's own pages while the suite is
 * indexed.
 */
class Error_Pages_Test extends Rsx_Test_Abstract
{
    /** Pure request/response work; nothing is written. */
    protected static $use_database_transactions = false;

    /** A native (non-ajax) POST path, for the CSRF rejection. */
    private const FORM_URI = '/settings/save';

    public static function teardown()
    {
        Error_Pages::_testing_set_resolver(null);
        static::__reset_session();
    }

    // =========================================================================
    // RESOLUTION
    // =========================================================================

    /**
     * The exact page for the status wins over the catch-all.
     */
    public static function test_resolution_prefers_the_exact_status_page()
    {
        static::__with_routes(
            ['/error/404' => static::__row('standard', 'exact'), '/error/generic' => static::__row('standard', 'generic')],
            [],
            function () {
                $match = Error_Pages::resolve(404, Auth_Gates::REALM_STAFF);

                static::__assert_not_empty($match);
                static::__assert_equals('/error/404', $match['pattern']);
                static::__assert_equals('exact', $match['method']);
            }
        );
    }

    /**
     * A status with no page of its own falls to the catch-all.
     */
    public static function test_resolution_falls_to_the_generic_page()
    {
        static::__with_routes(
            ['/error/generic' => static::__row('standard', 'generic')],
            [],
            function () {
                $match = Error_Pages::resolve(418, Auth_Gates::REALM_STAFF);

                static::__assert_not_empty($match);
                static::__assert_equals('/error/generic', $match['pattern']);
            }
        );
    }

    /**
     * The generic preview asks for the catch-all BY NAME, so the exact page is
     * not consulted even when it exists.
     */
    public static function test_resolution_skips_the_exact_page_when_asked_for_generic()
    {
        static::__with_routes(
            ['/error/500' => static::__row('standard', 'exact'), '/error/generic' => static::__row('standard', 'generic')],
            [],
            function () {
                $match = Error_Pages::resolve(500, Auth_Gates::REALM_STAFF, false);

                static::__assert_equals('/error/generic', $match['pattern']);
            }
        );
    }

    /**
     * A portal failure reads the portal table first - a staff page carries the
     * staff bundle and cannot render a portal link.
     */
    public static function test_resolution_reads_the_portal_table_for_a_portal_failure()
    {
        static::__with_routes(
            ['/error/404' => static::__row('standard', 'staff_page')],
            ['/error/404' => static::__row('portal', 'portal_page')],
            function () {
                $match = Error_Pages::resolve(404, Auth_Gates::REALM_PORTAL);

                static::__assert_equals('portal_page', $match['method']);
            }
        );
    }

    /**
     * A portal with no page of its own falls to the staff pair: a page somebody
     * designed beats the framework's own.
     */
    public static function test_resolution_falls_from_the_portal_to_the_staff_pair()
    {
        static::__with_routes(
            ['/error/generic' => static::__row('standard', 'staff_generic')],
            [],
            function () {
                $match = Error_Pages::resolve(404, Auth_Gates::REALM_PORTAL);

                static::__assert_equals('staff_generic', $match['method']);
            }
        );
    }

    /**
     * Nothing declared resolves to null, and the framework page renders.
     */
    public static function test_resolution_answers_null_when_nothing_is_declared()
    {
        static::__with_routes([], [], function () {
            static::__assert_null(Error_Pages::resolve(404, Auth_Gates::REALM_STAFF));
        });
    }

    // =========================================================================
    // THE FUNNEL
    // =========================================================================

    /**
     * The application page renders, and the framework forces the status onto it -
     * a page that answered 200 would be a lie to every caller.
     */
    public static function test_the_funnel_renders_the_application_page_with_the_forced_status()
    {
        static::__resolve_to('page');

        $response = Error_Screens::not_found(Request::create('/no-such-url', 'GET'));

        static::__assert_equals(404, $response->getStatusCode());
        static::__assert_contains(Error_Pages_Fixture_Controller::MARKER, $response->getContent());
        static::__assert_contains('status=404', $response->getContent());
        static::__assert_contains('title=Page Not Found', $response->getContent());
    }

    /**
     * The error page for the error page must not be a third error: a page that
     * throws is replaced by the framework's own.
     */
    public static function test_a_page_that_throws_falls_back_to_the_framework_page()
    {
        static::__resolve_to('throws');

        $response = Error_Screens::not_found(Request::create('/no-such-url', 'GET'));

        static::__assert_equals(404, $response->getStatusCode());
        static::__assert_contains('Page Not Found', $response->getContent());
        static::__assert_true(
            !str_contains($response->getContent(), Error_Pages_Fixture_Controller::MARKER),
            'the failing page must not contribute to the fallback body'
        );
    }

    /**
     * A coded response is a page failure too: routing it onward would call back
     * into Error_Screens and recurse.
     */
    public static function test_a_page_returning_a_coded_response_falls_back()
    {
        static::__resolve_to('coded');

        $response = Error_Screens::not_found(Request::create('/no-such-url', 'GET'));

        static::__assert_equals(404, $response->getStatusCode());
        static::__assert_contains('Page Not Found', $response->getContent());
    }

    /**
     * An error page is a document like any other and carries a policy - it is
     * reached by the exception chain as often as by the dispatcher, and only the
     * funnel is on both paths.
     */
    public static function test_the_funnel_stamps_a_content_security_policy()
    {
        static::__resolve_to('page');

        $response = Error_Screens::not_found(Request::create('/no-such-url', 'GET'));

        static::__assert_not_empty($response->headers->get('Content-Security-Policy'));
    }

    // =========================================================================
    // WHAT REACHES THE FUNNEL
    // =========================================================================

    /**
     * A CSRF failure on a native form POST is a 419 PAGE, not a line of text.
     */
    public static function test_a_native_csrf_rejection_renders_the_expired_page()
    {
        static::__no_application_pages();
        static::__reset_session();

        $request = Request::create(self::FORM_URI, 'POST', [], [], [], ['HTTP_ORIGIN' => 'http://evil.example']);

        $exception = static::__assert_throws(HttpResponseException::class, function () use ($request) {
            Rsx_Csrf::enforce($request);
        });

        $response = $exception->getResponse();

        static::__assert_equals(419, $response->getStatusCode());
        static::__assert_contains('Page Expired', $response->getContent());
    }

    /**
     * response_not_found() from a web GET renders the 404 page. It used to throw,
     * so a missing record was reported as a crash.
     */
    public static function test_a_get_route_returning_not_found_renders_the_404_page()
    {
        static::__no_application_pages();

        $url = '/test-error-pages/missing-record';
        app()->instance('request', Request::create($url, 'GET'));

        $response = Dispatcher::dispatch($url, 'GET', [], Request::create($url, 'GET'));

        static::__assert_equals(404, $response->getStatusCode());
        static::__assert_contains('Page Not Found', $response->getContent());
    }

    /**
     * response_form_error() from a web GET renders the 400 page carrying the
     * reason the endpoint gave - there is no form to return the field errors to.
     */
    public static function test_a_get_route_returning_a_form_error_renders_the_400_page()
    {
        static::__no_application_pages();

        $url = '/test-error-pages/rejected';
        app()->instance('request', Request::create($url, 'GET'));

        $response = Dispatcher::dispatch($url, 'GET', [], Request::create($url, 'GET'));

        static::__assert_equals(400, $response->getStatusCode());
        static::__assert_contains(Error_Pages_Fixture_Controller::VALIDATION_REASON, $response->getContent());
    }

    /**
     * abort() with a status no entry point names still gets a page, carrying its
     * own status - nothing is left to Laravel's stock error views.
     */
    public static function test_the_handler_renders_a_page_for_any_http_status()
    {
        static::__no_application_pages();

        $handler = new Web_Exception_Handler();

        $expired = $handler->handle(new HttpException(419, ''), Request::create('/anything', 'GET'));
        static::__assert_equals(419, $expired->getStatusCode());
        static::__assert_contains('Page Expired', $expired->getContent());

        $teapot = $handler->handle(new HttpException(418, ''), Request::create('/anything', 'GET'));
        static::__assert_equals(418, $teapot->getStatusCode());
        static::__assert_contains('teapot', $teapot->getContent());
    }

    // =========================================================================
    // THE DEVELOPMENT PREVIEW
    // =========================================================================

    /**
     * Browsing /error/<code> in development renders that page as it would look.
     */
    public static function test_the_preview_renders_the_page_in_development()
    {
        static::__resolve_to('page');

        static::__with_mode(Rsx::MODE_DEVELOPMENT, function () {
            $url = '/error/404';
            app()->instance('request', Request::create($url, 'GET'));

            $response = Dispatcher::dispatch($url, 'GET', [], Request::create($url, 'GET'));

            static::__assert_equals(404, $response->getStatusCode());
            static::__assert_contains(Error_Pages_Fixture_Controller::MARKER, $response->getContent());
            static::__assert_contains('preview=yes', $response->getContent());
        });
    }

    /**
     * A sealed build has no preview: /error/500 is an unknown URL like any other,
     * so it answers 404 rather than the 500 page a development browse shows.
     */
    public static function test_the_preview_is_a_404_in_a_sealed_build()
    {
        static::__no_application_pages();

        static::__with_mode(Rsx::MODE_PRODUCTION, function () {
            $url = '/error/500';
            app()->instance('request', Request::create($url, 'GET'));

            $response = Dispatcher::dispatch($url, 'GET', [], Request::create($url, 'GET'));

            static::__assert_equals(404, $response->getStatusCode());
            static::__assert_contains('Page Not Found', $response->getContent());
            static::__assert_true(
                !str_contains($response->getContent(), Error_Pages_Fixture_Controller::MARKER),
                'a sealed build serves no preview'
            );
        });
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Point resolution at one method of the fixture controller.
     */
    private static function __resolve_to(string $method): void
    {
        Error_Pages::_testing_set_resolver(function () use ($method) {
            return [
                'class' => Error_Pages_Fixture_Controller::class,
                'method' => $method,
                'pattern' => '/error/fixture',
                'surface' => 'Error_Pages_Fixture_Controller::' . $method,
            ];
        });
    }

    /**
     * Resolve to nothing, so the framework's own page is what renders.
     *
     * The seam is class-scoped (teardown runs once per class), so a test that
     * depends on there being no application page says so itself.
     */
    private static function __no_application_pages(): void
    {
        Error_Pages::_testing_set_resolver(function () {
            return null;
        });
    }

    /**
     * One synthetic route row.
     */
    private static function __row(string $type, string $method): array
    {
        return [
            'type' => $type,
            'class' => Error_Pages_Fixture_Controller::class,
            'method' => $method,
            'surface' => 'Error_Pages_Fixture_Controller::' . $method,
        ];
    }

    /**
     * Run a closure with both realms' route tables replaced, restoring them
     * afterwards. resolve() reads the live manifest, so this is what lets the
     * resolution chain be provoked in isolation.
     */
    private static function __with_routes(array $staff, array $portal, callable $fn): void
    {
        Error_Pages::_testing_set_resolver(null);

        $manifest = &Manifest::get_full_manifest();

        $saved_staff = $manifest['data']['routes'] ?? [];
        $saved_portal = $manifest['data']['portal_routes'] ?? [];

        $manifest['data']['routes'] = $staff;
        $manifest['data']['portal_routes'] = $portal;

        try {
            $fn();
        } finally {
            $manifest['data']['routes'] = $saved_staff;
            $manifest['data']['portal_routes'] = $saved_portal;
        }
    }

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
}
