<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use Closure;
use App\RSpade\CodeQuality\Rules\JavaScript\JsParentCallChain_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\JsParsers\Js_Parser;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for JS-PARENT-CHAIN-01 (JsParentCallChain_CodeQualityRule) - a JavaScript method
 * override must call super.<method>() unless the nearest ancestor declaring the method carries the
 * `@Replaceable` decorator.
 *
 * Each case writes JavaScript fixtures outside the manifest, extracts their records with the
 * real parser (Js_Parser::extract_metadata(), through the node service - the same records the
 * manifest holds) and hands the child and its ancestry to the rule's evaluate_class() seam, so
 * super-call detection is exercised over the real AST. The cases naming Component and
 * Spa_Layout build the ancestry with the rule's own manifest_ancestry(), over the real
 * framework classes. The incremental cases drive the rule's subjects() decision - which classes
 * a pass judges for a given changed set - over the real index.
 */
class Js_Parent_Call_Chain_Rule_Test extends Rsx_Test_Abstract
{
    // Pure file inspection - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'JS-PARENT-CHAIN-01';

    private const PARENT_PLAIN = "class Pcc_Parent {\n"
        . "    refresh_view() {\n"
        . "        this.count = 1;\n"
        . "    }\n"
        . "    static configure() {\n"
        . "        return 1;\n"
        . "    }\n"
        . "}\n";

    private const PARENT_REPLACEABLE = "class Pcc_Parent {\n"
        . "    /** A hook overriders replace. */\n"
        . "    @Replaceable\n"
        . "    refresh_view() {\n"
        . "        this.count = 1;\n"
        . "    }\n"
        . "    @Replaceable\n"
        . "    static configure() {\n"
        . "        return 1;\n"
        . "    }\n"
        . "}\n";

    // The JSDoc spelling of the marker is a comment, not the decorator: it exempts nothing.
    private const PARENT_DOCBLOCK_MARKER = "class Pcc_Parent {\n"
        . "    /**\n"
        . "     * @Replaceable\n"
        . "     */\n"
        . "    refresh_view() {\n"
        . "        this.count = 1;\n"
        . "    }\n"
        . "}\n";

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    /**
     * Write each source to a fresh directory and return its parsed record, keyed by class name.
     *
     * @param array<string,string> $sources class name => JavaScript source
     * @return array{0: string, 1: array<string,array>} [directory, class => record]
     */
    private static function __parse(array $sources): array
    {
        $dir = Rsx_Project_Paths::tmp_path() . '/js_parent_chain_fixture_' . uniqid();
        ensure_directory($dir);

        $records = [];
        foreach ($sources as $class_name => $source) {
            $path = $dir . '/' . $class_name . '.js';
            file_put_contents($path, $source);
            $records[$class_name] = ['extension' => 'js', 'file' => $path]
                + Js_Parser::extract_metadata($path);
        }

        return [$dir, $records];
    }

    private static function __cleanup(string $dir): void
    {
        foreach (glob($dir . '/*.js') as $path) {
            @unlink($path);
        }
        @rmdir($dir);
    }

    private static function __call_private(JsParentCallChain_CodeQualityRule $rule, string $method, array $args)
    {
        $call = Closure::bind(
            function (string $method, array $args) {
                return $this->{$method}(...$args);
            },
            $rule,
            JsParentCallChain_CodeQualityRule::class
        );

        return $call($method, $args);
    }

    /**
     * Judge $child_class against fixture ancestors $ancestry_order (nearest -> root). Returns
     * the JS-PARENT-CHAIN-01 violations.
     *
     * @param array<string,string> $sources
     * @param array<int,string>    $ancestry_order
     */
    private static function __run(array $sources, string $child_class, array $ancestry_order): array
    {
        [$dir, $records] = self::__parse($sources);

        try {
            $ancestry = [];
            foreach ($ancestry_order as $name) {
                $ancestry[] = ['class' => $name, 'metadata' => $records[$name]];
            }

            $collector = new ViolationCollector();
            $rule = new JsParentCallChain_CodeQualityRule($collector);
            self::__call_private($rule, 'evaluate_class', [$records[$child_class]['file'], $records[$child_class], $ancestry]);

            return array_values($collector->get_by_rule(self::RULE_ID));
        } finally {
            self::__cleanup($dir);
        }
    }

    /**
     * Judge one fixture class whose parent is a REAL manifest class, with the ancestry the
     * rule builds in production.
     */
    private static function __run_against_manifest(string $source, string $child_class, string $parent_class): array
    {
        [$dir, $records] = self::__parse([$child_class => $source]);

        try {
            $collector = new ViolationCollector();
            $rule = new JsParentCallChain_CodeQualityRule($collector);
            $ancestry = self::__call_private($rule, 'manifest_ancestry', [$parent_class]);
            self::__call_private($rule, 'evaluate_class', [$records[$child_class]['file'], $records[$child_class], $ancestry]);

            return array_values($collector->get_by_rule(self::RULE_ID));
        } finally {
            self::__cleanup($dir);
        }
    }

    private static function __child(string $body, string $parent = 'Pcc_Parent'): string
    {
        return "class Pcc_Child extends {$parent} {\n" . $body . "}\n";
    }

    // =====================================================================
    // The obligation
    // =====================================================================

    public static function test_missing_super_is_flagged()
    {
        $violations = self::__run([
            'Pcc_Parent' => self::PARENT_PLAIN,
            'Pcc_Child' => self::__child("    refresh_view() {\n        this.count = 2;\n    }\n"),
        ], 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(1, $violations, 'an override that never calls super is flagged');
        $violation = $violations[0];
        static::__assert_equals('critical', $violation->severity, 'the violation is critical');
        static::__assert_equals(2, $violation->line_number, 'the violation sits on the override');
        static::__assert_contains('Pcc_Child.refresh_view() overrides Pcc_Parent.refresh_view()', $violation->message,
            'the message names the child, the method and the declaring ancestor');
        static::__assert_contains('most-derived lifecycle hook', $violation->message, 'the message says why');
        static::__assert_contains('super.refresh_view(', $violation->suggestion, 'the fix names the super call');
        static::__assert_contains('@Replaceable', $violation->suggestion, 'the fix names the parent-side marker');
    }

    public static function test_super_call_passes()
    {
        $violations = self::__run([
            'Pcc_Parent' => self::PARENT_PLAIN,
            'Pcc_Child' => self::__child("    refresh_view() {\n        super.refresh_view();\n        this.count = 2;\n    }\n"),
        ], 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(0, $violations, 'an override calling super.<same name>() is clean');
    }

    public static function test_super_through_call_apply_and_arrow_passes()
    {
        $violations = self::__run([
            'Pcc_Parent' => self::PARENT_PLAIN,
            'Pcc_Child' => self::__child(
                "    refresh_view(...args) {\n"
                . "        const run = () => super.refresh_view.apply(this, args);\n"
                . "        run();\n"
                . "    }\n"
            ),
        ], 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(0, $violations, 'super.<name>.apply() inside an arrow function is the parent call');
    }

    public static function test_super_inside_conditional_passes()
    {
        $violations = self::__run([
            'Pcc_Parent' => self::PARENT_PLAIN,
            'Pcc_Child' => self::__child(
                "    refresh_view() {\n"
                . "        if (this.ready) {\n"
                . "            for (const step of [1]) {\n"
                . "                super.refresh_view();\n"
                . "            }\n"
                . "        }\n"
                . "    }\n"
            ),
        ], 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(0, $violations, 'a super call inside a conditional and a loop is the parent call');
    }

    public static function test_super_in_comment_or_string_is_still_flagged()
    {
        $violations = self::__run([
            'Pcc_Parent' => self::PARENT_PLAIN,
            'Pcc_Child' => self::__child(
                "    refresh_view() {\n"
                . "        // super.refresh_view();\n"
                . "        /* super.refresh_view(); */\n"
                . "        const text = 'super.refresh_view()';\n"
                . "        return `super.refresh_view()` + text;\n"
                . "    }\n"
            ),
        ], 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(1, $violations, 'a super call written only in comments and strings is no call');
    }

    public static function test_other_super_call_or_nested_scope_does_not_satisfy()
    {
        $violations = self::__run([
            'Pcc_Parent' => self::PARENT_PLAIN,
            'Pcc_Child' => self::__child(
                "    refresh_view() {\n"
                . "        super.toString();\n"
                . "        const helper = { refresh_view() { return super.refresh_view(); } };\n"
                . "        return helper;\n"
                . "    }\n"
            ),
        ], 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(1, $violations,
            'super of another method, or inside an object method with its own super, is not the parent call');
    }

    // =====================================================================
    // The opt-out and the exemptions
    // =====================================================================

    public static function test_replaceable_parent_passes()
    {
        $violations = self::__run([
            'Pcc_Parent' => self::PARENT_REPLACEABLE,
            'Pcc_Child' => self::__child(
                "    refresh_view() {\n        this.count = 2;\n    }\n"
                . "    static configure() {\n        return 2;\n    }\n"
            ),
        ], 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(0, $violations, '@Replaceable on the parent method exempts instance and static overrides');
    }

    public static function test_docblock_marker_is_not_the_decorator()
    {
        $violations = self::__run([
            'Pcc_Parent' => self::PARENT_DOCBLOCK_MARKER,
            'Pcc_Child' => self::__child("    refresh_view() {\n        this.count = 2;\n    }\n"),
        ], 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(1, $violations, 'a @Replaceable written in a docblock is a comment and exempts nothing');
    }

    public static function test_constructor_and_accessors_are_exempt()
    {
        $violations = self::__run([
            'Pcc_Parent' => "class Pcc_Parent {\n"
                . "    constructor() { this.a = 1; }\n"
                . "    get label() { return 'a'; }\n"
                . "}\n",
            'Pcc_Child' => self::__child(
                "    constructor() { super(); this.a = 2; }\n"
                . "    get label() { return 'b'; }\n"
            ),
        ], 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(0, $violations, 'the constructor and getters carry no chaining obligation');
    }

    public static function test_jqhtml_base_default_passes()
    {
        $violations = self::__run_against_manifest(
            self::__child("    on_create() {\n        this.state = {};\n    }\n", 'Component'),
            'Pcc_Child',
            'Component'
        );

        static::__assert_count(0, $violations,
            "jqhtml's own lifecycle defaults sit outside the manifest and are implicitly replaceable");
    }

    public static function test_jqhtml_sub_component_chain_is_enforced()
    {
        $component = "class Pcc_Component extends Component {\n"
            . "    on_ready() {\n        this.wired = true;\n    }\n"
            . "}\n";
        $sub_without = "class Pcc_Sub_Component extends Pcc_Component {\n"
            . "    on_ready() {\n        this.extra = true;\n    }\n"
            . "}\n";
        $sub_with = "class Pcc_Sub_Component extends Pcc_Component {\n"
            . "    async on_ready() {\n        await super.on_ready();\n        this.extra = true;\n    }\n"
            . "}\n";

        $judge = function (string $sub_source) use ($component): array {
            [$dir, $records] = self::__parse(['Pcc_Component' => $component, 'Pcc_Sub_Component' => $sub_source]);

            try {
                $collector = new ViolationCollector();
                $rule = new JsParentCallChain_CodeQualityRule($collector);
                $framework = self::__call_private($rule, 'manifest_ancestry', ['Component']);

                // The component itself: its on_ready() overrides only jqhtml's own base.
                self::__call_private($rule, 'evaluate_class', [$records['Pcc_Component']['file'], $records['Pcc_Component'], $framework]);
                $component_findings = count($collector->get_by_rule(self::RULE_ID));

                $ancestry = array_merge([['class' => 'Pcc_Component', 'metadata' => $records['Pcc_Component']]], $framework);
                self::__call_private($rule, 'evaluate_class', [$records['Pcc_Sub_Component']['file'], $records['Pcc_Sub_Component'], $ancestry]);

                return [$component_findings, array_values($collector->get_by_rule(self::RULE_ID))];
            } finally {
                self::__cleanup($dir);
            }
        };

        [$component_findings, $violations] = $judge($sub_without);
        static::__assert_equals(0, $component_findings, "a component's own on_ready() over jqhtml's base is free");
        static::__assert_count(1, $violations, "a sub-component's on_ready() over its parent component's is enforced");
        static::__assert_contains('Pcc_Sub_Component.on_ready() overrides Pcc_Component.on_ready()', $violations[0]->message,
            'the finding names the in-manifest parent component');

        [, $chained] = $judge($sub_with);
        static::__assert_count(0, $chained, 'await super.on_ready() satisfies it');
    }

    public static function test_real_framework_lineage()
    {
        $violations = self::__run_against_manifest(
            self::__child(
                "    on_create() {\n        this.state = {};\n    }\n"
                . "    on_action(url, action_name, args) {\n        this.url = url;\n    }\n",
                'Spa_Layout'
            ),
            'Pcc_Child',
            'Spa_Layout'
        );

        static::__assert_count(1, $violations, 'Spa_Layout.on_create() chains; on_action() is @Replaceable');
        static::__assert_contains('overrides Spa_Layout.on_create()', $violations[0]->message,
            'the finding names the framework class that declares the hook');
    }

    // =====================================================================
    // Static methods
    // =====================================================================

    public static function test_static_override()
    {
        $missing = self::__run([
            'Pcc_Parent' => self::PARENT_PLAIN,
            'Pcc_Child' => self::__child("    static configure() {\n        return 2;\n    }\n"),
        ], 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(1, $missing, 'a static override without super is flagged');
        static::__assert_contains('Pcc_Child.static configure()', $missing[0]->message, 'the message marks it static');

        $chained = self::__run([
            'Pcc_Parent' => self::PARENT_PLAIN,
            'Pcc_Child' => self::__child("    static configure() {\n        return super.configure() + 1;\n    }\n"),
        ], 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(0, $chained, 'a static override calling super.configure() is clean');

        $other_bucket = self::__run([
            'Pcc_Parent' => self::PARENT_PLAIN,
            'Pcc_Child' => self::__child("    configure() {\n        return 2;\n    }\n"),
        ], 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(0, $other_bucket, 'an instance method does not override a static one of the same name');
    }

    // =====================================================================
    // Multi-level lineage
    // =====================================================================

    public static function test_grandparent_declares()
    {
        $sources = [
            'Pcc_Root' => "class Pcc_Root {\n    refresh_view() {\n        this.count = 1;\n    }\n}\n",
            'Pcc_Parent' => "class Pcc_Parent extends Pcc_Root {\n    other() {\n        return 1;\n    }\n}\n",
            'Pcc_Child' => self::__child("    refresh_view() {\n        this.count = 3;\n    }\n"),
        ];

        $violations = self::__run($sources, 'Pcc_Child', ['Pcc_Parent', 'Pcc_Root']);

        static::__assert_count(1, $violations, 'an obligation set by a grandparent is enforced');
        static::__assert_contains('overrides Pcc_Root.refresh_view()', $violations[0]->message,
            'the finding names the grandparent that declares the method');
    }

    public static function test_nearest_declarer_anchors()
    {
        $sources = [
            'Pcc_Root' => "class Pcc_Root {\n    @Replaceable\n    refresh_view() {\n        this.count = 1;\n    }\n}\n",
            'Pcc_Parent' => "class Pcc_Parent extends Pcc_Root {\n    refresh_view() {\n        this.count = 2;\n    }\n}\n",
            'Pcc_Child' => self::__child("    refresh_view() {\n        this.count = 3;\n    }\n"),
        ];

        $child = self::__run($sources, 'Pcc_Child', ['Pcc_Parent', 'Pcc_Root']);
        static::__assert_count(1, $child, 'a concrete re-declaration re-establishes the chain below it');
        static::__assert_contains('overrides Pcc_Parent.refresh_view()', $child[0]->message,
            'the anchor is the nearest declaring ancestor');

        $parent = self::__run($sources, 'Pcc_Parent', ['Pcc_Root']);
        static::__assert_count(0, $parent, 'the middle class replaces a @Replaceable root method freely');
    }

    // =====================================================================
    // The child-side exception marker
    // =====================================================================

    public static function test_exception_marker_requires_rationale()
    {
        $marker = '@' . self::RULE_ID . '-EXCEPTION';

        $with_reason = self::__run([
            'Pcc_Parent' => self::PARENT_PLAIN,
            'Pcc_Child' => self::__child(
                "    // {$marker} the parent's work is repeated here against another source\n"
                . "    refresh_view() {\n        this.count = 2;\n    }\n"
            ),
        ], 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(0, $with_reason, 'a marker with a rationale excuses the override');

        $bare = self::__run([
            'Pcc_Parent' => self::PARENT_PLAIN,
            'Pcc_Child' => self::__child("    // {$marker}\n    refresh_view() {\n        this.count = 2;\n    }\n"),
        ], 'Pcc_Child', ['Pcc_Parent']);

        static::__assert_count(1, $bare, 'a bare marker excuses nothing');
    }

    // =====================================================================
    // Incremental: which classes a pass judges
    // =====================================================================

    /**
     * The rule's subjects() over the real JS class index for a changed set of files.
     *
     * @param array<int,string> $changed_classes classes whose files are reported changed
     * @return array<int,string>
     */
    private static function __subjects(array $changed_classes): array
    {
        $index = \App\RSpade\Core\Manifest\Manifest::get_full_manifest()['data'];
        $changed = array_map(fn ($class) => $index['js_classes'][$class]['file'], $changed_classes);

        $rule = new JsParentCallChain_CodeQualityRule(new ViolationCollector());

        return self::__call_private($rule, 'subjects', [$changed, $index['js_classes'], $index['js_subclass_index'] ?? []]);
    }

    public static function test_changed_child_judges_only_itself()
    {
        $subjects = self::__subjects(['_Sys_Layout']);

        static::__assert_equals(['_Sys_Layout'], $subjects, 'a changed leaf class is the only class re-judged');
    }

    public static function test_changed_parent_judges_its_descendants()
    {
        $index = \App\RSpade\Core\Manifest\Manifest::get_full_manifest()['data'];
        $subjects = self::__subjects(['Spa_Layout']);

        static::__assert_true(in_array('Spa_Layout', $subjects, true), 'the changed class itself is judged');
        static::__assert_true(in_array('_Sys_Layout', $subjects, true), 'a descendant of the changed class is judged');
        static::__assert_false(in_array('Spa_Action', $subjects, true), 'an unrelated class is not judged');

        $expected = array_merge(['Spa_Layout'], $index['js_subclass_index']['Spa_Layout']);
        sort($expected);
        sort($subjects);
        static::__assert_equals($expected, $subjects, 'exactly the class and every descendant the index lists');
    }

    public static function test_no_changed_set_judges_every_class()
    {
        $index = \App\RSpade\Core\Manifest\Manifest::get_full_manifest()['data'];

        static::__assert_count(count($index['js_classes']), self::__subjects([]),
            'a pass with no changed set (a full rebuild, rsx:check) judges every indexed class');
    }
}
