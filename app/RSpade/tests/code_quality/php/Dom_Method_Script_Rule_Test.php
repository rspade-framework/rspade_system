<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\CodeQualityChecker;
use App\RSpade\CodeQuality\Rules\JavaScript\DomMethod_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for the <script> half of JS-DOM-01 (DomMethod_CodeQualityRule).
 *
 * A <script> is not an ordinary element, so the rule's generic "use jQuery element
 * creation" advice is wrong for it in two directions at once: an external script must be
 * DECLARED (the CSP whitelist derives from *.externals.php, so a hand-injected tag is a
 * blocked tag under an enforcing policy), and jQuery never inserts a live script node at
 * all (domManip disables it and re-executes through _evalUrl - a synchronous XHR plus
 * globalEval - which suppresses load/error events and bypasses Subresource Integrity).
 *
 * Both spellings therefore get their own high-severity remediation pointing at
 * Rsx.load_external(), while every other tag keeps the generic jQuery advice.
 *
 * Fixtures are written under a directory named `rsx` (the rule only inspects application
 * code) and the offending expressions are assembled from string constants, so this test
 * file - which rsx:check also scans - contains no real violation of its own.
 */
class Dom_Method_Script_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is pure text inspection over temp fixture files - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'JS-DOM-01';

    /** Native script creation, single-quoted, as source text. */
    private const CREATE_SCRIPT_SQ = "document.createElement(" . "'script')";

    /** Native script creation, double-quoted, as source text. */
    private const CREATE_SCRIPT_DQ = 'document.createElement(' . '"script")';

    /** Native div creation, as source text. */
    private const CREATE_DIV = "document.createElement(" . "'div')";

    /** jQuery script construction with attributes, as source text. */
    private const JQUERY_SCRIPT = "$(" . "'<script src=\"https://cdn.example.com/x.js\">')";

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    /**
     * Write $source to a fresh fixture file and run the rule over it directly.
     *
     * $relative_name may carry directories, so path-based exclusions (vendor, the mirrored
     * CDN cache) can be exercised. The fixture root is named `rsx` because the rule only
     * inspects application code.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $source, string $relative_name = 'probe.js'): array
    {
        $root = storage_path('rsx-tmp') . '/js_dom_01_fixture_' . uniqid();
        $path = $root . '/rsx/' . $relative_name;
        ensure_directory(dirname($path));
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new DomMethod_CodeQualityRule($collector);
        $rule->check($path, $source);

        $violations = $collector->get_by_rule(self::RULE_ID);

        self::__remove_tree($root);

        return $violations;
    }

    /**
     * Wrap one statement in a function so the fixture is valid JavaScript.
     */
    private static function __function_source(string $statement): string
    {
        return "function probe() {\n"
            . '    const node = ' . $statement . ";\n"
            . "    return node;\n"
            . "}\n";
    }

    /**
     * Delete a fixture tree, deepest entries first.
     */
    private static function __remove_tree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($root);
    }

    // =====================================================================
    // The script cases get their own remediation
    // =====================================================================

    public static function test_native_script_creation_is_flagged_toward_load_external()
    {
        $violations = self::__run(self::__function_source(self::CREATE_SCRIPT_SQ));

        static::__assert_count(1, $violations, "createElement('script') raises exactly one violation");

        $violation = $violations[0];
        static::__assert_equals('high', $violation->severity, 'the script case is high severity');
        static::__assert_contains('Rsx.load_external', $violation->suggestion, 'the remediation names the loader');
        static::__assert_contains('externals.php', $violation->suggestion, 'the remediation names the declaration file');
        static::__assert_contains('_evalUrl', $violation->suggestion, 'the remediation explains why jQuery is not the answer');
        static::__assert_false(
            str_contains($violation->suggestion, "jQuery element creation"),
            'the script case never prescribes jQuery element creation'
        );
    }

    public static function test_double_quoted_script_creation_is_flagged_the_same_way()
    {
        $violations = self::__run(self::__function_source(self::CREATE_SCRIPT_DQ));

        static::__assert_count(1, $violations, 'the double-quoted spelling is detected too');
        static::__assert_equals('high', $violations[0]->severity, 'severity does not depend on the quoting');
        static::__assert_contains('Rsx.load_external', $violations[0]->suggestion, 'same remediation as the single-quoted form');
    }

    public static function test_jquery_script_construction_is_flagged_in_reverse()
    {
        $violations = self::__run(self::__function_source(self::JQUERY_SCRIPT));

        static::__assert_count(1, $violations, 'jQuery script construction raises exactly one violation');
        static::__assert_equals('high', $violations[0]->severity, 'the jQuery script case is high severity');
        static::__assert_contains(
            'Rsx.load_external',
            $violations[0]->suggestion,
            'the jQuery script case shares the load_external remediation'
        );
    }

    // =====================================================================
    // Every other tag keeps the generic advice
    // =====================================================================

    public static function test_other_tags_keep_the_generic_jquery_advice()
    {
        $violations = self::__run(self::__function_source(self::CREATE_DIV));

        static::__assert_count(1, $violations, "createElement('div') still raises one violation");
        static::__assert_equals('medium', $violations[0]->severity, 'an ordinary element stays medium severity');
        static::__assert_contains(
            "jQuery element creation",
            $violations[0]->suggestion,
            'an ordinary element still gets the jQuery advice'
        );
        static::__assert_false(
            str_contains($violations[0]->suggestion, 'Rsx.load_external'),
            'an ordinary element is never routed to the external-resource registry'
        );
    }

    // =====================================================================
    // Path exclusions
    // =====================================================================

    public static function test_vendor_and_cdn_cache_paths_are_not_scanned()
    {
        // A third-party bundle or test spec carrying a literal script string is not our code.
        $source = self::__function_source('"<scr' . 'ipt></scr' . 'ipt>"');

        static::__assert_count(
            0,
            self::__run($source, 'theme/vendor/bootstrap5/js/tests/spec.js'),
            'a vendored third-party file is skipped'
        );
        static::__assert_count(
            0,
            self::__run($source, 'resource/.cdn-cache/0123456789abcdef0123456789abcdef_bootstrap_bundle.js'),
            'the mirrored CDN cache is skipped'
        );
    }

    public static function test_a_comment_mentioning_script_creation_is_not_flagged()
    {
        // Detection reads the original line for the tag name, so the structural half must
        // still run against the sanitized line - otherwise prose spoofs the rule.
        $source = "// " . self::CREATE_SCRIPT_SQ . " is what NOT to write\n"
            . "function probe() {\n"
            . "    return 1;\n"
            . "}\n";

        static::__assert_count(0, self::__run($source), 'a comment never raises a violation');
    }

    // =====================================================================
    // The exception marker
    // =====================================================================

    /**
     * The marker is honored by the CHECKER, not by the rule, so this drives the real entry
     * point. Fixtures live outside the project tree because check_file() skips any path
     * containing an excluded directory - `storage` among them.
     */
    public static function test_exception_marker_suppresses_the_rule()
    {
        $root = sys_get_temp_dir() . '/js_dom_01_checker_' . uniqid();
        $dir = $root . '/rsx';
        ensure_directory($dir);

        $offending = self::__function_source(self::CREATE_SCRIPT_SQ);
        $control_path = $dir . '/control.js';
        $excepted_path = $dir . '/excepted.js';

        file_put_contents($control_path, $offending);
        file_put_contents(
            $excepted_path,
            "// @JS-DOM-01" . "-EXCEPTION - fixture: proves the marker suppresses the rule\n" . $offending
        );

        CodeQualityChecker::init([]);
        CodeQualityChecker::check_file($control_path);
        CodeQualityChecker::check_file($excepted_path);

        $violations = CodeQualityChecker::get_collector()->get_by_rule(self::RULE_ID);

        $files = [];
        foreach ($violations as $violation) {
            $files[] = $violation->file_path;
        }

        static::__assert_true(in_array($control_path, $files, true), 'the control fixture is flagged');
        static::__assert_false(in_array($excepted_path, $files, true), 'the marked fixture is not flagged');

        self::__remove_tree($root);
    }
}
