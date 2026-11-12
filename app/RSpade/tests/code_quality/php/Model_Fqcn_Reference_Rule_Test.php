<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\PHP\ModelFqcnReference_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for the PHP-FQCN-MODEL-01 rule (ModelFqcnReference_CodeQualityRule).
 *
 * The rule flags an inline fully-qualified reference to a FRAMEWORK MODEL - a class under
 * app/RSpade/ extending Rsx_Model_Abstract, wherever it lives - because a short name is what
 * an application override answers. The set comes from the manifest, so these cases name REAL
 * framework models in Core/Models, Core/Files and Lib/.
 *
 * Every FQCN below is assembled from string pieces, so this file carries none the rule could
 * read as a violation when rsx:check scans app/RSpade/tests.
 */
class Model_Fqcn_Reference_Rule_Test extends Rsx_Test_Abstract
{
    // Pure line scanning against the live manifest - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'PHP-FQCN-MODEL-01';

    private const ROOT = 'App' . '\\RSpade\\';

    /** A fixture path in no framework model's file. */
    private const FIXTURE_PATH = 'rsx/lib/model_fqcn_probe.php';

    private static function __run(string $source, string $path = self::FIXTURE_PATH): array
    {
        $collector = new ViolationCollector();
        $rule = new ModelFqcnReference_CodeQualityRule($collector);
        $rule->check($path, $source);

        return $collector->get_by_rule(self::RULE_ID);
    }

    /** One PHP line calling $fqcn statically, with a leading backslash. */
    private static function __inline(string $fqcn): string
    {
        return "<?php\n\$x = \\" . $fqcn . "::find(1);\n";
    }

    public static function test_a_core_models_model_is_flagged()
    {
        static::__assert_count(1, self::__run(self::__inline(self::ROOT . 'Core\\Models\\User_Model')), 'Core/Models model');
    }

    public static function test_a_model_beside_its_subsystem_is_flagged()
    {
        static::__assert_count(1, self::__run(self::__inline(self::ROOT . 'Core\\Files\\File_Attachment_Model')), 'Core/Files model');
    }

    public static function test_a_lib_model_is_flagged()
    {
        static::__assert_count(1, self::__run(self::__inline(self::ROOT . 'Lib\\Portal\\Portal_User_Model')), 'Lib/Portal model');
        static::__assert_count(1, self::__run(self::__inline(self::ROOT . 'Lib\\Geo\\Places\\Region_Model_Abstract')), 'Lib/Geo/Places base');
    }

    public static function test_a_string_reference_is_flagged()
    {
        $source = "<?php\n\$class = '" . self::ROOT . "Lib\\Messaging\\Mail\\Email_Queue_Model';\n";

        static::__assert_count(1, self::__run($source), 'string FQCN of a Lib model');
    }

    public static function test_a_system_model_is_not_flagged()
    {
        static::__assert_count(0, self::__run(self::__inline(self::ROOT . 'Core\\Session\\Session')), 'system model');
    }

    public static function test_a_non_model_class_is_not_flagged()
    {
        static::__assert_count(0, self::__run(self::__inline(self::ROOT . 'Lib\\Portal\\Rsx_Portal')), 'non-model class');
    }

    public static function test_use_statements_and_comments_are_allowed()
    {
        $fqcn = self::ROOT . 'Lib\\Portal\\Portal_User_Model';
        $source = "<?php\nuse {$fqcn};\n/**\n * @return \\{$fqcn}\n */\n// \\{$fqcn}::find()\n";

        static::__assert_count(0, self::__run($source), 'use line, docblock and comment');
    }

    public static function test_a_framework_model_file_itself_is_exempt()
    {
        $source = self::__inline(self::ROOT . 'Core\\Models\\User_Model');

        static::__assert_count(0, self::__run($source, 'app/RSpade/Lib/Portal/Portal_User_Model_Abstract.php'), 'model file');
    }
}
