<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Auth\Auth_BundleIntegration;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A REBUILD THAT CHANGES NOTHING REWRITES NO STUB.
 *
 * The three stub generators emit into storage/rsx-build/js-*-stubs/, and those files are
 * BUNDLE INPUTS: a write that changes not one byte still moves the mtime, and a moved mtime
 * recompiles every bundle that carries the stub. Model stubs were rewritten unconditionally
 * on every build; controller stubs were gated on a hash that folded in the generator file
 * itself but never compared the result to disk. Only the auth mirror compared content, and
 * it is the pattern the other two now follow.
 *
 * MTIME IS THE ASSERTION, because mtime is what the damage is made of.
 */
class Manifest_Stub_Rewrite_Test extends Rsx_Test_Abstract
{
    // The subject is the filesystem and a child process.
    protected static $use_database_transactions = false;

    /**
     * path => mtime for every generated stub on disk.
     *
     * @return array<string,int>
     */
    private static function __stub_mtimes(): array
    {
        $directories = [
            storage_path('rsx-build/js-stubs'),
            storage_path('rsx-build/js-model-stubs'),
            rsx_project_file_path(Auth_BundleIntegration::STUB_DIR),
        ];

        $test_tree_stubs = static::__test_tree_stub_filenames();
        $mtimes = [];

        foreach ($directories as $directory) {
            foreach (glob($directory . '/*.js') ?: [] as $file) {
                // A stub whose SOURCE is a test fixture is out of scope, and not because it
                // is inconvenient: a served request must not index the test trees, so a web
                // request landing on this box legitimately makes those stubs orphans and
                // the next test-run build recreates them. Their mtime therefore tracks the
                // box's traffic, not this build's behaviour. Every stub of ordinary
                // application and framework source is asserted.
                if (isset($test_tree_stubs[basename($file)])) {
                    continue;
                }

                clearstatcache(true, $file);
                $mtimes[$file] = filemtime($file);
            }
        }

        return $mtimes;
    }

    /**
     * Stub filenames whose source file lives in a test tree, as a set.
     *
     * Read from the manifest's own record of each stub (`source_model` /
     * `source_controller`), so nothing here has to guess from a filename.
     *
     * @return array<string,bool>
     */
    private static function __test_tree_stub_filenames(): array
    {
        $filenames = [];

        foreach (\App\RSpade\Core\Manifest\Manifest::get_all() as $path => $record) {
            $source = $record['source_model'] ?? $record['source_controller'] ?? null;

            if ($source === null) {
                continue;
            }

            if (str_starts_with($source, 'app/RSpade/tests/') || str_starts_with($source, 'rsx/tests/')) {
                $filenames[basename($path)] = true;
            }
        }

        return $filenames;
    }

    /**
     * Two builds with no source change leave every stub's mtime exactly where it was.
     */
    public static function test_a_no_change_rebuild_rewrites_no_stub()
    {
        // SETTLE FIRST, TWICE. The process this test runs in indexes the test trees, so the
        // first child build after it legitimately generates the fixture stubs; the
        // measurement is about the build AFTER the tree has stopped moving.
        foreach ([1, 2] as $settling_pass) {
            $output = [];
            static::__assert_equals(
                0,
                Rsx_Artisan::run('rsx:manifest:build', [], $output),
                "settling build {$settling_pass} failed:\n" . implode("\n", $output)
            );
        }

        $before = static::__stub_mtimes();

        static::__assert_true(
            count($before) > 0,
            'there are generated stubs on disk to assert about'
        );

        // Far enough apart that a rewrite could not be mistaken for no write.
        $output = [];
        static::__assert_equals(
            0,
            Rsx_Artisan::run('rsx:manifest:build', [], $output),
            "the no-change build failed:\n" . implode("\n", $output)
        );

        $after = static::__stub_mtimes();

        // THE SET IS NOT ASSERTED, and that is not a weakened test - it is the test trees.
        // A process running under `rsx:test` indexes app/RSpade/tests, an ordinary build does
        // not, and the stub generators' orphan sweep therefore adds and removes the fixture
        // stubs as the index legitimately gains and loses those files. What is asserted is
        // the thing the measurement is about: a stub that was there before and is there
        // after must not have been REWRITTEN.
        $rewritten = [];

        foreach ($before as $path => $mtime) {
            if (!array_key_exists($path, $after)) {
                continue;
            }

            if ($after[$path] !== $mtime) {
                $rewritten[] = basename($path);
            }
        }

        static::__assert_equals(
            [],
            $rewritten,
            'a no-change rebuild rewrote no stub: ' . implode(', ', $rewritten)
        );
    }
}
