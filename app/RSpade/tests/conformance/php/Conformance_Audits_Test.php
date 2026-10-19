<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Conformance\Php;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Conformance\Conformance_Audits;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The conformance-audit library: the header is read strictly, an id is unique across the
 * framework's and the application's audits, scope globs match the way the man page says, and
 * the shipped library itself parses.
 */
class Conformance_Audits_Test extends Rsx_Test_Abstract
{
    // Filesystem only - no database access.
    protected static $use_database_transactions = false;

    private static string $root = '';

    public static function setup()
    {
        static::$root = sys_get_temp_dir() . '/conformance_test_' . getmypid();
        @mkdir(static::$root . '/framework', 0777, true);
        @mkdir(static::$root . '/application', 0777, true);
    }

    public static function teardown()
    {
        Conformance_Audits::$_directories_for_tests = null;
        exec('rm -rf ' . escapeshellarg(static::$root));
    }

    private static function __write(string $origin, string $id, string $header, string $body = "Review the thing.\n"): string
    {
        $file = static::$root . "/{$origin}/{$id}.md";
        file_put_contents($file, "---\n{$header}\n---\n{$body}");

        return $file;
    }

    private static function __use_temp_dirs(): void
    {
        foreach (glob(static::$root . '/*/*.md') as $file) {
            unlink($file);
        }

        Conformance_Audits::$_directories_for_tests = [
            'framework' => static::$root . '/framework',
            'application' => static::$root . '/application',
        ];
    }

    public static function test_the_shipped_library_parses()
    {
        Conformance_Audits::$_directories_for_tests = null;

        foreach (Conformance_Audits::all() as $audit) {
            static::__assert_not_empty($audit['body'], "{$audit['id']} has a prompt");
        }

        static::__pass();
    }

    public static function test_a_complete_header_is_read()
    {
        static::__use_temp_dirs();
        static::__write('framework', 'probe_audit', "id: probe_audit\ntitle: Probe\nseverity: high\nwhen: launch, change\nscope: rsx/**/*.php, rsx/app/*.js\nrelated: rsx:man model");

        $audit = Conformance_Audits::find('probe_audit');

        static::__assert_equals('high', $audit['severity']);
        static::__assert_equals(['launch', 'change'], $audit['when']);
        static::__assert_equals(['rsx/**/*.php', 'rsx/app/*.js'], $audit['scope']);
        static::__assert_equals(['rsx:man model'], $audit['related']);
        static::__assert_equals("Review the thing.\n", $audit['body']);
    }

    public static function test_a_bad_header_throws()
    {
        $cases = [
            'no header at all' => [null, 'does not open with a header'],
            "id: other_name\ntitle: T\nseverity: high\nwhen: launch\nscope: rsx/*" => [null, "must equal the file's name"],
            "id: probe_audit\ntitle: T\nseverity: urgent\nwhen: launch\nscope: rsx/*" => [null, "severity 'urgent'"],
            "id: probe_audit\ntitle: T\nseverity: high\nwhen: sometimes\nscope: rsx/*" => [null, "when 'sometimes'"],
            "id: probe_audit\ntitle: T\nseverity: high\nwhen: launch" => [null, "no 'scope'"],
            "id: probe_audit\ntitle: T\nseverity: high\nwhen: launch\nscope: rsx/*\nowner: me" => [null, "unknown header key 'owner'"],
        ];

        foreach ($cases as $header => [, $expect]) {
            static::__use_temp_dirs();
            if ($header === 'no header at all') {
                file_put_contents(static::$root . '/framework/probe_audit.md', "Just a prompt.\n");
            } else {
                static::__write('framework', 'probe_audit', $header);
            }

            static::__assert_throws(\RuntimeException::class, fn () => Conformance_Audits::all(), $expect);
        }
    }

    public static function test_an_id_is_unique_across_both_homes()
    {
        static::__use_temp_dirs();
        $header = "id: probe_audit\ntitle: T\nseverity: low\nwhen: periodic\nscope: rsx/*";
        static::__write('framework', 'probe_audit', $header);
        static::__write('application', 'probe_audit', $header);

        static::__assert_throws(\RuntimeException::class, fn () => Conformance_Audits::all(), 'declared twice');
    }

    public static function test_a_directory_claude_md_is_not_an_audit()
    {
        static::__use_temp_dirs();
        static::__write('framework', 'probe_audit', "id: probe_audit\ntitle: T\nseverity: low\nwhen: periodic\nscope: rsx/*");
        file_put_contents(static::$root . '/application/CLAUDE.md', "# This directory\n\nNo header here.\n");

        static::__assert_equals(['probe_audit'], array_column(Conformance_Audits::all(), 'id'));
    }

    public static function test_scope_globs_match_as_documented()
    {
        static::__assert_equals(1, preg_match(Conformance_Audits::glob_regex('rsx/**/*.php'), 'rsx/app/a/b/c.php'));
        static::__assert_equals(1, preg_match(Conformance_Audits::glob_regex('rsx/**/*.php'), 'rsx/c.php'), '**/ matches no directory too');
        static::__assert_equals(0, preg_match(Conformance_Audits::glob_regex('rsx/*.php'), 'rsx/app/c.php'), '* stays within a segment');
        static::__assert_equals(1, preg_match(Conformance_Audits::glob_regex('rsx/**/*_input.js'), 'rsx/theme/components/inputs/text_input.js'));
        static::__assert_equals(0, preg_match(Conformance_Audits::glob_regex('rsx/**/*.php'), 'rsx/app/c.phpx'));
    }

    public static function test_the_commands_list_show_and_match()
    {
        static::__use_temp_dirs();
        static::__write('framework', 'probe_one', "id: probe_one\ntitle: Probe one\nseverity: critical\nwhen: launch\nscope: rsx/**/*.php", "Prompt one.\n");
        static::__write('application', 'probe_two', "id: probe_two\ntitle: Probe two\nseverity: low\nwhen: change\nscope: rsx/**/*.js", "Prompt two.\n");

        Artisan::call('rsx:conformance:list', ['--json' => true]);
        $listed = json_decode(Artisan::output(), true)['audits'];
        static::__assert_equals(['probe_one', 'probe_two'], array_column($listed, 'id'));

        Artisan::call('rsx:conformance:list', ['--json' => true, '--severity' => 'high']);
        static::__assert_equals(['probe_one'], array_column(json_decode(Artisan::output(), true)['audits'], 'id'), '--severity is a floor');

        Artisan::call('rsx:conformance:list', ['--json' => true, '--when' => 'change']);
        static::__assert_equals(['probe_two'], array_column(json_decode(Artisan::output(), true)['audits'], 'id'));

        Artisan::call('rsx:conformance:show', ['id' => 'probe_two']);
        static::__assert_contains('Prompt two.', Artisan::output());

        Artisan::call('rsx:conformance:for', ['path' => 'rsx/app/thing/thing_controller.php']);
        $output = Artisan::output();
        static::__assert_contains('probe_one', $output);
        static::__assert_false(str_contains($output, 'probe_two'), 'a .php file is not in a .js scope');
    }
}
