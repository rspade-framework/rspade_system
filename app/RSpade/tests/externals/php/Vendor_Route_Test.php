<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Externals\Php;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\RSpade\Core\Bundle\Cdn_Cache;
use App\RSpade\Core\Dispatch\AssetHandler;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The /_vendor/ route - the one way a mirrored external asset reaches a browser, in every
 * mode. EXT-38, exercised in-process rather than over http: sealing this box to assert the
 * sealed branch would seal the development environment, so the mode is driven through the
 * Rsx::_testing_set_mode() seam and the store through Cdn_Cache::$_testing_cache_dir.
 *
 * What is pinned: a hit is served with the right type and the immutable/nosniff headers; a
 * name the naming rule could never have produced (a traversal, an old-shape name) is refused
 * before the filesystem is touched; and a MISS names the remedy that fits the mode -
 * rsx:cdn_externals:refresh in development (the compile that names the file never ran here),
 * rsx:prod:refresh in a sealed build (the mirror is incomplete, which is a broken build).
 *
 * No DB, no network.
 */
class Vendor_Route_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** A well-formed store name for each type the route serves. */
    private const FONT_NAME = '0123456789abcdef0123456789abcdef_x.woff2';
    private const CSS_NAME = 'fedcba9876543210fedcba9876543210_x.css';

    private static function __scratch_dir(): string
    {
        return storage_path('rsx-tmp/vendor_route_test-temp');
    }

    public static function setup()
    {
        rmdir_recursive(static::__scratch_dir());
        ensure_directory(static::__scratch_dir());

        Cdn_Cache::$_testing_cache_dir = static::__scratch_dir();

        file_put_contents(static::__scratch_dir() . '/' . self::FONT_NAME, 'wOF2-not-really');
        file_put_contents(static::__scratch_dir() . '/' . self::CSS_NAME, '.x{color:red}');
    }

    public static function teardown()
    {
        Cdn_Cache::$_testing_cache_dir = null;
        Rsx::clear_mode_cache();
        rmdir_recursive(static::__scratch_dir());
    }

    private static function __serve(string $name)
    {
        return AssetHandler::try_serve('/_vendor/' . $name, Request::create('/_vendor/' . $name));
    }

    // -------------------------------------------------------------------------
    // Hits
    // -------------------------------------------------------------------------

    public static function test_a_mirrored_font_is_served_with_its_own_mime_type()
    {
        $response = static::__serve(self::FONT_NAME);

        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_equals('font/woff2', $response->headers->get('Content-Type'), 'a binary type carries no charset');
        static::__assert_contains('immutable', $response->headers->get('Cache-Control'), 'the name is the cache key');
        static::__assert_equals('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public static function test_a_mirrored_stylesheet_is_served_as_text_css()
    {
        $response = static::__serve(self::CSS_NAME);

        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_equals('text/css; charset=utf-8', $response->headers->get('Content-Type'));
        static::__assert_contains('immutable', $response->headers->get('Cache-Control'));
        static::__assert_equals('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    // -------------------------------------------------------------------------
    // Names the naming rule could never have produced
    // -------------------------------------------------------------------------

    public static function test_a_traversal_name_is_refused()
    {
        static::__assert_throws(
            NotFoundHttpException::class,
            fn () => static::__serve('../../.env'),
            'Invalid vendor filename'
        );
    }

    public static function test_an_old_shape_name_is_refused()
    {
        static::__assert_throws(
            NotFoundHttpException::class,
            fn () => static::__serve('bootstrap_0123456789ab.css'),
            'Invalid vendor filename'
        );
    }

    // -------------------------------------------------------------------------
    // Misses - the remedy follows the mode
    // -------------------------------------------------------------------------

    public static function test_a_development_miss_names_the_store_refresh_command()
    {
        Rsx::_testing_set_mode(Rsx::MODE_DEVELOPMENT);

        try {
            $exception = static::__assert_throws(
                NotFoundHttpException::class,
                fn () => static::__serve('00000000000000000000000000000000_missing.js'),
                'Vendor file not found'
            );

            static::__assert_contains('rsx:cdn_externals:refresh', $exception->getMessage());
        } finally {
            Rsx::clear_mode_cache();
        }
    }

    public static function test_a_sealed_miss_is_a_broken_build_and_fails_loud()
    {
        Rsx::_testing_set_mode(Rsx::MODE_PRODUCTION);

        try {
            $exception = static::__assert_throws(
                RuntimeException::class,
                fn () => static::__serve('00000000000000000000000000000000_missing.js'),
                'Missing mirrored external asset'
            );

            static::__assert_contains('rsx:prod:refresh', $exception->getMessage());
        } finally {
            Rsx::clear_mode_cache();
        }
    }
}
