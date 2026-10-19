<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Manifest\Manifest_Scanner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * An attribute declared twice WITH IDENTICAL ARGUMENTS on one declaration is a manifest-build
 * FATAL (Manifest_Scanner::_refuse_repeated_attributes()); the same attribute with different
 * arguments is allowed.
 *
 * The subjects are classes created at run time with eval(): a fixture FILE carrying the
 * duplicate would sit in a tree the test run's own manifest build scans, and fail that build.
 */
class Repeated_Attribute_Test extends Rsx_Test_Abstract
{
    // Reflection over eval'd classes only - no database access.
    protected static $use_database_transactions = false;

    private static function __check(string $class_body): void
    {
        $object = eval('return new class { ' . $class_body . ' };');
        Manifest_Scanner::_refuse_repeated_attributes(new \ReflectionClass($object), 'fixture.php');
    }

    private static function __refused(string $class_body, string $expect): void
    {
        static::__assert_throws(\RuntimeException::class, function () use ($class_body) {
            static::__check($class_body);
        }, $expect);
    }

    public static function test_an_identical_repeat_on_a_method_is_refused()
    {
        static::__refused(
            "#[Exclusive('g')] #[Exclusive('g')] public static function task() {}",
            "#[Exclusive('g')] is declared twice on method task()"
        );
        static::__refused(
            '#[Exclusive] #[Exclusive] public static function task() {}',
            'is declared twice on method task()'
        );
    }

    public static function test_the_same_attribute_with_different_arguments_is_allowed()
    {
        static::__check("#[Exclusive('a')] #[Exclusive('b')] public static function task() {}");
        static::__check("#[Exclusive] #[Exclusive('a')] public static function task() {}");
        static::__check("#[Route('/a')] #[Route('/b')] public static function page() {}");
        static::__check("#[Exclusive('g')] #[Exclusive(group: 'g')] public static function task() {}");
        static::__pass();
    }

    public static function test_every_kind_of_declaration_is_checked()
    {
        static::__refused("#[Sealed] #[Sealed] public static \$table = 'x';", 'property $table');
        static::__refused("#[Marker('x')] #[Marker('x')] const KIND = 1;", 'constant KIND');
        static::__refused("public static function task(#[Marker] #[Marker] \$value) {}", 'parameter $value of task()');
    }

    public static function test_the_class_itself_is_checked()
    {
        static::__assert_throws(\RuntimeException::class, function () {
            $object = eval("return new #[Instantiatable] #[Instantiatable] class {};");
            Manifest_Scanner::_refuse_repeated_attributes(new \ReflectionClass($object), 'fixture.php');
        }, '#[Instantiatable()] is declared twice on class');
    }
}
