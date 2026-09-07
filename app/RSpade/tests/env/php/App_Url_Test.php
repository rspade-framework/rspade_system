<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Env\Php;

use App\RSpade\Core\Env\Rsx_App_Url;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit coverage for Rsx_App_Url - the boot-time APP_URL resolver.
 *
 * Two pure transforms are covered here: resolve() (the $HOSTNAME token
 * substitution + trailing-slash trim) and enforce_scheme() (the scheme
 * boot invariant). patch_environment() / enforce_scheme_from_env() are the impure
 * boot seams (they read/write the process environment) and are proven E2E in the
 * ticket verification, not here.
 *
 * Pure logic, no DB.
 */
class App_Url_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // -------------------------------------------------------------------------
    // resolve() - token substitution
    // -------------------------------------------------------------------------

    public static function test_resolve_substitutes_nobrace_token()
    {
        static::__assert_equals(
            'https://myhost.example.com',
            Rsx_App_Url::resolve('https://$HOSTNAME', 'myhost.example.com')
        );
    }

    public static function test_resolve_substitutes_brace_token()
    {
        static::__assert_equals(
            'https://myhost.example.com',
            Rsx_App_Url::resolve('https://${HOSTNAME}', 'myhost.example.com')
        );
    }

    public static function test_resolve_no_token_passthrough()
    {
        static::__assert_equals(
            'https://literal.example.com',
            Rsx_App_Url::resolve('https://literal.example.com', 'ignored.host')
        );
    }

    public static function test_resolve_strips_trailing_slash()
    {
        static::__assert_equals(
            'https://myhost.example.com',
            Rsx_App_Url::resolve('https://$HOSTNAME/', 'myhost.example.com')
        );
        static::__assert_equals(
            'https://literal.example.com',
            Rsx_App_Url::resolve('https://literal.example.com/', 'ignored')
        );
    }

    public static function test_resolve_is_idempotent()
    {
        // Resolving an already-resolved value is a no-op (no token to replace).
        $once = Rsx_App_Url::resolve('https://$HOSTNAME', 'myhost.example.com');
        static::__assert_equals($once, Rsx_App_Url::resolve($once, 'myhost.example.com'));
    }

    // -------------------------------------------------------------------------
    // enforce_scheme() - https always, http in development only
    // -------------------------------------------------------------------------

    public static function test_enforce_scheme_passes_for_https_in_every_mode()
    {
        // No throw == pass. https is never mode-dependent.
        Rsx_App_Url::enforce_scheme('https://myhost.example.com', false);
        Rsx_App_Url::enforce_scheme('https://myhost.example.com', true);
        static::__pass('https APP_URL is accepted with and without the http allowance');
    }

    public static function test_enforce_scheme_allows_http_when_permitted()
    {
        // The development container case: no SSL terminator in front of it.
        Rsx_App_Url::enforce_scheme('http://localhost:8080', true);
        Rsx_App_Url::enforce_scheme('http://myhost.example.com', true);
        static::__pass('http APP_URL is accepted when the mode allows it');
    }

    public static function test_enforce_scheme_throws_for_http_when_not_permitted()
    {
        // Debug and production keep the hard requirement: their session cookie is
        // unconditionally Secure, which a plain-http page would discard.
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                Rsx_App_Url::enforce_scheme('http://myhost.example.com', false);
            },
            'outside development mode'
        );
    }

    public static function test_enforce_scheme_throws_for_http_localhost_when_not_permitted()
    {
        // localhost earns no exemption - the MODE decides, never the host.
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                Rsx_App_Url::enforce_scheme('http://localhost', false);
            },
            'outside development mode'
        );
    }

    public static function test_enforce_scheme_throws_for_empty_outside_development()
    {
        // A deployment nobody finished configuring.
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                Rsx_App_Url::enforce_scheme('', false);
            },
            'http:// or https://'
        );
    }

    public static function test_enforce_scheme_allows_empty_in_development()
    {
        // The FIRST-RUN state, not a misconfiguration: a container cannot know its
        // own published port, so a fresh install ships this blank and the setup
        // screen asks the browser. Throwing here would kill every artisan command
        // before anyone could reach that screen - including the container
        // entrypoint's own migrate.
        Rsx_App_Url::enforce_scheme('', true);
        static::__pass('an empty APP_URL is the un-configured state in development');
    }

    public static function test_enforce_scheme_throws_for_foreign_scheme()
    {
        // Only http and https are URL schemes this application can be served under.
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                Rsx_App_Url::enforce_scheme('ftp://myhost.example.com', true);
            },
            'http:// or https://'
        );
    }
}
