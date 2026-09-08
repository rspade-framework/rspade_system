<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ClassOverride\Php;

use App\RSpade\CodeQuality\Rules\Convention\ClassOverrideDrift_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Health\Class_Override_Drift_Health_Checks;
use App\RSpade\Core\Manifest\Class_Override_Drift;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * CLASS-OVERRIDE-DRIFT-01 - the drift detector.
 *
 * A class override is a frozen copy of a framework class that keeps moving. A downstream
 * field report records the consequence: the framework added members to a model an
 * application had replaced, then CALLED them - core calling into a class it believed was
 * its own - and the call landed on a copy that predated them. One path answered 500; the
 * other, a background queue, silently enqueued nothing for months. Nothing compared the
 * two files.
 *
 * These tests pin the comparison. The analyzer is driven over fixture PAIRS that are in no
 * manifest at all, so every member shape can be stated exactly; the health row is driven
 * over a synthetic manifest file list, the way the sibling archive-guard test drives the
 * override pass.
 *
 * Pure filesystem + static state, no DB.
 */
class Class_Override_Drift_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const PROBE_CLASS = 'Drift_Probe_Temp';

    /**
     * Both fixtures live under a resource/ directory, which the framework never scans - an
     * indexed .php.upstream with no active twin is exactly what the restore pass would
     * rename back into place, and these files are not a real override.
     */
    private const UPSTREAM_FILE = 'rsx/resource/drift_probe_temp.php.upstream';
    private const OVERRIDE_FILE = 'rsx/resource/drift_probe_temp_override.php';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    /**
     * Write one fixture class whose body is the given lines, and return its absolute path.
     */
    private static function __write(string $relative, array $body_lines, ?string $extends = null): string
    {
        $path = base_path($relative);
        ensure_directory(dirname($path));

        $declaration = 'class ' . self::PROBE_CLASS . ($extends === null ? '' : ' extends ' . $extends);

        $lines = array_merge(
            ['<?php', '', $declaration, '{'],
            $body_lines,
            ['}', '']
        );

        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    private static function __remove_fixtures(): void
    {
        foreach ([base_path(self::UPSTREAM_FILE), base_path(self::OVERRIDE_FILE)] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Run the rule's pair seam over two fixture bodies and return the violations.
     *
     * @return array<int, \App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run_rule(array $upstream_body, array $override_body): array
    {
        $upstream = static::__write(self::UPSTREAM_FILE, $upstream_body);
        $override = static::__write(self::OVERRIDE_FILE, $override_body);

        $collector = new ViolationCollector();
        $rule = new ClassOverrideDrift_CodeQualityRule($collector);

        try {
            $rule->evaluate_pair(self::PROBE_CLASS, $upstream, $override);
        } finally {
            static::__remove_fixtures();
        }

        return $collector->get_all();
    }

    // =====================================================================
    // The member reader
    // =====================================================================

    /**
     * Every shape the comparison depends on, read out of one file at once: methods static
     * and instance, a declared property, a trait adoption - and NOT a private member, NOT a
     * local of a method body, NOT a member of a nested anonymous class.
     */
    public static function test_reader_sees_declared_public_surface_and_nothing_else()
    {
        $source = implode("\n", [
            '<?php',
            'class ' . self::PROBE_CLASS,
            '{',
            '    use Some_Trait;',
            '',
            '    public static $registry = [];',
            '    protected $label = \'x\';',
            '    private $secret = 1;',
            '',
            '    public function instance_one() { $not_a_member = 1; return $not_a_member; }',
            '    public static function static_one() { return new class { public function nested() {} }; }',
            '    protected function protected_one() {}',
            '    private function private_one() {}',
            '}',
            '',
        ]);

        $members = Class_Override_Drift::declared_members($source, self::PROBE_CLASS);

        foreach ([
            'trait:Some_Trait',
            'property:registry',
            'property:label',
            'method:instance_one',
            'method:static_one',
            'method:protected_one',
        ] as $key) {
            static::__assert_array_has_key($key, $members, "{$key} is part of the declared surface");
        }

        static::__assert_false(isset($members['property:secret']), 'a private property is out of scope');
        static::__assert_false(isset($members['method:private_one']), 'a private method is out of scope');
        static::__assert_false(isset($members['method:nested']), 'a method of a nested anonymous class is not a member here');
        static::__assert_count(6, $members, 'and nothing else is counted');

        static::__assert_true($members['method:static_one']['static'], 'staticness is recorded');
        static::__assert_false($members['method:instance_one']['static'], 'and so is its absence');
    }

    /** A class the file does not declare yields nothing rather than guessing. */
    public static function test_reader_returns_nothing_for_an_absent_class()
    {
        $source = "<?php\nclass Something_Else\n{\n    public function a() {}\n}\n";

        static::__assert_count(
            0,
            Class_Override_Drift::declared_members($source, self::PROBE_CLASS),
            'the reader never reports members of a class it was not asked about'
        );
    }

    // =====================================================================
    // The comparison
    // =====================================================================

    /** THE INCIDENT SHAPE: the framework grew a method the override never got. */
    public static function test_a_missing_method_is_one_finding_that_names_it()
    {
        $violations = static::__run_rule(
            ['    public static function is_convertible_mime() {}', '    public function shared() {}'],
            ['    public function shared() {}']
        );

        static::__assert_count(1, $violations, 'one finding per missing member');

        $violation = $violations[0];

        static::__assert_equals('CLASS-OVERRIDE-DRIFT-01', $violation->rule_id, 'reported under the rule id');
        static::__assert_equals('high', $violation->severity, 'HIGH - loud, but never a build fatal');
        static::__assert_contains('is_convertible_mime', $violation->message, 'the finding names the member');
        static::__assert_contains(self::PROBE_CLASS, $violation->message, 'and the class');
        static::__assert_contains(self::UPSTREAM_FILE, $violation->message, 'and where the framework copy is');
        static::__assert_contains(self::OVERRIDE_FILE, $violation->message, 'and which file must change');
    }

    /** An override that carries everything upstream declares is not drifting. */
    public static function test_an_override_that_lacks_nothing_reports_nothing()
    {
        $violations = static::__run_rule(
            ['    public function shared() {}'],
            ['    public function shared() {}', '    public function app_only() {}']
        );

        static::__assert_count(0, $violations, 'additions of its own are not drift');
    }

    /** A property is a member too - $field_meta declarations are exactly what apps clone for. */
    public static function test_a_missing_property_is_a_finding()
    {
        $violations = static::__run_rule(
            ['    protected static $render_states = [];'],
            ['    public function app_only() {}']
        );

        static::__assert_count(1, $violations, 'a property upstream declares and the override does not');
        static::__assert_contains('$render_states', $violations[0]->message, 'named as a property');
    }

    /** Dropping a trait adoption drops every member it supplied - the same failure, one step removed. */
    public static function test_a_missing_trait_adoption_is_a_finding()
    {
        $violations = static::__run_rule(
            ['    use Staff_Authorizable;'],
            ['    public function app_only() {}']
        );

        static::__assert_count(1, $violations, 'the adoption is a member of the declared surface');
        static::__assert_contains('use Staff_Authorizable;', $violations[0]->message, 'named as an adoption');
    }

    /**
     * The reverse direction is CONTEXT, never a problem: the reader's next move is a
     * re-clone, and a re-clone has to carry the application's own additions forward.
     */
    public static function test_the_finding_lists_the_overrides_own_additions()
    {
        $violations = static::__run_rule(
            ['    public function upstream_only() {}'],
            ['    public function display_id() {}', '    protected static $field_meta = [];']
        );

        $suggestion = $violations[0]->suggestion;

        static::__assert_contains('INFO', $suggestion, 'the additions are informational');
        static::__assert_contains('display_id', $suggestion, 'and enumerated');
        static::__assert_contains('$field_meta', $suggestion, 'every one of them');
        static::__assert_contains('Staff_Authorizable', $suggestion, 'the seam alternative is offered too');
    }

    /** A pure stale copy - nothing of its own - is told it can simply be deleted. */
    public static function test_an_override_with_no_additions_is_told_it_can_go()
    {
        $violations = static::__run_rule(
            ['    public function upstream_only() {}'],
            []
        );

        static::__assert_contains(
            'deleting it restores the framework class',
            $violations[0]->suggestion,
            'an override that adds nothing has no reason to exist'
        );
    }

    /** The marker suppresses this override, and the rule reads it off the OVERRIDE file. */
    public static function test_the_exception_marker_on_the_override_suppresses_it()
    {
        $violations = static::__run_rule(
            ['    public function upstream_only() {}'],
            ['    // @CLASS-OVERRIDE-DRIFT-01-EXCEPTION - fixture: deliberate divergence']
        );

        static::__assert_count(0, $violations, 'the override declares the divergence deliberate');
    }

    // =====================================================================
    // The health row
    // =====================================================================

    /**
     * Drive the health check against a synthetic manifest file list, restoring the real one
     * in a finally exactly as the sibling archive-guard test does.
     */
    private static function __run_health(array $files): array
    {
        $saved_data = Manifest::$data;
        Manifest::$data = ['data' => ['files' => $files]];

        try {
            return Class_Override_Drift_Health_Checks::class_override_drift();
        } finally {
            Manifest::$data = $saved_data;
        }
    }

    /**
     * @return array<string, array>
     */
    private static function __manifest_pair(): array
    {
        return [
            self::UPSTREAM_FILE => [
                'file' => self::UPSTREAM_FILE,
                'extension' => 'php.upstream',
                'class' => self::PROBE_CLASS,
            ],
            self::OVERRIDE_FILE => [
                'file' => self::OVERRIDE_FILE,
                'extension' => 'php',
                'class' => self::PROBE_CLASS,
            ],
        ];
    }

    /** With no override active at all there is nothing to compare, and the row says so. */
    public static function test_health_is_ok_when_no_override_is_active()
    {
        $row = static::__run_health([]);

        static::__assert_equals('OK', $row['status'], 'no overrides, no drift');
    }

    /** A drifting override WARNs and counts what is missing - and never FAILs. */
    public static function test_health_warns_and_counts_a_drifting_override()
    {
        static::__write(self::UPSTREAM_FILE, [
            '    public function upstream_only() {}',
            '    public function shared() {}',
        ]);
        static::__write(self::OVERRIDE_FILE, ['    public function shared() {}']);

        try {
            $rows = static::__run_health(static::__manifest_pair());

            static::__assert_count(1, $rows, 'one row per drifting override');
            static::__assert_equals('WARN', $rows[0]['status'], 'advisory - rsx:health exits non-zero only on FAIL');
            static::__assert_contains('1 member(s)', $rows[0]['detail'], 'the count is the operator-facing number');
            static::__assert_contains('upstream_only', $rows[0]['detail'], 'and the member is named');
            static::__assert_contains('rsx:check', $rows[0]['remediation'], 'which points at the full finding');
        } finally {
            static::__remove_fixtures();
        }
    }

    /** An override that carries everything is reported as healthy, not omitted. */
    public static function test_health_is_ok_when_an_active_override_carries_everything()
    {
        static::__write(self::UPSTREAM_FILE, ['    public function shared() {}']);
        static::__write(self::OVERRIDE_FILE, [
            '    public function shared() {}',
            '    public function app_only() {}',
        ]);

        try {
            $row = static::__run_health(static::__manifest_pair());

            static::__assert_equals('OK', $row['status'], 'carrying everything upstream declares is not drift');
            static::__assert_contains('1 class override(s) active', $row['detail'], 'and the override is still counted');
        } finally {
            static::__remove_fixtures();
        }
    }

    // =====================================================================
    // A split framework class has no drift surface
    // =====================================================================

    /**
     * THE SPLIT MODEL IS NOT A CLONE. When the archived file declares nothing but
     * `class X extends X_Abstract`, every member lives on the base - and an override that
     * extends that same base INHERITS every one of them, including the ones the framework
     * adds tomorrow. There is no frozen copy here and nothing to compare, so the analyzer
     * reports nothing rather than naming the base's members as "missing" from a class that
     * inherits them.
     *
     * (An override of a split class that does NOT extend the base never reaches this code:
     * the manifest's override pass refuses it outright.)
     */
    public static function test_a_split_pair_that_shares_the_base_reports_nothing()
    {
        $upstream = static::__write(self::UPSTREAM_FILE, [], self::PROBE_CLASS . '_Abstract');
        $override = static::__write(
            self::OVERRIDE_FILE,
            ['    public function app_only() {}'],
            self::PROBE_CLASS . '_Abstract'
        );

        try {
            $analysis = Class_Override_Drift::analyze_pair($upstream, $override, self::PROBE_CLASS);

            static::__assert_count(0, $analysis['missing'], 'the base carries everything; nothing is missing');
            static::__assert_count(
                0,
                $analysis['added'],
                'and the override\'s own members are not reported as additions to a shell either'
            );
        } finally {
            static::__remove_fixtures();
        }
    }

    /**
     * A qualified `extends` is the same declaration. An override in another namespace writes
     * the base as `\App\...\X_Abstract` or imports it; both must read as the same base.
     */
    public static function test_a_split_pair_is_recognized_through_a_qualified_parent()
    {
        $upstream = static::__write(self::UPSTREAM_FILE, [], self::PROBE_CLASS . '_Abstract');
        $override = static::__write(
            self::OVERRIDE_FILE,
            [],
            '\\App\\RSpade\\Fixture\\' . self::PROBE_CLASS . '_Abstract'
        );

        try {
            $analysis = Class_Override_Drift::analyze_pair($upstream, $override, self::PROBE_CLASS);

            static::__assert_count(0, $analysis['missing'], 'the namespaced spelling names the same base');
        } finally {
            static::__remove_fixtures();
        }
    }

    /**
     * A CLONE of a non-split class is still compared in full. The exemption is about the
     * split shape, not about overrides in general.
     */
    public static function test_a_clone_of_a_non_split_class_is_still_compared()
    {
        $violations = static::__run_rule(
            ['    public function upstream_only() {}'],
            ['    public function shared() {}']
        );

        static::__assert_count(1, $violations, 'a clone that dropped a member is still a finding');
    }

    /**
     * The parent reader is token-based, like every other reader here: it answers with the
     * SIMPLE name, and a mention of the parent in a comment or a string is not a
     * declaration.
     */
    public static function test_the_parent_reader_answers_the_simple_name()
    {
        $source = implode("\n", [
            '<?php',
            '// class ' . self::PROBE_CLASS . ' extends Wrong_Comment_Parent',
            '$x = "class ' . self::PROBE_CLASS . ' extends Wrong_String_Parent";',
            'class ' . self::PROBE_CLASS . ' extends \\Some\\Space\\Right_Parent',
            '{',
            '}',
        ]);

        static::__assert_equals(
            'Right_Parent',
            Class_Override_Drift::declared_parent($source, self::PROBE_CLASS),
            'the declaration wins over the comment and the string, and the answer is simple'
        );

        static::__assert_null(
            Class_Override_Drift::declared_parent("<?php\nclass " . self::PROBE_CLASS . "\n{\n}\n", self::PROBE_CLASS),
            'a class that extends nothing answers null'
        );
    }
}
