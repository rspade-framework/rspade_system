<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Paths\Php;

use App\RSpade\Core\Console\Rsx_Internal_Flags;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Coverage for the path owner - the one place every volatile location is named.
 *
 * Three trees at the project root, each with one job: build/ holds build outputs,
 * tmp/ holds derived caches and runtime temp, storage/ holds user data plus
 * storage/state. tmp/ and storage/ are relocatable and build/ is fixed, so the contract
 * that matters is:
 *
 *   - the defaults are <project>/{build,tmp,storage} and state follows storage;
 *   - key_for() and absolute_for() round-trip, which is what makes a manifest index
 *     identical on two boxes with different roots;
 *   - the precedence order is in-process override, then argv flag, then resolver;
 *   - only ensure_build_tree() ever creates the build root.
 *
 * Pure logic, no DB.
 */
class Rsx_Project_Paths_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;


    /**
     * Put the run's file-subsystem isolation back, whatever a test did to the overrides.
     *
     * Several tests here drive _override()/_clear_overrides(), and a bare clear would
     * un-isolate the file subsystem for every LATER class in the run - a test-database
     * attachment delete would then reach the developer's blob store. Each of them
     * captures files_root() before it mutates anything and hands it back here.
     */
    private static function __restore_run_isolation(string $files_root): void
    {
        Rsx_Project_Paths::_clear_overrides();

        if ($files_root !== Rsx_Project_Paths::storage_root()) {
            Rsx_Project_Paths::_override(['files' => $files_root]);
        }
    }

    /**
     * The project root, derived the way the resolver derives it.
     */
    private static function _project_root(): string
    {
        return dirname(base_path());
    }

    public static function test_defaults_are_the_three_project_root_trees()
    {
        $root = static::_project_root();

        static::__assert_equals($root . '/build', Rsx_Project_Paths::build_root(), 'build root');
        static::__assert_equals($root . '/tmp', Rsx_Project_Paths::tmp_root(), 'tmp root');
        static::__assert_equals($root . '/storage', Rsx_Project_Paths::storage_root(), 'storage root');
    }

    /**
     * state follows storage and is never separately relocatable: a lock file only
     * excludes when a parent and its children open the same one.
     */
    public static function test_state_root_follows_storage()
    {
        static::__assert_equals(
            Rsx_Project_Paths::storage_root() . '/state',
            Rsx_Project_Paths::state_root(),
            'state_root is inside the storage root'
        );

        static::__assert_equals(
            Rsx_Project_Paths::state_root() . '/flock',
            Rsx_Project_Paths::flock_dir(),
            'the flock files live in state'
        );
    }

    /**
     * A key is LOGICAL, which is the whole determinism argument: two boxes with
     * different roots produce the same index and therefore the same build key.
     */
    public static function test_key_for_and_absolute_for_round_trip()
    {
        $cases = [
            Rsx_Project_Paths::manifest_index_file() => 'build/manifest_index.php',
            Rsx_Project_Paths::bundles_dir() . '/App_Bundle__app.js' => 'build/bundles/App_Bundle__app.js',
            Rsx_Project_Paths::stubs_dir(Rsx_Project_Paths::STUBS_MODEL) . '/base-user-model.js' => 'tmp/js-model-stubs/base-user-model.js',
            Rsx_Project_Paths::storage_path('uploads/ab/cd/ef') => 'storage/uploads/ab/cd/ef',
            Rsx_Project_Paths::thumbnails_dir() . '/preset/x.webp' => 'tmp/thumbnails/preset/x.webp',
        ];

        foreach ($cases as $absolute => $expected_key) {
            static::__assert_equals($expected_key, Rsx_Project_Paths::key_for($absolute), "key_for({$absolute})");
            static::__assert_equals($absolute, Rsx_Project_Paths::absolute_for($expected_key), "absolute_for({$expected_key})");
        }
    }

    /**
     * A path under none of the three trees is not ours.
     */
    public static function test_a_foreign_path_is_not_claimed()
    {
        static::__assert_equals(
            '/etc/hosts',
            Rsx_Project_Paths::key_for('/etc/hosts'),
            'a path outside every root comes back unchanged'
        );

        static::__assert_null(
            Rsx_Project_Paths::absolute_for('app/RSpade/helpers.php'),
            'a source key names no volatile tree'
        );
    }

    /**
     * stub_key() is the one spelling of a generated stub's key, and the predicates
     * answer both the key form and the absolute form.
     */
    public static function test_stub_key_and_predicates_accept_both_spellings()
    {
        static::__assert_equals(
            'tmp/js-stubs/Some_Controller.js',
            Rsx_Project_Paths::stub_key(Rsx_Project_Paths::STUBS_CONTROLLER, 'Some_Controller.js'),
            'controller stub key'
        );

        $absolute = Rsx_Project_Paths::stubs_dir(Rsx_Project_Paths::STUBS_AUTH) . '/Permission_Auth_Mirror.js';

        static::__assert_true(Rsx_Project_Paths::is_stub_file($absolute), 'absolute auth stub is a stub');
        static::__assert_true(Rsx_Project_Paths::is_stub_file('tmp/js-auth-stubs/Permission_Auth_Mirror.js'), 'key-form auth stub is a stub');
        static::__assert_false(Rsx_Project_Paths::is_stub_file('rsx/app/frontend/x.js'), 'a source file is not a stub');

        static::__assert_true(Rsx_Project_Paths::is_under_tmp($absolute), 'a stub is in the tmp tree');
        static::__assert_false(Rsx_Project_Paths::is_under_build($absolute), 'a stub is not a build output');

        static::__assert_true(Rsx_Project_Paths::is_under_build('build/bundles/x.js'), 'key-form build path');
        static::__assert_true(Rsx_Project_Paths::is_generated_key('tmp/derived/babel/x.js'), 'derived cache is generated');
        static::__assert_false(Rsx_Project_Paths::is_generated_key('rsx/models/user_model.php'), 'source is not generated');
    }

    /**
     * Precedence: an in-process override beats an argv flag, which beats the resolver.
     */
    public static function test_override_beats_argv_flag_beats_resolver()
    {
        $default = Rsx_Project_Paths::build_root();
        $files_root = Rsx_Project_Paths::files_root();

        try {
            Rsx_Internal_Flags::set('--_rsx-build-root=/tmp/rsx-paths-test-flag');

            static::__assert_equals(
                '/tmp/rsx-paths-test-flag',
                Rsx_Project_Paths::build_root(),
                'the argv flag beats the resolver'
            );

            Rsx_Project_Paths::_override(['build' => '/tmp/rsx-paths-test-override']);

            static::__assert_equals(
                '/tmp/rsx-paths-test-override',
                Rsx_Project_Paths::build_root(),
                'the in-process override beats the argv flag'
            );
        } finally {
            static::__restore_run_isolation($files_root);
            Rsx_Internal_Flags::clear('--_rsx-build-root=/tmp/rsx-paths-test-flag');
        }

        static::__assert_equals($default, Rsx_Project_Paths::build_root(), 'the default is restored');
    }

    /**
     * An override travels to a child, because a subprocess that built into a
     * different tree than its parent reads would be worse than no isolation at all.
     */
    public static function test_an_override_travels_to_a_child()
    {
        $files_root = Rsx_Project_Paths::files_root();

        try {
            Rsx_Project_Paths::_override(['build' => '/tmp/rsx-paths-test-child', 'files' => '/tmp/rsx-paths-test-files']);

            $env = Rsx_Project_Paths::child_env();
            static::__assert_false(isset($env['RSX_BUILD' . '_PATH']), 'the build root has no environment key');
            static::__assert_equals(Rsx_Project_Paths::tmp_root(), $env['TMPDIR'], 'a child inherits TMPDIR as the tmp root');

            // The build override has no key, so it travels as an argv flag instead.
            $flags = Rsx_Project_Paths::child_flags();
            static::__assert_contains('--_rsx-build-root=/tmp/rsx-paths-test-child', implode(' ', $flags), 'build flag');
            static::__assert_contains('--_rsx-files-root=/tmp/rsx-paths-test-files', implode(' ', $flags), 'files flag');
        } finally {
            static::__restore_run_isolation($files_root);
        }
    }

    /**
     * files_root() follows the storage root until a run redirects it - the one
     * isolation a test run needs, and deliberately separate from storage_root()
     * so that state and locks stay shared with every child process.
     */
    public static function test_files_root_is_redirectable_and_storage_root_is_not()
    {
        $storage = Rsx_Project_Paths::storage_root();
        $files_root = Rsx_Project_Paths::files_root();

        try {
            Rsx_Project_Paths::_clear_overrides();
            static::__assert_equals($storage, Rsx_Project_Paths::files_root(), 'files_root follows storage by default');

            Rsx_Project_Paths::_override(['files' => '/tmp/rsx-paths-test-files-root']);

            static::__assert_equals('/tmp/rsx-paths-test-files-root', Rsx_Project_Paths::files_root(), 'files_root is redirected');
            static::__assert_equals($storage, Rsx_Project_Paths::storage_root(), 'storage_root is unmoved');
            static::__assert_equals($storage . '/state', Rsx_Project_Paths::state_root(), 'state is unmoved');
        } finally {
            static::__restore_run_isolation($files_root);
        }
    }

    /**
     * There is no fourth root: _override() refuses a name it does not own, rather
     * than silently accepting a typo and answering with the default forever.
     */
    public static function test_override_refuses_an_unknown_root()
    {
        static::__assert_throws(
            \InvalidArgumentException::class,
            static fn () => Rsx_Project_Paths::_override(['storage' => '/tmp/nope']),
            'unknown root'
        );
    }

    /**
     * ensure_build_tree() is the ONLY thing that creates the build root. Boot calls
     * the other two and never this one, so a production box with no build fails loud
     * naming the build command instead of growing an empty tree for a request to find
     * nothing in.
     */
    public static function test_only_ensure_build_tree_creates_the_build_root()
    {
        $files_root = Rsx_Project_Paths::files_root();
        $scratch = Rsx_Project_Paths::tmp_path('paths-test-' . getmypid());
        rmdir_recursive($scratch);

        try {
            Rsx_Project_Paths::_override(['build' => $scratch . '/build', 'tmp' => $scratch . '/tmp']);

            Rsx_Project_Paths::ensure_tmp_tree();
            static::__assert_true(is_dir($scratch . '/tmp'), 'ensure_tmp_tree created the tmp root');
            static::__assert_false(is_dir($scratch . '/build'), 'the tmp skeleton did not create the build root');

            Rsx_Project_Paths::ensure_build_tree();
            static::__assert_true(is_dir($scratch . '/build'), 'ensure_build_tree created the build root');
            static::__assert_true(is_dir($scratch . '/build/bundles'), 'and the bundle directory');
            static::__assert_true(is_dir($scratch . '/build/laravel'), 'and the Laravel cache directory');
            static::__assert_true(is_dir($scratch . '/build/views'), 'and the compiled-view directory');
        } finally {
            static::__restore_run_isolation($files_root);
            rmdir_recursive($scratch);
        }
    }

    /**
     * The tmp skeleton carries two links back into persistent storage, because Laravel's
     * storage path IS the tmp tree: without them a lazy storage_path('logs') would put
     * the application's log history in a directory rsx:clean wipes.
     */
    public static function test_the_tmp_skeleton_links_logs_and_app_into_storage()
    {
        $files_root = Rsx_Project_Paths::files_root();
        $scratch = Rsx_Project_Paths::tmp_path('paths-test-links-' . getmypid());
        rmdir_recursive($scratch);

        try {
            Rsx_Project_Paths::_override(['tmp' => $scratch . '/tmp']);
            Rsx_Project_Paths::ensure_tmp_tree();

            foreach (['logs' => Rsx_Project_Paths::logs_dir(), 'app' => Rsx_Project_Paths::app_dir()] as $name => $target) {
                $link = $scratch . '/tmp/' . $name;

                static::__assert_true(is_link($link), "tmp/{$name} is a symlink");
                static::__assert_equals($target, readlink($link), "tmp/{$name} points at the persistent directory");
            }

            // A link pointing elsewhere is machine-made and is repaired in place.
            @unlink($scratch . '/tmp/logs');
            @symlink($scratch . '/somewhere-else', $scratch . '/tmp/logs');
            Rsx_Project_Paths::ensure_tmp_tree();

            static::__assert_equals(
                Rsx_Project_Paths::logs_dir(),
                readlink($scratch . '/tmp/logs'),
                'a wrong target is replaced'
            );

            // A REAL directory is somebody's, and is refused rather than removed.
            @unlink($scratch . '/tmp/app');
            mkdir($scratch . '/tmp/app', 0775, true);

            static::__assert_throws(
                \RuntimeException::class,
                static fn () => Rsx_Project_Paths::ensure_tmp_tree(),
                'is a real directory'
            );
        } finally {
            static::__restore_run_isolation($files_root);
            rmdir_recursive($scratch);
        }
    }

    /**
     * Thumbnails and renditions are DERIVED from a blob that is still in the store, so
     * they are caches in tmp/ rather than user data in storage/.
     */
    public static function test_thumbnails_and_renditions_are_tmp_caches()
    {
        static::__assert_equals(
            Rsx_Project_Paths::tmp_path('thumbnails'),
            Rsx_Project_Paths::thumbnails_dir(),
            'the thumbnail cache is in tmp/'
        );

        static::__assert_equals(
            Rsx_Project_Paths::tmp_path('renditions'),
            Rsx_Project_Paths::renditions_dir(),
            'the rendition cache is in tmp/'
        );
    }
}
