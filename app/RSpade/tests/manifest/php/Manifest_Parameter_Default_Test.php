<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Manifest\Manifest_Scanner;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE SCANNER NEVER EVALUATES A PARAMETER DEFAULT.
 *
 * A default like `Portal_User_Model::STATUS_ACTIVE` is a constant expression, and evaluating
 * it autoloads the class it names. Mid-build that class may not be loadable yet - an
 * overridden core model resolves to its rsx/ twin, which the same pass is still indexing -
 * and the evaluation then threw out of the whole manifest build, so a downstream field
 * report (2026-09-24) saw every `rsx:test` invocation abort over a private helper's
 * signature in a framework test. Nothing reads an evaluated default, so the scanner records
 * that a parameter is OPTIONAL and nothing more.
 *
 * The fixture names a class that does not exist at all: if the scanner evaluated the
 * default, the constant lookup would throw here exactly as it did downstream.
 */
class Manifest_Parameter_Default_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const FIXTURE_CLASS = 'Manifest_Parameter_Default_Fixture';

    /**
     * Write and load a class whose defaults name a class nobody declares.
     */
    private static function __load_fixture(): string
    {
        $path = Rsx_Project_Paths::tmp_path('manifest_parameter_default_fixture.php');

        if (!class_exists(self::FIXTURE_CLASS, false)) {
            file_put_contents($path, <<<'PHP'
<?php
class Manifest_Parameter_Default_Fixture
{
    public static function public_helper(int $status = Manifest_Parameter_Default_Missing::STATUS): int
    {
        return $status;
    }

    private static function __private_helper(int $status = Manifest_Parameter_Default_Missing::STATUS): int
    {
        return $status;
    }

    public function instance_helper(int $status = Manifest_Parameter_Default_Missing::STATUS): int
    {
        return $status;
    }
}
PHP);
            require $path;
        }

        return $path;
    }

    /**
     * Reflection over every method kind completes, and each optional parameter is recorded
     * as optional without a default value.
     */
    public static function test_a_constant_default_naming_a_missing_class_is_not_evaluated()
    {
        $path = static::__load_fixture();

        try {
            $data = [];
            Manifest_Scanner::_extract_reflection_data($path, self::FIXTURE_CLASS, $data);

            $param = $data['public_static_methods']['public_helper']['parameters'][0];
            static::__assert_true($param['optional'], 'the static parameter is recorded optional');
            static::__assert_false(array_key_exists('default', $param), 'and its default is not evaluated');

            $param = $data['public_instance_methods']['instance_helper']['parameters'][0];
            static::__assert_true($param['optional'], 'the instance parameter is recorded optional');
            static::__assert_false(array_key_exists('default', $param), 'and its default is not evaluated');
        } finally {
            @unlink($path);
        }
    }
}
