<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\JavaScript\EvalUsage_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for the JS-EVAL-01 rule (EvalUsage_CodeQualityRule).
 *
 * The rule bans eval() and new Function() in BUNDLED JavaScript, because the
 * framework's Content-Security-Policy carries no 'unsafe-eval' and the browser
 * reports both constructs identically as `blocked-uri: eval`.
 *
 * Two properties matter more than the happy path and are tested hardest:
 *
 *   - new Function() must be caught. A rule matching only eval() would report a
 *     page clean while it still violated, which is worse than no rule for anyone
 *     using this as a CSP-readiness gate.
 *   - It must not fire on the WORD appearing in a comment or a string literal,
 *     or on an identifier that merely ends in "eval". A rule that cries wolf
 *     trains everybody to ignore the checker.
 *
 * Fixtures are written under a synthetic path carrying a real scan-directory
 * segment, because the rule scopes itself to bundled JavaScript by consulting
 * rsx.manifest.scan_directories.
 *
 * The construct names are assembled from string fragments so that this test file
 * -- which lives under app/RSpade/tests, itself a scanned directory -- contains
 * no literal call the rule could read as a violation of its own.
 */
class Eval_Usage_Rule_Test extends Rsx_Test_Abstract
{
    // Pure file inspection - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'JS-EVAL-01';

    /** `eval(` as source text, never written literally in this file. */
    private const EVAL_CALL = 'ev' . 'al(';

    /** `new Function(` as source text. */
    private const FUNCTION_CTOR = 'new Fun' . 'ction(';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    /**
     * Write $source to a temp file and run the rule over it.
     *
     * $relative_name may carry directories; callers use that to place the fixture
     * inside (or deliberately outside) a scanned directory.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $source, string $relative_name = 'rsx/eval_probe.js'): array
    {
        $dir = storage_path('rsx-tmp') . '/eval_rule_fixture_' . uniqid();
        $path = $dir . '/' . $relative_name;
        ensure_directory(dirname($path));
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new EvalUsage_CodeQualityRule($collector);
        $rule->check($path, $source);

        $violations = $collector->get_by_rule(self::RULE_ID);

        // Cleanup (deepest first).
        @unlink($path);
        $cleanup = dirname($path);
        while (str_starts_with($cleanup, $dir)) {
            @rmdir($cleanup);
            $cleanup = dirname($cleanup);
        }
        @rmdir($dir);

        return $violations;
    }

    /**
     * Wrap one statement in a class method so the rule sees ordinary JS.
     */
    private static function __js(string $body): string
    {
        return "class Eval_Probe {\n"
            . "    static probe(x) {\n"
            . '        ' . $body . "\n"
            . "    }\n"
            . "}\n";
    }

    // =====================================================================
    // The construct is caught, in every shape reported from the field
    // =====================================================================

    /**
     * The three real-world shapes, all of which are "resolve a class from a name".
     * They share nothing textually beyond the construct itself, which is exactly
     * why the rule matches the construct rather than trying to recognize intent.
     */
    public static function test_every_reported_eval_shape_is_caught()
    {
        $bodies = [
            // Guarded assignment out of a component arg.
            'let cls = null; try { cls = ' . self::EVAL_CALL . 'x); } catch (e) { cls = null; }',
            // Controller half of a "Controller.method" string.
            'const controller = ' . self::EVAL_CALL . "x.split('.')[0]);",
            // Bare resolution of a parent_type arg.
            'return ' . self::EVAL_CALL . 'x);',
        ];

        foreach ($bodies as $body) {
            $violations = self::__run(self::__js($body));
            static::__assert_count(1, $violations, 'flagged: ' . $body);
        }
    }

    /**
     * new Function() is in scope. CSP treats it identically to eval, so a rule
     * that missed it would green-light a page that still violates.
     */
    public static function test_function_constructor_is_caught()
    {
        $violations = self::__run(
            self::__js('const f = ' . self::FUNCTION_CTOR . "'u', 'return import(u);'); return f(x);")
        );

        static::__assert_count(1, $violations);
        static::__assert_contains('new Function()', $violations[0]->message);
    }

    /**
     * Every occurrence is reported, not just the first - one fix per line.
     */
    public static function test_each_occurrence_is_reported_on_its_own_line()
    {
        $source = "class Eval_Probe {\n"
            . '    static a(x) { return ' . self::EVAL_CALL . "x); }\n"
            . '    static b(x) { return ' . self::FUNCTION_CTOR . "'return 1;'); }\n"
            . "}\n";

        $violations = self::__run($source);

        static::__assert_count(2, $violations);
        static::__assert_equals(2, $violations[0]->line_number);
        static::__assert_equals(3, $violations[1]->line_number);
    }

    // =====================================================================
    // What must NOT fire
    // =====================================================================

    /**
     * The word in a comment or a string literal is not a call. Pdf_Viewer.js
     * documents the construct it no longer uses, and must stay clean.
     */
    public static function test_comments_and_strings_are_not_flagged()
    {
        $source = "class Eval_Probe {\n"
            . '    // Historical note: this used to call ' . self::EVAL_CALL . "x).\n"
            . "    /* and " . self::FUNCTION_CTOR . "'u','return import(u);') too */\n"
            . '    static probe() { return "' . self::EVAL_CALL . "x) is only a string\"; }\n"
            . "}\n";

        static::__assert_count(0, self::__run($source));
    }

    /**
     * An identifier that merely ENDS in "eval", or a method of that name, belongs
     * to somebody else.
     */
    public static function test_identifiers_ending_in_eval_are_not_flagged()
    {
        $bodies = [
            'return medi' . self::EVAL_CALL . 'x);',
            'return this._' . self::EVAL_CALL . 'x);',
            'return that.retri' . self::EVAL_CALL . 'x);',
            'return Obj.' . self::EVAL_CALL . 'x);',
        ];

        foreach ($bodies as $body) {
            static::__assert_count(0, self::__run(self::__js($body)), 'not flagged: ' . $body);
        }
    }

    /**
     * Scope is bundled JavaScript. A standalone node script - a build tool or a
     * Playwright driver - never reaches a browser and has no CSP consequence, so
     * eval() there is a legitimate choice this rule has no opinion about.
     */
    public static function test_unbundled_javascript_is_out_of_scope()
    {
        $source = self::__js('return ' . self::EVAL_CALL . 'x);');

        // No scan-directory segment anywhere in the path.
        static::__assert_count(0, self::__run($source, 'bin/standalone_driver.js'));
    }

    /**
     * Third-party code is not ours to rewrite.
     */
    public static function test_vendor_and_node_modules_are_skipped()
    {
        $source = self::__js('return ' . self::EVAL_CALL . 'x);');

        static::__assert_count(0, self::__run($source, 'rsx/node_modules/pkg/index.js'));
        static::__assert_count(0, self::__run($source, 'rsx/vendor/thing/index.js'));
    }

    // =====================================================================
    // The remediation has to teach the real fix
    // =====================================================================

    /**
     * Nearly everyone who trips this rule was resolving a class by name, so the
     * resolution text must name the supported API rather than only saying "no".
     */
    public static function test_resolution_names_the_class_lookup_api()
    {
        $violations = self::__run(self::__js('return ' . self::EVAL_CALL . 'x);'));

        static::__assert_count(1, $violations);

        $suggestion = $violations[0]->suggestion;
        static::__assert_contains('Manifest.get_class_by_name', $suggestion);
        static::__assert_contains('rsx:man csp', $suggestion);
        static::__assert_contains('JS-EVAL-01-EXCEPTION', $suggestion);
    }
}
