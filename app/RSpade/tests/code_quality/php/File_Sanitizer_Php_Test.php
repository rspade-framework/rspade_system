<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\PHP\ExecUsage_CodeQualityRule;
use App\RSpade\CodeQuality\Support\FileSanitizer;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * FileSanitizer::sanitize_php() is LINE- AND COLUMN-PRESERVING: every comment is blanked to
 * spaces with its newlines kept, so index N of 'lines' is line N + 1 of the file.
 *
 * A rule that scans the sanitized lines reports $index + 1 as the violation's line, and pairs
 * it with the same index of the original file. Those are only correct while the two are
 * aligned - a `//` comment used to add a line each (the token carries its own newline), so a
 * violation below a run of comments was reported that many lines too low.
 */
class File_Sanitizer_Php_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const SOURCE = "<?php\n"
        . "// a line comment\n"
        . "\$a = 1; // trailing line comment\n"
        . "# a hash comment\n"
        . "/**\n"
        . " * a docblock\n"
        . " */\n"
        . "\$b = 2; /* inline */ \$c = 3;\n"
        . "/* two\n"
        . "   lines */ \$d = 4;\n"
        . "#[Attribute_Marker]\n"
        . "\$e = '// not a comment';\n"
        . "// a comment ending the file";

    public static function test_lines_and_bytes_match_the_file()
    {
        foreach ([self::SOURCE, self::SOURCE . "\n", "<?php\n"] as $source) {
            $result = FileSanitizer::sanitize_php($source);

            static::__assert_equals(count($result['original_lines']), count($result['lines']), 'one sanitized line per file line');
            static::__assert_equals(strlen($source), strlen($result['content']), 'one sanitized byte per file byte');
        }
    }

    public static function test_code_stays_on_its_own_line_and_column()
    {
        $lines = FileSanitizer::sanitize_php(self::SOURCE)['lines'];

        static::__assert_equals('$a = 1;', rtrim($lines[2]), 'code before a trailing comment keeps its line');
        static::__assert_equals('', trim($lines[1]), 'a line comment is blanked');
        static::__assert_equals('', trim($lines[3]), 'a hash comment is blanked');
        static::__assert_equals('', trim($lines[5]), 'a docblock line is blanked');
        static::__assert_equals(strpos(self::SOURCE, '$c = 3;') - strpos(self::SOURCE, '$b = 2;'), strpos($lines[7], '$c'), 'code after an inline comment keeps its column');
        static::__assert_equals(strpos(explode("\n", self::SOURCE)[9], '$d'), strpos($lines[9], '$d'), 'code after a multi-line comment keeps its line and column');
        static::__assert_equals('#[Attribute_Marker]', $lines[10], 'an attribute is code, not a hash comment');
        static::__assert_contains('// not a comment', $lines[11], 'a string holding // is untouched');
    }

    public static function test_a_rule_reports_the_true_line_below_a_run_of_comments()
    {
        $banned = 'exe' . 'c';
        $source = "<?php\n\n"
            . str_repeat("// comment\n", 12)
            . "/**\n * docblock\n */\n"
            . "{$banned}('true', \$out);\n";
        $expected_line = substr_count($source, "\n");

        $dir = Rsx_Project_Paths::tmp_path() . '/sanitizer_fixture_' . uniqid();
        ensure_directory($dir);
        $path = $dir . '/Sanitizer_Probe.php';
        file_put_contents($path, $source);

        try {
            $collector = new ViolationCollector();
            (new ExecUsage_CodeQualityRule($collector))->check($path, FileSanitizer::sanitize_php($source)['content']);
            $violations = $collector->get_by_rule('PHP-EXEC-01');
        } finally {
            @unlink($path);
            @rmdir($dir);
        }

        static::__assert_count(1, $violations, 'the call is flagged once');
        static::__assert_equals($expected_line, $violations[0]->line_number, 'reported on the line it is written on');
    }
}
