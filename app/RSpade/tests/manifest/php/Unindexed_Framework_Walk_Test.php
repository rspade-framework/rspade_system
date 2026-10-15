<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Manifest\Manifest_Indexer;
use App\RSpade\Core\Manifest\Manifest_Scanner;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE WALK OF UNINDEXED FRAMEWORK CODE (Manifest_Scanner::_walk_framework_files()).
 *
 * The manifest never indexes some framework subtrees (Commands, Ide, SchemaQuality, ...), yet
 * the autoloader resolves their classes by simple name and the override pass refuses an
 * application namesake of one. Both learn those classes from one walk, which honours the
 * scanner's own exclusions (Manifest_Scanner::_is_excluded_directory_name()): a resource/,
 * public/, hidden or configured-excluded directory - and vendor/, node_modules/ - is pruned
 * at every depth, because what it holds is data the framework never loads. The vendored
 * reference app at app/RSpade/resource/reference_app/ is the case that matters: its classes
 * share every name with an application built from it.
 */
class Unindexed_Framework_Walk_Test extends Rsx_Test_Abstract
{
    // The subject is the filesystem.
    protected static $use_database_transactions = false;

    /** @param array<string,string> $files absolute path => content */
    private static function __write(array $files): void
    {
        foreach ($files as $path => $content) {
            ensure_directory(dirname($path));
            file_put_contents($path, $content);
        }

        clearstatcache();
    }

    private static function __class_file(string $namespace, string $class): string
    {
        return "<?php\n\nnamespace {$namespace};\n\nclass {$class}\n{\n}\n";
    }

    /**
     * Every excluded directory is pruned at any depth, and everything else is walked.
     */
    public static function test_the_walk_prunes_every_excluded_directory_at_any_depth()
    {
        $root = Rsx_Project_Paths::tmp_path('unindexed-walk/' . getmypid());

        try {
            static::__write([
                $root . '/Uw_Kept.php' => static::__class_file('Uw', 'Uw_Kept'),
                $root . '/deep/er/Uw_Kept_Deep.php' => static::__class_file('Uw', 'Uw_Kept_Deep'),
                $root . '/resource/Uw_Resource.php' => static::__class_file('Uw', 'Uw_Resource'),
                $root . '/deep/resource/reference_app/Uw_Nested_Resource.php' => static::__class_file('Uw', 'Uw_Nested_Resource'),
                $root . '/deep/public/Uw_Public.php' => static::__class_file('Uw', 'Uw_Public'),
                $root . '/.hidden/Uw_Hidden.php' => static::__class_file('Uw', 'Uw_Hidden'),
                $root . '/vendor/acme/Uw_Vendor.php' => static::__class_file('Uw', 'Uw_Vendor'),
                $root . '/deep/node_modules/x/Uw_Node.php' => static::__class_file('Uw', 'Uw_Node'),
            ]);

            $classes = array_keys(Manifest_Scanner::_scan_directory_for_classes($root));
            sort($classes);

            static::__assert_equals(['Uw_Kept', 'Uw_Kept_Deep'], $classes, 'only the classes outside every excluded directory are found');
        } finally {
            if (is_dir($root)) {
                rmdir_recursive($root);
            }
        }
    }

    /**
     * A REAL (non-symlink) tree inside app/RSpade/resource - the shape the published framework
     * ships the reference app in - contributes nothing to the unindexed class map, while the
     * unscanned subtrees the framework does load still do.
     */
    public static function test_the_unindexed_class_map_never_reads_the_framework_resource_tree()
    {
        $tree = base_path('app/RSpade/resource/uw_' . getmypid());

        try {
            static::__write([
                $tree . '/reference_app/app/Uw_Template_Probe.php' => static::__class_file('Rsx\\App', 'Uw_Template_Probe'),
                $tree . '/reference_app/Main_Uw_Probe.php' => static::__class_file('Rsx', 'Main_Uw_Probe'),
            ]);

            static::__assert_false(is_link(dirname($tree)), 'app/RSpade/resource is a real directory');

            $classes = Manifest_Indexer::_unindexed_framework_classes();

            static::__assert_false(isset($classes['Uw_Template_Probe']), 'a class in the resource tree is not an unindexed framework class');
            static::__assert_false(isset($classes['Main_Uw_Probe']), 'nor is a root-namespace one');
            static::__assert_true(isset($classes['Manifest_Build_Command']), 'the unscanned Commands subtree is still walked');

            $from_resource = array_filter(
                array_merge(...array_values($classes)),
                static fn ($fqcn) => str_starts_with(ltrim($fqcn, '\\'), 'App\\RSpade\\Resource\\')
            );

            static::__assert_equals([], array_values($from_resource), 'no unindexed class carries the app/RSpade/resource namespace');
        } finally {
            if (is_dir($tree)) {
                rmdir_recursive($tree);
            }
        }
    }
}
