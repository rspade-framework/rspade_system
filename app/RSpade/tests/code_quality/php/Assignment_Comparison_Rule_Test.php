<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Common\Assignment_Comparison_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for COMMON-ASSIGN-01 (Assignment_Comparison_CodeQualityRule): an assignment in
 * an if, elseif or for condition, where a comparison was meant.
 *
 * The negatives carry the weight. `=>` - a PHP array key or arrow function, a JavaScript
 * arrow function - is never an assignment, and a condition holding a call is captured to its
 * matching parenthesis, so neither the call's own `)` nor the comparison after it is lost.
 *
 * Fixtures are handed to check() as contents under a path inside a real scan directory
 * (rsx/), which is how the rule scopes itself; no file is written. This test file's own
 * fixtures are string literals in a directory the rule does not judge.
 */
class Assignment_Comparison_Rule_Test extends Rsx_Test_Abstract
{
    // Pure source inspection - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'COMMON-ASSIGN-01';

    /**
     * Run the rule over one statement wrapped in a method of the file's language.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $statement, string $language = 'php'): array
    {
        if ($language === 'php') {
            $path = base_path('rsx/assign_rule_probe.php');
            $source = "<?php\n\nclass Assign_Rule_Probe\n{\n    public static function probe(\$a, \$b, \$k, \$pair, \$items, \$id)\n    {\n        "
                . $statement . "\n    }\n}\n";
        } else {
            $path = base_path('rsx/assign_rule_probe.js');
            $source = "class Assign_Rule_Probe {\n    static probe(items, id, x) {\n        " . $statement . "\n    }\n}\n";
        }

        $collector = new ViolationCollector();
        $rule = new Assignment_Comparison_CodeQualityRule($collector);
        $rule->check($path, $source);

        return $collector->get_by_rule(self::RULE_ID);
    }

    // AC-ARROW-NOT-ASSIGNMENT
    public static function test_arrows_and_array_keys_in_a_condition_are_not_assignments()
    {
        $php = [
            "if (array_filter(['field' => \$pair]) === []) {\n            return true;\n        }",
            "if (in_array(\$k, ['a' => 1])) {\n            return true;\n        }",
            "if (\$a) {\n            return 1;\n        } elseif (foo(['a' => \$b]) === []) {\n            return 2;\n        }",
            "for (\$i = 0; count(['a' => 1]) > \$i; \$i++) {\n            echo \$i;\n        }",
            "if (array_filter(\$items, fn (\$item) => \$item->id)) {\n            return true;\n        }",
        ];

        foreach ($php as $statement) {
            static::__assert_count(0, self::__run($statement), 'not flagged: ' . $statement);
        }

        $js = [
            "if (items.some(x => x.id === id)) {\n            return true;\n        }",
            "if (items.filter((x) => x.on).length) {\n            return true;\n        }",
            "if (a) {\n            return 1;\n        } else if (items.find(x => x.id)) {\n            return 2;\n        }",
        ];

        foreach ($js as $statement) {
            static::__assert_count(0, self::__run($statement, 'js'), 'not flagged: ' . $statement);
        }
    }

    // AC-STRINGS-COMMENTS
    public static function test_an_equals_sign_in_a_string_or_a_comment_is_not_judged()
    {
        $statements = [
            "if (str_contains(\$a, 'charset=utf-8)')) {\n            return true;\n        }",
            "// if (\$x = f()) was the old shape\n        return true;",
            "/* if (\$x = f()) { */\n        return true;",
            "if (\$a) { // \$x = f() below\n            return true;\n        }",
        ];

        foreach ($statements as $statement) {
            static::__assert_count(0, self::__run($statement), 'not flagged: ' . $statement);
        }
    }

    // AC-STILL-FLAGGED
    public static function test_a_real_assignment_is_still_flagged_and_named()
    {
        $php = [
            "if (\$x = f()) {\n            return \$x;\n        }" => '$x',
            "if ((\$x = f())) {\n            return \$x;\n        }" => '$x',
            "if (\$a) {\n            return 1;\n        } elseif (\$x = f()) {\n            return \$x;\n        }" => '$x',
            "if (trim(\$a) && \$x = f()) {\n            return \$x;\n        }" => '$x',
            "if (\$row['id'] = f()) {\n            return 1;\n        }" => "\$row['id']",
            "for (\$i = 0; \$i = 3; \$i++) {\n            echo \$i;\n        }" => '$i',
        ];

        foreach ($php as $statement => $target) {
            $violations = self::__run($statement);
            static::__assert_count(1, $violations, 'flagged: ' . $statement);
            static::__assert_contains('(assigns ' . $target . ')', $violations[0]->message, 'names the assignment: ' . $statement);
        }

        $violations = self::__run("if (x = f()) {\n            return x;\n        }", 'js');
        static::__assert_count(1, $violations, 'flagged: JS if (x = f())');
        static::__assert_contains('(assigns x)', $violations[0]->message);
    }

    // AC-LINE-AND-QUOTE
    public static function test_the_finding_reports_its_line_and_quotes_it_as_written()
    {
        $violations = self::__run("if (trim(\$a) && \$x = f()) {\n            return \$x;\n        }");

        static::__assert_count(1, $violations);
        static::__assert_equals(7, $violations[0]->line_number);
        static::__assert_equals('if (trim($a) && $x = f()) {', $violations[0]->code_snippet);
    }

    // AC-COMPARED-ASSIGNMENT
    public static function test_an_assignment_that_is_compared_is_not_flagged()
    {
        $statements = [
            "if ((\$x = f()) !== null) {\n            return \$x;\n        }",
            "while (\$row = f()) {\n            echo \$row;\n        }",
        ];

        foreach ($statements as $statement) {
            static::__assert_count(0, self::__run($statement), 'not flagged: ' . $statement);
        }
    }

    // AC-MULTILINE
    public static function test_a_condition_that_does_not_close_on_its_line_is_not_judged()
    {
        static::__assert_count(0, self::__run("if (\$x = f(\n            \$a\n        )) {\n            return \$x;\n        }"));
    }
}
