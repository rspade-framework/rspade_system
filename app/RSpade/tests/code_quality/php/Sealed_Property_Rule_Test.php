<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Manifest\SealedProperty_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for the SEALED-01 rule (SealedProperty_CodeQualityRule).
 *
 * The rule fatals a manifest build when a subclass redeclares a property an ancestor
 * marked #[Sealed]. These tests drive the rule's core seam evaluate_class_properties()
 * against synthetic fixture files forming real inheritance chains, so the rule
 * AST-parses them exactly as it parses manifest classes in production (which builds
 * the same ancestry list from php_get_lineage + php_find_class).
 *
 * The #[Sealed] marker and the property name are assembled from constants so this
 * test file never carries a real sealed declaration another rule could read.
 */
class Sealed_Property_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is pure AST parsing over temp fixture files - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'SEALED-01';
    private const PROP = 'guarded_flag';
    private const NS = 'App\\Sealed_Fixture';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    private static function __fixture_dir(): string
    {
        $dir = storage_path('rsx-tmp') . '/sealed_property_fixture_' . uniqid();
        ensure_directory($dir);

        return $dir;
    }

    /**
     * A class source: optional parent, plus already-rendered property/member blocks.
     *
     * @param array<int,string> $members
     */
    private static function __class_source(string $name, ?string $parent, array $members): string
    {
        $extends = $parent !== null ? " extends {$parent}" : '';

        return "<?php\n"
            . 'namespace ' . self::NS . ";\n"
            . "class {$name}{$extends}\n"
            . "{\n"
            . implode("\n", $members) . "\n"
            . "}\n";
    }

    /** The sealed declaration, assembled so no literal #[Sealed] appears in this file. */
    private static function __sealed_property(string $name = self::PROP): string
    {
        $marker = '#[' . 'Sealed' . ']';

        return "    {$marker}\n"
            . '    public static bool $' . $name . " = true;";
    }

    private static function __plain_property(string $name = self::PROP): string
    {
        return '    public static bool $' . $name . ' = false;';
    }

    private static function __plain_property_with_exception(string $name = self::PROP): string
    {
        $marker = '@' . self::RULE_ID . '-EXCEPTION';

        return "    // {$marker} - fixture: verifying line-level suppression\n"
            . '    public static bool $' . $name . ' = false;';
    }

    /** A grouped declaration: `public $a, $b;` declares two properties from one node. */
    private static function __grouped_property(string $first, string $second): string
    {
        return '    public $' . $first . ', $' . $second . ';';
    }

    private static function __method(): string
    {
        return "    public function untouched()\n    {\n        return 1;\n    }";
    }

    /**
     * Write class sources to a fresh temp dir, then drive the rule's core seam for
     * $child_class against $ancestry_order (nearest -> root class names, all present
     * in $sources). Returns the collected SEALED-01 violations.
     *
     * @param array<string,string> $sources        class_name => php source
     * @param array<int,string>    $ancestry_order nearest -> root ancestor class names
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(array $sources, string $child_class, array $ancestry_order): array
    {
        $dir = self::__fixture_dir();

        $paths = [];
        foreach ($sources as $class_name => $source) {
            $path = $dir . '/' . $class_name . '.php';
            file_put_contents($path, $source);
            $paths[$class_name] = $path;
        }

        $ancestry = [];
        foreach ($ancestry_order as $ancestor_name) {
            $ancestry[] = [
                'class' => $ancestor_name,
                'file' => $paths[$ancestor_name],
            ];
        }

        $collector = new ViolationCollector();
        $rule = new SealedProperty_CodeQualityRule($collector);
        $rule->evaluate_class_properties($paths[$child_class], $child_class, $ancestry);

        $violations = $collector->get_by_rule(self::RULE_ID);

        foreach ($paths as $path) {
            @unlink($path);
        }
        @rmdir($dir);

        return $violations;
    }

    // =====================================================================
    // The violation
    // =====================================================================

    public static function test_direct_redeclaration_is_caught()
    {
        $sources = [
            'Seal_Parent' => self::__class_source('Seal_Parent', null, [self::__sealed_property()]),
            'Seal_Child' => self::__class_source('Seal_Child', 'Seal_Parent', [self::__plain_property()]),
        ];

        $violations = self::__run($sources, 'Seal_Child', ['Seal_Parent']);

        static::__assert_count(1, $violations, 'redeclaring a sealed property is flagged');

        $violation = array_values($violations)[0];
        static::__assert_equals('critical', $violation->severity, 'the violation is critical severity');
        static::__assert_contains(self::PROP, $violation->message, 'the message names the sealed property');
        static::__assert_contains('Seal_Parent', $violation->message, 'the message names the sealing class');
    }

    public static function test_redeclaration_at_depth_is_caught()
    {
        // The seal binds EVERY descendant, not just direct children.
        $sources = [
            'Seal_Root' => self::__class_source('Seal_Root', null, [self::__sealed_property()]),
            'Seal_Middle' => self::__class_source('Seal_Middle', 'Seal_Root', [self::__method()]),
            'Seal_Leaf' => self::__class_source('Seal_Leaf', 'Seal_Middle', [self::__plain_property()]),
        ];

        $violations = self::__run($sources, 'Seal_Leaf', ['Seal_Middle', 'Seal_Root']);

        static::__assert_count(1, $violations, 'a seal two generations up still binds');
    }

    public static function test_redeclaring_with_the_same_value_is_still_caught()
    {
        // The point is that the declaration lives in exactly one place - matching the
        // parent's value does not make a second declaration site acceptable.
        $sources = [
            'Seal_Same_Parent' => self::__class_source('Seal_Same_Parent', null, [self::__sealed_property()]),
            'Seal_Same_Child' => self::__class_source('Seal_Same_Child', 'Seal_Same_Parent', [
                '    public static bool $' . self::PROP . ' = true;',
            ]),
        ];

        $violations = self::__run($sources, 'Seal_Same_Child', ['Seal_Same_Parent']);

        static::__assert_count(1, $violations, 'an identical redeclaration is still a redeclaration');
    }

    public static function test_grouped_declaration_flags_only_the_sealed_name()
    {
        // The child declares BOTH names from one `public $x, $y;` node.
        $sources = [
            'Seal_Group_Parent' => self::__class_source('Seal_Group_Parent', null, [self::__sealed_property()]),
            'Seal_Group_Child' => self::__class_source('Seal_Group_Child', 'Seal_Group_Parent', [
                self::__grouped_property('unrelated_flag', self::PROP),
            ]),
        ];

        $violations = self::__run($sources, 'Seal_Group_Child', ['Seal_Group_Parent']);

        static::__assert_count(1, $violations, 'only the sealed name in a grouped declaration is flagged');
        $violation = array_values($violations)[0];
        static::__assert_contains(self::PROP, $violation->message, 'the flagged name is the sealed one');
    }

    // =====================================================================
    // What is NOT a violation
    // =====================================================================

    public static function test_unsealed_property_may_be_redeclared()
    {
        $sources = [
            'Seal_Open_Parent' => self::__class_source('Seal_Open_Parent', null, [self::__plain_property()]),
            'Seal_Open_Child' => self::__class_source('Seal_Open_Child', 'Seal_Open_Parent', [self::__plain_property()]),
        ];

        $violations = self::__run($sources, 'Seal_Open_Child', ['Seal_Open_Parent']);

        static::__assert_count(0, $violations, 'an ordinary property may be redeclared');
    }

    public static function test_different_property_name_is_not_a_violation()
    {
        $sources = [
            'Seal_Other_Parent' => self::__class_source('Seal_Other_Parent', null, [self::__sealed_property()]),
            'Seal_Other_Child' => self::__class_source('Seal_Other_Child', 'Seal_Other_Parent', [
                self::__plain_property('some_other_flag'),
            ]),
        ];

        $violations = self::__run($sources, 'Seal_Other_Child', ['Seal_Other_Parent']);

        static::__assert_count(0, $violations, 'declaring a differently-named property is fine');
    }

    public static function test_declaring_class_itself_is_not_a_violation()
    {
        // The class that declares the seal obviously declares the property.
        $sources = [
            'Seal_Self_Parent' => self::__class_source('Seal_Self_Parent', null, [self::__method()]),
            'Seal_Self' => self::__class_source('Seal_Self', 'Seal_Self_Parent', [self::__sealed_property()]),
        ];

        $violations = self::__run($sources, 'Seal_Self', ['Seal_Self_Parent']);

        static::__assert_count(0, $violations, 'the sealing class is not in violation of its own seal');
    }

    public static function test_exception_marker_suppresses_the_violation()
    {
        $sources = [
            'Seal_Exc_Parent' => self::__class_source('Seal_Exc_Parent', null, [self::__sealed_property()]),
            'Seal_Exc_Child' => self::__class_source('Seal_Exc_Child', 'Seal_Exc_Parent', [
                self::__plain_property_with_exception(),
            ]),
        ];

        $violations = self::__run($sources, 'Seal_Exc_Child', ['Seal_Exc_Parent']);

        static::__assert_count(0, $violations, 'the line-level exception marker suppresses the violation');
    }

    public static function test_class_with_no_properties_is_ignored()
    {
        $sources = [
            'Seal_Empty_Parent' => self::__class_source('Seal_Empty_Parent', null, [self::__sealed_property()]),
            'Seal_Empty_Child' => self::__class_source('Seal_Empty_Child', 'Seal_Empty_Parent', [self::__method()]),
        ];

        $violations = self::__run($sources, 'Seal_Empty_Child', ['Seal_Empty_Parent']);

        static::__assert_count(0, $violations, 'a subclass declaring no properties cannot violate a seal');
    }
}
