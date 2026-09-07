<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Manifest\RelationshipOverride_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for the RELATIONSHIP-OVERRIDE-01 rule
 * (RelationshipOverride_CodeQualityRule).
 *
 * The rule fatals a manifest build when a class overrides an ancestor's #[Relationship]
 * method without redeclaring the attribute. Detection is a compiled-manifest walk, so
 * these tests drive the rule's core seam evaluate_class_methods() with manifest-shaped
 * method maps over real fixture files - the file is what the rule reads for the code
 * snippet and the exception marker.
 *
 * The attribute marker is assembled from constants so this test file never carries a real
 * #[Relationship] declaration another rule could read.
 */
class Relationship_Override_Rule_Test extends Rsx_Test_Abstract
{
    // Detection reads manifest-shaped arrays plus a temp fixture file - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'RELATIONSHIP-OVERRIDE-01';
    private const METHOD = 'owner';
    private const PARENT_CLASS = 'Rel_Parent_Fixture';
    private const CHILD_CLASS = 'Rel_Child_Fixture';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    /**
     * Write a child-class fixture whose $method is declared at a known line, with an
     * optional attribute line and an optional exception marker above it.
     *
     * @return array{file: string, dir: string, line: int}
     */
    private static function __write_child(bool $with_attribute, bool $with_exception_marker): array
    {
        $dir = storage_path('rsx-tmp') . '/relationship_override_fixture_' . uniqid();
        ensure_directory($dir);

        $lines = [
            '<?php',
            '',
            'class ' . self::CHILD_CLASS . ' extends ' . self::PARENT_CLASS,
            '{',
        ];

        if ($with_exception_marker) {
            $lines[] = '    // @' . self::RULE_ID . '-EXCEPTION - fixture: verifying suppression';
        }

        if ($with_attribute) {
            $lines[] = '    #[' . 'Relationship' . ']';
        }

        $lines[] = '    public function ' . self::METHOD . '()';
        $declaration_line = count($lines);

        $lines[] = '    {';
        $lines[] = '        return null;';
        $lines[] = '    }';
        $lines[] = '}';

        $file = $dir . '/' . self::CHILD_CLASS . '.php';
        file_put_contents($file, implode("\n", $lines) . "\n");

        return ['file' => $file, 'dir' => $dir, 'line' => $declaration_line];
    }

    /**
     * Drive the rule seam for the child fixture and return the collected violations.
     *
     * @param array<string, string> $ancestor_relationships method name => declaring class
     * @return array<int, \App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(
        bool $with_attribute,
        bool $with_exception_marker,
        array $ancestor_relationships,
        array $extra_methods = []
    ): array {
        $fixture = self::__write_child($with_attribute, $with_exception_marker);

        $own_methods = [
            self::METHOD => [
                'name' => self::METHOD,
                'line' => $fixture['line'],
            ],
        ];

        if ($with_attribute) {
            $own_methods[self::METHOD]['attributes'] = ['Relationship' => [[]]];
        }

        foreach ($extra_methods as $name => $data) {
            $own_methods[$name] = $data;
        }

        $collector = new ViolationCollector();
        $rule = new RelationshipOverride_CodeQualityRule($collector);
        $rule->evaluate_class_methods($fixture['file'], self::CHILD_CLASS, $own_methods, $ancestor_relationships);

        $violations = $collector->get_by_rule(self::RULE_ID);

        @unlink($fixture['file']);
        @rmdir($fixture['dir']);

        return $violations;
    }

    // =====================================================================
    // The violation
    // =====================================================================

    public static function test_override_without_the_attribute_is_caught()
    {
        $violations = self::__run(false, false, [self::METHOD => self::PARENT_CLASS]);

        static::__assert_count(1, $violations, 'an unattributed override of a relationship method is flagged');

        $violation = array_values($violations)[0];
        static::__assert_equals('critical', $violation->severity, 'the violation is critical severity');
        static::__assert_contains(self::METHOD, $violation->message, 'the message names the method');
        static::__assert_contains(self::PARENT_CLASS, $violation->message, 'the message names the declaring ancestor');
    }

    // =====================================================================
    // What is NOT a violation
    // =====================================================================

    public static function test_override_that_redeclares_the_attribute_is_clean()
    {
        $violations = self::__run(true, false, [self::METHOD => self::PARENT_CLASS]);

        static::__assert_count(0, $violations, 'redeclaring the attribute clears the rule');
    }

    public static function test_method_no_ancestor_declares_is_clean()
    {
        $violations = self::__run(false, false, ['some_other_relation' => self::PARENT_CLASS]);

        static::__assert_count(0, $violations, 'a method that overrides no relationship is not judged');
    }

    public static function test_class_with_no_relationship_ancestors_is_clean()
    {
        $violations = self::__run(false, false, []);

        static::__assert_count(0, $violations, 'nothing to violate when no ancestor declares a relationship');
    }

    public static function test_exception_marker_suppresses_the_violation()
    {
        $violations = self::__run(false, true, [self::METHOD => self::PARENT_CLASS]);

        static::__assert_count(0, $violations, 'the exception marker above the declaration suppresses it');
    }

    public static function test_only_the_overriding_method_is_flagged()
    {
        // A second own method that no ancestor declares as a relationship rides along.
        $violations = self::__run(false, false, [self::METHOD => self::PARENT_CLASS], [
            'unrelated_method' => ['name' => 'unrelated_method', 'line' => 3],
        ]);

        static::__assert_count(1, $violations, 'only the method the ancestor declares is flagged');
        $violation = array_values($violations)[0];
        static::__assert_contains(self::METHOD, $violation->message, 'the flagged method is the overriding one');
    }
}
