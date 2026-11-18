<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Scss\ScssImport_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * SCSS-IMPORT-01: an authored stylesheet does not `@import`, refused at manifest build.
 *
 * Each fixture is stylesheet text handed straight to check() under a synthetic path - the
 * path decides the vendor exemption. The rule shares its predicate with the bundle
 * compiler's refusal (Scss_Import_Refusal_Test), so this covers the rule's own half: one
 * violation per import, at its line, critical, and the negatives.
 */
class Scss_Import_Rule_Test extends Rsx_Test_Abstract
{
    // Pure text analysis; no database.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'SCSS-IMPORT-01';

    /** The directive, never written as a live line of a stylesheet in this file. */
    private const IMPORT = '@imp' . 'ort';

    private static function __check(string $scss, ?string $path = null): array
    {
        $collector = new ViolationCollector();
        (new ScssImport_CodeQualityRule($collector))->check($path ?? base_path('rsx/theme/components/fixture/fixture.scss'), $scss);

        return array_values($collector->get_by_rule(self::RULE_ID));
    }

    public static function test_each_quoted_import_is_a_violation_at_its_line()
    {
        $violations = static::__check(".Fixture {\n    color: red;\n}\n" . self::IMPORT . " 'variables';\n" . self::IMPORT . " \"mixins\";\n");

        static::__assert_count(2, $violations, 'one per import');
        static::__assert_equals(4, $violations[0]->line_number, 'the first at its line');
        static::__assert_equals(5, $violations[1]->line_number, 'the second at its line');
        static::__assert_contains("'variables'", $violations[0]->message, 'naming the import');
        static::__assert_contains('include list', $violations[0]->suggestion, 'the fix is the bundle include list');
        static::__assert_equals('critical', $violations[0]->severity);
    }

    public static function test_a_sass_file_is_judged_too()
    {
        static::__assert_count(1, static::__check(self::IMPORT . " 'base'\n", base_path('rsx/theme/fixture.sass')));
    }

    public static function test_a_commented_out_import_is_not_one()
    {
        $scss = "// " . self::IMPORT . " 'variables';\n/* " . self::IMPORT . " 'mixins'; */\n.Fixture { color: red; }\n";

        static::__assert_count(0, static::__check($scss));
    }

    public static function test_a_vendor_stylesheet_may_import()
    {
        static::__assert_count(0, static::__check(self::IMPORT . " 'functions';\n", base_path('rsx/theme/vendor/bootstrap5/scss/bootstrap.scss')));
    }

    public static function test_a_css_url_import_is_not_refused()
    {
        // A remote stylesheet imported with url() is localized by the compiler.
        static::__assert_count(0, static::__check(self::IMPORT . " url(https://fonts.example.com/css?family=X);\n.Fixture { color: red; }\n"));
    }

    public static function test_the_word_in_a_selector_or_property_is_not_an_import()
    {
        static::__assert_count(0, static::__check(".Fixture__important { font-weight: bold; }\n.Fixture { content: 'import'; }\n"));
    }
}
