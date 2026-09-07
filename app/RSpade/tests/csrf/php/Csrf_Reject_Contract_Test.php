<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Csrf\Php;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use App\RSpade\Core\Session\Rsx_Csrf;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Csrf_Reject_Contract_Test - the CSRF REJECTION CONTRACT survives the trip to the client,
 * and the @csrf blade directive emits the browser session's one token.
 *
 * Why this class exists (regression, CR 2026_08_04_flash_alert_portal_realm finding 1b):
 * a rejection is not an error - Rsx_Csrf::__reject() builds the response the client must
 * see (ajax contract, or 419) and throws it inside an HttpResponseException. RSX dispatch
 * runs INSIDE Laravel's exception rendering (the 404 -> Rsx_Dispatch_Bootstrapper_Handler
 * seam), so that throw re-enters the handler chain, where every handler renders SOME error
 * surface for a Throwable it does not recognize: Web_Exception_Handler's fatal screen
 * (anywhere outside development+app.debug), Playwright_Exception_Handler's plain-text dump,
 * or an app-registered handler. Any of them turns the documented contract into an HTTP 500.
 * Rsx_Exception_Handler therefore honours an HttpResponseException BEFORE the chain runs;
 * these tests pin that, and pin that BOTH channel spellings (/_ajax and the portal-prefixed
 * <prefix>/_ajax) are recognized as ajax by the reject path test.
 *
 * The end-to-end HTTP proof (real dispatcher, real handler chain, both channels, including the
 * Playwright-header variant that regressed even with app.debug true) lives in
 * tests/csrf/http/csrf_roundtrip.sh - CLI cannot exercise the web exception chain.
 */
class Csrf_Reject_Contract_Test extends Rsx_Test_Abstract
{
    /** Pure-logic: no DB writes, so per-test transactions are unnecessary. */
    protected static $use_database_transactions = false;

    /** Staff internal-endpoint channel. */
    private const AJAX_URI = '/_ajax/Foo_Controller/bar';

    /** Portal internal-endpoint channel in prefix mode (domain mode reuses /_ajax). */
    private const PORTAL_AJAX_URI = '/_portal/_ajax/Foo_Controller/bar';

    /** A native #[Route] path -> reject renders as a bare 419. */
    private const FORM_URI = '/settings/save';

    /**
     * Build a POST request with optional server headers.
     *
     * @param string $uri
     * @param array $server
     * @return Request
     */
    private static function __post(string $uri, array $server = []): Request
    {
        return Request::create($uri, 'POST', [], [], [], $server);
    }

    /**
     * Capture the response Rsx_Csrf rejects a request with.
     *
     * @param string $uri
     * @return \Symfony\Component\HttpFoundation\Response
     */
    private static function __rejection_response(string $uri)
    {
        // A foreign Origin rejects ahead of any session check, so the shape is
        // observable without a session at all.
        $request = static::__post($uri, ['HTTP_ORIGIN' => 'http://evil.example']);

        $exception = static::__assert_throws(HttpResponseException::class, function () use ($request) {
            Rsx_Csrf::enforce($request);
        });

        return $exception->getResponse();
    }

    public static function teardown()
    {
        static::__reset_session();
    }

    // --- The portal channel spelling is recognized as ajax ---

    public static function test_portal_prefixed_ajax_path_gets_the_ajax_contract()
    {
        static::__reset_session();
        $response = static::__rejection_response(self::PORTAL_AJAX_URI);

        static::__assert_equals(200, $response->getStatusCode(), 'portal-prefixed /_ajax reject uses the ajax error contract (HTTP 200)');
        static::__assert_contains('_success', $response->getContent());
        static::__assert_contains('CSRF token mismatch', $response->getContent());
    }

    public static function test_portal_upload_path_gets_the_ajax_contract()
    {
        static::__reset_session();
        $response = static::__rejection_response('/_portal/_upload', true);

        static::__assert_equals(200, $response->getStatusCode(), 'portal-prefixed /_upload reject uses the ajax error contract');
        static::__assert_contains('_success', $response->getContent());
    }

    // --- The handler chain must not embellish the rejection ---

    public static function test_exception_handler_returns_the_ajax_rejection_verbatim()
    {
        static::__reset_session();
        $intended = static::__rejection_response(self::AJAX_URI);

        $rendered = app(ExceptionHandler::class)->render(
            static::__post(self::AJAX_URI),
            new HttpResponseException($intended)
        );

        static::__assert_equals(200, $rendered->getStatusCode(), 'the handler chain must not restatus the ajax rejection');
        static::__assert_equals($intended->getContent(), $rendered->getContent(), 'the handler chain must not rewrite the ajax rejection body');
    }

    public static function test_exception_handler_returns_the_419_rejection_verbatim()
    {
        static::__reset_session();
        $intended = static::__rejection_response(self::FORM_URI);

        $rendered = app(ExceptionHandler::class)->render(
            static::__post(self::FORM_URI),
            new HttpResponseException($intended)
        );

        static::__assert_equals(419, $rendered->getStatusCode(), 'the handler chain must not restatus the native 419 rejection');
        static::__assert_contains('CSRF token mismatch', $rendered->getContent());
    }

    /**
     * The Playwright handler (priority 30) renders ANY unrecognized Throwable as a 500
     * plain-text dump, regardless of debug settings - so this is the variant that pins the
     * fix independently of how the test environment is configured. Before the
     * HttpResponseException short-circuit, an rsx:debug CSRF rejection came back as a 500
     * with a stack trace instead of the ajax contract.
     */
    public static function test_playwright_request_does_not_convert_the_rejection_to_a_dump()
    {
        static::__reset_session();
        $intended = static::__rejection_response(self::AJAX_URI);

        $rendered = app(ExceptionHandler::class)->render(
            static::__post(self::AJAX_URI, ['HTTP_X_PLAYWRIGHT_TEST' => '1']),
            new HttpResponseException($intended)
        );

        static::__assert_equals(200, $rendered->getStatusCode(), 'a Playwright-flagged request must still get the csrf contract, not a 500 dump');
        static::__assert_equals($intended->getContent(), $rendered->getContent(), 'the Playwright handler must not replace the rejection body');
    }

    /**
     * The exact condition the field report hit: outside development+app.debug,
     * Web_Exception_Handler (priority 1100) renders an unrecognized Throwable as the fatal
     * screen - HTTP 500, HTML - which an XHR cannot use. The rejection must outrank it.
     */
    public static function test_rejection_survives_with_app_debug_off()
    {
        static::__reset_session();
        $intended = static::__rejection_response(self::AJAX_URI);

        $original_debug = config('app.debug');
        config(['app.debug' => false]);

        try {
            $rendered = app(ExceptionHandler::class)->render(
                static::__post(self::AJAX_URI),
                new HttpResponseException($intended)
            );
        } finally {
            config(['app.debug' => $original_debug]);
        }

        static::__assert_equals(200, $rendered->getStatusCode(), 'with app.debug off the rejection must NOT become a 500 fatal screen');
        static::__assert_equals($intended->getContent(), $rendered->getContent(), 'with app.debug off the rejection body must survive');
    }

    public static function test_exception_handler_returns_the_portal_rejection_verbatim()
    {
        static::__reset_session();
        $intended = static::__rejection_response(self::PORTAL_AJAX_URI);

        $rendered = app(ExceptionHandler::class)->render(
            static::__post(self::PORTAL_AJAX_URI),
            new HttpResponseException($intended)
        );

        static::__assert_equals(200, $rendered->getStatusCode(), 'the handler chain must not restatus the portal rejection');
        static::__assert_equals($intended->getContent(), $rendered->getContent(), 'the handler chain must not rewrite the portal rejection body');
    }

    // --- @csrf emits the ONE token, with no realm fork ---

    public static function test_csrf_blade_directive_emits_the_one_session_token()
    {
        $compiled = Blade::compileString('@csrf');

        static::__assert_contains('Session::get_csrf_token()', $compiled, '@csrf reads the browser session token');
        static::__assert_contains('name="_csrf_token"', $compiled, '@csrf emits the hidden field the seam reads');

        // One browser, one session, one token: nothing to branch on. A reintroduced
        // fork here would put two tokens back in circulation.
        static::__assert_false(
            str_contains($compiled, 'is_portal_request'),
            '@csrf must not branch on the request experience - there is only one token'
        );
    }

    public static function test_csrf_blade_directive_emits_nothing_without_a_session()
    {
        static::__reset_session();

        // CLI has no session, so the facade returns null and the directive must render
        // an empty string rather than an empty-valued input.
        $rendered = Blade::render('@csrf');

        static::__assert_equals('', trim($rendered), '@csrf emits nothing when there is no session (a session-less POST needs no token)');
    }

    /**
     * The compiled directive must be fully qualified, so a view compiled in any
     * namespace resolves the facade without an alias.
     */
    public static function test_csrf_blade_directive_is_fully_qualified()
    {
        $compiled = Blade::compileString('@csrf');

        static::__assert_contains('App\\RSpade\\Core\\Session\\Session', $compiled, 'the facade is fully qualified');
        static::__assert_false(Session::has_session(), 'sanity: no CLI session leaked into this test');
    }
}
