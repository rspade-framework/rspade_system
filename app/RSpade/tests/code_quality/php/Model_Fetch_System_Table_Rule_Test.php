<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Manifest\ModelFetchSystemTable_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for MODEL-FETCH-SYSTEM-01 (ModelFetchSystemTable_CodeQualityRule): a model
 * stored in an underscore-prefixed (framework system) table may not declare a browser
 * fetch surface.
 *
 * Tests drive evaluate_file() over synthetic fixture files. The attribute name is
 * assembled from string pieces so this file contains nothing the rule could read as a
 * declaration when it scans app/RSpade/tests.
 */
class Model_Fetch_System_Table_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const RULE_ID = 'MODEL-FETCH-SYSTEM-01';

    private const ATTRIBUTE = 'Ajax_Endpoint' . '_Model_Fetch';

    /**
     * A fixture model with a $table and one annotated static fetch().
     */
    private static function __source(string $class_name, string $table, string $docblock = ''): string
    {
        return "<?php\n"
            . "class {$class_name}\n"
            . "{\n"
            . "    protected \$table = '{$table}';\n\n"
            . $docblock
            . '    #[' . self::ATTRIBUTE . "]\n"
            . "    #[Auth('is_logged_in')]\n"
            . "    public static function fetch(\$id)\n"
            . "    {\n"
            . "        return false;\n"
            . "    }\n"
            . "}\n";
    }

    /**
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $source, string $class_name): array
    {
        $dir = Rsx_Project_Paths::tmp_path() . '/model_fetch_system_fixture_' . uniqid();
        $path = $dir . '/' . $class_name . '.php';
        ensure_directory($dir);
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new ModelFetchSystemTable_CodeQualityRule($collector);
        $rule->evaluate_file($path, $class_name);

        $violations = $collector->get_by_rule(self::RULE_ID);

        @unlink($path);
        @rmdir($dir);

        return $violations;
    }

    public static function test_a_fetch_surface_on_a_system_table_is_refused()
    {
        $class = 'Fetch_System_Probe_' . uniqid();
        $violations = static::__run(static::__source($class, '_probe_queue'), $class);

        static::__assert_count(1, $violations);
        static::__assert_contains('_probe_queue', $violations[0]->message);
    }

    public static function test_a_fetch_surface_on_an_application_table_passes()
    {
        $class = 'Fetch_App_Probe_' . uniqid();

        static::__assert_count(0, static::__run(static::__source($class, 'probe_records'), $class));
    }

    public static function test_the_exception_marker_on_the_member_suppresses()
    {
        $class = 'Fetch_Excepted_Probe_' . uniqid();
        $docblock = "    /**\n     * @" . self::RULE_ID . "-EXCEPTION - curated payload, per-record gate\n     */\n";

        static::__assert_count(0, static::__run(static::__source($class, '_probe_queue', $docblock), $class));
    }

    /**
     * The framework's queue models carry no fetch surface: their rows hold rendered bodies
     * and invite / reset links.
     */
    public static function test_the_queue_models_declare_no_fetch()
    {
        $surfaces = \App\RSpade\Core\Auth\Auth_Gates::get_surfaces();

        foreach (['Email_Queue_Model', 'Sms_Queue_Model'] as $model) {
            foreach (['fetch', 'portal_fetch'] as $method) {
                static::__assert_false(
                    isset($surfaces["{$model}::{$method}"]),
                    "{$model}::{$method} must not be a fetch surface"
                );
            }
        }
    }
}
