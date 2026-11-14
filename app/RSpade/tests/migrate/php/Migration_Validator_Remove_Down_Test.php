<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use App\RSpade\Core\Database\MigrationValidator;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * MigrationValidator::remove_down_method() finds the end of down() by pairing braces
 * (Php_Parser::_block_brace()): a '}' closing a string interpolation ("{$a}", "${a}") is
 * told from a '}' closing a block by the brace it pairs with, never by how far away an
 * interpolation opener is.
 *
 * Every fixture is a migration whose down() interpolates. The method is right exactly when
 * down() is removed and up() survives whole in a file that still parses.
 */
class Migration_Validator_Remove_Down_Test extends Rsx_Test_Abstract
{
    // Scratch files only - no database access.
    protected static $use_database_transactions = false;

    private const UP = "    public function up()\n"
        . "    {\n"
        . "        DB::statement('SELECT 1');\n"
        . "    }\n";

    /** remove_down_method() over a migration whose down() holds $body; [returned, new content] */
    private static function __remove_down(string $body): array
    {
        $root = Rsx_Project_Paths::tmp_path('remove_down_' . random_hash(8));
        $file = $root . '/2026_01_01_000000_probe.php';
        ensure_directory($root);
        file_put_contents(
            $file,
            "<?php\n"
            . "return new class extends Migration\n"
            . "{\n"
            . self::UP
            . "\n"
            . "    public function down()\n"
            . "    {\n"
            . $body . "\n"
            . "    }\n"
            . "};\n"
        );

        try {
            return [MigrationValidator::remove_down_method($file), file_get_contents($file)];
        } finally {
            exec_safe('rm -rf ' . escapeshellarg($root));
        }
    }

    /** Assert down() was removed and the rest of the migration is intact */
    private static function __assert_removed(string $body, string $case): void
    {
        [$removed, $content] = static::__remove_down($body);

        static::__assert_true($removed, "{$case}: remove_down_method() reports the removal");
        static::__assert_equals(
            "<?php\nreturn new class extends Migration\n{\n" . self::UP . "};\n",
            $content,
            "{$case}: down() is gone and up() is intact"
        );

        // TOKEN_PARSE throws a ParseError on unbalanced braces or any other syntax error
        token_get_all($content, TOKEN_PARSE);
    }

    public static function test_control_without_interpolation()
    {
        static::__assert_removed('        if ($x) { $s = 1; } if ($y) { $t = 2; }', 'no interpolation');
    }

    public static function test_blocks_closed_right_after_an_interpolation()
    {
        static::__assert_removed(
            '        if ($x) { $s = "{$a}"; } if ($y) { $t = "{$b}"; }',
            'two blocks each closed right after "{$a}"'
        );
    }

    public static function test_interpolation_longer_than_ten_tokens()
    {
        static::__assert_removed(
            '        $s = "{$a[\'b\'][0][\'c\'][\'d\'][\'e\']} text"; if ($y) { $t = 1; }',
            'a long interpolation'
        );
    }

    public static function test_dollar_brace_interpolation()
    {
        static::__assert_removed('        $s = "${name} text"; if ($y) { $t = 1; }', '"${name}"');
    }
}
