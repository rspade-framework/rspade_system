<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\CodeQualityChecker;
use App\RSpade\CodeQuality\Rules\Blade\EmailTemplate_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for EMAIL-TEMPLATE-01 (EmailTemplate_CodeQualityRule).
 *
 * Two habits that are correct on a web page are broken in an inbox, and the rule's
 * whole design turns on that asymmetry - so the tests that matter most are the
 * NEGATIVE ones. A page localizes its datetimes in the browser and keeps its links
 * relative so they survive any host or prefix; an email can do neither. The rule
 * therefore refuses to look at anything outside an email directory, and
 * test_a_blade_outside_an_email_directory_is_never_inspected is the assertion that
 * keeps it that way.
 *
 * Every interpolation in this file is assembled from string constants split across
 * the braces, so this test file - which rsx:check also scans - carries no violation
 * of its own and no live exception marker.
 */
class Email_Template_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is text inspection over temp fixture files - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'EMAIL-TEMPLATE-01';

    /** The marker, split so this file never carries a live one. */
    private const MARKER = '@EMAIL-TEMPLATE-01' . '-EXCEPTION';

    /** Interpolation delimiters, split so nothing here is a real blade expression. */
    private const OPEN = '{' . '{';
    private const CLOSE = '}' . '}';
    private const RAW_OPEN = '{' . '!!';
    private const RAW_CLOSE = '!' . '!}';
    private const COMMENT_OPEN = '{' . '{--';
    private const COMMENT_CLOSE = '--' . '}}';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    /**
     * Write $source to a fixture blade and run the rule over it directly.
     *
     * The default path sits under an `emails/` segment, because that is what puts a
     * file in the rule's scope at all.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $source, string $relative_name = 'emails/probe.blade.php'): array
    {
        $root = storage_path('rsx-tmp') . '/email_template_01_fixture_' . uniqid();
        $path = $root . '/rsx/' . $relative_name;
        ensure_directory(dirname($path));
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new EmailTemplate_CodeQualityRule($collector);
        $rule->check($path, $source);

        $violations = $collector->get_by_rule(self::RULE_ID);

        self::__remove_tree($root);

        return $violations;
    }

    /** One escaped blade interpolation carrying $expression. */
    private static function __interpolate(string $expression): string
    {
        return '<p>Sent ' . self::OPEN . ' ' . $expression . ' ' . self::CLOSE . '</p>' . "\n";
    }

    /** One RAW blade interpolation carrying $expression. */
    private static function __interpolate_raw(string $expression): string
    {
        return '<p>Sent ' . self::RAW_OPEN . ' ' . $expression . ' ' . self::RAW_CLOSE . '</p>' . "\n";
    }

    /** One blade comment carrying $body. */
    private static function __comment(string $body): string
    {
        return self::COMMENT_OPEN . ' ' . $body . ' ' . self::COMMENT_CLOSE . "\n";
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
    // Scope
    // =====================================================================

    /**
     * The rule is WRONG outside an email template, not merely unnecessary: a page's
     * datetimes are localized in the browser, and a page's links must stay relative so
     * they work behind any host or prefix. So scope is not an optimization.
     */
    public static function test_a_blade_outside_an_email_directory_is_never_inspected()
    {
        $offending = self::__interpolate('$created_at')
            . self::__interpolate("Rsx::Route('Contacts_Index_Action')");

        static::__assert_count(
            2,
            self::__run($offending),
            'the control fixture under emails/ is flagged twice'
        );

        foreach (['pages/probe.blade.php', 'app/frontend/clients/probe.blade.php'] as $name) {
            static::__assert_count(
                0,
                self::__run($offending, $name),
                "an ordinary page blade is not an email template ({$name})"
            );
        }
    }

    /**
     * The framework's own email templates live beside the class that sends them, not in
     * an emails/ directory, and they owe the same contract.
     */
    public static function test_a_framework_mail_template_is_in_scope()
    {
        static::__assert_count(
            1,
            self::__run(self::__interpolate('$sent_at'), 'Core/Mail/Probe_Email.blade.php'),
            'a blade under Core/Mail/ is an email template'
        );
    }

    // =====================================================================
    // (a) Datetimes - flagged forms
    // =====================================================================

    public static function test_every_raw_datetime_shape_is_flagged_at_high_severity()
    {
        $expressions = [
            '$created_at',
            '$row->due_date',
            "\$data['sent_at']",
            '$data["expires_at"]',
            '$thread->meta[\'reviewed_at\']',
            "\$expires_at ?? ''",
        ];

        foreach ($expressions as $expression) {
            $violations = self::__run(self::__interpolate($expression));

            static::__assert_count(1, $violations, "'{$expression}' prints a moment raw");
            static::__assert_equals('high', $violations[0]->severity, 'the rule is high severity');
        }
    }

    public static function test_the_raw_interpolation_form_is_flagged_too()
    {
        static::__assert_count(
            1,
            self::__run(self::__interpolate_raw('$created_at')),
            'an unescaped interpolation prints the same unformatted string'
        );
    }

    public static function test_the_datetime_remediation_names_both_formatters_and_the_man_page()
    {
        $violations = self::__run(self::__interpolate('$created_at'));

        static::__assert_contains('Rsx_Time::format_datetime', $violations[0]->suggestion, 'names the moment formatter');
        static::__assert_contains('Rsx_Date::format', $violations[0]->suggestion, 'names the date formatter');
        static::__assert_contains('rsx:man time', $violations[0]->suggestion, 'names the man page');
        static::__assert_contains(self::MARKER, $violations[0]->suggestion, 'names the exception marker');
    }

    // =====================================================================
    // (a) Datetimes - clean forms
    // =====================================================================

    public static function test_a_formatted_datetime_is_clean()
    {
        $expressions = [
            'Rsx_Time::format_datetime($created_at)',
            'Rsx_Time::format_datetime_with_tz($expires_at)',
            'Rsx_Date::format($due_date)',
            '\\App\\RSpade\\Core\\Time\\Rsx_Time::format_datetime($row->sent_at)',
        ];

        foreach ($expressions as $expression) {
            static::__assert_count(
                0,
                self::__run(self::__interpolate($expression)),
                "'{$expression}' went through a formatter"
            );
        }
    }

    /**
     * The rule reads BARE accesses only. Anything that calls a function or builds a
     * value has left its reach, and guessing there is how a checker earns the reputation
     * that makes people stop reading it.
     */
    public static function test_values_that_do_not_name_a_moment_are_left_alone()
    {
        $expressions = [
            '$app_name',
            '$user->email',
            '$client_name',
            "\$data['view_url']",
            '$expiry_days',
            '$attachment_count',
        ];

        foreach ($expressions as $expression) {
            static::__assert_count(
                0,
                self::__run(self::__interpolate($expression)),
                "'{$expression}' does not name a moment"
            );
        }
    }

    // =====================================================================
    // (b) Absolute URLs
    // =====================================================================

    public static function test_a_bare_route_call_is_flagged_in_both_realms()
    {
        $calls = [
            "Rsx::Route('Contacts_Index_Action')",
            "Rsx_Portal::Route('Portal_Dashboard_Action')",
            "\\App\\RSpade\\Core\\Portal\\Rsx_Portal::Route('Portal_Dashboard_Action')",
        ];

        foreach ($calls as $call) {
            $violations = self::__run(self::__interpolate($call));

            static::__assert_count(1, $violations, "'{$call}' produces a path, not a URL");
            static::__assert_equals('high', $violations[0]->severity, 'the rule is high severity');
            static::__assert_contains(
                'rsx_absolute_url(',
                $violations[0]->suggestion,
                'the remediation names the wrapper'
            );
        }
    }

    public static function test_a_wrapped_route_call_is_the_compliant_form()
    {
        $calls = [
            "rsx_absolute_url(Rsx::Route('Contacts_Index_Action'))",
            "rsx_absolute_url( Rsx_Portal::Route('Portal_Dashboard_Action') )",
            "rsx_absolute_url(Rsx::Route('Clients_View_Action', ['id' => \$id]))",
        ];

        foreach ($calls as $call) {
            static::__assert_count(0, self::__run(self::__interpolate($call)), "'{$call}' is absolute");
        }
    }

    /**
     * Two calls on one line, one wrapped and one not. Wrapping is decided by what sits
     * immediately to the LEFT of each call, so a compliant neighbour must not launder
     * the offending one.
     */
    public static function test_a_wrapped_neighbour_does_not_launder_a_bare_call()
    {
        $line = '<a href="' . self::OPEN . " rsx_absolute_url(Rsx::Route('Contacts_Index_Action')) " . self::CLOSE
            . '">a</a> <a href="' . self::OPEN . " Rsx::Route('Contacts_View_Action') " . self::CLOSE . '">b</a>' . "\n";

        $violations = self::__run($line);

        static::__assert_count(1, $violations, 'only the unwrapped call is flagged');
        static::__assert_contains('Rsx::Route', $violations[0]->message, 'and the message names it');
    }

    /**
     * A whole URL handed in as data is not this rule's business - the sender already made
     * it absolute, which is exactly what the framework's own templates do.
     */
    public static function test_a_url_passed_in_as_data_is_clean()
    {
        static::__assert_count(
            0,
            self::__run('<a href="' . self::OPEN . ' $view_url ' . self::CLOSE . '">x</a>' . "\n"),
            'a pre-built URL variable is not a Route() call'
        );
    }

    // =====================================================================
    // Comment safety
    // =====================================================================

    public static function test_values_inside_a_blade_comment_never_fire()
    {
        $source = self::__comment('Example: ' . self::OPEN . ' $created_at ' . self::CLOSE
            . ' and ' . self::OPEN . " Rsx::Route('Contacts_Index_Action') " . self::CLOSE . ' - what NOT to write');

        static::__assert_count(0, self::__run($source), 'a blade doc comment is not a message anybody receives');
    }

    public static function test_a_comment_does_not_mask_the_code_below_it()
    {
        $source = self::__comment('Example: ' . self::OPEN . ' $created_at ' . self::CLOSE)
            . self::__interpolate('$sent_at');

        $violations = self::__run($source);

        static::__assert_count(1, $violations, 'blanking a comment leaves the real line intact');
        static::__assert_equals(2, $violations[0]->line_number, 'and reports it at its original line number');
    }

    // =====================================================================
    // The exception marker
    // =====================================================================

    public static function test_the_marker_suppresses_on_its_own_line_and_the_line_above()
    {
        $same_line = rtrim(self::__interpolate('$created_at')) . ' '
            . self::__comment(self::MARKER . ' - fixture: this column stores a version tag, not a moment');

        static::__assert_count(0, self::__run($same_line), 'a same-line marker suppresses');

        $previous_line = self::__comment(self::MARKER . ' - fixture: this column stores a version tag')
            . self::__interpolate('$created_at');

        static::__assert_count(0, self::__run($previous_line), 'a previous-line marker suppresses');
    }

    public static function test_the_marker_suppresses_a_relative_route_too()
    {
        $source = self::__comment(self::MARKER . ' - fixture: this link is consumed by an internal preview')
            . self::__interpolate("Rsx::Route('Contacts_Index_Action')");

        static::__assert_count(0, self::__run($source), 'one marker covers both checks on the line');
    }

    public static function test_a_marker_without_a_rationale_suppresses_nothing()
    {
        $source = rtrim(self::__interpolate('$created_at')) . ' ' . self::__comment(self::MARKER);

        static::__assert_count(
            1,
            self::__run($source),
            'an exception that does not say why is the thing the rule exists to prevent'
        );
    }

    /**
     * File-level suppression is the CHECKER's, not the rule's, so this drives the real
     * entry point. Fixtures live outside the project tree because check_file() skips any
     * path containing an excluded directory - `storage` among them.
     */
    public static function test_the_checker_honors_a_file_level_marker()
    {
        $root = sys_get_temp_dir() . '/email_template_01_checker_' . uniqid();
        $dir = $root . '/rsx/emails';
        ensure_directory($dir);

        $offending = self::__interpolate('$created_at');
        $control_path = $dir . '/control.blade.php';
        $excepted_path = $dir . '/excepted.blade.php';

        file_put_contents($control_path, $offending);
        file_put_contents(
            $excepted_path,
            self::__comment(self::MARKER . ' - fixture: proves the file-level marker suppresses') . $offending
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

    // =====================================================================
    // The shipped templates
    // =====================================================================

    /**
     * The rule reports nothing on the templates this repository actually ships.
     *
     * A rule whose own tree violates it is a rule everybody learns to ignore, and this
     * one found a real defect when it was written - a raw expiry timestamp in the portal
     * shared-content email, which would have mailed a UTC database string to a client in
     * another timezone.
     */
    public static function test_the_shipped_email_templates_are_clean()
    {
        $collector = new ViolationCollector();
        $rule = new EmailTemplate_CodeQualityRule($collector);

        $templates = array_merge(
            glob(rsx_project_file_path('rsx/emails/*.blade.php')) ?: [],
            glob(base_path('app/RSpade/Core/Mail/*.blade.php')) ?: []
        );

        static::__assert_greater_than(0, count($templates), 'the shipped templates were found');

        foreach ($templates as $template) {
            $rule->check($template, (string) file_get_contents($template));
        }

        $violations = $collector->get_by_rule(self::RULE_ID);
        $detail = '';

        foreach ($violations as $violation) {
            $detail .= "\n  " . $violation->file_path . ':' . $violation->line_number . ' ' . $violation->message;
        }

        static::__assert_count(0, $violations, 'every shipped email template obeys its own rule' . $detail);
    }
}
