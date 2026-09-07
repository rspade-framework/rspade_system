<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\PHP\ArtisanSubprocessSpawn_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

// @ARTISAN-SPAWN-01-EXCEPTION - this file's fixtures are SOURCE STRINGS describing the banned
// pattern so the rule can be run against them. It spawns nothing itself.

/**
 * Unit tests for ARTISAN-SPAWN-01 (ArtisanSubprocessSpawn_CodeQualityRule).
 *
 * The rule requires `php artisan` subprocesses to go through Rsx_Artisan, because a
 * hand-rolled spawn does not inherit the parent's lock group and therefore deadlocks
 * against locks its own parent holds - invisibly, since the parent's half of the cycle is
 * an OS waitpid rather than a lock wait.
 *
 * Fixtures are written to temp files and the rule is driven directly, the same way
 * Session_Id_Null_Check_Rule_Test drives SESSION-ID-01.
 *
 * The LINE-NUMBER test is not incidental. The first implementation of this rule scanned
 * FileSanitizer::sanitize_php()['lines'], which is not index-aligned with the file (194
 * entries for a 182-line file), and consequently reported line 163 for a call on line 153.
 * The rule now tokenizes the file and takes the line from the token. See backlog B-86,
 * which tracks the same latent defect in the other rules that use that pattern.
 */
class Artisan_Subprocess_Spawn_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is pure token parsing over temp fixture files - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'ARTISAN-SPAWN-01';

    /**
     * Wrap fixture statements in a class so the source is realistic, run the rule, and
     * return the collected violations.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $body, string $relative_name = 'Spawn_Probe.php'): array
    {
        $source = "<?php\n\nclass Spawn_Probe\n{\n    public static function go()\n    {\n"
            . $body
            . "    }\n}\n";

        $dir = storage_path('rsx-tmp') . '/artisan_spawn_fixture_' . uniqid();
        $path = $dir . '/' . $relative_name;
        ensure_directory(dirname($path));
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new ArtisanSubprocessSpawn_CodeQualityRule($collector);
        $rule->check($path, $source);

        $violations = $collector->get_by_rule(self::RULE_ID);

        @unlink($path);
        @rmdir(dirname($path));
        @rmdir($dir);

        return $violations;
    }

    // =====================================================================
    // The banned spellings
    // =====================================================================

    public static function test_every_spawn_function_is_flagged()
    {
        // The two banned-outright names are ASSEMBLED rather than written as literals:
        // PHP-EXEC-01 and PHP-PROC-01 scan text, so a fixture string containing them reads
        // to those rules as a real call in this file. (That imprecision is exactly what
        // ARTISAN-SPAWN-01 avoids by tokenizing - see backlog B-86.) Same technique
        // Parent_Call_Chain_Rule_Test uses to keep its own fixtures from being misread.
        $banned_exec = 'exe' . 'c';
        $banned_proc = 'proc_' . 'open';

        $cases = [
            'passthru'   => "        passthru('php artisan rsx:clean');\n",
            'shell_exec' => "        shell_exec('php artisan rsx:clean');\n",
            'exec_safe'  => "        exec_safe('php artisan rsx:clean', \$out, \$rc);\n",
            'exec'       => "        {$banned_exec}('php artisan rsx:clean', \$out, \$rc);\n",
            'system'     => "        system('php artisan rsx:clean');\n",
            'popen'      => "        popen('php artisan rsx:clean', 'r');\n",
            'proc_open'  => "        {$banned_proc}('php artisan rsx:clean', \$d, \$p);\n",
        ];

        foreach ($cases as $function => $body) {
            $violations = static::__run($body);
            static::__assert_count(1, $violations, "{$function}() spawning artisan is flagged");
        }
    }

    public static function test_the_artisan_path_in_a_variable_is_flagged()
    {
        // The spelling every framework test uses: the command is assembled, so no single
        // string literal contains the word - it is the VARIABLE that names artisan.
        $violations = static::__run(
            "        exec_safe('php ' . escapeshellarg(\$artisan) . ' --version', \$out, \$rc);\n"
        );

        static::__assert_count(1, $violations, 'an artisan path held in a variable is still a spawn');
    }

    // =====================================================================
    // What must NOT be flagged
    // =====================================================================

    public static function test_a_spawn_of_something_else_is_not_flagged()
    {
        $violations = static::__run("        passthru('composer dump-autoload --optimize');\n");

        static::__assert_empty($violations, 'a non-artisan subprocess is none of this rule\'s business');
    }

    public static function test_in_process_artisan_call_is_not_flagged()
    {
        // Artisan::call() runs on THIS process's connection and is already reentrant.
        $violations = static::__run("        \\Artisan::call('rsx:clean');\n");

        static::__assert_empty($violations, 'in-process Artisan::call() needs no subprocess handling');
    }

    public static function test_the_sanctioned_helper_is_not_flagged()
    {
        $violations = static::__run("        Rsx_Artisan::passthru('rsx:clean', ['--_no-system-reset']);\n");

        static::__assert_empty($violations, 'the replacement must not flag as the thing it replaces');
    }

    public static function test_comments_and_strings_do_not_produce_violations()
    {
        // Documentation and error text routinely quote the banned pattern - including the
        // three framework commands whose "how to call me" help does exactly that.
        $body = "        // passthru('php artisan rsx:clean');\n"
            . "        /* passthru('php artisan rsx:clean'); */\n"
            . "        \$help = \"passthru('php artisan rsx:clean');\";\n";

        $violations = static::__run($body);

        static::__assert_empty($violations, 'only real calls are violations, never text about them');
    }

    public static function test_a_method_named_like_a_spawn_function_is_not_flagged()
    {
        $body = "        \$runner->passthru('php artisan rsx:clean');\n"
            . "        Some_Class::passthru('php artisan rsx:clean');\n";

        $violations = static::__run($body);

        static::__assert_empty($violations, 'a method call is not the global function');
    }

    // =====================================================================
    // Position reporting (see B-86)
    // =====================================================================

    public static function test_the_reported_line_is_the_real_line()
    {
        // A heredoc is what makes the sanitizer's line count diverge from the file's, so
        // it is exactly what a line-number regression needs in front of the violation.
        $body = "        \$doc = <<<TEXT\n"
            . "one\ntwo\nthree\nfour\nfive\n"
            . "TEXT;\n"
            . "        passthru('php artisan rsx:clean');\n";

        $violations = static::__run($body);

        static::__assert_count(1, $violations, 'the call after a heredoc is found');

        // Fixture wrapper is 6 lines, then the heredoc occupies 7 (opener, 5 body lines,
        // terminator), putting the call on 14.
        static::__assert_equals(
            14,
            $violations[0]->line_number,
            'the reported line is the real file line, not a sanitized index'
        );
    }
}
