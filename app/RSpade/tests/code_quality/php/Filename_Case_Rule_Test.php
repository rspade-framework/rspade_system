<?php

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Common\FilenameCase_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * FILE-CASE-01: filenames under rsx/ are lowercase - except a JS class file or a jqhtml
 * component file that carries its own class name, which is the spelling MANIFEST-FILENAME-01
 * accepts for those kinds and the reference app uses throughout. The rule was inert until the
 * rule driver began handing rules absolute paths (its scope test needs `/rsx/`), so these are
 * the first assertions it has ever had.
 */
class Filename_Case_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __run(string $path, array $metadata = []): array
    {
        $collector = new ViolationCollector();
        $rule = new FilenameCase_CodeQualityRule($collector);
        $rule->check($path, '', $metadata);

        return $collector->get_all();
    }

    public static function test_an_uppercase_filename_that_is_no_class_is_a_violation()
    {
        static::__assert_count(1, static::__run('/var/www/html/rsx/lib/Some_Notes.php', []));
    }

    public static function test_a_php_class_file_named_for_its_class_is_clean()
    {
        static::__assert_count(0, static::__run('/var/www/html/rsx/app/frontend/Frontend_Spa_Controller.php', ['class' => 'Frontend_Spa_Controller']));
    }

    public static function test_a_companion_scss_of_a_real_class_is_clean()
    {
        // Frontend_Spa_Layout is a real JS class of the reference app; its scss companion may share the stem.
        static::__assert_count(0, static::__run('/var/www/html/rsx/app/frontend/Frontend_Spa_Layout.scss', []));
    }

    public static function test_a_js_class_file_named_for_its_class_is_clean()
    {
        static::__assert_count(0, static::__run('/var/www/html/rsx/app/frontend/Frontend_Spa_Layout.js', ['class' => 'Frontend_Spa_Layout']));
    }

    public static function test_a_jqhtml_component_file_named_for_its_component_is_clean()
    {
        static::__assert_count(0, static::__run('/var/www/html/rsx/theme/components/notification/Notification_Dropdown.jqhtml', ['id' => 'Notification_Dropdown']));
    }

    public static function test_a_js_file_whose_name_is_no_known_class_is_still_a_violation()
    {
        static::__assert_count(1, static::__run('/var/www/html/rsx/lib/Zz_No_Such_Class.js', ['class' => 'Formatters']));
    }

    public static function test_framework_files_are_out_of_scope()
    {
        static::__assert_count(0, static::__run('/var/www/html/system/app/RSpade/Core/Rsx.php', ['class' => 'Rsx']));
    }
}
