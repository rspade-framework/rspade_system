<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Externals\Php;

use ReflectionMethod;
use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Bundle\Cdn_Cache;
use App\RSpade\Core\Externals\Rsx_Externals;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The compiler's two seams into the mirror store, pinned in DEVELOPMENT mode.
 *
 * The whole point of the one-code-path rule is that a dev box behaves like a sealed one:
 * the same files, from the same place, named the same way. Both seams used to be gated on
 * Rsx::is_production(), so a resource that worked all through development could 404 the
 * first time it was sealed. These two tests are what stop that gate coming back.
 *
 * - _prepare_cdn_assets() mirrors every bundle `cdn_assets` entry and names the file the
 *   /_vendor/ URL is built from.
 * - _create_javascript_externals() populates the mirror BEFORE it bakes the client map, so
 *   every mirror:true URL the map names is already a file on disk.
 *
 * Both run against a scratch store through $_testing_fetcher - no network, and the real
 * rsx/resource/.cdn-cache is never touched.
 */
class Externals_Compile_Mirror_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const JS_URL = 'https://cdn.example.com/lib/thing.js';
    private const MIRRORED_JS = 'https://cdn.example.com/pinned/widget.js';

    /** Every URL the stand-in fetcher was asked for, in order. */
    private static array $fetched = [];

    private static function __scratch_dir(): string
    {
        return storage_path('rsx-tmp/externals_compile_mirror_test-temp');
    }

    public static function setup()
    {
        static::__reset_scratch();
    }

    public static function teardown()
    {
        Cdn_Cache::$_testing_cache_dir = null;
        Cdn_Cache::$_testing_fetcher = null;
        Rsx_Externals::$_testing_entries = null;
        Rsx::clear_mode_cache();

        static::__remove_scratch();
    }

    private static function __reset_scratch(): void
    {
        static::__remove_scratch();
        ensure_directory(static::__scratch_dir());

        self::$fetched = [];

        Cdn_Cache::$_testing_cache_dir = static::__scratch_dir();
        Cdn_Cache::$_testing_fetcher = function (string $url): string {
            self::$fetched[] = $url;

            return "/* stand-in bytes for {$url} */";
        };
    }

    private static function __remove_scratch(): void
    {
        $dir = static::__scratch_dir();

        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($dir);
    }

    /**
     * Call a protected BundleCompiler method on a bare instance.
     */
    private static function __invoke_compiler(string $method, array $arguments)
    {
        $reflection = new ReflectionMethod(BundleCompiler::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(new BundleCompiler(), $arguments);
    }

    // -------------------------------------------------------------------------
    // EXT-52
    // -------------------------------------------------------------------------

    public static function test_prepare_cdn_assets_names_the_mirror_file_in_development_mode()
    {
        static::__reset_scratch();
        Rsx::_testing_set_mode(Rsx::MODE_DEVELOPMENT);

        try {
            $prepared = static::__invoke_compiler('_prepare_cdn_assets', [
                [['url' => self::JS_URL], ['url' => self::JS_URL]],
                'js',
            ]);

            static::__assert_count(1, $prepared, 'the same url twice is one asset');

            $expected = Cdn_Cache::filename_for(self::JS_URL, 'js');

            static::__assert_equals(
                $expected,
                $prepared[0]['cached_filename'] ?? null,
                'DEVELOPMENT mode names the mirror file - there is no CDN-direct branch left'
            );

            static::__assert_true(
                file_exists(static::__scratch_dir() . '/' . $expected),
                'the file the /_vendor/ url will name is on disk after preparing the asset'
            );

            static::__assert_equals([self::JS_URL], self::$fetched, 'downloaded exactly once');
        } finally {
            Rsx::clear_mode_cache();
        }
    }

    // -------------------------------------------------------------------------
    // EXT-53
    // -------------------------------------------------------------------------

    public static function test_baking_the_client_map_populates_the_mirror_first()
    {
        static::__reset_scratch();

        Rsx_Externals::$_testing_entries = [
            'pinned' => [
                'js' => [self::MIRRORED_JS],
                'css' => [],
                'integrity' => [],
                'mirror' => true,
                'realm' => 'both',
                'readiness' => 'onload',
                'csp' => [],
                'file' => 'fixture/pinned.externals.php',
            ],
        ];

        Rsx::_testing_set_mode(Rsx::MODE_DEVELOPMENT);

        try {
            static::__invoke_compiler('_create_javascript_externals', []);

            static::__assert_equals(
                [self::MIRRORED_JS],
                self::$fetched,
                'compiling the externals map mirrors every mirror:true url, in development too'
            );

            static::__assert_true(
                file_exists(static::__scratch_dir() . '/' . Cdn_Cache::filename_for(self::MIRRORED_JS, 'js')),
                'the mirrored file exists before anything can ask /_vendor/ for it'
            );
        } finally {
            Rsx::clear_mode_cache();
            Rsx_Externals::$_testing_entries = null;
        }
    }
}
