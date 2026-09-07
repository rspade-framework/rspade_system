<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Convention\NameReservedPrefix_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for NAME-RESERVED-01 (NameReservedPrefix_CodeQualityRule).
 *
 * A SINGLE leading underscore before the capital is the framework-application prefix. It is
 * reserved from rsx/ - where a colliding `_`-name would be read as a CLASS OVERRIDE and
 * silently archive the framework's file - and it is REQUIRED under app/RSpade/Sys/.
 *
 * The Sys tree does not exist yet: direction (b) keys on the path prefix, so these tests
 * write synthetic app/RSpade/Sys/ fixtures and the rule judges them on spelling alone.
 */
class Name_Reserved_Prefix_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is text inspection over temp fixture files - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'NAME-RESERVED-01';

    /**
     * Write $source to a fixture at $relative_name under a throwaway root, run the rule
     * over it, and return the violations it produced.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $relative_name, string $source): array
    {
        $root = storage_path('rsx-tmp') . '/name_reserved_01_fixture_' . uniqid();
        $path = $root . '/' . $relative_name;
        ensure_directory(dirname($path));
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new NameReservedPrefix_CodeQualityRule($collector);
        $rule->check($path, $source);

        $violations = array_values($collector->get_by_rule(self::RULE_ID));

        self::__remove_tree($root);

        return $violations;
    }

    private static function __remove_tree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($root);
    }

    // =====================================================================
    // Direction (a): rsx/ may not declare a reserved name
    // =====================================================================

    /**
     * A `_`-prefixed PHP class in application code is the shape that silently overrides a
     * framework class, so it is the headline violation.
     */
    public static function test_underscore_php_class_in_app_is_a_violation()
    {
        $violations = self::__run(
            'rsx/models/_sys_widget_model.php',
            "<?php\n\nclass _Sys_Widget_Model\n{\n}\n"
        );

        static::__assert_count(1, $violations, 'expected exactly one violation');
        static::__assert_contains('_Sys_Widget_Model', $violations[0]->message);
        static::__assert_contains('framework-application prefix', $violations[0]->message);
    }

    /**
     * The same rule reaches a jqhtml component, which is declared by its <Define:> tag
     * rather than by a class.
     */
    public static function test_underscore_jqhtml_define_in_app_is_a_violation()
    {
        $violations = self::__run(
            'rsx/app/frontend/_root_card.jqhtml',
            "<Define:_Sys_Card tag=\"div\">\n    <p>hello</p>\n</Define:_Sys_Card>\n"
        );

        static::__assert_count(1, $violations, 'expected exactly one violation');
        static::__assert_contains('_Sys_Card', $violations[0]->message);
    }

    /**
     * And a Blade @rsx_id, the third way an application claims a name.
     */
    public static function test_underscore_rsx_id_in_app_is_a_violation()
    {
        $violations = self::__run(
            'rsx/app/frontend/_sys_page.blade.php',
            "@rsx_id('_Sys_Page')\n\n<div>hello</div>\n"
        );

        static::__assert_count(1, $violations, 'expected exactly one violation');
        static::__assert_contains('_Sys_Page', $violations[0]->message);
    }

    /**
     * An ordinary application name is untouched - the rule must not become a tax on rsx/.
     */
    public static function test_ordinary_app_name_is_clean()
    {
        $violations = self::__run(
            'rsx/models/widget_model.php',
            "<?php\n\nclass Widget_Model\n{\n}\n"
        );

        static::__assert_count(0, $violations, 'an ordinary application class must not be flagged');
    }

    // =====================================================================
    // Direction (b): app/RSpade/Sys/ must declare reserved names
    // =====================================================================

    /**
     * A compliant `_`-name inside the framework-application tree is exactly right.
     */
    public static function test_reserved_name_under_sys_tree_is_clean()
    {
        $violations = self::__run(
            'app/RSpade/Sys/app/sys/_Sys_Controller.php',
            "<?php\n\nclass _Sys_Controller\n{\n}\n"
        );

        static::__assert_count(0, $violations, 'a prefixed name under the Sys tree is compliant');
    }

    /**
     * A bare name inside that tree is the other direction of the same rule.
     */
    public static function test_bare_name_under_sys_tree_is_a_violation()
    {
        $violations = self::__run(
            'app/RSpade/Sys/app/sys/Sys_Controller.php',
            "<?php\n\nclass Sys_Controller\n{\n}\n"
        );

        static::__assert_count(1, $violations, 'expected exactly one violation');
        static::__assert_contains('Sys_Controller', $violations[0]->message);
        static::__assert_contains('_Sys_Controller', $violations[0]->suggestion);
    }

    /**
     * Framework code OUTSIDE the Sys tree is not governed by either direction - the
     * `_Manifest_*_Helper` classes are the standing proof that a framework-internal
     * `_`-name is ordinary.
     */
    public static function test_framework_code_outside_the_sys_tree_is_untouched()
    {
        $violations = self::__run(
            'app/RSpade/Core/Manifest/_Manifest_Scanner_Helper.php',
            "<?php\n\nclass _Manifest_Scanner_Helper\n{\n}\n"
        );

        static::__assert_count(0, $violations, 'framework code outside Root/ is out of scope');
    }
}
