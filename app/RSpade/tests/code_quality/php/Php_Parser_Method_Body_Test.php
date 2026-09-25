<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\Core\PHP\Php_Parser;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Php_Parser::method_body() - the ONE token-based method-body locator, shared by the
 * code-quality rules (through CodeQualityRule_Abstract::method_body()) and the API
 * GET-purity check.
 *
 * Braces are counted on tokens, so a brace inside a string or a comment cannot end the
 * body early; the name is matched by its text, so a method named after a reserved word is
 * found; an abstract declaration has no body.
 */
class Php_Parser_Method_Body_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const SOURCE = <<<'PHP'
<?php
abstract class Fixture_Class
{
    abstract public static function pending(): void;

    public static function list(array $params = [])
    {
        $text = "a } brace in a string";
        // and one } in a comment
        return ['text' => $text, 'map' => ["{$text}" => 1]];
    }

    public static function &by_reference()
    {
        static $value = 1;
        return $value;
    }

    public static function after()
    {
        return 2;
    }
}
PHP;

    private static function __body(string $name): ?string
    {
        return Php_Parser::method_body(token_get_all(self::SOURCE), $name);
    }

    public static function test_reserved_word_name_is_found_and_string_braces_do_not_end_the_body()
    {
        $body = static::__body('list');

        static::__assert_true($body !== null, 'a method named after a reserved word is located');
        static::__assert_true(str_starts_with($body, '{') && str_ends_with($body, '}'), 'the body runs brace to brace');
        static::__assert_contains("return ['text' => \$text", $body, 'a brace in a string or comment does not end the body early');
        static::__assert_true(!str_contains($body, 'by_reference'), 'the body stops at its own closing brace');
    }

    public static function test_by_reference_method_is_found()
    {
        static::__assert_contains('static $value = 1;', (string) static::__body('by_reference'), 'function &name is located');
    }

    public static function test_name_match_is_case_insensitive()
    {
        static::__assert_contains('return 2;', (string) static::__body('AFTER'), 'PHP method names are case-insensitive');
    }

    public static function test_abstract_declaration_and_absent_method_have_no_body()
    {
        static::__assert_true(static::__body('pending') === null, 'an abstract declaration has no body');
        static::__assert_true(static::__body('missing') === null, 'an absent method has no body');
    }
}
