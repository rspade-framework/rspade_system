<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Manifest_Rule_Driver;
use App\RSpade\CodeQuality\Rules\JavaScript\Navigation_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for JS-NAVIGATE-01 (Navigation_CodeQualityRule) - the manifest-build FATAL that
 * keeps application navigation on the one door, Rsx.navigate() / Rsx.redirect().
 *
 * Every FATAL shape is proved to be reported and every legal shape proved NOT to be: reads of
 * location, location.reload(), history.back()/state, the door itself, and a property merely
 * named `location` on another object. The marker excuses a pushState/replaceState WITH a
 * reason and nothing else. The JavaScript half runs through the real node service
 * (`quality.analyze_navigation`), not a stub; the .jqhtml half through the template pass.
 *
 * The rule reports with add_violation() and never throws - the manifest driver turns the
 * first violation into the FATAL - so the assertions read the collector.
 */
class Navigation_Rule_Test extends Rsx_Test_Abstract
{
    // Pure file inspection - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'JS-NAVIGATE-01';

    /** The exception marker, assembled so no literal marker sits in this file. */
    private const MARKER = '@JS-NAVIGATE-01' . '-EXCEPTION';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    /**
     * Write $source at $relative under a fresh fixture root, run the rule over it, and return
     * its violations.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $source, string $relative = 'rsx/app/probe/navigation_probe.js'): array
    {
        $dir = Rsx_Project_Paths::tmp_path() . '/navigation_rule_fixture_' . uniqid();
        $path = $dir . '/' . $relative;
        ensure_directory(dirname($path));
        file_put_contents($path, $source);

        try {
            $collector = new ViolationCollector();
            (new Navigation_CodeQualityRule($collector))->check($path, $source);

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

        return "class Navigation_Probe {\n"
            . "    static probe(url, obj) {\n"
            . $body
            . "    }\n"
            . "}\n";
    }

    /**
     * Assert each statement, alone in a method, is reported - on its own line.
     */
    private static function __assert_each_reported(array $statements, string $suggestion_needle): void
    {
        foreach ($statements as $statement) {
            $violations = static::__run(static::__js($statement));

            static::__assert_count(1, $violations, "reported: {$statement}");
            static::__assert_equals(3, $violations[0]->line_number, "on its line: {$statement}");
            static::__assert_contains($suggestion_needle, (string) $violations[0]->suggestion);
        }
    }

    // =====================================================================
    // FATAL shapes
    // =====================================================================

    public static function test_location_writes_are_reported()
    {
        static::__assert_each_reported([
            'location.href = url;',
            'location = url;',
            'window.location = url;',
            'window.location.href = url;',
            'document.location.href = url;',
            "self.location.search = '?a=1';",
            "top.location.hash = '#x';",
            "parent.location.pathname = '/p';",
            "globalThis.location.host = 'h';",
            "window.location.hostname = 'h';",
            "window.location.port = '81';",
            "window.location.protocol = 'https:';",
            "window['location'] = url;",
            "location['href'] = url;",
            "window.location.href += '#x';",
        ], 'Rsx.navigate(url)');
    }

    public static function test_aliasing_the_location_object_is_reported()
    {
        static::__assert_each_reported([
            'const l = window.location;',
            'const d = document.location;',
            'let z = location;',
            'obj.loc = window.location;',
        ], 'Rsx.navigate(url)');

        // A write THROUGH the alias is reported on its own line as well.
        $violations = static::__run(static::__js('const l = window.location;', 'l.href = url;'));
        static::__assert_count(2, $violations);
        static::__assert_equals([3, 4], array_map(fn ($v) => $v->line_number, $violations));
    }

    public static function test_location_assign_and_replace_are_reported()
    {
        static::__assert_each_reported([
            'location.assign(url);',
            'window.location.replace(url);',
            'document.location.assign(url);',
        ], 'Rsx.navigate(url)');
    }

    public static function test_jquery_location_writes_are_reported()
    {
        static::__assert_each_reported([
            "\$(location).attr('href', url);",
            "\$(window).attr('location', url);",
            "\$(document).prop('href', url);",
            "\$(window.location).attr('href', url);",
        ], 'Rsx.navigate(url)');
    }

    public static function test_history_writes_are_reported()
    {
        static::__assert_each_reported([
            "history.pushState({}, '', url);",
            "history.replaceState(null, '', url);",
            "window.history.pushState({}, '', url);",
            "window.history.replaceState(null, '', url);",
        ], "{load: 'none', history: 'push'|'replace'}");
    }

    public static function test_removed_spa_calls_are_reported()
    {
        static::__assert_each_reported([
            'Spa.dispatch(url);',
            "Spa.redirect(url, {history: 'replace'});",
        ], 'Spa.dispatch() and Spa.redirect() were removed');
    }

    // =====================================================================
    // LEGAL shapes
    // =====================================================================

    public static function test_legal_shapes_are_not_reported()
    {
        $violations = static::__run(static::__js(
            'location.reload();',
            'window.location.reload();',
            'const here = location.href;',
            "if (location.search.includes('x')) { return; }",
            'const parsed = new URL(location.href);',
            'const { pathname } = window.location;',
            'history.back();',
            'history.forward();',
            'history.go(-1);',
            'const state = history.state;',
            'Rsx.navigate(url);',
            'Rsx.redirect(url);',
            "Rsx.url_hash_set('tab');",
            'this.data.location = url;',
            'obj.location = url;',
            'obj.location.href = url;',
            'const where = obj.location;',
        ));

        static::__assert_count(0, $violations);
    }

    /**
     * A file that binds `location` itself has no bare global of that name.
     */
    public static function test_locally_bound_location_is_not_the_global()
    {
        $source = "class Navigation_Probe {\n"
            . "    static probe(location) {\n"
            . "        location.href = '/x';\n"
            . "    }\n"
            . "}\n";

        static::__assert_count(0, static::__run($source));
    }

    public static function test_a_mention_in_a_comment_or_string_is_not_reported()
    {
        $violations = static::__run(static::__js(
            "// window.location.href = url;",
            "const text = 'window.location.href = url';",
        ));

        static::__assert_count(0, $violations);
    }

    // =====================================================================
    // The marker
    // =====================================================================

    public static function test_marker_with_reason_excuses_a_history_write()
    {
        $same_line = static::__run(static::__js(
            "history.pushState({}, '', url); // " . self::MARKER . ' - Blade page with its own router'
        ));
        static::__assert_count(0, $same_line);

        $line_above = static::__run(static::__js(
            '// ' . self::MARKER . ' - Blade page with its own router',
            "history.replaceState(null, '', url);",
        ));
        static::__assert_count(0, $line_above);
    }

    public static function test_bare_marker_excuses_nothing()
    {
        $violations = static::__run(static::__js(
            "history.pushState({}, '', url); // " . self::MARKER,
        ));

        static::__assert_count(1, $violations);
        static::__assert_contains('no reason after it', (string) $violations[0]->suggestion);
    }

    /**
     * The marker is scoped to pushState/replaceState: on any other finding it excuses nothing,
     * and the report says so.
     */
    public static function test_marker_does_not_excuse_other_findings()
    {
        $violations = static::__run(static::__js(
            'window.location.href = url; // ' . self::MARKER . ' - hand-rolled router page',
            '// ' . self::MARKER . ' - hand-rolled router page',
            'Spa.dispatch(url);',
        ));

        static::__assert_count(2, $violations);

        foreach ($violations as $violation) {
            static::__assert_contains('marker here does not apply', (string) $violation->suggestion);
        }
    }

    /**
     * Through the real driver in rsx:check mode (which skips a whole file for other rules'
     * markers), one granted history write must not hide a second, ungranted one.
     */
    public static function test_driver_does_not_skip_the_file_on_a_granted_marker()
    {
        $dir = Rsx_Project_Paths::tmp_path() . '/navigation_rule_driver_' . uniqid();
        $path = $dir . '/rsx/app/probe/navigation_probe.js';
        ensure_directory(dirname($path));
        file_put_contents($path, static::__js(
            "history.pushState({}, '', url); // " . self::MARKER . ' - Blade page with its own router',
            'const x = 1;',
            "history.pushState({}, '', url);",
        ));

        try {
            $collector = new ViolationCollector();
            $driver = new Manifest_Rule_Driver(collector: $collector, honor_exception_comments: true);
            $driver->run([$path], include_cross_file: false);

            $violations = array_values($collector->get_by_rule(self::RULE_ID));
        } finally {
            exec_safe('rm -rf ' . escapeshellarg($dir));
        }

        static::__assert_count(1, $violations);
        static::__assert_equals(5, $violations[0]->line_number);
    }

    // =====================================================================
    // Scope
    // =====================================================================

    /**
     * Framework code (the door itself writes location), test trees, resource/ trees,
     * vendored and minified files are out of scope.
     */
    public static function test_out_of_scope_paths_are_ignored()
    {
        $source = static::__js('window.location.href = url;');

        foreach ([
            'app/RSpade/Core/Js/navigation_probe.js',
            'rsx/tests/navigation_probe.js',
            'rsx/resource/tool/navigation_probe.js',
            'rsx/vendor/lib/navigation_probe.js',
            'rsx/lib/navigation_probe.min.js',
        ] as $relative) {
            static::__assert_count(0, static::__run($source, $relative), "out of scope: {$relative}");
        }

        static::__assert_count(1, static::__run($source, 'rsx/lib/navigation_probe.js'), 'application code is in scope');
    }

    // =====================================================================
    // Templates
    // =====================================================================

    public static function test_jqhtml_template_code_is_checked()
    {
        $template = "<%-- window.location.href = '/in-a-comment'; --%>\n"
            . "<Define:Navigation_Probe>\n"
            . "    <% window.location.href = '/x'; %>\n"
            . "    <button @click=(() => location.assign('/y'))>Go</button>\n"
            . "    <% window.location.reload(); %>\n"
            . "    <span \$location=row.location>window.location.href = prose</span>\n"
            . "    <% if (x) { history.pushState({}, '', '/z'); } %>\n"
            . "    <% Spa.redirect('/q'); %>\n"
            . "</Define:Navigation_Probe>\n";

        $violations = static::__run($template, 'rsx/app/probe/navigation_probe.jqhtml');

        static::__assert_equals([3, 4, 7, 8], array_map(fn ($v) => $v->line_number, $violations));
    }

    public static function test_jqhtml_marker_excuses_a_history_write()
    {
        $template = "<Define:Navigation_Probe>\n"
            . "    <%-- " . self::MARKER . " - Blade page with its own router --%>\n"
            . "    <% history.pushState({}, '', '/z'); %>\n"
            . "</Define:Navigation_Probe>\n";

        static::__assert_count(0, static::__run($template, 'rsx/app/probe/navigation_probe.jqhtml'));
    }
}
