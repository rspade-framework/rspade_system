<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\JavaScript\ThisUsage_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for JS-THIS-01 (ThisUsage_CodeQualityRule).
 *
 * The rule forbids a naked `this` in a static method (use the class name, or alias it as
 * `const CurrentClass = this`). Its analysis must read every class file the transform
 * accepts - a decorated SPA action, a class with private members or a static block - because
 * a file it cannot parse was once reported as clean, and a third of the class files were
 * never checked. A file it still cannot parse is one violation naming the parse error.
 *
 * Detection runs in the node service, so the tests exercise the real analyzer.
 */
class This_Usage_Rule_Test extends Rsx_Test_Abstract
{
    // Pure file inspection - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'JS-THIS-01';

    /**
     * Write $source as a class file and run the rule over it.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $source): array
    {
        $dir = Rsx_Project_Paths::tmp_path() . '/this_usage_rule_fixture_' . uniqid();
        $path = $dir . '/rsx/this_usage_probe.js';
        ensure_directory(dirname($path));
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new ThisUsage_CodeQualityRule($collector);
        $rule->check($path, $source, ['class' => 'This_Usage_Probe']);

        @unlink($path);
        @rmdir(dirname($path));
        @rmdir($dir);

        return array_values($collector->get_by_rule(self::RULE_ID));
    }

    /**
     * A DECORATED class is analyzed: its static method's naked `this` is reported.
     */
    public static function test_decorated_class_is_analyzed()
    {
        $violations = self::__run(
            "@route('/probe')\n"
            . "@layout('Probe_Layout')\n"
            . "class This_Usage_Probe extends Spa_Action {\n"
            . "    static TABS = ['a'];\n"
            . "    static tabs() {\n"
            . "        return this.TABS;\n"
            . "    }\n"
            . "    on_ready() {\n"
            . "        return this.args;\n"
            . "    }\n"
            . "}\n"
        );

        static::__assert_count(1, $violations, 'the static method\'s naked this, and nothing in the instance method');
        static::__assert_equals(6, $violations[0]->line_number);
        static::__assert_contains("should not use naked 'this'", $violations[0]->message);
    }

    /**
     * A class with private members, a static block, BigInt, optional chaining and nullish
     * coalescing is analyzed: the naked `this` in its static method is reported.
     */
    public static function test_class_with_private_members_and_static_block_is_analyzed()
    {
        $violations = self::__run(
            "class This_Usage_Probe {\n"
            . "    static #SCALE = 100n;\n"
            . "    #count = 1;\n"
            . "    static {\n"
            . "        This_Usage_Probe.READY = true;\n"
            . "    }\n"
            . "    static scale(value) {\n"
            . "        return value?.amount ?? this.#SCALE;\n"
            . "    }\n"
            . "    count() {\n"
            . "        return this.#count;\n"
            . "    }\n"
            . "}\n"
        );

        static::__assert_count(1, $violations, 'the static method\'s naked this');
        static::__assert_equals(8, $violations[0]->line_number);
    }

    /**
     * The sanctioned forms in the same syntax are clean: the class name in a static method,
     * `const CurrentClass = this`, `this` in an instance method and an arrow function.
     */
    public static function test_sanctioned_forms_are_clean()
    {
        $violations = self::__run(
            "@title('Probe')\n"
            . "class This_Usage_Probe extends Spa_Action {\n"
            . "    static #registry = new Map();\n"
            . "    static by_name(name) {\n"
            . "        return This_Usage_Probe.#registry.get(name);\n"
            . "    }\n"
            . "    static polymorphic() {\n"
            . "        const CurrentClass = this;\n"
            . "        return CurrentClass.name;\n"
            . "    }\n"
            . "    on_ready() {\n"
            . "        [1].forEach(() => this.args);\n"
            . "    }\n"
            . "}\n"
        );

        static::__assert_count(0, $violations, 'the sanctioned forms are not findings');
    }

    /**
     * A file the analyzer cannot parse is ONE violation naming the parse error - never an
     * empty list, which would read as a clean file.
     */
    public static function test_unparseable_file_is_a_violation_naming_the_parse_error()
    {
        $violations = self::__run(
            "class This_Usage_Probe {\n"
            . "    static broken() {\n"
            . "        return 1 +;\n"
            . "    }\n"
            . "}\n"
        );

        static::__assert_count(1, $violations, 'an unparseable file is reported, not passed');
        static::__assert_contains('does not parse', $violations[0]->message);
        static::__assert_equals(3, $violations[0]->line_number, 'reported on the line of the parse error');
    }
}
