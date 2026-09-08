<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\PHP\SessionIdNullCheck_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for the SESSION-ID-01 rule (SessionIdNullCheck_CodeQualityRule).
 *
 * The rule flags any null-ish / zero-ish TEST applied to the result of
 * Session::get_session_id() or Portal_Session::get_session_id() - calls that always
 * create a session and are declared `: int`, so such a test is dead code and the
 * author almost certainly wanted has_session().
 *
 * Tests drive the rule's public check() over synthetic fixture files written to a
 * temp directory, so real nikic/php-parser output is exercised end to end. The call
 * expressions live in STRING constants assembled into fixture source: this test file
 * therefore contains no real static call and no real null test of its own that the
 * rule (which also scans app/RSpade/tests) could read as a violation.
 */
class Session_Id_Null_Check_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is pure AST parsing over temp fixture files - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'SESSION-ID-01';

    /** Staff facade call, as source text. */
    private const CALL = 'Session' . '::' . 'get_session_id()';

    /** Portal facade call, as source text. */
    private const PORTAL_CALL = 'Portal_Session' . '::' . 'get_session_id()';

    /** Fully-qualified staff facade call, as source text. */
    private const FQCN_CALL = '\\App\\RSpade\\Core\\Session\\Session' . '::' . 'get_session_id()';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    /**
     * Wrap one method body in a class so the rule sees an ordinary function scope.
     */
    private static function __class_source(string $body): string
    {
        return "<?php\n"
            . "class Sid_Probe\n"
            . "{\n"
            . "    public function probe()\n"
            . "    {\n"
            . '        ' . $body . "\n"
            . "    }\n"
            . "}\n";
    }

    /**
     * Write $source to a fresh temp file (at optional relative path $relative_name,
     * so path-based exclusions can be exercised), run the rule over it, and return
     * the collected SESSION-ID-01 violations.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $source, string $relative_name = 'Sid_Probe.php'): array
    {
        $dir = storage_path('rsx-tmp') . '/session_id_rule_fixture_' . uniqid();
        $path = $dir . '/' . $relative_name;
        ensure_directory(dirname($path));
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new SessionIdNullCheck_CodeQualityRule($collector);
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
     * Convenience: run one method-body fixture and return its violations.
     */
    private static function __run_body(string $body): array
    {
        return self::__run(self::__class_source($body));
    }

    // =====================================================================
    // Every detected pattern fires
    // =====================================================================

    public static function test_null_comparisons_are_caught()
    {
        $bodies = [
            'if (' . self::CALL . ' === null) { return 1; }',
            'if (' . self::CALL . ' !== null) { return 1; }',
            'if (' . self::CALL . ' == null) { return 1; }',
            'if (' . self::CALL . ' != null) { return 1; }',
            'if (null === ' . self::CALL . ') { return 1; }',
        ];

        foreach ($bodies as $body) {
            $violations = self::__run_body($body);
            static::__assert_count(1, $violations, 'a null comparison is flagged: ' . $body);
        }
    }

    public static function test_zero_comparisons_are_caught()
    {
        $bodies = [
            'if (' . self::CALL . ' === 0) { return 1; }',
            'if (' . self::CALL . ' == 0) { return 1; }',
            'if (' . self::CALL . ' !== 0) { return 1; }',
            'if (' . self::CALL . ' != 0) { return 1; }',
            'if (' . self::CALL . ' > 0) { return 1; }',
            'if (' . self::CALL . ' >= 0) { return 1; }',
            'if (' . self::CALL . ' < 0) { return 1; }',
            'if (' . self::CALL . ' <= 0) { return 1; }',
            'if (0 === ' . self::CALL . ') { return 1; }',
        ];

        foreach ($bodies as $body) {
            $violations = self::__run_body($body);
            static::__assert_count(1, $violations, 'a zero comparison is flagged: ' . $body);
        }
    }

    public static function test_is_null_and_empty_and_isset_are_caught()
    {
        $bodies = [
            'if (is_null(' . self::CALL . ')) { return 1; }',
            'if (empty(' . self::CALL . ')) { return 1; }',
        ];

        foreach ($bodies as $body) {
            $violations = self::__run_body($body);
            static::__assert_count(1, $violations, 'an absence test is flagged: ' . $body);
        }

        // isset() cannot wrap a call - exercise it through the variable form.
        $violations = self::__run_body('$sid = ' . self::CALL . '; if (isset($sid)) { return 1; }');
        static::__assert_count(1, $violations, 'isset() on a variable holding the call result is flagged');
    }

    public static function test_coalesce_and_ternary_are_caught()
    {
        $bodies = [
            '$value = ' . self::CALL . ' ?? 5; return $value;',
            '$value = ' . self::CALL . ' ?: 7; return $value;',
            '$value = ' . self::CALL . ' ? 1 : 2; return $value;',
        ];

        foreach ($bodies as $body) {
            $violations = self::__run_body($body);
            static::__assert_count(1, $violations, 'a coalesce/ternary on the call result is flagged: ' . $body);
        }
    }

    public static function test_truthiness_tests_are_caught()
    {
        $bodies = [
            'if (!' . self::CALL . ') { return 1; }',
            'if (' . self::CALL . ') { return 1; }',
            'while (' . self::CALL . ') { return 1; }',
        ];

        foreach ($bodies as $body) {
            $violations = self::__run_body($body);
            static::__assert_count(1, $violations, 'a truthiness test on the call result is flagged: ' . $body);
        }
    }

    // =====================================================================
    // The variable-assignment form fires (single-assignment dataflow)
    // =====================================================================

    public static function test_variable_assigned_from_the_call_is_tracked()
    {
        $violations = self::__run_body('$sid = ' . self::CALL . '; if ($sid === null) { return 1; } return $sid;');

        static::__assert_count(1, $violations, 'a null test on a variable assigned from the call is flagged');
    }

    public static function test_variable_form_reports_once_per_test()
    {
        $body = '$sid = ' . self::CALL . ";\n"
            . '        if ($sid === null) { return 1; }' . "\n"
            . '        if (empty($sid)) { return 2; }' . "\n"
            . '        return $sid;';

        $violations = self::__run_body($body);

        static::__assert_count(2, $violations, 'two tests on the tracked variable produce exactly two violations');
    }

    public static function test_reassigned_variable_is_not_tracked()
    {
        $body = '$sid = ' . self::CALL . '; $sid = 5; if ($sid === null) { return 1; } return $sid;';

        $violations = self::__run_body($body);

        static::__assert_count(0, $violations, 'a variable rebound after the call is not provably the session id');
    }

    public static function test_variable_tracking_is_scoped_to_one_function_body()
    {
        // Two methods each assign $sid once, from the call; both tests must fire, and the
        // per-function scoping must not confuse them into "assigned twice, drop it".
        $source = "<?php\n"
            . "class Sid_Probe\n"
            . "{\n"
            . "    public function one()\n"
            . "    {\n"
            . '        $sid = ' . self::CALL . '; if ($sid === null) { return 1; } return $sid;' . "\n"
            . "    }\n"
            . "\n"
            . "    public function two()\n"
            . "    {\n"
            . '        $sid = ' . self::CALL . '; if (empty($sid)) { return 2; } return $sid;' . "\n"
            . "    }\n"
            . "}\n";

        $violations = self::__run($source);

        static::__assert_count(2, $violations, 'the same variable name in two function bodies is tracked separately');
    }

    // =====================================================================
    // The portal facade and namespace-qualified calls are covered
    // =====================================================================

    public static function test_portal_facade_is_covered()
    {
        $violations = self::__run_body('if (' . self::PORTAL_CALL . ' === null) { return 1; }');

        static::__assert_count(1, $violations, 'the portal facade is covered');
        $violation = array_values($violations)[0];
        static::__assert_contains('Portal_Session', $violation->message, 'the violation names the portal facade');
        static::__assert_contains('Portal_Session::has_session()', $violation->suggestion, 'the remediation names the portal has_session()');
    }

    public static function test_namespace_qualified_call_is_covered()
    {
        $violations = self::__run_body('if (' . self::FQCN_CALL . ' === null) { return 1; }');

        static::__assert_count(1, $violations, 'a fully-qualified call is resolved to the staff facade');
    }

    // =====================================================================
    // Legitimate use does not fire
    // =====================================================================

    public static function test_legitimate_use_of_the_result_is_not_flagged()
    {
        $bodies = [
            // Arithmetic.
            '$sid = ' . self::CALL . '; return $sid + 1;',
            // String use.
            'return "session=" . ' . self::CALL . ';',
            // Passed as an argument / used in a query.
            '$sid = ' . self::CALL . '; return Flash_Alert_Model::where("session_id", $sid)->get();',
            // Compared against ANOTHER id, not against absence.
            '$sid = ' . self::CALL . '; return $this->session_id != $sid;',
            // Assigned to a property.
            '$this->session_id = ' . self::CALL . '; return $this->session_id;',
        ];

        foreach ($bodies as $body) {
            $violations = self::__run_body($body);
            static::__assert_count(0, $violations, 'legitimate use is not flagged: ' . $body);
        }
    }

    public static function test_other_session_accessors_are_not_flagged()
    {
        $bodies = [
            'if (Session' . '::' . 'get_site_id() === null) { return 1; }',
            'if (Session' . '::' . 'get_csrf_token() === null) { return 1; }',
            'if (Some_Other_Class' . '::' . 'get_session_id() === null) { return 1; }',
        ];

        foreach ($bodies as $body) {
            $violations = self::__run_body($body);
            static::__assert_count(0, $violations, 'a different accessor or class is not flagged: ' . $body);
        }
    }

    // =====================================================================
    // The facades' own internals are excluded
    // =====================================================================

    public static function test_staff_facade_internals_are_excluded()
    {
        $violations = self::__run(
            self::__class_source('if (' . self::CALL . ' === null) { return 1; }'),
            'Core/Session/Session.php'
        );

        static::__assert_count(0, $violations, 'Session.php is excluded - it is where the int is produced');
    }

    public static function test_portal_facade_internals_are_excluded()
    {
        $violations = self::__run(
            self::__class_source('if (' . self::PORTAL_CALL . ' === null) { return 1; }'),
            'Core/Portal/Portal_Session.php'
        );

        static::__assert_count(0, $violations, 'Portal_Session.php is excluded - it is where the int is produced');
    }

    public static function test_code_quality_rules_and_fixtures_are_excluded()
    {
        $violations = self::__run(
            self::__class_source('if (' . self::CALL . ' === null) { return 1; }'),
            'CodeQuality/Rules/PHP/Sid_Probe.php'
        );

        static::__assert_count(0, $violations, 'code-quality meta-code is excluded');
    }

    // =====================================================================
    // Exception markers suppress
    // =====================================================================

    public static function test_file_level_exception_marker_suppresses()
    {
        $marker = '@' . self::RULE_ID . '-EXCEPTION';
        $source = "<?php\n"
            . "// {$marker} - documented reason\n"
            . "class Sid_Probe\n"
            . "{\n"
            . "    public function probe()\n"
            . "    {\n"
            . '        if (' . self::CALL . ' === null) { return 1; }' . "\n"
            . "    }\n"
            . "}\n";

        $violations = self::__run($source);

        static::__assert_count(0, $violations, 'a file-level exception marker suppresses the rule');
    }

    // =====================================================================
    // Rule wiring: this is a FATAL manifest-time rule
    // =====================================================================

    public static function test_rule_is_fatal_at_manifest_build()
    {
        $rule = new SessionIdNullCheck_CodeQualityRule(new ViolationCollector());

        static::__assert_equals(self::RULE_ID, $rule->get_id(), 'rule id');
        static::__assert_true($rule->is_called_during_manifest_scan(), 'the rule runs during the manifest scan');
        static::__assert_equals(
            \App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract::KIND_PER_FILE,
            $rule->kind(),
            'the rule is per-file'
        );
        static::__assert_equals('critical', $rule->get_default_severity(), 'violations are critical');

        // A critical violation raised during the manifest scan is what aborts the build
        // (Manifest_Indexer throws YoureDoingItWrongException on the first one),
        // so pin the severity the emitted violation actually carries.
        $violations = self::__run_body('if (' . self::CALL . ' === null) { return 1; }');
        static::__assert_count(1, $violations, 'the fixture violates');
        $violation = array_values($violations)[0];
        static::__assert_equals('critical', $violation->severity, 'the emitted violation is critical');
        static::__assert_contains('has_session()', $violation->suggestion, 'the remediation names has_session()');
    }
}
