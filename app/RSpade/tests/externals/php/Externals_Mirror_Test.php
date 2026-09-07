<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Externals\Php;

use ReflectionClass;
use RuntimeException;
use App\RSpade\Commands\Rsx\Prod_Export_Command;
use App\RSpade\Core\Bundle\Cdn_Cache;
use App\RSpade\Core\Externals\Rsx_Externals;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The vendor mirror: the half of the registry that puts a file on disk.
 *
 * Two halves have to agree exactly or a page 404s (or worse, silently reaches the
 * internet): the mirror step WRITES a file named by Cdn_Cache::filename_for($url,
 * Rsx_Externals::url_asset_type($url)), and Rsx_Externals::resolve_url() NAMES
 * /_vendor/{that same file}. The agreement is pure logic and is pinned here, together
 * with the naming rule itself - the node CSS localizer implements the identical rule,
 * and EXT-49 pins the two implementations to each other.
 *
 * The download guard is pinned through its pure predicate: downloading is a BUILD
 * activity, so a cache miss at request time in a sealed mode is a broken build and must
 * throw instead of curling.
 *
 * ensure() is exercised through the $_testing_fetcher seam against a scratch directory -
 * no network, and the real rsx/resource/.cdn-cache is never touched.
 *
 * Pure logic, no DB, no network.
 */
class Externals_Mirror_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const JS_URL = 'https://cdn.example.com/lib/thing.js';
    private const CSS_URL = 'https://cdn.example.com/lib/thing.css';
    private const QUERY_URL = 'https://widget.example.net/api.js?render=explicit';

    /**
     * A scratch store, wiped in teardown. NEVER the real mirror.
     */
    private static function __scratch_dir(): string
    {
        return storage_path('rsx-tmp/externals_mirror_test-temp');
    }

    public static function setup()
    {
        static::__reset_scratch();
    }

    public static function teardown()
    {
        Cdn_Cache::$_testing_cache_dir = null;
        Cdn_Cache::$_testing_fetcher = null;
        rmdir_recursive(static::__scratch_dir());
    }

    private static function __reset_scratch(): void
    {
        rmdir_recursive(static::__scratch_dir());
        ensure_directory(static::__scratch_dir());
        Cdn_Cache::$_testing_cache_dir = static::__scratch_dir();
        Cdn_Cache::$_testing_fetcher = null;
    }

    // -------------------------------------------------------------------------
    // The naming rule
    // -------------------------------------------------------------------------

    public static function test_the_naming_rule_is_the_md5_plus_a_readable_tail()
    {
        $cases = [
            ['https://fonts.googleapis.com/css2?family=Roboto', 'css', 'css2.css'],
            ['https://fonts.gstatic.com/s/x/v1/KFOmCnqEu92Fr1Mu4mxK.woff2', 'css', 'KFOmCnqEu92Fr1Mu4mxK.woff2'],
            [
                'https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.default.min.css',
                'css',
                'tom-select_default_min.css',
            ],
            [
                'https://cdn.jsdelivr.net/npm/bootstrap@5/dist/js/bootstrap.bundle.min.js',
                'js',
                'bootstrap_bundle_min.js',
            ],
        ];

        foreach ($cases as [$url, $type, $tail]) {
            $filename = Cdn_Cache::filename_for($url, $type);

            static::__assert_equals(
                md5($url) . '_' . $tail,
                $filename,
                "the mirror filename for {$url}"
            );

            static::__assert_equals(
                1,
                preg_match(Cdn_Cache::FILENAME_PATTERN, $filename),
                "FILENAME_PATTERN admits exactly what filename_for() produces ({$filename})"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Mirror step <-> resolver filename agreement
    // -------------------------------------------------------------------------

    public static function test_the_mirror_step_writes_exactly_the_file_resolve_url_names()
    {
        Rsx::_testing_set_mode(Rsx::MODE_PRODUCTION);

        try {
            foreach ([self::JS_URL, self::CSS_URL, self::QUERY_URL] as $url) {
                // What the mirror step writes (Cdn_Cache::mirror_externals).
                $written = Cdn_Cache::filename_for($url, Rsx_Externals::url_asset_type($url));

                // What a page asks for.
                $requested = Rsx_Externals::resolve_url($url, true);

                static::__assert_equals(
                    '/_vendor/' . $written,
                    $requested,
                    "the mirror step and the resolver name the same file for {$url}"
                );
            }
        } finally {
            Rsx::clear_mode_cache();
        }
    }

    public static function test_the_asset_type_follows_the_url_extension_not_the_query_string()
    {
        static::__assert_equals('js', Rsx_Externals::url_asset_type(self::JS_URL));
        static::__assert_equals('css', Rsx_Externals::url_asset_type(self::CSS_URL));
        static::__assert_equals(
            'js',
            Rsx_Externals::url_asset_type(self::QUERY_URL),
            'a query string never changes the type'
        );
        static::__assert_equals(
            'js',
            Rsx_Externals::url_asset_type('https://example.com/loader'),
            'an extensionless URL is script by default'
        );
    }

    public static function test_a_mirror_false_url_is_never_named_as_a_local_file()
    {
        Rsx::_testing_set_mode(Rsx::MODE_PRODUCTION);

        try {
            static::__assert_equals(
                self::QUERY_URL,
                Rsx_Externals::resolve_url(self::QUERY_URL, false),
                'the mirror step skips it, so nothing may point at /_vendor for it'
            );
        } finally {
            Rsx::clear_mode_cache();
        }
    }

    // -------------------------------------------------------------------------
    // The build-vs-request download guard
    // -------------------------------------------------------------------------

    public static function test_a_request_time_miss_in_a_sealed_mode_is_refused()
    {
        static::__assert_false(
            Cdn_Cache::_download_is_permitted(false, true, false),
            'a web request serving a sealed build never downloads - the mirror is the build'
        );
    }

    public static function test_the_build_pipeline_and_the_cli_may_download()
    {
        static::__assert_true(
            Cdn_Cache::_download_is_permitted(false, true, true),
            'the build phase marker is what populates the mirror'
        );

        static::__assert_true(
            Cdn_Cache::_download_is_permitted(true, true, false),
            'every CLI entry into the compiler is a build or a developer workflow'
        );
    }

    public static function test_development_downloads_at_request_time()
    {
        static::__assert_true(
            Cdn_Cache::_download_is_permitted(false, false, false),
            'development populates the mirror on demand - a dev box is a real box, and it '
            . 'serves the same /_vendor/ files a sealed build does'
        );
    }

    public static function test_the_build_phase_marker_is_off_by_default()
    {
        static::__assert_false(
            Cdn_Cache::$_build_phase,
            'nothing but rsx:prod:build may leave the build-phase marker set'
        );
    }

    // -------------------------------------------------------------------------
    // ensure()
    // -------------------------------------------------------------------------

    public static function test_ensure_writes_the_named_file_and_a_second_call_is_a_hit()
    {
        static::__reset_scratch();

        $calls = 0;
        Cdn_Cache::$_testing_fetcher = function ($url) use (&$calls) {
            $calls++;

            return "/* body of {$url} */";
        };

        $filename = Cdn_Cache::ensure(self::JS_URL, 'js');

        static::__assert_equals(Cdn_Cache::filename_for(self::JS_URL, 'js'), $filename);
        static::__assert_true(file_exists(static::__scratch_dir() . '/' . $filename), 'the file is on disk');
        static::__assert_equals(
            '/* body of ' . self::JS_URL . ' */',
            file_get_contents(static::__scratch_dir() . '/' . $filename),
            'bytes are stored VERBATIM - no source header, no normalisation'
        );
        static::__assert_equals(1, $calls);

        static::__assert_equals($filename, Cdn_Cache::ensure(self::JS_URL, 'js'), 'same name');
        static::__assert_equals(1, $calls, 'a present file is never re-fetched');
        static::__assert_true(Cdn_Cache::is_cached(self::JS_URL, 'js'));
    }

    public static function test_an_integrity_hash_is_verified_against_the_downloaded_bytes()
    {
        static::__reset_scratch();

        $body = 'console.log(1);';
        Cdn_Cache::$_testing_fetcher = fn ($url) => $body;

        foreach (['sha512', 'sha384'] as $algo) {
            $hash = $algo . '-' . base64_encode(hash($algo, $body, true));

            static::__assert_equals(
                Cdn_Cache::filename_for('https://cdn.example.com/ok-' . $algo . '.js', 'js'),
                Cdn_Cache::ensure('https://cdn.example.com/ok-' . $algo . '.js', 'js', $hash),
                "a matching {$algo} hash passes"
            );
        }

        $wrong = 'sha384-' . base64_encode(hash('sha384', 'something else', true));

        $error = static::__assert_throws(
            RuntimeException::class,
            fn () => Cdn_Cache::ensure('https://cdn.example.com/bad.js', 'js', $wrong)
        );

        static::__assert_contains('https://cdn.example.com/bad.js', $error->getMessage());
        static::__assert_contains($wrong, $error->getMessage(), 'the expected hash is named');
        static::__assert_contains(
            base64_encode(hash('sha384', $body, true)),
            $error->getMessage(),
            'the actual hash is named'
        );

        $unknown = static::__assert_throws(
            RuntimeException::class,
            fn () => Cdn_Cache::ensure('https://cdn.example.com/md5.js', 'js', 'md5-' . base64_encode('x'))
        );

        static::__assert_contains('md5-', $unknown->getMessage());
    }

    public static function test_a_failed_download_throws_naming_the_url_and_writes_nothing()
    {
        static::__reset_scratch();

        Cdn_Cache::$_testing_fetcher = fn ($url) => false;

        $error = static::__assert_throws(
            RuntimeException::class,
            fn () => Cdn_Cache::ensure(self::JS_URL, 'js')
        );

        static::__assert_contains(self::JS_URL, $error->getMessage());
        static::__assert_false(
            file_exists(static::__scratch_dir() . '/' . Cdn_Cache::filename_for(self::JS_URL, 'js')),
            'a failed download leaves no half-written mirror file'
        );
    }

    public static function test_clear_removes_every_file_whatever_its_extension()
    {
        static::__reset_scratch();

        foreach (['a.js', 'b.css', 'c.woff2'] as $name) {
            file_put_contents(static::__scratch_dir() . '/' . $name, 'x');
        }

        static::__assert_equals(3, Cdn_Cache::clear(), 'every regular file is removed, not just js and css');
        static::__assert_equals([], glob(static::__scratch_dir() . '/*'), 'the store is empty');
    }

    // -------------------------------------------------------------------------
    // The mirror ships inside rsx/, never as a storage build artifact
    // -------------------------------------------------------------------------

    public static function test_the_export_no_longer_carries_a_phantom_storage_cdn_cache()
    {
        $reflection = new ReflectionClass(Prod_Export_Command::class);
        $dirs = $reflection->getConstant('SEALED_BUILD_DIRS');

        static::__assert_false(
            in_array('rsx-build/cdn-cache', $dirs, true),
            'the mirror lives in rsx/resource/.cdn-cache (copied with the rsx tree), never under storage'
        );
    }
}
