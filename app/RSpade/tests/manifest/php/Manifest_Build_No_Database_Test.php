<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A DEVELOPMENT MANIFEST BUILD ISSUES ZERO DATABASE STATEMENTS.
 *
 * The index holds code facts only, and so does everything a build generates from it - the
 * JS model stubs included, which carry no column lengths (a page reads those from its
 * bundle's field-length script, at run time). So the whole build - scan, every support
 * module, the stub generators, the manifest-time quality pass - asks the database nothing.
 *
 * Proven on a COLD build of the whole tree in a child process, against scratch build and tmp
 * roots so every stub is generated from nothing and the developer's own are left alone. The
 * child counts what it issued while building under the --_manifest-report-cold probe; the
 * statements a process issues for other reasons (booting, the command's own tail) are not
 * the build's and are not counted.
 *
 * AND THE PROCESS THAT BUILDS NEEDS NO DATABASE EITHER. Booting registers the polymorphic
 * morph map, which reads _type_refs; a process that produces the build registers it on its
 * first database statement instead (Type_Ref_Registry::register_morph_map_on_first_query()),
 * so `rsx:manifest:build` runs to completion against a database host nothing answers on -
 * while every other command, pointed at the same host, still fails at boot as it always did.
 */
class Manifest_Build_No_Database_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static string $root = '';

    public static function setup()
    {
        // The pid keeps two concurrent runs out of each other's tree.
        static::$root = Rsx_Project_Paths::tmp_path('manifest-test/no-database-' . getmypid());
        static::__remove();
    }

    public static function teardown()
    {
        static::__remove();
    }

    private static function __remove(): void
    {
        if (static::$root !== '' && is_dir(static::$root)) {
            rmdir_recursive(static::$root);
        }
    }

    public static function test_a_cold_build_of_the_whole_tree_issues_no_statement()
    {
        $output = [];

        $exit = Rsx_Artisan::run('rsx:manifest:build', [
            '--_rsx-build-root=' . static::$root . '/build',
            '--_rsx-tmp-root=' . static::$root . '/tmp',
            '--_manifest-report-cold',
        ], $output);

        $text = implode("\n", $output);

        static::__assert_equals(0, $exit, "the build failed:\n" . $text);
        static::__assert_contains('MANIFEST_REBUILT=1', $text, 'sanity: the child built (a build that did not run proves nothing)');

        static::__assert_true(
            (bool) preg_match('/^MANIFEST_BUILD_DB_STATEMENTS=(\d+)$/m', $text, $matches),
            "the child reported how many statements its build issued:\n" . $text
        );

        $statements = [];
        foreach ($output as $line) {
            if (str_starts_with(trim($line), 'MANIFEST_BUILD_DB_STATEMENT: ')) {
                $statements[] = trim($line);
            }
        }

        static::__assert_equals(
            0,
            (int) $matches[1],
            "a manifest build asked the database nothing:\n  " . implode("\n  ", $statements)
        );

        // The build that was counted generated the model stubs, and none of them carries
        // anything read from a schema.
        $stubs = glob(static::$root . '/tmp/js-model-stubs/*.js');

        static::__assert_greater_than(0, count($stubs), 'the counted build generated the model stubs');

        foreach ($stubs as $stub) {
            static::__assert_false(
                str_contains(file_get_contents($stub), 'field_length'),
                basename($stub) . ' carries no column lengths'
            );
        }
    }

    /**
     * A database host nothing answers on: loopback, port 1. A connection attempt is refused
     * at once, so the child is not waiting on anything.
     */
    private const UNREACHABLE = ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '1'];

    public static function test_a_manifest_build_completes_with_no_reachable_database()
    {
        $output = [];

        $exit = Rsx_Artisan::run('rsx:manifest:build', [
            '--_rsx-build-root=' . static::$root . '/unreachable/build',
            '--_rsx-tmp-root=' . static::$root . '/unreachable/tmp',
        ], $output, self::UNREACHABLE);

        $text = implode("\n", $output);

        static::__assert_equals(0, $exit, "the build failed with no database to reach:\n" . $text);
        static::__assert_contains('Rebuilt: yes', $text, 'sanity: the child built');
        static::__assert_true(
            file_exists(static::$root . '/unreachable/build/manifest_index.php'),
            'the child wrote its index'
        );
        static::__assert_false(
            str_contains($text, 'SQLSTATE'),
            "the build reported no database error:\n" . $text
        );
    }

    public static function test_the_same_host_still_fails_a_process_that_is_not_a_build()
    {
        // The control: the host really is unreachable, and only a build is excused from it.
        $output = [];

        $exit = Rsx_Artisan::run('rsx:manifest:get_build_key', [], $output, self::UNREACHABLE);

        static::__assert_true($exit !== 0, 'a command that is not a build still needs its database at boot');
        static::__assert_contains('SQLSTATE', implode("\n", $output), 'and it says the connection failed');
    }

    public static function test_a_deferred_morph_map_is_registered_before_the_first_statement()
    {
        $original = Relation::morphMap();

        // A registry with at least one row to register, in a transaction this test rolls
        // back: the migrated baseline may hold no type ref at all.
        DB::beginTransaction();

        try {
            $id = Type_Ref_Registry::class_to_id('User_Model');

            Relation::morphMap([], false);
            Type_Ref_Registry::register_morph_map_on_first_query();

            static::__assert_equals([], Relation::morphMap(), 'deferring registers nothing by itself');

            DB::select('SELECT 1');

            $registered = Relation::morphMap();

            static::__assert_true(
                isset($registered['User_Model']) && isset($registered[$id])
                    && $registered['User_Model'] === $registered[$id],
                'the first statement the process issues is preceded by the registration, under both aliases'
            );
        } finally {
            DB::rollBack();
            Type_Ref_Registry::_reset_cached_state();
            Relation::morphMap($original, false);
        }
    }
}
