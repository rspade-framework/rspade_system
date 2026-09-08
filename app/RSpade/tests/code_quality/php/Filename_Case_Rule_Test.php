<?php

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Common\FilenameCase_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * FILE-CASE-01: filenames under rsx/ are lowercase - except a JS class file or a jqhtml
 * component file that carries its own class name, which is the spelling MANIFEST-FILENAME-01
 * accepts for those kinds and an application uses throughout. The rule was inert until the
 * rule driver began handing rules absolute paths (its scope test needs `/rsx/`), so these are
 * the first assertions it has ever had.
 *
 * THE PATHS ARE SYNTHETIC AND NEED NOT EXIST. The rule never opens a file: it reads the
 * basename, the metadata it is handed, and the manifest's NAME indexes. So the fixtures below
 * are invented rsx/ paths, and the one case that genuinely needs a name the manifest knows -
 * a companion sharing a real class's stem - uses a FRAMEWORK class name, which is present in
 * every install.
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
        static::__assert_count(0, static::__run('/var/www/html/rsx/app/probe/Probe_Widget_Controller.php', ['class' => 'Probe_Widget_Controller']));
    }

    public static function test_a_companion_scss_of_a_real_class_is_clean()
    {
        // Rsx_Storage is a framework JS class, so the manifest knows the name in every
        // install; a companion sharing its stem may keep the spelling.
        static::__assert_count(0, static::__run('/var/www/html/rsx/app/probe/Rsx_Storage.scss', []));
    }

    public static function test_a_js_class_file_named_for_its_class_is_clean()
    {
        static::__assert_count(0, static::__run('/var/www/html/rsx/app/probe/Probe_Widget_Layout.js', ['class' => 'Probe_Widget_Layout']));
    }

    public static function test_a_jqhtml_component_file_named_for_its_component_is_clean()
    {
        static::__assert_count(0, static::__run('/var/www/html/rsx/app/probe/Probe_Widget_Panel.jqhtml', ['id' => 'Probe_Widget_Panel']));
    }

    public static function test_a_js_file_whose_name_is_no_known_class_is_still_a_violation()
    {
        // The metadata names a DIFFERENT class than the stem, and the stem is in no index.
        static::__assert_count(1, static::__run('/var/www/html/rsx/lib/Zz_No_Such_Class.js', ['class' => 'Probe_Widget_Layout']));
    }

    public static function test_framework_files_are_out_of_scope()
    {
        static::__assert_count(0, static::__run('/var/www/html/system/app/RSpade/Core/Rsx.php', ['class' => 'Rsx']));
    }
}
