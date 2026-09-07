<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Absolute_Url_Test - rsx_absolute_url() with no request to read a host from.
 *
 * WHY THIS LIVES IN THE MAIL CONCERN. There is no request in a background task, and
 * email is the only subsystem that is rendered entirely inside one - so mail is where a
 * wrong answer here does damage, and it does it silently: a message goes out carrying a
 * link to a host that does not exist, the queue row says SENT, and the only evidence is
 * a recipient who cannot click it. (The helper is general; if a second CLI-rendering
 * subsystem ever needs it, this belongs in a concern of its own.)
 *
 * APP_URL IS THE SINGLE HOSTNAME SOURCE, and a CLI process has nothing else. The scheme
 * comes from it, the host comes from it, and a non-default port comes from it too - the
 * port being the part that a naive implementation drops, which is exactly how every link
 * in every email breaks on a box that serves on 8080.
 */
class Absolute_Url_Test extends Rsx_Test_Abstract
{
    // Pure string composition over config - no database.
    protected static $use_database_transactions = false;

    /**
     * scheme, host and the port only when it is not the scheme's default - the
     * expectation built from APP_URL independently of the helper under test.
     *
     * @return array{0: string, 1: string}
     */
    private static function __expected_origin(): array
    {
        $app_url = (string) config('app.url');

        $scheme = (string) (parse_url($app_url, PHP_URL_SCHEME) ?: 'https');
        $host = (string) parse_url($app_url, PHP_URL_HOST);
        $port = parse_url($app_url, PHP_URL_PORT);

        $default_port = $scheme === 'https' ? 443 : 80;
        $authority = $host;

        if ($port !== null && (int) $port !== $default_port) {
            $authority .= ':' . $port;
        }

        return [$scheme, $authority];
    }

    public static function test_a_path_becomes_the_app_url_origin_plus_the_path()
    {
        [$scheme, $authority] = static::__expected_origin();

        static::__assert_equals(
            $scheme . '://' . $authority . '/x',
            rsx_absolute_url('/x'),
            'a CLI process composes the URL from APP_URL, the single hostname source'
        );
    }

    public static function test_the_scheme_is_never_hardcoded()
    {
        [$scheme] = static::__expected_origin();

        static::__assert_true(
            str_starts_with(rsx_absolute_url('/x'), $scheme . '://'),
            "the scheme is APP_URL's ({$scheme}), not a constant - development is allowed to serve http"
        );
    }

    /**
     * A box that serves on a non-default port is the case a naive implementation gets
     * wrong, and it gets it wrong invisibly: every link in every email points at the
     * right host and the wrong socket.
     */
    public static function test_a_non_default_port_survives()
    {
        [$scheme, $authority] = static::__expected_origin();
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);
        $default_port = $scheme === 'https' ? 443 : 80;

        if ($port !== null && (int) $port !== $default_port) {
            static::__assert_contains(
                ':' . $port,
                rsx_absolute_url('/x'),
                'this install serves on a non-default port, and the URL says so'
            );

            return;
        }

        // This install uses the scheme's default port, so the assertion available here
        // is the inverse one: no port is appended where none is needed.
        static::__assert_equals(
            $scheme . '://' . $authority . '/x',
            rsx_absolute_url('/x'),
            'a default port is not spelled out - :443 in every link would be noise'
        );
    }

    public static function test_a_path_without_a_leading_slash_still_produces_one()
    {
        static::__assert_equals(
            rsx_absolute_url('/x'),
            rsx_absolute_url('x'),
            'the helper is forgiving about the leading slash, so a caller cannot produce hosty'
        );
    }

    public static function test_a_query_string_and_fragment_survive_untouched()
    {
        [$scheme, $authority] = static::__expected_origin();

        static::__assert_equals(
            $scheme . '://' . $authority . '/_mail/unsubscribe?email=a%40b.com&sig=abc',
            rsx_absolute_url('/_mail/unsubscribe?email=a%40b.com&sig=abc'),
            'the helper prepends an origin and does nothing else - a signed query must arrive byte-identical'
        );
    }
}
