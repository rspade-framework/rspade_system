<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Rsx_Session_Cookie;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The session cookie attributes both realms emit (Rsx_Session_Cookie).
 *
 * The Secure flag is the interesting one. RSpade assumes upstream SSL termination,
 * so outside development mode the cookie is ALWAYS Secure regardless of what the
 * request claims. In development it follows the request's scheme, because the
 * headless harness drives a real browser over plain http on loopback and a Secure
 * cookie is DROPPED there - which silently unauthenticates every Ajax call the page
 * makes and makes an honest end-to-end check impossible.
 *
 * The X-Forwarded-Proto read is untrusted on purpose: it can only ADD the Secure
 * attribute, never remove it, so a spoofed header cannot relax the cookie.
 */
class Session_Cookie_Flags_Test extends Rsx_Test_Abstract
{
    // Pure request/mode logic; no database.
    protected static $use_database_transactions = false;

    /**
     * Evaluate is_secure() with a given request and RSX mode, restoring both after.
     */
    private static function __is_secure_for(Request $request, string $mode): bool
    {
        $previous_request = app('request');
        $previous_mode = $_SERVER['RSX_MODE'] ?? null;

        $_ENV['RSX_MODE'] = $mode;
        $_SERVER['RSX_MODE'] = $mode;
        putenv('RSX_MODE=' . $mode);
        Rsx::clear_mode_cache();

        app()->instance('request', $request);

        try {
            return Rsx_Session_Cookie::is_secure();
        } finally {
            app()->instance('request', $previous_request);

            if ($previous_mode === null) {
                unset($_ENV['RSX_MODE'], $_SERVER['RSX_MODE']);
                putenv('RSX_MODE');
            } else {
                $_ENV['RSX_MODE'] = $previous_mode;
                $_SERVER['RSX_MODE'] = $previous_mode;
                putenv('RSX_MODE=' . $previous_mode);
            }

            Rsx::clear_mode_cache();
        }
    }

    private static function __plain_http_request(): Request
    {
        return Request::create('http://rspade.test/clients', 'GET');
    }

    private static function __https_request(): Request
    {
        return Request::create('https://rspade.test/clients', 'GET');
    }

    private static function __terminated_request(): Request
    {
        // What an upstream SSL terminator forwards: plain http to the app, with the
        // original scheme stamped on the request.
        return Request::create(
            'http://rspade.test/clients',
            'GET',
            [],
            [],
            [],
            ['HTTP_X_FORWARDED_PROTO' => 'https']
        );
    }

    // =========================================================================
    // THE SECURE FLAG
    // =========================================================================

    /**
     * The harness case: development mode, plain http, no terminator header.
     */
    public static function test_development_plain_http_drops_the_secure_flag()
    {
        static::__assert_false(
            static::__is_secure_for(static::__plain_http_request(), Rsx::MODE_DEVELOPMENT),
            'a plain-http development request emits a cookie the browser will keep'
        );
    }

    /**
     * A genuinely secure development request keeps the flag.
     */
    public static function test_development_https_keeps_the_secure_flag()
    {
        static::__assert_true(
            static::__is_secure_for(static::__https_request(), Rsx::MODE_DEVELOPMENT),
            'an https development request still emits a Secure cookie'
        );
    }

    /**
     * The SSL-terminated path is the normal way a dev site is browsed: http to the
     * app, X-Forwarded-Proto: https from the terminator.
     */
    public static function test_development_forwarded_proto_keeps_the_secure_flag()
    {
        static::__assert_true(
            static::__is_secure_for(static::__terminated_request(), Rsx::MODE_DEVELOPMENT),
            'X-Forwarded-Proto https keeps the cookie Secure behind a terminator'
        );
    }

    /**
     * Outside development the flag is unconditional - a plain-http (or spoofed)
     * request can never talk a sealed build into a non-Secure session cookie.
     */
    public static function test_debug_mode_forces_the_secure_flag()
    {
        static::__assert_true(
            static::__is_secure_for(static::__plain_http_request(), Rsx::MODE_DEBUG),
            'debug mode ignores the request scheme'
        );
    }

    public static function test_production_mode_forces_the_secure_flag()
    {
        static::__assert_true(
            static::__is_secure_for(static::__plain_http_request(), Rsx::MODE_PRODUCTION),
            'production mode ignores the request scheme'
        );
    }

    // =========================================================================
    // THE REST OF THE ATTRIBUTES
    // =========================================================================

    /**
     * Both realms emit one attribute set; only the lifetime differs.
     */
    public static function test_options_carry_the_shared_attributes()
    {
        $expires = time() + 3600;
        $options = Rsx_Session_Cookie::options($expires);

        static::__assert_equals($expires, $options['expires'], 'the caller owns the lifetime');
        static::__assert_equals('/', $options['path']);
        static::__assert_equals('', $options['domain'], 'current domain only');
        static::__assert_true($options['httponly'], 'no JavaScript access');
        static::__assert_equals('Lax', $options['samesite'], 'CSRF protection');
        static::__assert_true(is_bool($options['secure']), 'the secure flag is resolved, not deferred');
    }
}
