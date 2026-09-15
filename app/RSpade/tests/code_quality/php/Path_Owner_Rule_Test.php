<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Convention\PathOwner_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for PATH-OWNER-01 (PathOwner_CodeQualityRule).
 *
 * The rule exists because a literal volatile path does not ERROR after a relocation -
 * it addresses a directory that exists, is writable, and that nothing else reads. The
 * writer succeeds, the reader finds nothing, and the defect surfaces somewhere else
 * entirely. So the assertions that matter are: it fires on each shape it is supposed
 * to catch, it does NOT fire on a path taken from the owner, the allow-list genuinely
 * exempts the files that legitimately produce build outputs, and the exception comment
 * suppresses.
 *
 * Every needle in this file is assembled from constants split across the `=`, so this
 * file - which rsx:check also scans - carries no violation of its own.
 */
class Path_Owner_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is text inspection over temp fixture files - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'PATH-OWNER-01';

    /** The marker, split so this file never carries a live one. */
    private const MARKER = '@PATH-OWNER-01' . '-EXCEPTION';

    /** Retired directory names, split so the rule never matches this file. */
    private const OLD_BUILD = 'rsx-' . 'build';
    private const OLD_TMP = 'rsx-' . 'tmp';
    private const OLD_STATE = 'rsx-' . 'framework';
    private const OLD_THUMBNAILS = 'rsx-' . 'thumbnails';

    /** The retired build key and the retired links, split for the same reason. */
    private const OLD_BUILD_KEY = 'RSX_BUILD' . '_PATH';
    private const OLD_STORAGE_LINK = 'system' . '/storage';

    /** Tree names, split so an assembled needle never reads as a literal here. */
    private const TREE_BUILD = 'bui' . 'ld';
    private const TREE_TMP = 't' . 'mp';
    private const TREE_STORAGE = 'stor' . 'age';

    /** The node joiner and the bash root variable, split for the same reason. */
    private const PATH_JOIN = 'path.' . 'join';
    private const BASH_ROOT = '$PROJECT' . '_ROOT';

    /** Helper names, split for the same reason. */
    private const STORAGE_PATH = 'storage_' . 'path';
    private const BASE_PATH = 'base_' . 'path';

    /**
     * Run the rule over $source as if it sat at $relative_name, and return what it said.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $source, string $relative_name = 'rsx/lib/probe.php'): array
    {
        $root = Rsx_Project_Paths::tmp_path('path_owner_01_fixture_' . uniqid());
        $path = $root . '/' . $relative_name;
        ensure_directory(dirname($path));
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new PathOwner_CodeQualityRule($collector);
        $rule->check($path, $source);

        $violations = $collector->get_by_rule(self::RULE_ID);

        static::__remove_tree($root);

        return $violations;
    }

    private static function __remove_tree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;

            if (is_dir($child) && !is_link($child)) {
                static::__remove_tree($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }

    // =====================================================================
    // It fires
    // =====================================================================

    /**
     * Each retired directory name is caught wherever it appears.
     */
    public static function test_a_retired_directory_name_fires()
    {
        foreach ([self::OLD_BUILD, self::OLD_TMP, self::OLD_STATE] as $name) {
            $source = "<?php\n\$p = 'storage/{$name}/thing';\n";

            static::__assert_greater_than(
                0,
                count(static::__run($source)),
                "'{$name}' is reported"
            );
        }
    }

    /**
     * The retired build key and the retired system/ links are reported wherever they
     * appear: build/ is fixed, and a relocatable root never had a link to promise.
     */
    public static function test_the_retired_build_key_and_links_fire()
    {
        $key_source = "<?php\n\$p = getenv('" . self::OLD_BUILD_KEY . "');\n";
        $link_source = "<?php\n\$p = '" . self::OLD_STORAGE_LINK . "/uploads';\n";

        $key_violations = static::__run($key_source);

        static::__assert_greater_than(0, count($key_violations), 'the retired key is reported');
        static::__assert_contains(
            'environment key',
            $key_violations[0]->message,
            'and is described as a key, not a directory'
        );
        static::__assert_greater_than(0, count(static::__run($link_source)), 'the retired link is reported');
    }

    /**
     * The two regenerable caches moved into tmp/ under new names.
     */
    public static function test_a_retired_cache_directory_fires()
    {
        $source = "<?php\n\$p = \$root . '/" . self::OLD_THUMBNAILS . "/preset';\n";

        static::__assert_greater_than(0, count(static::__run($source)), 'the old thumbnail cache is reported');
    }

    /**
     * A ./ or ../ literal naming a tree: it resolves against the working directory,
     * which no framework process chooses.
     */
    public static function test_a_relative_tree_literal_fires()
    {
        foreach ([self::TREE_BUILD, self::TREE_TMP, self::TREE_STORAGE] as $tree) {
            $here = "<?php\n\$p = '." . '/' . "{$tree}/thing';\n";
            $up = "<?php\n\$p = '.." . '/' . "{$tree}';\n";

            static::__assert_greater_than(0, count(static::__run($here)), "./{$tree} is reported");
            static::__assert_greater_than(0, count(static::__run($up)), "../{$tree} is reported");
        }
    }

    /**
     * A tree name concatenated onto a root expression, in each of the three languages.
     */
    public static function test_a_root_concatenation_fires_in_every_language()
    {
        $php = "<?php\n\$p = " . self::BASE_PATH . "() . '/" . self::TREE_TMP . "/scratch';\n";
        $dir = "<?php\n\$p = __DIR__ . '/../" . self::TREE_BUILD . "/bundles';\n";
        $bash = "#!/usr/bin/env bash\nout=\"" . self::BASH_ROOT . "/" . self::TREE_STORAGE . "/uploads\"\n";
        $node = "const p = " . self::PATH_JOIN . "(root, '" . self::TREE_TMP . "', 'x');\n";

        static::__assert_greater_than(0, count(static::__run($php)), 'a PHP root concatenation fires');
        static::__assert_greater_than(0, count(static::__run($dir)), 'a __DIR__ concatenation fires');
        static::__assert_greater_than(0, count(static::__run($bash, 'system/bin/probe.sh')), 'a bash root variable fires');
        static::__assert_greater_than(0, count(static::__run($node, 'rsx/app/probe.js')), 'a node path join fires');
    }

    /**
     * A LOGICAL KEY IS NOT A PATH. `tmp/js-stubs/x.js` is the manifest's own spelling,
     * resolved by absolute_for(), and stays legal on its own - the probes require a root
     * expression or a ./ prefix beside it.
     */
    public static function test_a_logical_key_does_not_fire()
    {
        $source = "<?php\n"
            . "\$a = Rsx_Project_Paths::absolute_for('" . self::TREE_TMP . "/js-stubs/Some_Controller.js');\n"
            . "\$b = ['key' => '" . self::TREE_STORAGE . "/uploads'];\n";

        static::__assert_count(0, static::__run($source), 'a logical key is not a path literal');
    }

    /**
     * A literal argument to the storage-path helper is the shape that survives a
     * relocation silently, so it fires on its own - retired name or not.
     */
    public static function test_a_literal_storage_path_argument_fires()
    {
        $source = "<?php\n\$p = " . self::STORAGE_PATH . "('uploads');\n";

        $violations = static::__run($source);

        static::__assert_greater_than(0, count($violations), 'a literal storage path is reported');
        static::__assert_contains(
            'literal',
            strtolower($violations[0]->message . ' ' . (string) $violations[0]->suggestion),
            'and the message says why'
        );
    }

    /**
     * A base-path call naming one of the three trees fires; one naming source does not.
     */
    public static function test_base_path_fires_only_for_a_volatile_tree()
    {
        $volatile = "<?php\n\$p = " . self::BASE_PATH . "('build/bundles');\n";
        $source_tree = "<?php\n\$p = " . self::BASE_PATH . "('app/RSpade/helpers.php');\n";

        static::__assert_greater_than(0, count(static::__run($volatile)), 'a volatile tree fires');
        static::__assert_count(0, static::__run($source_tree), 'a source path does not');
    }

    /**
     * A raw write primitive aimed at a build-root-derived path fires outside the
     * allow-list: build outputs are produced by the build and by nothing else.
     */
    public static function test_a_raw_write_into_the_build_tree_fires()
    {
        $source = "<?php\nfile_put_contents(Rsx_Project_Paths::build_path('x.json'), \$data);\n";

        static::__assert_greater_than(0, count(static::__run($source)), 'a raw build-tree write is reported');
    }

    // =====================================================================
    // It does not fire
    // =====================================================================

    /**
     * A path taken from the owner is exactly what the rule is steering people toward.
     */
    public static function test_owner_methods_do_not_fire()
    {
        $source = "<?php\n"
            . "\$a = Rsx_Project_Paths::build_root();\n"
            . "\$b = Rsx_Project_Paths::tmp_path('scratch');\n"
            . "\$c = Rsx_Project_Paths::flock_dir();\n"
            . "\$d = Rsx_Project_Paths::stub_key(Rsx_Project_Paths::STUBS_MODEL, 'x.js');\n";

        static::__assert_count(0, static::__run($source), 'owner calls are clean');
    }

    /**
     * READING a build-root path is not a violation - only a raw WRITE primitive is.
     */
    public static function test_reading_the_build_tree_does_not_fire()
    {
        $source = "<?php\n\$json = file_get_contents(Rsx_Project_Paths::build_path('x.json'));\n";

        static::__assert_count(0, static::__run($source), 'a read is not a write');
    }

    /**
     * The allow-list exempts the files that legitimately produce build outputs - the
     * owner itself, the manifest store, the bundle compiler, the stub generators.
     */
    public static function test_the_allow_list_exempts_a_build_producer()
    {
        $source = "<?php\nfile_put_contents(Rsx_Project_Paths::build_path('x.json'), \$data);\n";

        static::__assert_greater_than(0, count(static::__run($source, 'rsx/lib/probe.php')), 'an app file fires');
        static::__assert_count(
            0,
            static::__run($source, 'app/RSpade/Core/Manifest/Manifest_Store.php'),
            'the manifest store is allowed to write the index'
        );
        static::__assert_count(
            0,
            static::__run($source, 'app/RSpade/Core/Bundle/BundleCompiler.php'),
            'the bundle compiler is allowed to write bundles'
        );
    }

    /**
     * Documentation is not code: a man page describing the old layout historically is
     * not a call site the rule should rewrite.
     */
    public static function test_documentation_trees_are_not_scanned()
    {
        $source = "<?php\n\$p = 'storage/" . self::OLD_BUILD . "/x';\n";

        static::__assert_count(0, static::__run($source, 'man/probe.php'), 'man/ is exempt');
        static::__assert_count(0, static::__run($source, 'vendor/pkg/probe.php'), 'vendor/ is exempt');
    }

    // =====================================================================
    // Suppression
    // =====================================================================

    /**
     * The exception comment suppresses, on the line and on the line above - the two
     * spellings a developer actually writes.
     */
    public static function test_the_exception_comment_suppresses()
    {
        $same_line = "<?php\n\$p = " . self::STORAGE_PATH . "('uploads'); // " . self::MARKER . " probe\n";
        $line_above = "<?php\n// " . self::MARKER . " probe\n\$p = " . self::STORAGE_PATH . "('uploads');\n";

        static::__assert_count(0, static::__run($same_line), 'suppressed on the same line');
        static::__assert_count(0, static::__run($line_above), 'suppressed by the line above');
    }

    // =====================================================================
    // Registration
    // =====================================================================

    /**
     * The rule reports, it does not fail the build: a path literal is a defect to fix,
     * not a condition that makes the code unrunnable.
     */
    public static function test_the_rule_is_not_manifest_fatal()
    {
        $rule = new PathOwner_CodeQualityRule(new ViolationCollector());

        static::__assert_equals(self::RULE_ID, $rule->get_id(), 'the id is the API');
        static::__assert_equals('high', $rule->get_default_severity(), 'high severity');
        static::__assert_false($rule->is_called_during_manifest_scan(), 'not fatal at manifest build');
    }

    /**
     * The remediation carries the owner method table - agents are told to trust that
     * text as authoritative, so vague remediation produces confident wrong fixes.
     */
    public static function test_the_remediation_names_the_owner_methods()
    {
        $violations = static::__run("<?php\n\$p = " . self::STORAGE_PATH . "('uploads');\n");
        $resolution = (string) $violations[0]->suggestion;

        foreach (['Rsx_Project_Paths', 'build_root()', 'tmp_path(', 'state_path(', 'rsx_paths.sh'] as $needle) {
            static::__assert_contains($needle, $resolution, "the remediation names {$needle}");
        }
    }
}
