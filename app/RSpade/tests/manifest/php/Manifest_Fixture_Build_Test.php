<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE BUILD, OVER A TREE THE TEST WROTE.
 *
 * Until `Manifest_Build` existed, "build a manifest" meant "build THE manifest, from
 * config, into the developer's own storage directory" - so there was nothing to assert
 * against except the index the developer was already using, and no way to say what an
 * incremental rebuild actually re-parsed.
 *
 * Every build here happens in a CHILD process against a scratch storage root, and the
 * assertion is made against the index file the child wrote. In-process would mean
 * replacing `Manifest::$data` (and the autoloader behind it) inside a test process that is
 * using it.
 */
class Manifest_Fixture_Build_Test extends Rsx_Test_Abstract
{
    // The subject is the filesystem and a child process; nothing here touches the database.
    protected static $use_database_transactions = false;

    /** The fixture tree, relative to base_path(). Under temp/ so the index can name it. */
    private static string $tree = '';

    /** Absolute path of the scratch storage root the child writes its index to. */
    private static string $storage = '';

    /**
     * A fixture tree of one file of every kind the build indexes differently.
     *
     * Each one is shaped the way the build INSISTS a real file is shaped, because the build
     * enforces it: the PHP namespace must match the path (Php_Fixer throws otherwise), an
     * `@rsx_id` must be `Name_Type` (the blade module validates it), and a `<Define>` may
     * not carry a `class` equal to its own name.
     *
     * @return array<string,string> relative path => contents
     */
    private static function __fixture_files(): array
    {
        $namespace = 'App\\RSpade\\Temp\\' . static::__namespace_segment();

        return [
            'fixture_widget.php' => "<?php\n\nnamespace {$namespace};\n\nclass Fixture_Widget\n{\n"
                . "    public static function label(): string\n    {\n        return 'fixture';\n    }\n}\n",
            'fixture_thing.js' => "class Fixture_Thing {\n    static label() {\n        return 'fixture';\n    }\n}\n",
            'fixture_panel.jqhtml' => "<Define:Fixture_Panel>\n    <div class=\"Fixture_Panel__body\">fixture</div>\n</Define:Fixture_Panel>\n",
            'fixture_page.blade.php' => "@rsx_id('Fixture_Page')\n<div class=\"fixture-page\">fixture</div>\n",
            'fixture_panel.scss' => ".Fixture_Panel {\n    &__body {\n        color: #333;\n    }\n}\n",
        ];
    }

    /**
     * The PascalCased last path segment, which is what the namespace validator demands.
     */
    private static function __namespace_segment(): string
    {
        $parts = explode('_', basename(static::$tree));

        return implode('', array_map('ucfirst', $parts));
    }

    /**
     * Write the fixture tree and the scratch storage root.
     */
    private static function __make_tree(): void
    {
        // The pid keeps two concurrent runs (the docker workers) out of each other's tree.
        static::$tree = 'app/RSpade/temp/manifest_fixture' . getmypid();
        static::$storage = storage_path('rsx-tmp/manifest-test/' . getmypid());

        static::__remove_tree();

        $absolute = base_path(static::$tree);
        ensure_directory($absolute);
        ensure_directory(static::$storage);

        foreach (static::__fixture_files() as $name => $contents) {
            file_put_contents($absolute . '/' . $name, $contents);
        }
    }

    private static function __remove_tree(): void
    {
        if (static::$tree !== '' && is_dir(base_path(static::$tree))) {
            static::__rmdir_recursive(base_path(static::$tree));
        }

        if (static::$storage !== '' && is_dir(static::$storage)) {
            static::__rmdir_recursive(static::$storage);
        }
    }

    private static function __rmdir_recursive(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($directory);
    }

    /**
     * Build in a child against the scratch root, and return the index it wrote.
     *
     * @return array the manifest structure
     */
    private static function __build(): array
    {
        $output = [];

        $exit = Rsx_Artisan::run('rsx:manifest:build', [
            '--_manifest-storage-root=' . static::$storage,
            '--_manifest-extra-scan-roots=' . static::$tree,
        ], $output);

        static::__assert_equals(
            0,
            $exit,
            "the fixture build failed:\n" . implode("\n", $output)
        );

        $path = static::$storage . '/rsx-build/manifest_index.php';

        static::__assert_true(
            file_exists($path),
            'the child wrote its index to the scratch storage root (' . $path . ')'
        );

        $manifest = include $path;

        // The index is TWO files (rsx:man manifest_build, Phase 7). These tests are about
        // WHAT WAS INDEXED, not about which half a record landed in, so they read the union -
        // the same view Manifest::get_all() gives a caller. The hot/cold contract itself is
        // asserted by test_the_build_writes_both_halves_of_the_index and by
        // Manifest_Cold_Isolation_Test.
        $cold_path = static::$storage . '/rsx-build/manifest_files.php';

        if (file_exists($cold_path)) {
            $manifest['data']['files'] = $manifest['data']['files'] + (include $cold_path);
        }

        return $manifest;
    }

    /**
     * Every fixture file's index entry, keyed by basename.
     *
     * @return array<string,array>
     */
    private static function __fixture_entries(array $manifest): array
    {
        $entries = [];

        foreach ($manifest['data']['files'] as $path => $metadata) {
            if (str_starts_with($path, static::$tree . '/')) {
                $entries[basename($path)] = $metadata;
            }
        }

        return $entries;
    }

    /**
     * A tree of five kinds of file is indexed as five kinds of file.
     */
    public static function test_fixture_tree_is_indexed()
    {
        static::__make_tree();

        try {
            $manifest = static::__build();
            $entries = static::__fixture_entries($manifest);

            static::__assert_count(5, $entries, 'all five fixture files are in the index');

            static::__assert_equals(
                'Fixture_Widget',
                $entries['fixture_widget.php']['class'] ?? null,
                'the PHP class was extracted'
            );

            static::__assert_equals(
                'Fixture_Thing',
                $entries['fixture_thing.js']['class'] ?? null,
                'the JS class was extracted'
            );

            static::__assert_true(
                isset($manifest['data']['php_classes']['Fixture_Widget']),
                'the PHP class map names the fixture class'
            );

            static::__assert_true(
                isset($manifest['data']['js_classes']['Fixture_Thing']),
                'the JS class map names the fixture class'
            );

            static::__assert_true(
                isset($manifest['data']['jqhtml']['components']['Fixture_Panel']),
                'the jqhtml component registry names the fixture component'
            );

            static::__assert_true(
                isset($entries['fixture_panel.scss']),
                'the stylesheet is indexed'
            );

            static::__assert_true(
                isset($entries['fixture_page.blade.php']),
                'the blade view is indexed'
            );
        } finally {
            static::__remove_tree();
        }
    }

    /**
     * AN EDIT RE-PARSES THAT FILE AND NOTHING ELSE.
     *
     * The evidence is the per-file hash: the manifest recomputes it only for a file whose
     * size or mtime moved, so a second build in which exactly one hash differs is a second
     * build that re-read exactly one file.
     */
    public static function test_editing_one_file_reparses_only_that_file()
    {
        static::__make_tree();

        try {
            $before = static::__hashes(static::__build());

            // A content change big enough to move the size, so the mtime granularity of the
            // filesystem cannot decide the outcome.
            $target = base_path(static::$tree . '/fixture_widget.php');
            file_put_contents(
                $target,
                str_replace("return 'fixture';", "return 'fixture edited once';", file_get_contents($target))
            );

            $after = static::__hashes(static::__build());

            $moved = [];

            foreach ($after as $path => $hash) {
                if (($before[$path] ?? null) !== $hash) {
                    $moved[] = $path;
                }
            }

            static::__assert_equals(
                [static::$tree . '/fixture_widget.php'],
                $moved,
                'exactly the edited file was re-parsed'
            );
        } finally {
            static::__remove_tree();
        }
    }

    /**
     * A file that appears between builds is indexed.
     */
    public static function test_adding_a_file_indexes_it()
    {
        static::__make_tree();

        try {
            static::__build();

            $namespace = 'App\\RSpade\\Temp\\' . static::__namespace_segment();

            file_put_contents(
                base_path(static::$tree . '/fixture_added.php'),
                "<?php\n\nnamespace {$namespace};\n\nclass Fixture_Added\n{\n"
                . "    public static function label(): string\n    {\n        return 'added';\n    }\n}\n"
            );

            $manifest = static::__build();

            static::__assert_true(
                isset($manifest['data']['files'][static::$tree . '/fixture_added.php']),
                'the added file is in the index'
            );

            static::__assert_true(
                isset($manifest['data']['php_classes']['Fixture_Added']),
                'the added class is in the class map'
            );
        } finally {
            static::__remove_tree();
        }
    }

    /**
     * A file that disappears between builds leaves the index.
     */
    public static function test_removing_a_file_drops_it()
    {
        static::__make_tree();

        try {
            $manifest = static::__build();

            static::__assert_true(
                isset($manifest['data']['files'][static::$tree . '/fixture_thing.js']),
                'the file is indexed before it is removed'
            );

            unlink(base_path(static::$tree . '/fixture_thing.js'));

            $manifest = static::__build();

            static::__assert_false(
                isset($manifest['data']['files'][static::$tree . '/fixture_thing.js']),
                'the removed file left the index'
            );

            static::__assert_false(
                isset($manifest['data']['js_classes']['Fixture_Thing']),
                'the removed class left the JS class map'
            );
        } finally {
            static::__remove_tree();
        }
    }

    /**
     * [relative path => hash] for every indexed file.
     *
     * @return array<string,string>
     */
    private static function __hashes(array $manifest): array
    {
        $hashes = [];

        foreach ($manifest['data']['files'] as $path => $metadata) {
            $hashes[$path] = (string) ($metadata['hash'] ?? '');
        }

        return $hashes;
    }

    /**
     * TWO BUILDS OF AN UNCHANGED TREE PRODUCE BYTE-IDENTICAL FILES.
     *
     * The index has no timestamp in it any more, in any mode: the bytes of a build are a
     * function of the tree and nothing else. That is what makes two builds comparable at all,
     * and it is the property a cluster keys on - `rsx:prod:verify` compares asset hashes, so
     * a wall-clock stamp in the body would make every host disagree with every other.
     */
    public static function test_two_builds_of_an_unchanged_tree_are_byte_identical()
    {
        static::__make_tree();

        try {
            static::__build();

            $first = [
                'index' => file_get_contents(static::$storage . '/rsx-build/manifest_index.php'),
                'files' => file_get_contents(static::$storage . '/rsx-build/manifest_files.php'),
                'key' => file_get_contents(static::$storage . '/rsx-build/build_key'),
            ];

            static::__build();

            static::__assert_equals(
                $first['index'],
                file_get_contents(static::$storage . '/rsx-build/manifest_index.php'),
                'the hot index is byte-identical across two builds of an unchanged tree'
            );

            static::__assert_equals(
                $first['files'],
                file_get_contents(static::$storage . '/rsx-build/manifest_files.php'),
                'the cold index is byte-identical across two builds of an unchanged tree'
            );

            static::__assert_equals(
                $first['key'],
                file_get_contents(static::$storage . '/rsx-build/build_key'),
                'the build key is stable across two builds of an unchanged tree'
            );
        } finally {
            static::__remove_tree();
        }
    }

    /**
     * The build writes BOTH halves, and the cold one carries the method maps.
     */
    public static function test_the_build_writes_both_halves_of_the_index()
    {
        static::__make_tree();

        try {
            $manifest = static::__build();

            $cold_path = static::$storage . '/rsx-build/manifest_files.php';

            static::__assert_true(file_exists($cold_path), 'the cold half was written');

            $cold = include $cold_path;

            static::__assert_true(
                isset($cold[static::$tree . '/fixture_widget.php']['public_static_methods']['label']),
                "the fixture class's method map is in the COLD half"
            );

            // Read the HOT file directly - __build() returns the union.
            $hot = include static::$storage . '/rsx-build/manifest_index.php';

            static::__assert_false(
                isset($hot['data']['files'][static::$tree . '/fixture_widget.php']),
                'and not in the hot one'
            );

            // The staleness sweep still sees it: file_index spans the whole tree.
            static::__assert_true(
                isset($manifest['data']['file_index'][static::$tree . '/fixture_widget.php']),
                'file_index carries every indexed file, hot or cold'
            );
        } finally {
            static::__remove_tree();
        }
    }

    /**
     * A MISSING SCAN ROOT IS FATAL - unless it is one of the three test trees.
     *
     * The test trees are put on the list by the test RUN, not by the operator, and every one
     * of them is legitimately absent (an application that keeps its suites beside the code it
     * tests has no rsx/tests at all), so _get_rsx_files() skips a missing one silently.
     * Anything else on the list came from config and stays a loud failure: a served site's
     * own root going missing is a broken install.
     *
     * Only the fatal half is exercised here. The skip half needs one of the three fixed
     * names - app/RSpade/tests, app/RSpade/temp, rsx/tests - to be absent from this tree,
     * and all three are present in the monorepo.
     */
    public static function test_a_missing_scan_root_that_is_not_a_test_tree_is_fatal()
    {
        // Inside app/RSpade/temp, so it is NOT the temp tree itself - a root under a test
        // tree is a deliberate request to index exactly that subtree.
        $absent = 'app/RSpade/temp/absent_scan_root' . getmypid();

        $output = [];

        $exit = Rsx_Artisan::run('rsx:manifest:build', [
            '--_manifest-storage-root=' . storage_path('rsx-tmp/manifest-test/absent' . getmypid()),
            '--_manifest-extra-scan-roots=' . $absent,
        ], $output);

        static::__assert_not_equals(0, $exit, 'the build refuses a scan root that does not exist');

        static::__assert_contains(
            'Manifest scan path does not exist',
            implode("\n", $output),
            'and says which path it could not find'
        );
    }
}
