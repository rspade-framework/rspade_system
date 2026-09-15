<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Fpc\Php;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\FPC\Rsx_FPC;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Fpc\Php\Fpc_Ttl_Fixture_Controller;

/**
 * The per-route cache lifetime: #[FPC(ttl: 5)] on a route, 300 seconds on the wire.
 *
 * THE LIFETIME IS A PROPERTY OF THE PAGE. It is declared beside the route that knows how
 * long its own output stays true, baked onto the route row at build time, and carried to
 * the Node proxy on the marker header the proxy already keys its Redis write on - so
 * nothing has to tell the proxy out of band what some route wants, and there is no
 * environment value to keep in step with the code.
 *
 * The chain is read where it actually lives: the manifest row for a REAL route carrying
 * the attribute (the fixture controller beside this file), and the marker value the
 * dispatcher stamps from it.
 *
 * No database access - skip the per-test transaction.
 */
class Fpc_Ttl_Marker_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The manifest row for a routed path.
     */
    private static function __route(string $path): array
    {
        $routes = Manifest::get_routes();

        static::__assert_array_has_key($path, $routes, "{$path} is a routed path");

        return $routes[$path];
    }

    // -------------------------------------------------------------------------
    // The declaration reaches the route row
    // -------------------------------------------------------------------------

    public static function test_a_declared_ttl_is_baked_onto_the_route_row_in_minutes()
    {
        $route = self::__route(Fpc_Ttl_Fixture_Controller::TTL_PATH);

        static::__assert_true((bool) ($route['fpc'] ?? false), 'the route is cacheable');
        static::__assert_equals(5, $route['fpc_ttl_mins'] ?? null, 'minutes, as the attribute spelled them');
    }

    public static function test_a_bare_attribute_declares_no_ttl()
    {
        $route = self::__route(Fpc_Ttl_Fixture_Controller::FOREVER_PATH);

        static::__assert_true((bool) ($route['fpc'] ?? false), 'the route is cacheable');
        static::__assert_equals(0, $route['fpc_ttl_mins'] ?? null, 'no declaration is zero, not null');
    }

    public static function test_a_route_without_the_attribute_is_not_cacheable_at_all()
    {
        // The FPC is always available and that is exactly why this matters: a page is
        // cached because someone wrote #[FPC] on it, so a route that did not ask carries
        // no flag and the dispatcher never stamps a marker for it.
        foreach (Manifest::get_routes() as $path => $route) {
            if (($route['class'] ?? null) === Fpc_Ttl_Fixture_Controller::class) {
                continue;
            }

            if (!empty($route['fpc'])) {
                continue;
            }

            static::__assert_true(
                !array_key_exists('fpc_ttl_mins', $route),
                "{$path} declares no #[FPC], so it carries no lifetime either"
            );
        }
    }

    // -------------------------------------------------------------------------
    // The row becomes the marker the proxy reads
    // -------------------------------------------------------------------------

    public static function test_five_minutes_reaches_the_proxy_as_three_hundred_seconds()
    {
        $route = self::__route(Fpc_Ttl_Fixture_Controller::TTL_PATH);

        static::__assert_equals(
            '300',
            Rsx_FPC::marker_value((int) $route['fpc_ttl_mins']),
            'minutes in the attribute, seconds on the wire - the unit the proxy hands Redis'
        );
    }

    public static function test_no_declaration_reaches_the_proxy_as_no_expiry()
    {
        $route = self::__route(Fpc_Ttl_Fixture_Controller::FOREVER_PATH);

        static::__assert_equals(
            Rsx_FPC::MARKER_NO_EXPIRY,
            Rsx_FPC::marker_value((int) $route['fpc_ttl_mins']),
            'a word, not a zero - a number meaning "forever" reads as "immediately" to whoever meets it next'
        );
        static::__assert_equals('none', Rsx_FPC::MARKER_NO_EXPIRY, 'and that word is the one the proxy matches');
    }

    public static function test_the_marker_header_is_one_channel()
    {
        // The TTL rides on the SAME header that says "cache this". A second header would
        // be a second thing to strip, to document and to forget.
        static::__assert_equals('X-RSpade-FPC', Rsx_FPC::MARKER_HEADER, 'the marker the proxy keys on');

        $proxy = file_get_contents(base_path('bin/fpc-proxy.js'));

        static::__assert_contains("'x-rspade-fpc'", $proxy, 'the proxy reads that header');
        static::__assert_contains("'none'", $proxy, 'and understands the no-expiry value');
        static::__assert_false(
            str_contains($proxy, 'FPC_TTL_MINS'),
            'and no longer reads a TTL from the environment'
        );
    }

    // -------------------------------------------------------------------------
    // The operator lever
    // -------------------------------------------------------------------------

    public static function test_the_clear_command_reports_what_it_cleared_for_this_build()
    {
        $exit = Artisan::call('rsx:fpc:clear');
        $output = Artisan::output();

        static::__assert_equals(0, $exit, 'clearing an already-clean build is not a failure');
        static::__assert_contains('Cleared', $output, 'it says what it did');
        static::__assert_contains(Manifest::get_build_key(), $output, 'and which build it did it for');
    }

    public static function test_the_clear_command_clears_one_page()
    {
        $exit = Artisan::call('rsx:fpc:clear', ['--url' => Fpc_Ttl_Fixture_Controller::TTL_PATH]);
        $output = Artisan::output();

        static::__assert_equals(0, $exit, 'a page that was not cached is the state the operator wanted');
        static::__assert_contains(Fpc_Ttl_Fixture_Controller::TTL_PATH, $output, 'it names the page');
    }

    public static function test_clearing_removes_the_entry_the_proxy_would_have_written()
    {
        // The key format is a CONTRACT between this class and the Node proxy, so the test
        // writes an entry the proxy's own key derivation would produce and then clears it
        // through the command an operator types.
        $path = '/test-fpc/ttl';
        $key = 'fpc:' . Manifest::get_build_key() . ':' . sha1($path);

        $redis = new \Redis();
        $redis->connect((string) env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 2.0);

        $password = env('REDIS_PASSWORD');
        if ($password && $password !== 'null') {
            $redis->auth($password);
        }

        $redis->select(2);

        try {
            $redis->set($key, json_encode(['html' => 'x']));

            static::__assert_true((bool) $redis->exists($key), 'the entry is there to begin with');

            Artisan::call('rsx:fpc:clear', ['--url' => $path]);

            static::__assert_false((bool) $redis->exists($key), 'and the command removed it');
        } finally {
            $redis->del($key);
        }
    }
}
