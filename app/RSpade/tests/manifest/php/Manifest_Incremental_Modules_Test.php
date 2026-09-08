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
 * THE INCREMENTAL CONTRACT, AS AN EQUALITY.
 *
 * Every support module now updates its own index section from the CHANGED and REMOVED sets
 * instead of rebuilding it from a pass over the whole file map. That is only worth having if
 * it produces the same answer, and "the same answer" is not a thing prose can check: the
 * only honest assertion is that an INCREMENTALLY updated index is byte-identical to the one
 * a COLD build of the same tree produces.
 *
 * So each test builds a fixture tree twice - once incrementally (build, edit, build again in
 * the SAME storage root) and once cold (the final tree, in a FRESH storage root) - and
 * compares the two indexes section by section. A module that forgot to drop a stale row, or
 * dropped one it should have kept, cannot survive that.
 *
 * Both builds are CHILD processes against scratch storage roots, for the reason
 * Manifest_Fixture_Build_Test documents: replacing Manifest::$data in a process that is
 * using it is not a test, it is a hazard.
 */
class Manifest_Incremental_Modules_Test extends Rsx_Test_Abstract
{
    // The subject is the filesystem and two child processes.
    protected static $use_database_transactions = false;

    /** The fixture tree, relative to base_path(). */
    private static string $tree = '';

    /** The storage root the INCREMENTAL build writes to (reused across builds). */
    private static string $incremental_storage = '';

    /** The storage root the COLD build writes to (fresh). */
    private static string $cold_storage = '';

    /**
     * Sections whose value is a function of the tree and must therefore match exactly.
     *
     * `file_index` is excluded because it carries per-file mtime, which the edit moves by
     * design; the per-file records are compared separately, with the edited file's own
     * volatile fields excluded.
     */
    private const COMPARED_SECTIONS = [
        'routes',
        'portal_routes',
        'api_endpoints',
        'jqhtml',
        'external_resources',
        'task_commands',
        'emails',
        'bundle_aliases',
        'auth',
        'models',
        'php_classes',
        'js_classes',
        'php_subclass_index',
        'js_subclass_index',
        'attribute_index',
        'blade_views',
        'models_by_table',
        'autoloader_class_map',
        'event_handlers',
        'classless_php_files',
    ];

    private static function __namespace_segment(): string
    {
        $parts = explode('_', basename(static::$tree));

        return implode('', array_map('ucfirst', $parts));
    }

    /**
     * The fixture tree: one file of each kind a module reads, so every module has something
     * of its own in the index to get wrong.
     *
     * @return array<string,string>
     */
    private static function __fixture_files(string $action_label): array
    {
        $namespace = 'App\\RSpade\\Temp\\' . static::__namespace_segment();

        return [
            'fixture_inc_widget.php' => "<?php\n\nnamespace {$namespace};\n\nclass Fixture_Inc_Widget\n{\n"
                . "    public static function label(): string\n    {\n        return 'fixture';\n    }\n}\n",
            'fixture_inc_action.js' => "class Fixture_Inc_Action {\n    static label() {\n        return '{$action_label}';\n    }\n}\n",
            'fixture_inc_panel.jqhtml' => "<Define:Fixture_Inc_Panel>\n    <div class=\"Fixture_Inc_Panel__body\">fixture</div>\n</Define:Fixture_Inc_Panel>\n",
            'fixture_inc_page.blade.php' => "@rsx_id('Fixture_Inc_Page')\n<div class=\"fixture-inc-page\">fixture</div>\n",
            'fixture_inc_panel.scss' => ".Fixture_Inc_Panel {\n    &__body {\n        color: #333;\n    }\n}\n",
        ];
    }

    private static function __write_tree(string $action_label): void
    {
        $absolute = base_path(static::$tree);
        ensure_directory($absolute);

        foreach (static::__fixture_files($action_label) as $name => $contents) {
            file_put_contents($absolute . '/' . $name, $contents);
        }
    }

    private static function __make_tree(): void
    {
        static::$tree = 'app/RSpade/temp/manifest_incremental' . getmypid();
        static::$incremental_storage = storage_path('rsx-tmp/manifest-incremental/' . getmypid() . '/inc');
        static::$cold_storage = storage_path('rsx-tmp/manifest-incremental/' . getmypid() . '/cold');

        static::__remove_tree();

        ensure_directory(static::$incremental_storage);
        ensure_directory(static::$cold_storage);

        static::__write_tree('before');
    }

    private static function __remove_tree(): void
    {
        foreach ([base_path(static::$tree), dirname(static::$incremental_storage)] as $directory) {
            if ($directory !== '' && is_dir($directory)) {
                static::__rmdir_recursive($directory);
            }
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
     * Build in a child against $storage, and return the index it wrote (both halves).
     */
    private static function __build(string $storage): array
    {
        $output = [];

        $exit = Rsx_Artisan::run('rsx:manifest:build', [
            '--_manifest-storage-root=' . $storage,
            '--_manifest-extra-scan-roots=' . static::$tree,
        ], $output);

        static::__assert_equals(0, $exit, "the fixture build failed:\n" . implode("\n", $output));

        $manifest = include $storage . '/rsx-build/manifest_index.php';
        $cold_path = $storage . '/rsx-build/manifest_files.php';

        if (file_exists($cold_path)) {
            $manifest['data']['files'] = $manifest['data']['files'] + (include $cold_path);
        }

        return $manifest;
    }

    /**
     * A one-file JS change, applied incrementally, produces the index a cold build produces.
     */
    public static function test_an_incremental_js_edit_matches_a_cold_build()
    {
        static::__make_tree();

        try {
            // 1. Cold, into the incremental root.
            static::__build(static::$incremental_storage);

            // 2. Edit ONE JS file - and only that file, so the second build's changed set
            //    is exactly one path and every module has to get the rest right from what it
            //    carried forward.
            file_put_contents(
                base_path(static::$tree) . '/fixture_inc_action.js',
                static::__fixture_files('after')['fixture_inc_action.js']
            );
            clearstatcache();

            // 3. Incremental, into the SAME root.
            $incremental = static::__build(static::$incremental_storage);

            // 4. Cold, into a fresh root, over the tree as it now stands.
            $cold = static::__build(static::$cold_storage);

            static::__assert_sections_match($incremental, $cold);
            static::__assert_file_records_match($incremental, $cold);
        } finally {
            static::__remove_tree();
        }
    }

    /**
     * ADDING and then REMOVING a file leaves the index a cold build would produce - the
     * removal half of the contract, which no changed-file list ever mentions.
     */
    public static function test_an_incremental_remove_matches_a_cold_build()
    {
        static::__make_tree();

        try {
            $absolute = base_path(static::$tree);

            // A second component, present for the first build only.
            file_put_contents(
                $absolute . '/fixture_inc_extra.jqhtml',
                "<Define:Fixture_Inc_Extra>\n    <div class=\"Fixture_Inc_Extra__body\">x</div>\n</Define:Fixture_Inc_Extra>\n"
            );

            static::__build(static::$incremental_storage);

            unlink($absolute . '/fixture_inc_extra.jqhtml');
            clearstatcache();

            $incremental = static::__build(static::$incremental_storage);
            $cold = static::__build(static::$cold_storage);

            static::__assert_false(
                isset($incremental['data']['jqhtml']['components']['Fixture_Inc_Extra']),
                'the removed component is gone from the incrementally-updated registry'
            );

            static::__assert_sections_match($incremental, $cold);
            static::__assert_file_records_match($incremental, $cold);
        } finally {
            static::__remove_tree();
        }
    }

    /**
     * Every derived section, field by field.
     */
    private static function __assert_sections_match(array $incremental, array $cold): void
    {
        foreach (self::COMPARED_SECTIONS as $section) {
            static::__assert_equals(
                json_encode($cold['data'][$section] ?? null),
                json_encode($incremental['data'][$section] ?? null),
                "the '{$section}' index matches the one a cold build produces"
            );
        }
    }

    /**
     * Every file record, minus the fields an edit legitimately moves.
     */
    private static function __assert_file_records_match(array $incremental, array $cold): void
    {
        $strip = static function (array $files): array {
            $stripped = [];

            foreach ($files as $path => $record) {
                unset($record['mtime'], $record['size'], $record['hash']);
                $stripped[$path] = $record;
            }

            ksort($stripped);

            return $stripped;
        };

        $incremental_files = $strip($incremental['data']['files']);
        $cold_files = $strip($cold['data']['files']);

        static::__assert_equals(
            array_keys($cold_files),
            array_keys($incremental_files),
            'the two builds indexed the same files'
        );

        foreach ($cold_files as $path => $record) {
            static::__assert_equals(
                json_encode($record),
                json_encode($incremental_files[$path] ?? null),
                "the record for {$path} matches the cold build's\n  cold: " . json_encode($record)
                    . "\n   inc: " . json_encode($incremental_files[$path] ?? null)
            );
        }
    }
}
