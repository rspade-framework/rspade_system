<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\PHP\NamedOptionalArgument_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * PHP-NAMED-OPTIONAL-01 (NamedOptionalArgument): a literal null / true / false passed
 * POSITIONALLY into a defaulted parameter of a framework or application callee.
 *
 * The rule is driven directly over synthetic fixture files written to the tmp tree (it reads
 * the raw bytes through Source_Cache, and the exception marker is a comment). The CALLEES
 * are real framework symbols, because the rule resolves them through the manifest and reads
 * their signatures by reflection: RsxLocks::system_lock(string $name, ?int $timeout = null,
 * bool $db_scoped = true), Task_Runner::execute(..., bool $release_leaked_locks, bool
 * $own_context = false), Manifest::php_get_subclasses_of(string, bool $concrete_only = true)
 * and the helper rmdir_recursive($dir, $delete_self = true, ...). The vendor case is
 * Eloquent's Model::preventLazyLoading($value = true), inherited by Rsx_Model_Abstract.
 *
 * Fixture source is held in string literals, which are data to the rule's AST pass, so this
 * file is not itself a violation.
 */
class Named_Optional_Argument_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const RULE_ID = 'PHP-NAMED-OPTIONAL-01';

    private static function __run_rule(array $body_lines): array
    {
        $lines = array_merge(
            [
                '<?php',
                '',
                'namespace Fixture_Namespace;',
                '',
                'class Named_Optional_Fixture',
                '{',
                '    public static function run($dir, $instance, $flag, $object)',
                '    {',
            ],
            $body_lines,
            ['    }', '}', '']
        );
        $source = implode("\n", $lines);

        $dir = Rsx_Project_Paths::tmp_path() . '/named_optional_fixture_' . uniqid();
        ensure_directory($dir);
        $path = $dir . '/fixture.php';
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new NamedOptionalArgument_CodeQualityRule($collector);
        $rule->check($path, $source, []);

        @unlink($path);
        @rmdir($dir);

        return ['violations' => array_values($collector->get_by_rule(self::RULE_ID)), 'lines' => $lines];
    }

    private static function __line_of(array $lines, string $needle): int
    {
        foreach ($lines as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index + 1;
            }
        }

        static::__fail("fixture has no line containing '{$needle}'");

        return 0;
    }

    // =====================================================================
    // Violations
    // =====================================================================

    public static function test_placeholder_and_flag_into_defaulted_static_is_flagged()
    {
        $result = self::__run_rule([
            "        \$token = \\App\\RSpade\\Core\\Locks\\RsxLocks::system_lock('X', null, false);",
            "        \$other = RsxLocks::system_lock('Y', null, false);",
        ]);

        static::__assert_count(2, $result['violations'], 'a null placeholder then a bare flag is one finding per call');

        $violation = $result['violations'][1];
        static::__assert_equals(self::__line_of($result['lines'], "system_lock('Y'"), $violation->line_number, 'the finding points at the call line');
        static::__assert_equals('high', $violation->severity, 'the rule reports at high severity');
        static::__assert_contains('$timeout', $violation->message, 'the first defaulted slot reached by a literal is named');
        static::__assert_contains('BY NAME', $violation->suggestion, 'the remediation says to pass it by name');
    }

    public static function test_bare_flag_into_helper_function_is_flagged()
    {
        $result = self::__run_rule([
            '        rmdir_recursive($dir, false);',
        ]);

        static::__assert_count(1, $result['violations'], 'a bare false into a framework helper\'s defaulted parameter is flagged');
        static::__assert_contains('$delete_self', $result['violations'][0]->message);
    }

    // =====================================================================
    // Clean shapes
    // =====================================================================

    public static function test_named_optional_arguments_pass()
    {
        $result = self::__run_rule([
            "        RsxLocks::system_lock('X', db_scoped: false);",
            "        RsxLocks::system_lock('X', timeout: null, db_scoped: false);",
            '        rmdir_recursive($dir, delete_self: false);',
        ]);

        static::__assert_count(0, $result['violations'], 'optional settings passed by name are clean');
    }

    public static function test_required_positional_literal_passes()
    {
        $result = self::__run_rule([
            '        Task_Runner::execute($instance, false);',
        ]);

        static::__assert_count(0, $result['violations'], 'a literal into a REQUIRED parameter is not judged');
    }

    public static function test_non_literal_into_defaulted_parameter_passes()
    {
        $result = self::__run_rule([
            "        Manifest::php_get_subclasses_of('Rsx_Model_Abstract', \$flag);",
            "        RsxLocks::system_lock('X', 5);",
        ]);

        static::__assert_count(0, $result['violations'], 'only a bare null/true/false literal is judged');
    }

    public static function test_unresolvable_and_vendor_callees_are_skipped()
    {
        $result = self::__run_rule([
            '        No_Such_Class_Anywhere_Fixture::run($dir, null, true);',
            '        $object::run(null, true);',
            '        no_such_function_anywhere_fixture($dir, false);',
            '        \\Illuminate\\Support\\Str::of(null);',
            '        Rsx_Model_Abstract::preventLazyLoading(false);',
            '        str_pad($dir, 4, " ", STR_PAD_LEFT);',
            '        in_array($dir, [], true);',
        ]);

        static::__assert_count(0, $result['violations'], 'unresolvable, vendor-declared and builtin callees are never judged');
    }

    public static function test_mentions_in_strings_and_comments_pass()
    {
        $result = self::__run_rule([
            "        \$text = \"RsxLocks::system_lock('X', null, false)\";",
            "        // RsxLocks::system_lock('X', null, false);",
        ]);

        static::__assert_count(0, $result['violations'], 'a call spelled inside a string or a comment is not a call');
    }

    // =====================================================================
    // Exception marker
    // =====================================================================

    public static function test_exception_marker_with_rationale_excuses_one_call()
    {
        $result = self::__run_rule([
            '        // @' . self::RULE_ID . '-EXCEPTION mirrors a vendor signature quoted in the docs',
            "        RsxLocks::system_lock('X', null, false);",
            "        RsxLocks::system_lock('Y', null, false);",
        ]);

        static::__assert_count(1, $result['violations'], 'the marker excuses only the call beneath it');
        static::__assert_equals(self::__line_of($result['lines'], "system_lock('Y'"), $result['violations'][0]->line_number);
    }

    public static function test_bare_exception_marker_excuses_nothing()
    {
        $result = self::__run_rule([
            '        // @' . self::RULE_ID . '-EXCEPTION',
            "        RsxLocks::system_lock('X', null, false);",
        ]);

        static::__assert_count(1, $result['violations'], 'a marker without a rationale excuses nothing');
    }
}
