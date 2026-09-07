<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Manifest\AbstractRegistryAttribute_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for the ABSTRACT-ATTR-01 rule (AbstractRegistryAttribute_CodeQualityRule).
 *
 * The rule fatals a manifest build when an abstract class declares a registry attribute -
 * one whose effect is to enrol the DECLARING class into an inheritance-blind runtime
 * registry. Detection is a compiled-manifest walk, so these tests drive the rule's core
 * seam evaluate_class_attributes() with manifest-shaped data over real fixture files (the
 * file supplies the class line, the snippet and the exception marker).
 *
 * Attribute markers are assembled from constants so this test file never carries a real
 * registry attribute another rule or the manifest scanner could read.
 */
class Abstract_Registry_Attribute_Rule_Test extends Rsx_Test_Abstract
{
    // Detection reads manifest-shaped arrays plus a temp fixture file - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'ABSTRACT-ATTR-01';
    private const CLASS_NAME = 'Abstract_Attr_Fixture';
    private const METHOD = 'nightly_sweep';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    /**
     * Write a fixture class carrying $attribute_name on its method (and optionally on the
     * class itself), with an optional exception marker above the method.
     *
     * @return array{file: string, dir: string, class_line: int, method_line: int}
     */
    private static function __write_fixture(
        string $attribute_name,
        bool $on_class,
        bool $with_exception_marker
    ): array {
        $dir = storage_path('rsx-tmp') . '/abstract_attr_fixture_' . uniqid();
        ensure_directory($dir);

        $marker = '#[' . $attribute_name . ']';

        $lines = ['<?php', ''];

        if ($on_class) {
            $lines[] = $marker;
        }

        $lines[] = 'abstract class ' . self::CLASS_NAME;
        $class_line = count($lines);

        $lines[] = '{';

        if ($with_exception_marker) {
            $lines[] = '    // @' . self::RULE_ID . '-EXCEPTION - fixture: verifying suppression';
        }

        $lines[] = '    ' . $marker;
        $lines[] = '    public static function ' . self::METHOD . '()';
        $method_line = count($lines);

        $lines[] = '    {';
        $lines[] = '        return null;';
        $lines[] = '    }';
        $lines[] = '}';

        $file = $dir . '/' . self::CLASS_NAME . '.php';
        file_put_contents($file, implode("\n", $lines) . "\n");

        return [
            'file' => $file,
            'dir' => $dir,
            'class_line' => $class_line,
            'method_line' => $method_line,
        ];
    }

    /**
     * Drive the rule seam and return the collected violations.
     *
     * @return array<int, \App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(
        string $attribute_name,
        bool $on_class = false,
        bool $with_exception_marker = false
    ): array {
        $fixture = self::__write_fixture($attribute_name, $on_class, $with_exception_marker);

        $class_attributes = $on_class ? [$attribute_name => [[]]] : [];

        $methods = [
            self::METHOD => [
                'name' => self::METHOD,
                'line' => $fixture['method_line'],
                'attributes' => [$attribute_name => [[]]],
            ],
        ];

        $collector = new ViolationCollector();
        $rule = new AbstractRegistryAttribute_CodeQualityRule($collector);
        $rule->evaluate_class_attributes($fixture['file'], self::CLASS_NAME, $class_attributes, $methods);

        $violations = $collector->get_by_rule(self::RULE_ID);

        @unlink($fixture['file']);
        @rmdir($fixture['dir']);

        return $violations;
    }

    // =====================================================================
    // The violation
    // =====================================================================

    public static function test_task_attribute_on_an_abstract_method_is_caught()
    {
        $violations = self::__run('Task');

        static::__assert_count(1, $violations, 'a registry attribute on an abstract class method is flagged');

        $violation = array_values($violations)[0];
        static::__assert_equals('critical', $violation->severity, 'the violation is critical severity');
        static::__assert_contains('Task', $violation->message, 'the message names the attribute');
        static::__assert_contains(self::METHOD, $violation->message, 'the message names the method');
    }

    public static function test_class_level_and_method_level_are_both_reported()
    {
        $violations = self::__run('Route', true);

        static::__assert_count(2, $violations, 'the class-level and the method-level declaration each report');
    }

    public static function test_every_forbidden_attribute_is_detected()
    {
        // The forbidden set is the rule's single source of truth; each entry must fire.
        $forbidden = [
            'Auth', 'Route', 'SPA', 'Portal_Route', 'Ajax_Endpoint', 'Ajax_Endpoint_Model_Fetch',
            'Api_Endpoint', 'Task', 'Schedule', 'Exclusive', 'Debounce', 'Emitter', 'OnEvent',
            'Health_Check', 'Realtime_Touch', 'FPC',
        ];

        foreach ($forbidden as $attribute_name) {
            $violations = self::__run($attribute_name);
            static::__assert_count(1, $violations, "#[{$attribute_name}] on an abstract is flagged");
        }
    }

    // =====================================================================
    // What is NOT a violation
    // =====================================================================

    public static function test_lineage_consumed_attributes_are_allowed()
    {
        // #[Relationship] is unioned across the lineage by get_relationships(), and
        // #[Auth_Check] by the auth check registry - both belong on abstracts.
        foreach (['Relationship', 'Auth_Check', 'Replaceable', 'Instantiatable', 'Monoprogenic', 'Sealed'] as $allowed) {
            $violations = self::__run($allowed);
            static::__assert_count(0, $violations, "#[{$allowed}] on an abstract is not flagged");
        }
    }

    public static function test_exception_marker_suppresses_the_violation()
    {
        $violations = self::__run('Task', false, true);

        static::__assert_count(0, $violations, 'the exception marker above the declaration suppresses it');
    }

    public static function test_a_forbidden_attribute_beside_an_allowed_one_reports_once()
    {
        $fixture = self::__write_fixture('Task', false, false);

        $methods = [
            self::METHOD => [
                'name' => self::METHOD,
                'line' => $fixture['method_line'],
                'attributes' => [
                    'Replaceable' => [[]],
                    'Task' => [[]],
                ],
            ],
        ];

        $collector = new ViolationCollector();
        $rule = new AbstractRegistryAttribute_CodeQualityRule($collector);
        $rule->evaluate_class_attributes($fixture['file'], self::CLASS_NAME, [], $methods);

        $violations = $collector->get_by_rule(self::RULE_ID);

        @unlink($fixture['file']);
        @rmdir($fixture['dir']);

        static::__assert_count(1, $violations, 'only the forbidden attribute reports');
        $violation = array_values($violations)[0];
        static::__assert_contains('Task', $violation->message, 'the reported attribute is the forbidden one');
    }
}
