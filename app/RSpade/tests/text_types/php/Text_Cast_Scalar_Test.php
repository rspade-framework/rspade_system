<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TextTypes\Php;

use App\RSpade\Core\Database\TextTypes\Rsx_Text_Cast;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\TextTypes\Php\Text_Fixture_Plain_Text;

/**
 * What the cast does with a scalar that is not a string.
 *
 * An int, float or bool assigned to a declared column is stringified and then treated as
 * PLAIN TEXT - the same path as a bare string. That is the designed behaviour (an importer
 * handing over a number should not have to cast it first), and it is pinned here because
 * nothing else exercises it: the value-contract tests never touch the cast.
 *
 * The chain most worth holding is false: it stringifies to '', which the wrapping type
 * escapes into an empty wrapper - and is_empty() must then answer true.
 *
 * The fixture model is loaded from a temporary file, outside the manifest, and the cast is
 * driven directly: the column's type is the only thing the cast asks the model.
 * Text_Fixture_Wrapped_Text escapes plain text into '<p>...</p>', the shape of a WYSIWYG
 * type.
 */
class Text_Cast_Scalar_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const MODEL = 'Text_Cast_Scalar_Fixture_Model';

    private static function __model(): object
    {
        if (!class_exists(self::MODEL, false)) {
            $path = Rsx_Project_Paths::tmp_path('text_cast_scalar_fixture_model.php');
            file_put_contents($path, "<?php\n"
                . "class " . self::MODEL . " extends \\App\\RSpade\\Core\\Database\\Models\\Rsx_Model_Abstract\n"
                . "{\n"
                . "    protected \$table = 'fixture';\n"
                . "    public static \$text_types = ['body' => \\App\\RSpade\\Tests\\TextTypes\\Php\\Text_Fixture_Wrapped_Text::class];\n"
                . "}\n");
            require $path;
            @unlink($path);
        }

        return (new \ReflectionClass(self::MODEL))->newInstanceWithoutConstructor();
    }

    private static function __store(mixed $value): ?string
    {
        return (new Rsx_Text_Cast())->set(static::__model(), 'body', $value, []);
    }

    public static function test_an_int_is_plain_text()
    {
        static::__assert_equals('<p>42</p>', static::__store(42), 'an int stores as its digits, escaped and wrapped');
    }

    public static function test_a_float_is_plain_text()
    {
        static::__assert_equals('<p>4.5</p>', static::__store(4.5), 'a float stores as its decimal form');
    }

    public static function test_true_is_plain_text()
    {
        static::__assert_equals('<p>1</p>', static::__store(true), 'true stringifies to 1');
    }

    /**
     * false -> '' -> an empty wrapper -> is_empty(). A refactor that changed any link of
     * that chain would store a column that reads as filled.
     */
    public static function test_false_is_an_empty_value()
    {
        $stored = static::__store(false);

        static::__assert_equals('<p></p>', $stored, 'false stringifies to an empty wrapper');

        $read = (new Rsx_Text_Cast())->get(static::__model(), 'body', $stored, []);

        static::__assert_true($read->is_empty(), 'and reads back empty');
    }

    public static function test_an_array_is_refused()
    {
        static::__assert_throws(\InvalidArgumentException::class, fn () => static::__store(['a']), 'got array');
    }

    /**
     * The wrong-type refusal names the two conversions that exist - and neither is a string
     * cast, which a text value refuses.
     */
    public static function test_a_different_type_is_refused_with_a_usable_suggestion()
    {
        $other = Text_Fixture_Plain_Text::from_untrusted('x');

        $e = static::__assert_throws(\InvalidArgumentException::class, fn () => static::__store($other));

        static::__assert_contains('from_untrusted($value->to_storage())', $e->getMessage(), 'naming the keep-the-markup conversion');
        static::__assert_contains('from_string($value->to_text())', $e->getMessage(), 'and the reinterpret-as-text one');
        static::__assert_false(str_contains($e->getMessage(), '(string)'), 'and never a string cast, which throws');
    }
}
