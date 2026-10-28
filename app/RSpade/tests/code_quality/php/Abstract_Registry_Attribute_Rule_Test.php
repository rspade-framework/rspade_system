<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Manifest\AbstractRegistryAttribute_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for the ABSTRACT-ATTR-01 rule (AbstractRegistryAttribute_CodeQualityRule): no registry
 * attribute on an abstract class, and no subclass of a concrete class that declares one.
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
        $dir = Rsx_Project_Paths::tmp_path() . '/abstract_attr_fixture_' . uniqid();
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

    // =====================================================================
    // The second half: a class carrying a registry attribute is FINAL
    // =====================================================================

    private const SUBCLASS_NAME = 'Registry_Final_Fixture_Child';

    /**
     * Write a concrete subclass fixture (optionally with an exception marker above its
     * declaration), drive evaluate_registry_inheritance() with manifest-shaped ancestors, and
     * return the violations and the class line.
     *
     * @return array{0: array, 1: int}
     */
    private static function __run_inheritance(array $ancestors, bool $with_exception_marker = false): array
    {
        $dir = Rsx_Project_Paths::tmp_path() . '/registry_final_fixture_' . uniqid();
        ensure_directory($dir);

        $lines = ['<?php', ''];
        if ($with_exception_marker) {
            $lines[] = '// @' . self::RULE_ID . '-EXCEPTION - fixture: verifying suppression';
        }
        $lines[] = 'class ' . self::SUBCLASS_NAME . ' extends ' . $ancestors[0]['class'];
        $class_line = count($lines);
        $lines[] = '{';
        $lines[] = '}';

        $file = $dir . '/' . self::SUBCLASS_NAME . '.php';
        file_put_contents($file, implode("\n", $lines) . "\n");

        $collector = new ViolationCollector();
        (new AbstractRegistryAttribute_CodeQualityRule($collector))
            ->evaluate_registry_inheritance($file, self::SUBCLASS_NAME, $ancestors);

        @unlink($file);
        @rmdir($dir);

        return [$collector->get_by_rule(self::RULE_ID), $class_line];
    }

    /** One manifest-shaped ancestor carrying $attribute_name on a method (or nothing when null). */
    private static function __ancestor(string $class, bool $abstract, ?string $attribute_name, bool $is_model = false, array $method_attributes = []): array
    {
        $attributes = $method_attributes ?: ($attribute_name === null ? [] : [$attribute_name => [[]]]);

        return [
            'class' => $class,
            'file' => 'fixture/' . $class . '.php',
            'abstract' => $abstract,
            'is_model' => $is_model,
            'class_attributes' => [],
            'methods' => $attributes ? [self::METHOD => ['name' => self::METHOD, 'line' => 3, 'attributes' => $attributes]] : [],
        ];
    }

    public static function test_extending_a_concrete_task_service_is_caught()
    {
        [$violations, $class_line] = self::__run_inheritance([self::__ancestor('Parent_Service', false, 'Task')]);

        static::__assert_count(1, $violations, 'a subclass of a concrete class declaring #[Task] is flagged');
        $violation = array_values($violations)[0];
        static::__assert_equals($class_line, $violation->line_number, 'reported on the subclass declaration');
        static::__assert_contains('Parent_Service::' . self::METHOD . '()', $violation->message, 'the message names the declaring member');
    }

    public static function test_a_handler_class_with_no_common_base_is_final_too()
    {
        [$violations] = self::__run_inheritance([self::__ancestor('Some_Handlers', false, 'OnEvent')]);

        static::__assert_count(1, $violations, 'an #[OnEvent] class is final although it has no framework base');
    }

    public static function test_an_abstract_in_between_does_not_hide_the_registry_class()
    {
        [$violations] = self::__run_inheritance([
            self::__ancestor('Middle_Abstract', true, null),
            self::__ancestor('Top_Controller', false, 'Route'),
        ]);

        static::__assert_count(1, $violations, 'concrete -> abstract -> concrete still extends the registry class');
    }

    public static function test_an_abstract_ancestor_is_left_to_the_first_half()
    {
        [$violations] = self::__run_inheritance([self::__ancestor('Base_Abstract', true, 'Task')]);

        static::__assert_count(0, $violations, 'an attribute on an abstract ancestor is the first half\'s finding, not this one');
    }

    public static function test_extending_a_class_with_no_registry_attribute_is_allowed()
    {
        [$violations] = self::__run_inheritance([self::__ancestor('Plain_Parent', false, 'Relationship')]);

        static::__assert_count(0, $violations, 'only registry attributes make a class final');
    }

    public static function test_a_models_fetch_surface_does_not_make_it_final()
    {
        [$violations] = self::__run_inheritance([
            self::__ancestor('Some_Model', false, null, true, ['Ajax_Endpoint_Model_Fetch' => [[]], 'Auth' => [[]]]),
        ]);

        static::__assert_count(0, $violations, 'the model-fetch exemption holds for inheritance too');
    }

    public static function test_exception_marker_suppresses_the_inheritance_violation()
    {
        [$violations] = self::__run_inheritance([self::__ancestor('Parent_Service', false, 'Task')], true);

        static::__assert_count(0, $violations, 'the exception marker above the subclass declaration suppresses it');
    }
}
