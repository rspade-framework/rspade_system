<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use Closure;
use App\RSpade\CodeQuality\Rules\Manifest\ParentCallChain_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for the PHP-PARENT-CHAIN-01 rule (ParentCallChain_CodeQualityRule).
 *
 * The rule requires a method override to call parent::<method>() unless the nearest
 * ancestor declaring the method is abstract or marked #[Replaceable]. These tests
 * drive the rule's core seam evaluate_class_methods() directly (via a bound
 * closure), feeding it synthetic fixture files that form real inheritance chains.
 * The rule AST-parses those fixtures exactly as it would AST-parse manifest classes
 * in production, so lineage resolution, nearest-declarer anchoring, abstract /
 * #[Replaceable] exemption, and parent-call detection are all exercised over real
 * parser output without touching the manifest (production builds the same ancestry
 * list from php_get_lineage + php_find_class).
 *
 * Method names and the literal "parent::" call token are assembled from variables so
 * THIS test file never declares a real overriding method or a real parent call of
 * its own that another code-quality rule could misread.
 */
class Parent_Call_Chain_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is pure AST parsing over temp fixture files - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'PHP-PARENT-CHAIN-01';
    private const METHOD = 'configure';
    private const NS = 'App\\Pcc_Fixture';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    private static function __fixture_dir(): string
    {
        $dir = storage_path('rsx-tmp') . '/parent_chain_fixture_' . uniqid();
        ensure_directory($dir);

        return $dir;
    }

    /**
     * Build a fixture class source. $methods is an array of already-rendered method
     * source blocks.
     */
    private static function __class_source(string $name, ?string $parent, array $methods): string
    {
        $extends = $parent !== null ? " extends {$parent}" : '';
        $body = implode("\n", $methods);

        return "<?php\n"
            . 'namespace ' . self::NS . ";\n"
            . "class {$name}{$extends}\n"
            . "{\n"
            . $body . "\n"
            . "}\n";
    }

    private static function __method_missing_parent(bool $static): string
    {
        $keyword = $static ? 'public static function' : 'public function';

        return "    {$keyword} " . self::METHOD . "()\n"
            . "    {\n"
            . "        \$value = 1;\n"
            . "        return \$value;\n"
            . "    }";
    }

    private static function __method_with_parent(bool $static): string
    {
        $keyword = $static ? 'public static function' : 'public function';
        // Assemble the parent call from a token so this file has no literal of it.
        $call = 'parent::' . self::METHOD . '();';

        return "    {$keyword} " . self::METHOD . "()\n"
            . "    {\n"
            . "        {$call}\n"
            . "        return 1;\n"
            . "    }";
    }

    private static function __method_wrong_parent_name(): string
    {
        $call = 'parent::' . 'some_other_method();';

        return '    public function ' . self::METHOD . "()\n"
            . "    {\n"
            . "        {$call}\n"
            . "        return 1;\n"
            . "    }";
    }

    private static function __method_missing_parent_with_exception(): string
    {
        $marker = '@' . self::RULE_ID . '-EXCEPTION';

        return "    // {$marker} - documented edge syntax invokes the parent indirectly\n"
            . '    public function ' . self::METHOD . "()\n"
            . "    {\n"
            . "        return 1;\n"
            . "    }";
    }

    private static function __method_concrete_declarer(bool $replaceable): string
    {
        $attr = $replaceable ? "    #[Replaceable]\n" : '';

        return $attr
            . '    public function ' . self::METHOD . "()\n"
            . "    {\n"
            . "        return 0;\n"
            . "    }";
    }

    private static function __method_abstract_declarer(): string
    {
        return '    abstract public function ' . self::METHOD . '();';
    }

    /**
     * Write class sources to a fresh temp dir, then drive the rule's core seam
     * evaluate_class_methods() for $child_class against the ancestry given by
     * $ancestry_order (a nearest -> root list of class names, each of which must be
     * present in $sources). Returns the collected PHP-PARENT-CHAIN-01 violations.
     *
     * @param array<string,string> $sources     class_name => php source
     * @param string               $child_class the class under evaluation
     * @param array<int,string>    $ancestry_order nearest -> root ancestor class names
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
        $rule = new ParentCallChain_CodeQualityRule($collector);

        $invoke = Closure::bind(
            function ($child_file, $child, $chain) {
                return $this->evaluate_class_methods($child_file, $child, $chain);
            },
            $rule,
            ParentCallChain_CodeQualityRule::class
        );

        $invoke($paths[$child_class], $child_class, $ancestry);

        $violations = $collector->get_by_rule(self::RULE_ID);

        // Cleanup.
        foreach ($paths as $path) {
            @unlink($path);
        }
        @rmdir($dir);

        return $violations;
    }

    // =====================================================================
    // Missing parent call is caught (static + non-static)
    // =====================================================================

    public static function test_static_override_missing_parent_is_caught()
    {
        $sources = [
            'Pcc_Parent' => self::__class_source('Pcc_Parent', null, [self::__method_concrete_declarer(false)]),
            'Pcc_Child' => self::__class_source('Pcc_Child', 'Pcc_Parent', [self::__method_missing_parent(true)]),
        ];

        $violations = self::__run($sources, 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(1, $violations, 'a static override that omits parent:: is flagged');
        $violation = array_values($violations)[0];
        static::__assert_equals('critical', $violation->severity, 'the violation is critical severity');
    }

    public static function test_instance_override_missing_parent_is_caught()
    {
        $sources = [
            'Pcc_Parent' => self::__class_source('Pcc_Parent', null, [self::__method_concrete_declarer(false)]),
            'Pcc_Child' => self::__class_source('Pcc_Child', 'Pcc_Parent', [self::__method_missing_parent(false)]),
        ];

        $violations = self::__run($sources, 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(1, $violations, 'a non-static override that omits parent:: is flagged');
    }

    // =====================================================================
    // A real parent call clears the obligation (positive control)
    // =====================================================================

    public static function test_override_with_parent_call_is_clean()
    {
        $sources = [
            'Pcc_Parent' => self::__class_source('Pcc_Parent', null, [self::__method_concrete_declarer(false)]),
            'Pcc_Child' => self::__class_source('Pcc_Child', 'Pcc_Parent', [self::__method_with_parent(false)]),
        ];

        $violations = self::__run($sources, 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(0, $violations, 'an override that calls parent::configure() is clean');
    }

    // =====================================================================
    // #[Replaceable] on the parent method clears both static and non-static
    // =====================================================================

    public static function test_replaceable_parent_clears_instance_override()
    {
        $sources = [
            'Pcc_Parent' => self::__class_source('Pcc_Parent', null, [self::__method_concrete_declarer(true)]),
            'Pcc_Child' => self::__class_source('Pcc_Child', 'Pcc_Parent', [self::__method_missing_parent(false)]),
        ];

        $violations = self::__run($sources, 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(0, $violations, '#[Replaceable] on the parent exempts a non-static override');
    }

    public static function test_replaceable_parent_clears_static_override()
    {
        $sources = [
            'Pcc_Parent' => self::__class_source('Pcc_Parent', null, [self::__method_concrete_declarer(true)]),
            'Pcc_Child' => self::__class_source('Pcc_Child', 'Pcc_Parent', [self::__method_missing_parent(true)]),
        ];

        $violations = self::__run($sources, 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(0, $violations, '#[Replaceable] on the parent exempts a static override');
    }

    // =====================================================================
    // 4-level chain: method declared only at the top A; D must chain to A.
    // #[Replaceable] on A::configure() clears D (nearest-declarer resolves
    // through the non-declaring B and C links).
    // =====================================================================

    public static function test_four_level_chain_requires_chaining_to_top()
    {
        $sources = [
            'Pcc_A' => self::__class_source('Pcc_A', null, [self::__method_concrete_declarer(false)]),
            'Pcc_B' => self::__class_source('Pcc_B', 'Pcc_A', []),
            'Pcc_C' => self::__class_source('Pcc_C', 'Pcc_B', []),
            'Pcc_D' => self::__class_source('Pcc_D', 'Pcc_C', [self::__method_missing_parent(false)]),
        ];

        $violations = self::__run($sources, 'Pcc_D', ['Pcc_C', 'Pcc_B', 'Pcc_A']);

        static::__assert_count(1, $violations, 'a 4-level override must chain to the top declarer A');
        $violation = array_values($violations)[0];
        static::__assert_contains('Pcc_A::configure()', $violation->message, 'the violation names A as the declarer');
    }

    public static function test_four_level_chain_replaceable_top_clears_descendant()
    {
        $sources = [
            'Pcc_A' => self::__class_source('Pcc_A', null, [self::__method_concrete_declarer(true)]),
            'Pcc_B' => self::__class_source('Pcc_B', 'Pcc_A', []),
            'Pcc_C' => self::__class_source('Pcc_C', 'Pcc_B', []),
            'Pcc_D' => self::__class_source('Pcc_D', 'Pcc_C', [self::__method_missing_parent(false)]),
        ];

        $violations = self::__run($sources, 'Pcc_D', ['Pcc_C', 'Pcc_B', 'Pcc_A']);

        static::__assert_count(0, $violations, '#[Replaceable] on the top declarer A clears the 4-level descendant D');
    }

    // =====================================================================
    // Intermediate concrete re-declaration anchors to the NEAREST declarer.
    // A::configure() is #[Replaceable]; B re-declares it concretely (no attr);
    // C overrides -> C must chain to B (not to the replaceable A).
    // =====================================================================

    public static function test_intermediate_concrete_redeclaration_reestablishes_obligation()
    {
        $sources = [
            'Pcc_A' => self::__class_source('Pcc_A', null, [self::__method_concrete_declarer(true)]),
            'Pcc_B' => self::__class_source('Pcc_B', 'Pcc_A', [self::__method_concrete_declarer(false)]),
            'Pcc_C' => self::__class_source('Pcc_C', 'Pcc_B', [self::__method_missing_parent(false)]),
        ];

        $violations = self::__run($sources, 'Pcc_C', ['Pcc_B', 'Pcc_A']);

        static::__assert_count(1, $violations, 'a concrete re-declaration at B re-establishes the chain obligation for C');
        $violation = array_values($violations)[0];
        static::__assert_contains('Pcc_B::configure()', $violation->message, 'the obligation anchors to the nearest declarer B');
    }

    // =====================================================================
    // Abstract parent method -> child is exempt (cannot call an abstract parent)
    // =====================================================================

    public static function test_abstract_parent_method_exempts_child()
    {
        $sources = [
            'Pcc_Parent' => self::__class_source('Pcc_Parent', null, [self::__method_abstract_declarer()]),
            'Pcc_Child' => self::__class_source('Pcc_Child', 'Pcc_Parent', [self::__method_missing_parent(false)]),
        ];

        $violations = self::__run($sources, 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(0, $violations, 'overriding an abstract parent method is exempt');
    }

    // =====================================================================
    // Vendor parent (declaration invisible to the manifest) -> not flagged.
    // Simulated by an ancestry whose classes do NOT declare the method: the
    // nearest-declarer walk finds nothing (the real declarer lives in vendor),
    // exactly the F-002 boundary for e.g. overriding Eloquent booted().
    // =====================================================================

    public static function test_vendor_parent_method_is_not_flagged()
    {
        $sources = [
            // Framework-visible ancestor that does NOT declare configure().
            'Pcc_Parent' => self::__class_source('Pcc_Parent', null, [
                "    public function unrelated_method()\n    {\n        return 0;\n    }",
            ]),
            'Pcc_Child' => self::__class_source('Pcc_Child', 'Pcc_Parent', [self::__method_missing_parent(false)]),
        ];

        $violations = self::__run($sources, 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(0, $violations, 'an override of a method no manifest ancestor declares (vendor) is not flagged');
    }

    // =====================================================================
    // parent::other_method() does NOT satisfy the requirement (decision 6)
    // =====================================================================

    public static function test_wrong_parent_method_name_does_not_satisfy()
    {
        $sources = [
            'Pcc_Parent' => self::__class_source('Pcc_Parent', null, [self::__method_concrete_declarer(false)]),
            'Pcc_Child' => self::__class_source('Pcc_Child', 'Pcc_Parent', [self::__method_wrong_parent_name()]),
        ];

        $violations = self::__run($sources, 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(1, $violations, 'calling parent::some_other_method() does not satisfy the configure() chain');
    }

    // =====================================================================
    // @PHP-PARENT-CHAIN-01-EXCEPTION suppresses a flagged override
    // =====================================================================

    public static function test_exception_marker_suppresses_violation()
    {
        $sources = [
            'Pcc_Parent' => self::__class_source('Pcc_Parent', null, [self::__method_concrete_declarer(false)]),
            'Pcc_Child' => self::__class_source('Pcc_Child', 'Pcc_Parent', [self::__method_missing_parent_with_exception()]),
        ];

        $violations = self::__run($sources, 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(0, $violations, 'a per-method @PHP-PARENT-CHAIN-01-EXCEPTION marker suppresses the violation');
    }
}
