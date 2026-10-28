<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A class record's public_instance_methods carries the instance methods its TRAITS supply,
 * attributes included, exactly as public_static_methods carries their statics - so a
 * #[Relationship] or #[Realtime_Touch] a trait supplies is seen by every registry reading
 * the map. A VENDOR trait's instance methods are not taken (they carry no RSX attribute and
 * would read as methods the class declares).
 *
 * Driven through Manifest::_extract_reflection_data() over a fixture trait and class written
 * to a scratch directory and loaded, so no fixture enters the real index.
 */
class Manifest_Trait_Instance_Methods_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** @return array{0: array, 1: string} the extracted record and the trait file */
    private static function __extract(): array
    {
        $suffix = uniqid();
        $namespace = 'Rsx_Test_Fixture_' . $suffix;
        $dir = Rsx_Project_Paths::tmp_path() . '/trait_instance_fixture_' . $suffix;
        ensure_directory($dir);

        $attribute = 'Relation' . 'ship';
        $trait_file = $dir . '/Fixture_Trait.php';
        file_put_contents($trait_file, "<?php\nnamespace {$namespace};\n\ntrait Fixture_Trait\n{\n"
            . "    #[{$attribute}]\n    public function owner()\n    {\n        return null;\n    }\n\n"
            . "    public static function trait_static()\n    {\n        return null;\n    }\n}\n");

        $class_file = $dir . '/Fixture_Holder.php';
        file_put_contents($class_file, "<?php\nnamespace {$namespace};\n\n"
            . "class Fixture_Holder\n{\n    use Fixture_Trait;\n"
            . "    use \\Illuminate\\Foundation\\Validation\\ValidatesRequests;\n\n"
            . "    public function own_method()\n    {\n        return null;\n    }\n}\n");

        require_once $trait_file;
        require_once $class_file;

        $data = [];
        Manifest::_extract_reflection_data($class_file, $namespace . '\\Fixture_Holder', $data);

        @unlink($trait_file);
        @unlink($class_file);
        @rmdir($dir);

        return [$data, $trait_file];
    }

    public static function test_a_trait_instance_method_is_recorded_with_its_attributes()
    {
        [$data, $trait_file] = static::__extract();
        $instance = $data['public_instance_methods'] ?? [];

        static::__assert_true(isset($instance['owner']), 'the trait\'s instance method is in the map');
        static::__assert_true(isset($instance['owner']['attributes']['Relationship']), 'with its attribute');
        static::__assert_equals($trait_file, $instance['owner']['file'] ?? null, 'and the file it is written in');
        static::__assert_true(isset($instance['own_method']), 'the class\'s own instance method is still there');
        static::__assert_true(!isset($instance['trait_static']), 'a static stays out of the instance map');
        static::__assert_true(isset($data['public_static_methods']['trait_static']), 'and in the static map');
    }

    public static function test_a_vendor_traits_instance_methods_are_not_recorded()
    {
        [$data] = static::__extract();

        static::__assert_true(
            !isset($data['public_instance_methods']['validate']),
            'ValidatesRequests::validate() (a vendor trait) is not listed as the class\'s method'
        );
    }
}
