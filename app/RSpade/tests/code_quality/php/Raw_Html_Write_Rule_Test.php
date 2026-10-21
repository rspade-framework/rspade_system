<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Manifest_Rule_Driver;
use App\RSpade\CodeQuality\Rules\JavaScript\RawHtmlWrite_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for JS-RAW-HTML-01 (RawHtmlWrite_CodeQualityRule).
 *
 * The rule reports a non-literal string written into the page as markup - jQuery .html(x),
 * innerHTML/outerHTML assignment, insertAdjacentHTML(), document.write() - because every one
 * of them parses its argument, and data in that argument is an XSS hole. Detection is an AST
 * walk in the node service, so the tests exercise the real service, not a stub.
 *
 * What matters most, and is pinned here:
 *   - a write whose argument spans several lines is still one finding, on the call's line
 *   - the safe forms (literals, escape_html() and friends, compositions of only those) are
 *     NOT findings - a rule that flags '&nbsp;' trains everyone to ignore it
 *   - .html('') gets its own message pointing at .empty()
 *   - the marker is per LINE and needs a rationale; a bare marker suppresses nothing, and a
 *     granted marker does not hide a second write elsewhere in the file (the driver's
 *     file-level skip is switched off for this rule)
 *
 * The constructs under test are assembled from fragments so that this file - which a test
 * run indexes - holds no literal the rule itself could read as a write.
 */
class Raw_Html_Write_Rule_Test extends Rsx_Test_Abstract
{
    // Pure file inspection - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'JS-RAW-HTML-01';

    /** `.html(` as source text. */
    private const HTML = '.ht' . 'ml(';

    /** The exception marker, never written whole in this file. */
    private const MARKER = '@JS-RAW-HTML-01' . '-EXCEPTION';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    /**
     * Write $source under a scanned directory segment and run the rule over it.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $source): array
    {
        $path = static::__write($source);

        $collector = new ViolationCollector();
        $rule = new RawHtmlWrite_CodeQualityRule($collector);
        $rule->check($path, $source);

        static::__remove($path);

        return array_values($collector->get_by_rule(self::RULE_ID));
    }

    private static function __write(string $source): string
    {
        $dir = Rsx_Project_Paths::tmp_path() . '/raw_html_rule_fixture_' . uniqid();
        $path = $dir . '/rsx/raw_html_probe.js';
        ensure_directory(dirname($path));
        file_put_contents($path, $source);

        return $path;
    }

    private static function __remove(string $path): void
    {
        @unlink($path);
        @rmdir(dirname($path));
        @rmdir(dirname($path, 2));
    }

    /**
     * Wrap statements in a class method so the rule sees ordinary JS. Each statement is
     * one line, starting on line 3.
     */
    private static function __js(string ...$statements): string
    {
        $body = '';

        foreach ($statements as $statement) {
            $body .= '        ' . $statement . "\n";
        }

        return "class Raw_Html_Probe {\n"
            . "    static probe(\$el, el, value) {\n"
            . $body
            . "    }\n"
            . "}\n";
    }

    // =====================================================================
    // Findings
    // =====================================================================

    /**
     * Every write kind with data in it is a finding, each on its own line.
     */
    public static function test_every_write_kind_with_data_is_reported()
    {
        $violations = self::__run(self::__js(
            '$el' . self::HTML . 'value);',
            'el.inner' . 'HTML = value;',
            'el.outer' . 'HTML = value;',
            "el.insertAdjacent" . "HTML('beforeend', value);",
            'document.wri' . 'te(value);',
            "\$el" . self::HTML . "'<b>' + value + '</b>');",
            "\$el" . self::HTML . '`<b>${value}</b>`);',
        ));

        static::__assert_count(7, $violations);

        $lines = array_map(fn ($violation) => $violation->line_number, $violations);
        static::__assert_equals([3, 4, 5, 6, 7, 8, 9], $lines);
    }

    /**
     * An argument spread over several lines is one finding, reported on the call's line.
     */
    public static function test_multi_line_argument_is_one_finding_on_the_call_line()
    {
        $source = "class Raw_Html_Probe {\n"
            . "    static probe(\$el, value) {\n"
            . '        $el' . self::HTML . "\n"
            . "            '<div>' +\n"
            . "            value +\n"
            . "            '</div>'\n"
            . "        );\n"
            . "    }\n"
            . "}\n";

        $violations = self::__run($source);

        static::__assert_count(1, $violations);
        static::__assert_equals(3, $violations[0]->line_number);
    }

    /**
     * .html('') is named as such, with .empty() as the fix.
     */
    public static function test_empty_string_points_at_empty()
    {
        foreach (["''", '""', '``'] as $empty) {
            $violations = self::__run(self::__js('$el' . self::HTML . $empty . ');'));

            static::__assert_count(1, $violations, 'flagged: ' . $empty);
            static::__assert_contains('.empty()', $violations[0]->message);
            static::__assert_contains('.empty()', $violations[0]->suggestion);
        }
    }

    /**
     * The general remediation teaches every alternative and the marker.
     */
    public static function test_remediation_names_the_alternatives()
    {
        $violations = self::__run(self::__js('$el' . self::HTML . 'value);'));

        static::__assert_count(1, $violations);

        $suggestion = $violations[0]->suggestion;
        static::__assert_contains('.text(', $suggestion);
        static::__assert_contains('.empty()', $suggestion);
        static::__assert_contains('nl2brhtml(', $suggestion);
        static::__assert_contains('html_to_text(', $suggestion);
        static::__assert_contains(".component('Name', args)", $suggestion);
        static::__assert_contains(self::MARKER, $suggestion);
    }

    // =====================================================================
    // What must NOT be reported
    // =====================================================================

    public static function test_literals_and_safe_calls_are_allowed()
    {
        $violations = self::__run(self::__js(
            "\$el" . self::HTML . "'&nbsp;');",
            "\$el" . self::HTML . "\"<div class='x'></div>\");",
            "\$el" . self::HTML . "`<span>static</span>`);",
            '$el' . self::HTML . 'escape_html(value));',
            '$el' . self::HTML . 'sanitize_rich_text_html(value));',
            '$el' . self::HTML . 'nl2brhtml(value));',
            '$el' . self::HTML . 'nl2br(escape_html(value)));',
            "\$el" . self::HTML . "'<p>' + escape_html(value) + '</p>');",
            "\$el" . self::HTML . '`<p>${escape_html(value)}</p>`);',
            "el.inner" . "HTML = '<i></i>';",
            '$el' . self::HTML . ');',
            'const markup = $el' . self::HTML . ');',
        ));

        static::__assert_count(0, $violations);
    }

    /**
     * nl2br() alone does not escape, so it is not a safe call.
     */
    public static function test_nl2br_without_escape_is_reported()
    {
        static::__assert_count(1, self::__run(self::__js('$el' . self::HTML . 'nl2br(value));')));
    }

    /**
     * The construct in a comment or a string is not a write.
     */
    public static function test_comments_and_strings_are_not_reported()
    {
        $source = "class Raw_Html_Probe {\n"
            . '    // $el' . self::HTML . "value) was the old way\n"
            . '    static probe() { return "$el' . self::HTML . 'value)"; }' . "\n"
            . "}\n";

        static::__assert_count(0, self::__run($source));
    }

    // =====================================================================
    // The exception marker
    // =====================================================================

    public static function test_marker_with_rationale_on_the_same_line_suppresses()
    {
        $violations = self::__run(self::__js(
            '$el' . self::HTML . 'value); // ' . self::MARKER . ' - effects plugin output',
        ));

        static::__assert_count(0, $violations);
    }

    public static function test_marker_with_rationale_on_the_line_before_suppresses()
    {
        $violations = self::__run(self::__js(
            '// ' . self::MARKER . ' - effects plugin output',
            '$el' . self::HTML . 'value);',
        ));

        static::__assert_count(0, $violations);
    }

    public static function test_bare_marker_suppresses_nothing()
    {
        $violations = self::__run(self::__js(
            '// ' . self::MARKER,
            '$el' . self::HTML . 'value);',
            '$el' . self::HTML . 'value); // ' . self::MARKER . ' -',
        ));

        static::__assert_count(2, $violations);
    }

    /**
     * A marker two lines above covers nothing - it is per line, not per block.
     */
    public static function test_marker_two_lines_above_does_not_reach()
    {
        $violations = self::__run(self::__js(
            '// ' . self::MARKER . ' - effects plugin output',
            'const x = 1;',
            '$el' . self::HTML . 'value);',
        ));

        static::__assert_count(1, $violations);
    }

    /**
     * Through the real driver (which honors markers file-wide for other rules), one granted
     * write must not hide a second, ungranted one in the same file.
     */
    public static function test_driver_does_not_skip_the_file_on_a_granted_marker()
    {
        $source = self::__js(
            '$el' . self::HTML . 'value); // ' . self::MARKER . ' - effects plugin output',
            'const x = 1;',
            '$el' . self::HTML . 'value);',
        );

        $path = static::__write($source);

        $collector = new ViolationCollector();
        $driver = new Manifest_Rule_Driver(collector: $collector, honor_exception_comments: true);
        $driver->run([$path], include_cross_file: false);

        static::__remove($path);

        $violations = $collector->get_by_rule(self::RULE_ID);

        static::__assert_count(1, $violations);
        static::__assert_equals(5, array_values($violations)[0]->line_number);
    }

    // =====================================================================
    // Scope
    // =====================================================================

    /**
     * Only browser JavaScript under a scan directory; node scripts, resource/ trees, tests
     * and vendored code are out of scope.
     */
    public static function test_out_of_scope_paths_are_skipped()
    {
        $source = self::__js('$el' . self::HTML . 'value);');
        $dir = Rsx_Project_Paths::tmp_path() . '/raw_html_rule_scope_' . uniqid();

        foreach (['bin/driver.js', 'rsx/resource/tool.js', 'rsx/tests/probe.js', 'rsx/vendor/lib.js', 'rsx/lib/x.min.js'] as $relative) {
            $path = $dir . '/' . $relative;
            ensure_directory(dirname($path));
            file_put_contents($path, $source);

            $collector = new ViolationCollector();
            (new RawHtmlWrite_CodeQualityRule($collector))->check($path, $source);

            static::__assert_count(0, $collector->get_by_rule(self::RULE_ID), 'out of scope: ' . $relative);

            @unlink($path);
        }

        exec_safe('rm -rf ' . escapeshellarg($dir));
    }
}
