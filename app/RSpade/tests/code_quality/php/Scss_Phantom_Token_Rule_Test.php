<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Manifest_Rule_Driver;
use App\RSpade\CodeQuality\Rules\Scss\ScssPhantomToken_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * SCSS-PHANTOM-TOKEN-01: `var(--token, <literal>)` is flagged, because the literal makes an
 * undefined token look fine; a `var(--a, var(--b))` chain is not.
 *
 * Each fixture is SCSS text handed straight to check() under a synthetic path - the path is
 * what the rule's scope decision reads, so application, vendored, framework-core and Sys/
 * placements are all exercised without writing into any of those trees. The exception
 * marker is honored by the DRIVER, so that case runs the driver over a temp file.
 */
class Scss_Phantom_Token_Rule_Test extends Rsx_Test_Abstract
{
    // Pure text analysis; no database.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'SCSS-PHANTOM-TOKEN-01';

    /**
     * The rule's violations for $scss checked at $path.
     */
    private static function __check(string $scss, ?string $path = null): array
    {
        $collector = new ViolationCollector();
        $rule = new ScssPhantomToken_CodeQualityRule($collector);
        $rule->check($path ?? base_path('rsx/theme/components/fixture/fixture.scss'), $scss);

        return array_values($collector->get_by_rule(self::RULE_ID));
    }

    public static function test_a_literal_colour_default_is_flagged()
    {
        $violations = static::__check(".Fixture {\n    color: var(--brand, #2563eb);\n}\n");

        static::__assert_count(1, $violations, 'a hex default is the archetype');
        static::__assert_equals(2, $violations[0]->line_number, 'reported on the line of the var() read');
        static::__assert_contains('--brand', $violations[0]->message, 'naming the token');
        static::__assert_contains('#2563eb', $violations[0]->message, 'and the literal');
        static::__assert_contains('var(--brand)', $violations[0]->suggestion, 'the remediation shows the bare read');
        static::__assert_equals('high', $violations[0]->severity, 'severity is high');
    }

    public static function test_every_literal_kind_is_flagged()
    {
        $scss = ".Fixture {\n"
            . "    background: var(--veil, rgba(0, 0, 0, 0.5));\n"
            . "    gap: var(--gap, 8px);\n"
            . "    opacity: var(--fade, 1);\n"
            . "    color: var(--ink, currentColor);\n"
            . "    border-color: var(--edge, \$border-color);\n"
            . "    box-shadow: var(--extra-shadow,);\n"
            . "}\n";

        $violations = static::__check($scss);

        static::__assert_count(6, $violations, 'colour function, length, number, keyword, Sass variable and an empty default all count');
        static::__assert_contains('rgba(0, 0, 0, 0.5)', $violations[0]->message, 'a default with nested parentheses is read whole');
    }

    public static function test_token_names_with_digits_are_matched_whole()
    {
        $violations = static::__check(".Fixture {\n    background: var(--cp-surface-2, #fff);\n    color: rgb(var(--primary-rgb, 13, 110, 253));\n}\n");

        static::__assert_count(2, $violations, 'digits are part of the token charset');
        static::__assert_contains('var(--cp-surface-2)', $violations[0]->message, 'the whole token is named');
        static::__assert_contains('var(--primary-rgb)', $violations[1]->message, 'including a trailing -rgb');
    }

    public static function test_a_chain_is_not_flagged_but_a_chain_ending_in_a_literal_is()
    {
        static::__assert_count(
            0,
            static::__check(".Fixture {\n    color: var(--accent, var(--bs-primary));\n    gap: var(--gap-2, var( --gap ));\n}\n"),
            'a default that is itself a var() read is a chain, never a literal'
        );

        $violations = static::__check(".Fixture {\n    color: var(--accent, var(--brand, #fff));\n}\n");

        static::__assert_count(1, $violations, 'the chain is fine; its last link is not');
        static::__assert_contains('var(--brand)', $violations[0]->message, 'reported at the inner read that carries the literal');
    }

    public static function test_reads_without_a_default_and_comments_are_clean()
    {
        $scss = "// var(--brand, #2563eb) in a line comment\n"
            . "/* var(--brand, #2563eb) in a block comment */\n"
            . ".Fixture {\n"
            . "    color: var(--brand);\n"
            . "    margin: var(--page-pad) var(--block-gap);\n"
            . "}\n";

        static::__assert_count(0, static::__check($scss), 'no default, and nothing inside a comment, is a finding');
    }

    public static function test_scope_excludes_vendored_and_minified_only()
    {
        $scss = ".Fixture {\n    color: var(--brand, #2563eb);\n}\n";

        static::__assert_count(0, static::__check($scss, base_path('rsx/theme/vendor/bootstrap5/scss/fixture.scss')), 'a vendored tree is not ours to change');
        static::__assert_count(0, static::__check($scss, base_path('rsx/theme/components/fixture/fixture.min.scss')), 'a minified file is generated');
        static::__assert_count(1, static::__check($scss, base_path('app/RSpade/Core/Forms/Fixture.scss')), 'the framework core is held to the rule: its hooks are declared on :root and read bare');
        static::__assert_count(1, static::__check($scss, base_path('app/RSpade/Sys/theme/fixture.scss')), 'the framework application is held to the rule');
    }

    public static function test_the_exception_marker_suppresses_the_file()
    {
        $flagged = ".Fixture {\n    color: var(--brand, #2563eb);\n}\n";
        $granted = "// @" . self::RULE_ID . "-EXCEPTION a deliberately optional knob\n" . $flagged;

        static::__assert_count(1, static::__drive($flagged), 'without the marker the driver reports it');
        static::__assert_count(0, static::__drive($granted), 'with the marker the driver skips the rule for the file');
    }

    /**
     * The rule's violations when the DRIVER runs it over a temp file - the driver is what
     * honors the exception marker.
     */
    private static function __drive(string $scss): array
    {
        $path = Rsx_Project_Paths::tmp_path('scss_phantom_token_' . uniqid() . '.scss');

        file_put_contents($path, $scss);

        try {
            $collector = new ViolationCollector();
            $driver = new Manifest_Rule_Driver(collector: $collector, honor_exception_comments: true);
            $driver->run([$path], include_cross_file: false);

            return array_values($collector->get_by_rule(self::RULE_ID));
        } finally {
            @unlink($path);
        }
    }
}
