<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Externals\Php;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Commands\Rsx\Cdn_Externals_Refresh_Command;
use App\RSpade\Core\Bundle\Cdn_Cache;
use App\RSpade\Core\Externals\Rsx_Externals;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx:cdn_externals:refresh - the ONE expiry for the CDN externals mirror store.
 *
 * Two things are worth pinning and nothing else is. First, the SEAL REFUSAL: the store is
 * a git-tracked source artifact refreshed on a development box, so a sealed host must be
 * sent to rsx:build --force and must not have its shipped mirror emptied under it - and the
 * refusal has to come BEFORE Cdn_Cache::clear(), which is destructive and unrecoverable
 * without the network. Second, the ORDER: empty, then mirror. A refresh that mirrored
 * before clearing would preserve exactly the stale bytes it exists to replace.
 *
 * Both run against scratch directories through the store's own seams
 * (Cdn_Cache::$_testing_cache_dir / $_testing_fetcher) plus Rsx_Externals::$_testing_entries
 * for the declaration table. No network, and the real rsx/resource/.cdn-cache is never
 * touched.
 *
 * The two artisan subprocesses - rsx:clean and rsx:build - are recorded rather than run
 * through Cdn_Externals_Refresh_Command::$_testing_spawned. Running them for real would
 * empty the developer's build tree and take minutes, and neither is this test's subject;
 * what IS the subject is that the command delegates both, in order.
 *
 * Pure logic + scratch files, no DB, no network.
 */
class Cdn_Externals_Refresh_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const MIRRORED_JS = 'https://cdn.example.com/pinned/thing.js';

    /** A name the naming rule can produce - a legitimate current entry. */
    private const NEW_SHAPE = '0123456789abcdef0123456789abcdef_leftover.js';

    /** A name from the store's superseded scheme - an orphan nothing will ever serve. */
    private const OLD_SHAPE = 'leftover_0123456789ab.js';

    private static function __scratch_dir(): string
    {
        return Rsx_Project_Paths::tmp_path('cdn_externals_refresh_test-temp/store');
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
        Cdn_Externals_Refresh_Command::$_testing_spawned = null;
        Rsx_Prod_Seal::_testing_reset();

        rmdir_recursive(Rsx_Project_Paths::tmp_path('cdn_externals_refresh_test-temp'));
    }

    /**
     * A cold scratch store pre-seeded with one file of each shape, the network replaced by
     * a fixture fetcher, and the subprocess recorder armed.
     */
    private static function __reset_scratch(): void
    {
        rmdir_recursive(Rsx_Project_Paths::tmp_path('cdn_externals_refresh_test-temp'));

        ensure_directory(static::__scratch_dir());

        file_put_contents(static::__scratch_dir() . '/' . self::NEW_SHAPE, 'stale-new');
        file_put_contents(static::__scratch_dir() . '/' . self::OLD_SHAPE, 'stale-old');

        Cdn_Cache::$_testing_cache_dir = static::__scratch_dir();
        Cdn_Cache::$_testing_fetcher = fn ($url) => "/* fixture body of {$url} */";

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

        Cdn_Externals_Refresh_Command::$_testing_spawned = [];
        Rsx_Prod_Seal::_testing_reset();
    }

    /**
     * @return array{0: int, 1: string} exit code and captured output
     */
    private static function __run(): array
    {
        $exit_code = Artisan::call('rsx:cdn_externals:refresh');

        return [$exit_code, Artisan::output()];
    }

    // -------------------------------------------------------------------------
    // EXT-55
    // -------------------------------------------------------------------------

    public static function test_a_sealed_host_is_refused_before_anything_is_deleted()
    {
        static::__reset_scratch();

        Rsx_Prod_Seal::_testing_set_sealed(true);

        try {
            [$exit_code, $output] = static::__run();
        } finally {
            Rsx_Prod_Seal::_testing_reset();
        }

        static::__assert_equals(1, $exit_code, 'a sealed host refuses to refresh the mirror');
        static::__assert_contains('rsx:build --force', $output, 'the refusal names the command a sealed host uses');

        static::__assert_true(
            file_exists(static::__scratch_dir() . '/' . self::NEW_SHAPE),
            'the refusal comes before Cdn_Cache::clear() - a sealed store is never emptied'
        );
        static::__assert_true(
            file_exists(static::__scratch_dir() . '/' . self::OLD_SHAPE),
            'nothing at all in the store was touched'
        );
        static::__assert_false(
            file_exists(static::__scratch_dir() . '/' . Cdn_Cache::filename_for(self::MIRRORED_JS, 'js')),
            'and nothing was mirrored either'
        );
    }

    // -------------------------------------------------------------------------
    // EXT-56
    // -------------------------------------------------------------------------

    public static function test_the_store_is_emptied_before_the_declared_externals_are_mirrored()
    {
        static::__reset_scratch();

        [$exit_code, $output] = static::__run();

        static::__assert_equals(0, $exit_code, "the refresh succeeded:\n{$output}");
        static::__assert_contains('[OK] CDN externals store refreshed', $output);

        static::__assert_false(
            file_exists(static::__scratch_dir() . '/' . self::NEW_SHAPE),
            'a current-shape file nothing declares any more is still removed - the store is emptied, not pruned'
        );
        static::__assert_false(
            file_exists(static::__scratch_dir() . '/' . self::OLD_SHAPE),
            'an orphan from the superseded naming scheme goes with it'
        );

        $mirrored = static::__scratch_dir() . '/' . Cdn_Cache::filename_for(self::MIRRORED_JS, 'js');

        static::__assert_true(file_exists($mirrored), 'the declared external was re-mirrored');
        static::__assert_equals(
            '/* fixture body of ' . self::MIRRORED_JS . ' */',
            file_get_contents($mirrored),
            'with the bytes the fetcher returned, verbatim'
        );

        static::__assert_equals(
            [$mirrored],
            glob(static::__scratch_dir() . '/*'),
            'the store now holds exactly what the declarations name'
        );

        static::__assert_contains('CDN externals store refreshed: 1 files', $output, 'the count is the store as it now stands');
    }

    public static function test_the_build_tree_is_discarded_and_rebuilt_around_the_new_store()
    {
        static::__reset_scratch();

        [$exit_code, $output] = static::__run();

        static::__assert_equals(0, $exit_code, $output);

        // Compiled bundles NAME /_vendor/ files, so they are torn down with the store they
        // reference and built again against the new one. Both wipes and builds belong to
        // the commands that own them, so what this command owes is the delegation, in order.
        static::__assert_equals(
            ['rsx:clean', 'rsx:build'],
            Cdn_Externals_Refresh_Command::$_testing_spawned,
            'the refresh discards the build tree, then rebuilds it'
        );
    }
}
