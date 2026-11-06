<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Manifest_Rule_Driver;
use App\RSpade\CodeQuality\Rules\JavaScript\NewTab_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for JS-NEW-TAB-01 (NewTab_CodeQualityRule) - the manifest-build FATAL behind the
 * policy that the ONLY way to open a new tab is an <a target="_blank"> the user clicks.
 *
 * Every FATAL shape is proved to be reported - window.open() and its spellings, a dispatched
 * synthetic click, and an anchor or form given target _blank and activated from code in the
 * same function - and every legal shape proved NOT to be: a blob download click with no
 * target, a file-input or button click, a handler bound with .click(fn), target _blank set on
 * an element the user clicks, another object's open(), and a file's own open() function. The
 * JavaScript half runs through the real node service (`quality.analyze_new_tab`).
 */
class New_Tab_Rule_Test extends Rsx_Test_Abstract
{
    // Pure file inspection - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'JS-NEW-TAB-01';

    /** The exception marker, assembled so no literal marker sits in this file. */
    private const MARKER = '@JS-NEW-TAB-01' . '-EXCEPTION';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    /**
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $source, string $relative = 'rsx/app/probe/new_tab_probe.js'): array
    {
        $dir = Rsx_Project_Paths::tmp_path() . '/new_tab_rule_fixture_' . uniqid();
        $path = $dir . '/' . $relative;
        ensure_directory(dirname($path));
        file_put_contents($path, $source);

        try {
            $collector = new ViolationCollector();
            (new NewTab_CodeQualityRule($collector))->check($path, $source);

            return array_values($collector->get_by_rule(self::RULE_ID));
        } finally {
            exec_safe('rm -rf ' . escapeshellarg($dir));
        }
    }

    /**
     * Wrap statements in a class method, one per line, starting on line 3.
     */
    private static function __js(string ...$statements): string
    {
        $body = '';

        foreach ($statements as $statement) {
            $body .= '        ' . $statement . "\n";
        }

        return "class New_Tab_Probe {\n"
            . "    static probe(url, el, form, \$btn) {\n"
            . $body
            . "    }\n"
            . "}\n";
    }

    /**
     * @return array<int,int>
     */
    private static function __lines(array $violations): array
    {
        return array_map(fn ($violation) => $violation->line_number, $violations);
    }

    // =====================================================================
    // FATAL shapes
    // =====================================================================

    public static function test_window_open_is_reported()
    {
        foreach (['window.open(url);', "self.open(url, '_blank');", 'top.open(url);', 'globalThis.open(url);', 'parent.open(url);', 'open(url);'] as $statement) {
            $violations = static::__run(static::__js($statement));

            static::__assert_count(1, $violations, "reported: {$statement}");
            static::__assert_equals(3, $violations[0]->line_number);
            static::__assert_contains('target="_blank" rel="noopener"', (string) $violations[0]->suggestion);
        }
    }

    public static function test_synthetic_click_is_reported()
    {
        $violations = static::__run(static::__js(
            "el.dispatchEvent(new MouseEvent('click', { bubbles: true }));",
            "el.dispatchEvent(new Event('click'));",
            "const ev = new PointerEvent('click');",
            'el.dispatchEvent(ev);',
        ));

        static::__assert_equals([3, 4, 6], static::__lines($violations));
    }

    public static function test_target_blank_anchor_clicked_from_code_is_reported()
    {
        $cases = [
            ["const a = document.createElement('a');", "a.target = '_blank';", 'a.click();'],
            ["const \$a = \$('<a target=\"_blank\">').attr('href', url).appendTo('body');", '$a[0].click();'],
            ["const \$a = \$('<a>', { target: '_blank', href: url });", "\$a.trigger('click');"],
            ["const \$a = \$('<a>').attr('target', '_blank');", '$a.get(0).click();'],
            ["const \$a = \$('<a>').attr({ href: url, target: '_blank' });", "\$a.triggerHandler('click');"],
            ["el.setAttribute('target', '_blank');", 'el.click();'],
            ["\$('<a target=\"_blank\">').attr('href', url).appendTo('body')[0].click();"],
        ];

        foreach ($cases as $statements) {
            $violations = static::__run(static::__js(...$statements));
            $expected_line = 2 + count($statements);

            static::__assert_equals([$expected_line], static::__lines($violations), 'reported: ' . implode(' ', $statements));
        }
    }

    public static function test_target_blank_form_submitted_from_code_is_reported()
    {
        $cases = [
            ["form.target = '_blank';", 'form.submit();'],
            ["form.target = '_blank';", 'form.requestSubmit();'],
            ["\$(form).attr('target', '_blank');", "\$(form).trigger('submit');"],
        ];

        foreach ($cases as $statements) {
            $violations = static::__run(static::__js(...$statements));

            static::__assert_equals([4], static::__lines($violations), 'reported: ' . implode(' ', $statements));
        }
    }

    // =====================================================================
    // LEGAL shapes
    // =====================================================================

    public static function test_legal_shapes_are_not_reported()
    {
        $violations = static::__run(static::__js(
            // A blob download: an anchor with download and no target.
            "const \$link = \$('<a>').attr({ href: url, download: 'report.csv' });",
            "\$('body').append(\$link);",
            '$link[0].click();',
            // File inputs and buttons.
            "this.\$sid('file_input').click();",
            "\$btn.trigger('click');",
            // target _blank on an element the user clicks; a handler bound, not an activation.
            "this.\$.attr('target', '_blank');",
            'this.$.click(() => null);',
            // Other objects' open(), and the document stream.
            'this.modal.open();',
            'dialog.open(url);',
            'document.open();',
            // A non-click event.
            "el.dispatchEvent(new Event('change'));",
        ));

        static::__assert_count(0, $violations);
    }

    /**
     * The target-blank heuristic is per FUNCTION: target _blank set in one method and a
     * click in another is not a finding.
     */
    public static function test_target_blank_and_click_in_different_functions_are_not_reported()
    {
        $source = "class New_Tab_Probe {\n"
            . "    static mark(a) { a.target = '_blank'; }\n"
            . "    static press(a) { a.click(); }\n"
            . "}\n";

        static::__assert_count(0, static::__run($source));
    }

    public static function test_a_locally_declared_open_is_not_window_open()
    {
        $source = "function open(x) { return x; }\n"
            . "open(1);\n";

        static::__assert_count(0, static::__run($source));
    }

    public static function test_a_mention_in_a_comment_or_string_is_not_reported()
    {
        $violations = static::__run(static::__js(
            '// window.open(url);',
            "const text = 'window.open(url)';",
        ));

        static::__assert_count(0, $violations);
    }

    // =====================================================================
    // The marker
    // =====================================================================

    public static function test_marker_with_reason_excuses_the_finding()
    {
        static::__assert_count(0, static::__run(static::__js(
            'window.open(url); // ' . self::MARKER . ' - OAuth popup the provider requires'
        )));

        static::__assert_count(0, static::__run(static::__js(
            '// ' . self::MARKER . ' - OAuth popup the provider requires',
            'window.open(url);',
        )));
    }

    public static function test_bare_marker_excuses_nothing()
    {
        $violations = static::__run(static::__js('window.open(url); // ' . self::MARKER));

        static::__assert_count(1, $violations);
        static::__assert_contains('no reason after it', (string) $violations[0]->suggestion);
    }

    /**
     * Through the real driver in rsx:check mode, one granted finding must not hide a second,
     * ungranted one in the same file.
     */
    public static function test_driver_does_not_skip_the_file_on_a_granted_marker()
    {
        $dir = Rsx_Project_Paths::tmp_path() . '/new_tab_rule_driver_' . uniqid();
        $path = $dir . '/rsx/app/probe/new_tab_probe.js';
        ensure_directory(dirname($path));
        file_put_contents($path, static::__js(
            'window.open(url); // ' . self::MARKER . ' - OAuth popup the provider requires',
            'const x = 1;',
            'window.open(url);',
        ));

        try {
            $collector = new ViolationCollector();
            $driver = new Manifest_Rule_Driver(collector: $collector, honor_exception_comments: true);
            $driver->run([$path], include_cross_file: false);

            $violations = array_values($collector->get_by_rule(self::RULE_ID));
        } finally {
            exec_safe('rm -rf ' . escapeshellarg($dir));
        }

        static::__assert_equals([5], static::__lines($violations));
    }

    // =====================================================================
    // Scope
    // =====================================================================

    public static function test_out_of_scope_paths_are_ignored()
    {
        $source = static::__js('window.open(url);');

        foreach ([
            'app/RSpade/Core/Js/new_tab_probe.js',
            'rsx/tests/new_tab_probe.js',
            'rsx/resource/tool/new_tab_probe.js',
            'rsx/vendor/lib/new_tab_probe.js',
            'rsx/lib/new_tab_probe.min.js',
        ] as $relative) {
            static::__assert_count(0, static::__run($source, $relative), "out of scope: {$relative}");
        }

        static::__assert_count(1, static::__run($source, 'rsx/lib/new_tab_probe.js'), 'application code is in scope');
    }

    // =====================================================================
    // Templates
    // =====================================================================

    public static function test_jqhtml_template_code_is_checked()
    {
        $template = "<%-- window.open('/in-a-comment') --%>\n"
            . "<Define:New_Tab_Probe>\n"
            . "    <button @click=(() => window.open('/x'))>Go</button>\n"
            . "    <% const \$a = \$('<a>').attr('target', '_blank'); \$a[0].click(); %>\n"
            . "    <a href=\"/y\" target=\"_blank\" rel=\"noopener\">window.open( in prose</a>\n"
            . "    <% this.\$.attr('target', '_blank'); %>\n"
            . "    <% el.dispatchEvent(new MouseEvent('click')); %>\n"
            . "</Define:New_Tab_Probe>\n";

        $violations = static::__run($template, 'rsx/app/probe/new_tab_probe.jqhtml');

        static::__assert_equals([3, 4, 7], static::__lines($violations));
    }

    public static function test_jqhtml_marker_excuses_the_finding()
    {
        $template = "<Define:New_Tab_Probe>\n"
            . "    <%-- " . self::MARKER . " - OAuth popup the provider requires --%>\n"
            . "    <button @click=(() => window.open('/x'))>Go</button>\n"
            . "</Define:New_Tab_Probe>\n";

        static::__assert_count(0, static::__run($template, 'rsx/app/probe/new_tab_probe.jqhtml'));
    }
}
