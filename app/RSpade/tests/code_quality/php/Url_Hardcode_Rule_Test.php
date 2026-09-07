<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\CodeQualityChecker;
use App\RSpade\CodeQuality\Rules\Common\HardcodedInternalUrl_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for URL-HARDCODE-01 (HardcodedInternalUrl_CodeQualityRule).
 *
 * The rule is DENY-BY-DEFAULT: an href value starting with "/" is a violation unless it is
 * an explicitly allowed form. Its predecessor flagged a literal only when the literal
 * happened to resolve through the STAFF dispatcher, which structurally could not catch the
 * three cases that matter - an interpolated path (the expression's dot was read as a file
 * extension), a portal path (invisible to the staff route table), and a route not yet
 * registered. A hardcoded portal href shipped a real navigation bug through that hole.
 *
 * Every href in this file is assembled from string constants split across the `=`, so this
 * test file - which rsx:check also scans - carries no violation of its own.
 */
class Url_Hardcode_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is text inspection over temp fixture files - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'URL-HARDCODE-01';

    /** `href=`, never adjacent to a value in this file's own source. */
    private const HREF = 'href=';

    /** The marker, split so this file never carries a live one. */
    private const MARKER = '@URL-HARDCODE-01' . '-EXCEPTION';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    /**
     * Write $source to a fresh fixture file and run the rule over it directly.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $source, string $relative_name = 'probe.jqhtml'): array
    {
        $root = storage_path('rsx-tmp') . '/url_hardcode_01_fixture_' . uniqid();
        $path = $root . '/rsx/' . $relative_name;
        ensure_directory(dirname($path));
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new HardcodedInternalUrl_CodeQualityRule($collector);
        $rule->check($path, $source);

        $violations = $collector->get_by_rule(self::RULE_ID);

        self::__remove_tree($root);

        return $violations;
    }

    /** One anchor tag whose href carries $value. */
    private static function __anchor(string $value): string
    {
        return '<a class="x" ' . self::HREF . '"' . $value . '">label</a>' . "\n";
    }

    private static function __remove_tree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($root);
    }

    // =====================================================================
    // Allowed forms
    // =====================================================================

    public static function test_a_route_call_is_the_compliant_form_in_every_language()
    {
        $cases = [
            'probe.jqhtml' => '<%= ' . "Rsx.Route('Contacts_Index_Action')" . ' %>',
            'portal/probe.jqhtml' => '<%= ' . "Rsx_Portal.Route('Portal_Dashboard_Action')" . ' %>',
            'probe.blade.php' => '{{ ' . "Rsx::Route('Contacts_Index_Action')" . ' }}',
            'portal/probe.blade.php' => '{{ ' . "Rsx_Portal::Route('Portal_Login_Controller::index')" . ' }}',
        ];

        foreach ($cases as $name => $value) {
            static::__assert_count(
                0,
                self::__run(self::__anchor($value), $name),
                "a whole-value Route() call is clean in {$name}"
            );
        }
    }

    public static function test_javascript_route_expressions_are_clean()
    {
        $template = "function probe() {\n"
            . '    const html = `<a ' . self::HREF . '"${' . "Rsx.Route('Contacts_Index_Action')" . '}">x</a>`;' . "\n"
            . '    $(' . "'#x').attr('href', Rsx.Route('Contacts_Index_Action'));" . "\n"
            . "    return html;\n"
            . "}\n";

        static::__assert_count(0, self::__run($template, 'probe.js'), 'a JS Route() expression is clean');
    }

    public static function test_non_internal_targets_are_never_flagged()
    {
        $values = [
            '#',
            '#section',
            'https://example.com/contacts',
            '//cdn.example.com/x',
            'mailto:someone@example.com',
            'tel:+15550100',
            'javascript:void(0)',
            '/',
        ];

        foreach ($values as $value) {
            static::__assert_count(0, self::__run(self::__anchor($value)), "'{$value}' is not an internal route");
        }
    }

    public static function test_static_and_framework_service_prefixes_are_skipped()
    {
        $values = [
            '/assets/logo.png',
            '/css/app.css',
            '/images/avatar.jpg',
            '/fonts/x.woff2',
            '/storage/report.pdf',
            '/_compiled/Frontend_Bundle__app.0123456789abcdef.js',
            '/_vendor/0123456789abcdef0123456789abcdef_bootstrap.css',
            '/_download/<%= key %>',
            '/_inline/<%= key %>',
            '/favicon.ico',
        ];

        foreach ($values as $value) {
            static::__assert_count(0, self::__run(self::__anchor($value)), "'{$value}' is not a page route");
        }
    }

    // =====================================================================
    // Flagged forms
    // =====================================================================

    public static function test_a_bare_internal_literal_is_flagged_at_high_severity()
    {
        $violations = self::__run(self::__anchor('/contacts'));

        static::__assert_count(1, $violations, 'a clean hardcoded path is a violation on its own');
        static::__assert_equals('high', $violations[0]->severity, 'the rule is high severity');
        static::__assert_contains('rsx:man routing', $violations[0]->suggestion, 'the remediation names the man page');
        static::__assert_contains(self::MARKER, $violations[0]->suggestion, 'the remediation names the exception marker');
    }

    public static function test_an_interpolated_jqhtml_path_is_flagged_and_names_the_action()
    {
        // The predecessor skipped this one: it took the last segment, saw the dot in
        // `row.id`, and called the whole href a static file.
        $violations = self::__run(self::__anchor('/contacts/view/<%= row.id %>'));

        static::__assert_count(1, $violations, 'an interpolated path is flagged, not skipped');
        static::__assert_contains(
            "Rsx.Route('Contacts_View_Action', {id: row.id})",
            $violations[0]->suggestion,
            'a resolvable path names the destination action and carries the parameter through'
        );
    }

    public static function test_an_interpolated_blade_path_is_flagged()
    {
        $violations = self::__run(self::__anchor('/contacts/view/{{ $id }}'), 'probe.blade.php');

        static::__assert_count(1, $violations, 'a blade-interpolated path is flagged');
        static::__assert_contains(
            "Rsx::Route('Contacts_View_Action', ['id' => \$id])",
            $violations[0]->suggestion,
            'the blade suggestion uses the PHP spelling and array parameters'
        );
    }

    public static function test_javascript_concatenation_and_template_literals_are_flagged()
    {
        $concatenation = "function probe(id) {\n"
            . '    return ' . "'<a " . self::HREF . '"/clients/' . "' + id + '" . '">x</a>' . "';\n"
            . "}\n";

        static::__assert_count(1, self::__run($concatenation, 'probe.js'), 'a concatenated path is flagged');

        $literal = "function probe(id) {\n"
            . '    return `<a ' . self::HREF . '"/contacts/view/${id}">x</a>`;' . "\n"
            . "}\n";

        $violations = self::__run($literal, 'probe.js');

        static::__assert_count(1, $violations, 'a template-literal path is flagged');
        static::__assert_contains(
            "Rsx.Route('Contacts_View_Action', {id: id})",
            $violations[0]->suggestion,
            'the JS suggestion uses the dot spelling and an object literal'
        );
    }

    public static function test_a_portal_file_gets_the_portal_helper_and_the_portal_route_table()
    {
        // The exact shape that shipped the bug: a portal thread link written bare.
        $violations = self::__run(
            self::__anchor('/workspace/<%= w.id %>/requests/<%= t.id %>'),
            'portal/messages/probe.jqhtml'
        );

        static::__assert_count(1, $violations, 'a portal literal is flagged');
        static::__assert_contains(
            "Rsx_Portal.Route('Portal_Request_Thread_Action'",
            $violations[0]->suggestion,
            'a portal file is resolved against the PORTAL route table and named the portal helper'
        );
        static::__assert_contains(
            'rsx/portal/',
            $violations[0]->suggestion,
            'the remediation says why the portal helper is the right one here'
        );
    }

    public static function test_a_staff_file_never_gets_the_portal_suggestion()
    {
        $violations = self::__run(self::__anchor('/contacts'));

        static::__assert_false(
            str_contains($violations[0]->suggestion, "Rsx_Portal.Route('"),
            'a staff screen is told to use Rsx.Route()'
        );
    }

    // =====================================================================
    // Comment safety
    // =====================================================================

    public static function test_values_inside_comments_never_fire()
    {
        $jqhtml = '<%-- Usage: <tr data-' . self::HREF . '"/url"> makes the row navigate --%>' . "\n"
            . '<div></div>' . "\n";
        static::__assert_count(0, self::__run($jqhtml), 'a jqhtml doc comment is not code');

        $blade = '{{-- <a ' . self::HREF . '"/contacts">x</a> --}}' . "\n";
        static::__assert_count(0, self::__run($blade, 'probe.blade.php'), 'a blade comment is not code');

        $html = '<!-- <a ' . self::HREF . '"/contacts">x</a> -->' . "\n";
        static::__assert_count(0, self::__run($html), 'an html comment is not code');

        $js = '// ' . self::HREF . '"/contacts" is what NOT to write' . "\n"
            . "/*\n"
            . ' * <a ' . self::HREF . '"/clients/1">x</a>' . "\n"
            . " */\n"
            . "function probe() { return 1; }\n";
        static::__assert_count(0, self::__run($js, 'probe.js'), 'JS line and block comments are not code');

        $php = "<?php\n"
            . '# ' . self::HREF . '"/contacts"' . "\n"
            . '// <a ' . self::HREF . '"/clients">x</a>' . "\n"
            . "function probe() { return 1; }\n";
        static::__assert_count(0, self::__run($php, 'probe.php'), 'PHP hash and slash comments are not code');
    }

    public static function test_a_url_in_a_comment_does_not_mask_the_code_below_it()
    {
        $source = '<%-- example: <a ' . self::HREF . '"/contacts">x</a> --%>' . "\n"
            . self::__anchor('/clients');

        $violations = self::__run($source);

        static::__assert_count(1, $violations, 'blanking a comment leaves the real line intact');
        static::__assert_equals(2, $violations[0]->line_number, 'and reports it at its original line number');
    }

    // =====================================================================
    // The exception marker
    // =====================================================================

    public static function test_the_marker_suppresses_on_its_own_line_and_the_line_above()
    {
        $same_line = rtrim(self::__anchor('/legacy-entry')) . ' <%-- ' . self::MARKER
            . ' - fixture: served by the legacy mount, not by the router --%>' . "\n";

        static::__assert_count(0, self::__run($same_line), 'a same-line marker suppresses');

        $previous_line = '<%-- ' . self::MARKER . ' - fixture: served by the legacy mount --%>' . "\n"
            . self::__anchor('/legacy-entry');

        static::__assert_count(0, self::__run($previous_line), 'a previous-line marker suppresses');
    }

    public static function test_a_marker_without_a_rationale_suppresses_nothing()
    {
        $source = rtrim(self::__anchor('/legacy-entry')) . ' <%-- ' . self::MARKER . ' --%>' . "\n";

        static::__assert_count(
            1,
            self::__run($source),
            'an exception that does not say why is the thing the rule exists to prevent'
        );
    }

    /**
     * File-level suppression is the CHECKER's, not the rule's, so this drives the real entry
     * point. Fixtures live outside the project tree because check_file() skips any path
     * containing an excluded directory - `storage` among them.
     */
    public static function test_the_checker_honors_a_file_level_marker()
    {
        $root = sys_get_temp_dir() . '/url_hardcode_01_checker_' . uniqid();
        $dir = $root . '/rsx';
        ensure_directory($dir);

        $offending = self::__anchor('/contacts');
        $control_path = $dir . '/control.jqhtml';
        $excepted_path = $dir . '/excepted.jqhtml';

        file_put_contents($control_path, $offending);
        file_put_contents(
            $excepted_path,
            '<%-- ' . self::MARKER . " - fixture: proves the file-level marker suppresses --%>\n" . $offending
        );

        CodeQualityChecker::init([]);
        CodeQualityChecker::check_file($control_path);
        CodeQualityChecker::check_file($excepted_path);

        $files = [];
        foreach (CodeQualityChecker::get_collector()->get_by_rule(self::RULE_ID) as $violation) {
            $files[] = $violation->file_path;
        }

        static::__assert_true(in_array($control_path, $files, true), 'the control fixture is flagged');
        static::__assert_false(in_array($excepted_path, $files, true), 'the marked fixture is not flagged');

        self::__remove_tree($root);
    }
}
