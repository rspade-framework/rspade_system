<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Csrf\Php;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use App\RSpade\Core\Session\Rsx_Csrf;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Csrf_Enforce_Test - the Rsx_Csrf::enforce() decision matrix, exercised in-process by
 * constructing an Illuminate Request and calling enforce() directly (the same call both
 * dispatchers make at their POST seam - IDENTICALLY, since one browser has one session
 * and therefore one csrf_token).
 *
 * Coverage split (a deliberate architectural constraint, not a gap):
 *   - The ORIGIN/REFERER gate, the NO-SESSION allow, and the SESSION-PRESENT reject
 *     branches are all expressible here: __acting_as_site() makes Session::has_session()
 *     true, and under CLI Session::$_session is null so verify_csrf_token() returns false
 *     for ANY token - which is exactly the reject path we assert.
 *   - The "session present + a VALID token -> ACCEPT" path CANNOT be expressed in CLI
 *     (verify_csrf_token() can never return true without a real _sessions row/cookie), so
 *     it is covered by tests/csrf/http/csrf_roundtrip.sh instead.
 *
 * Session CLI overrides are process statics and setup()/teardown() run once per class, so
 * each test resets session state itself for order-independence.
 */
class Csrf_Enforce_Test extends Rsx_Test_Abstract
{
    /** Pure-logic: no DB writes, so per-test transactions are unnecessary. */
    protected static $use_database_transactions = false;

    /** An /_ajax path -> reject renders as the ajax error contract (200 + _success:false). */
    private const AJAX_URI = '/_ajax/Foo_Controller/bar';

    /** A native #[Route] path -> reject renders as a bare 419. */
    private const FORM_URI = '/settings/save';

    /**
     * Build a POST request with optional body params and server headers.
     *
     * @param string $uri
     * @param array $body
     * @param array $server  e.g. ['HTTP_ORIGIN' => '...', 'HTTP_X_CSRF_TOKEN' => '...']
     * @return Request
     */
    private static function __post(string $uri, array $body = [], array $server = []): Request
    {
        return Request::create($uri, 'POST', $body, [], [], $server);
    }

    /**
     * Clear session context after the class so no other concern inherits it.
     */
    public static function teardown()
    {
        Session::reset_impersonation();
    }

    // --- Origin/Referer gate (applies to every POST, session or not) ---

    public static function test_foreign_origin_rejected()
    {
        static::__reset_session();
        $request = static::__post(self::AJAX_URI, [], ['HTTP_ORIGIN' => 'http://evil.example']);
        static::__assert_throws(HttpResponseException::class, function () use ($request) {
            Rsx_Csrf::enforce($request);
        });
    }

    public static function test_foreign_referer_rejected()
    {
        static::__reset_session();
        // No Origin header -> the check falls to Referer, whose host must also match.
        $request = static::__post(self::AJAX_URI, [], ['HTTP_REFERER' => 'http://evil.example/page']);
        static::__assert_throws(HttpResponseException::class, function () use ($request) {
            Rsx_Csrf::enforce($request);
        });
    }

    public static function test_matching_origin_no_session_allowed()
    {
        static::__reset_session();
        // getHost() defaults to 'localhost' for a constructed request.
        $request = static::__post(self::AJAX_URI, [], ['HTTP_ORIGIN' => 'http://localhost']);
        Rsx_Csrf::enforce($request);
        static::__assert_true(true, 'same-origin POST with no session is allowed');
    }

    public static function test_no_origin_no_referer_no_session_allowed()
    {
        static::__reset_session();
        // Neither header present -> not a cross-site browser POST (curl / server-to-server).
        $request = static::__post(self::AJAX_URI);
        Rsx_Csrf::enforce($request);
        static::__assert_true(true, 'header-less POST with no session is allowed (non-browser caller)');
    }

    // --- Session-gated synchronizer token ---

    public static function test_session_present_missing_token_rejected()
    {
        static::__reset_session();
        static::__acting_as_site(1); // Session::has_session() -> true
        $request = static::__post(self::AJAX_URI, [], ['HTTP_ORIGIN' => 'http://localhost']);
        static::__assert_throws(HttpResponseException::class, function () use ($request) {
            Rsx_Csrf::enforce($request);
        });
        static::__reset_session();
    }

    public static function test_session_present_bad_token_rejected()
    {
        static::__reset_session();
        static::__acting_as_site(1);
        $request = static::__post(self::AJAX_URI, ['_csrf_token' => 'not-the-real-token'], ['HTTP_ORIGIN' => 'http://localhost']);
        static::__assert_throws(HttpResponseException::class, function () use ($request) {
            Rsx_Csrf::enforce($request);
        });
        static::__reset_session();
    }

    // --- Reject shape depends on the target path ---

    public static function test_ajax_reject_is_json_200_contract()
    {
        static::__reset_session();
        static::__acting_as_site(1);
        $request = static::__post(self::AJAX_URI, [], ['HTTP_ORIGIN' => 'http://localhost']);
        $exception = static::__assert_throws(HttpResponseException::class, function () use ($request) {
            Rsx_Csrf::enforce($request);
        });
        $response = $exception->getResponse();
        static::__assert_equals(200, $response->getStatusCode(), 'ajax csrf reject uses the ajax error contract (HTTP 200)');
        static::__assert_contains('_success', $response->getContent());
        static::__reset_session();
    }

    public static function test_native_form_reject_is_419()
    {
        static::__reset_session();
        static::__acting_as_site(1);
        $request = static::__post(self::FORM_URI, [], ['HTTP_ORIGIN' => 'http://localhost']);
        $exception = static::__assert_throws(HttpResponseException::class, function () use ($request) {
            Rsx_Csrf::enforce($request);
        });
        static::__assert_equals(419, $exception->getResponse()->getStatusCode(), 'native full-page csrf reject uses HTTP 419');
        static::__reset_session();
    }

    // --- The portal dispatch path is the SAME call ---

    /**
     * Portal_Dispatcher's POST seam calls enforce() with no realm argument, because
     * there is no realm: a portal POST is checked against the browser's one session
     * token, exactly like a staff POST. This test exists to pin that there is no
     * second code path to keep in step.
     */
    public static function test_portal_dispatch_uses_the_same_enforcement()
    {
        static::__reset_session();
        static::__acting_as_site(1); // Session::has_session() -> true

        $request = static::__post('/_portal/_ajax/Foo_Controller/bar', [], ['HTTP_ORIGIN' => 'http://localhost']);

        static::__assert_throws(HttpResponseException::class, function () use ($request) {
            Rsx_Csrf::enforce($request);
        });
        static::__reset_session();
    }
}
