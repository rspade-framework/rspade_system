<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Env\Php;

use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Coverage for Rsx::get_http_host() - the application authority INCLUDING a
 * non-default port, which is what a URL handed to the browser must match.
 *
 * get_hostname() answers "which host am I" and strips the port; this answers
 * "what must a URL for this browser say". They differ exactly when the app is
 * published on a port other than the scheme default - the development-container
 * case (http://localhost:8080), where a portless realtime URL would point the
 * socket at :80 and silently never connect.
 *
 * Coverage targets compose_authority(), the PURE core. get_http_host() itself is
 * the impure seam that reads the request superglobals, and its request branch is
 * unreachable under the CLI test runner - the same constraint that makes
 * Rsx_Env_Hostname_Guard::find_mismatch() a separate public pure function.
 *
 * NOT covered: an IPv6 literal host. Rsx::get_hostname() splits the port with a
 * naive explode(':'), which mangles "[::1]:8080" - a PRE-EXISTING gap this change
 * neither introduces nor fixes (Rsx_Env_Hostname_Guard::normalize_request_host has
 * the correct bracket-aware parse if it is ever unified).
 *
 * Pure logic, no DB.
 */
class Http_Host_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // -------------------------------------------------------------------------
    // A non-default port is part of the authority
    // -------------------------------------------------------------------------

    public static function test_http_port_is_appended()
    {
        // The development-container case: published on 8080, browsed over plain http.
        static::__assert_equals(
            'localhost:8080',
            Rsx::compose_authority('localhost', 'localhost:8080', 'http'),
            'a non-default http port belongs in the authority'
        );
    }

    public static function test_https_non_default_port_is_appended()
    {
        static::__assert_equals(
            'myhost.example.com:8443',
            Rsx::compose_authority('myhost.example.com', 'myhost.example.com:8443', 'https'),
            'a non-default https port belongs in the authority'
        );
    }

    // -------------------------------------------------------------------------
    // A default port is never spelled - the ordinary deployment is unchanged
    // -------------------------------------------------------------------------

    public static function test_no_port_is_unchanged()
    {
        static::__assert_equals(
            'myhost.example.com',
            Rsx::compose_authority('myhost.example.com', 'myhost.example.com', 'https'),
            'an authority with no port produces the bare host'
        );
    }

    public static function test_default_https_port_is_dropped()
    {
        static::__assert_equals(
            'myhost.example.com',
            Rsx::compose_authority('myhost.example.com', 'myhost.example.com:443', 'https'),
            ':443 on https is the default and is never spelled'
        );
    }

    public static function test_default_http_port_is_dropped()
    {
        static::__assert_equals(
            'localhost',
            Rsx::compose_authority('localhost', 'localhost:80', 'http'),
            ':80 on http is the default and is never spelled'
        );
    }

    // -------------------------------------------------------------------------
    // The BROWSER's scheme decides which port counts as default
    // -------------------------------------------------------------------------

    public static function test_scheme_decides_the_default_port()
    {
        // Behind an SSL terminator the browser is on https, so :443 is the default
        // here and must be dropped - while :80 is NOT default for https and stays.
        static::__assert_equals(
            'myhost.example.com',
            Rsx::compose_authority('myhost.example.com', 'myhost.example.com:443', 'https'),
            'https drops :443'
        );

        static::__assert_equals(
            'myhost.example.com:443',
            Rsx::compose_authority('myhost.example.com', 'myhost.example.com:443', 'http'),
            ':443 is not the default for http, so it is spelled'
        );
    }

    public static function test_real_port_survives_every_scheme()
    {
        static::__assert_equals(
            'myhost.example.com:8080',
            Rsx::compose_authority('myhost.example.com', 'myhost.example.com:8080', 'https'),
            'a genuinely non-default port survives the terminated path'
        );
    }

    // -------------------------------------------------------------------------
    // The resolved host is the one returned - the raw authority only carries the port
    // -------------------------------------------------------------------------

    public static function test_resolved_host_wins_over_the_raw_authority()
    {
        // get_hostname() has already lowercased/validated the host; compose_authority
        // must not re-derive it from the raw header, only read its port.
        static::__assert_equals(
            'myhost.example.com:8080',
            Rsx::compose_authority('myhost.example.com', 'MyHost.Example.COM:8080', 'http'),
            'the resolved host is authoritative; the raw authority supplies only the port'
        );
    }
}
