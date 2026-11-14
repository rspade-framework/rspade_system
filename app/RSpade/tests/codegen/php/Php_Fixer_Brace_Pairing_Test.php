<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use ReflectionClass;
use App\RSpade\Core\PHP\Php_Fixer;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Php_Fixer's #[Relationship] pass tracks class depth by pairing braces
 * (Php_Parser::_block_brace()): a '}' closing a string interpolation ("{$a}", "${a}") is
 * told from a '}' closing a block by the brace it pairs with, never by how far away an
 * interpolation opener is.
 *
 * Every fixture is a model whose first method interpolates, followed by a relationship
 * method. Depth is right exactly when the relationship method is seen at class level and
 * gets its attribute; the rewrite is right exactly when that one inserted line is the whole
 * difference and the result still parses.
 *
 * The pass is a private pure function over the file's text, driven through reflection: it
 * needs no manifest and writes no files.
 */
class Php_Fixer_Brace_Pairing_Test extends Rsx_Test_Abstract
{
    // Pure token parsing - no database access.
    protected static $use_database_transactions = false;

    /** The line the pass inserts before a relationship method */
    private const ATTRIBUTE_LINE = "    #[\\Relationship]\n";

    /** Php_Fixer::__add_relationship_attributes() over $content */
    private static function __add_attributes(string $content): string
    {
        $reflection = new ReflectionClass(Php_Fixer::class);
        $seam = $reflection->getMethod('__add_relationship_attributes');
        $seam->setAccessible(true);

        return $seam->invoke(null, $content);
    }

    /** A model whose first method holds $body, followed by a relationship method */
    private static function __fixture(string $body): string
    {
        return "<?php\n"
            . "class Brace_Probe_Model\n"
            . "{\n"
            . "    public function label(\$x, \$a, \$name)\n"
            . "    {\n"
            . $body . "\n"
            . "    }\n"
            . "\n"
            . "    public function posts()\n"
            . "    {\n"
            . "        return \$this->hasMany(Post_Model::class);\n"
            . "    }\n"
            . "}\n";
    }

    /** Assert the pass attributed posts() and changed nothing else */
    private static function __assert_attributed_intact(string $body, string $case): void
    {
        $source = static::__fixture($body);
        $fixed = static::__add_attributes($source);

        $expected = str_replace(
            "    public function posts()\n",
            self::ATTRIBUTE_LINE . "    public function posts()\n",
            $source
        );

        static::__assert_equals(
            $expected,
            $fixed,
            "{$case}: posts() gets #[\\Relationship] and the rest of the file is byte-for-byte unchanged"
        );

        // TOKEN_PARSE throws a ParseError on unbalanced braces or any other syntax error
        token_get_all($fixed, TOKEN_PARSE);
        static::__assert_equals(
            substr_count($source, '{') . '/' . substr_count($source, '}'),
            substr_count($fixed, '{') . '/' . substr_count($fixed, '}'),
            "{$case}: the rewrite adds and removes no brace"
        );
    }

    public static function test_control_without_interpolation()
    {
        static::__assert_attributed_intact('        if ($x) { return 1; }', 'no interpolation');
    }

    public static function test_block_closed_right_after_an_interpolation()
    {
        static::__assert_attributed_intact('        if ($x) { return "{$a}"; }', 'if ($x) { return "{$a}"; }');
    }

    public static function test_interpolation_longer_than_ten_tokens()
    {
        static::__assert_attributed_intact(
            '        return "{$a[\'b\'][0][\'c\'][\'d\'][\'e\']} text";',
            'a long interpolation'
        );
    }

    public static function test_dollar_brace_interpolation()
    {
        static::__assert_attributed_intact('        return "${name} text";', '"${name}"');
    }

    public static function test_all_shapes_together()
    {
        static::__assert_attributed_intact(
            "        if (\$x) { \$s = \"{\$a}\"; }\n"
            . "        \$long = \"{\$a['b'][0]['c']['d']['e']} text\";\n"
            . "        \$nested = \"{\$a->b(\"{\$x}\")}\";\n"
            . "        return \"\${name} \" . \$long . \$nested;",
            'every shape in one method'
        );
    }
}
