<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use App\RSpade\Core\Api\Api_Param_Validator;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Api_Param_Validator - undeclared-key rejection, required/default handling, and the
 * full scalar coercion matrix (int/float/bool/string). Pure array-in/array-out logic,
 * so no database is touched.
 *
 * A baked spec always carries every key (default => null when none was declared), which
 * these helpers mirror exactly so the tests exercise the real shape.
 */
class Api_Param_Validator_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Build a single #[Api_Param] spec in the exact shape the manifest bakes.
     */
    private static function __spec(string $name, string $type, bool $required = false, $default = null): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'required' => $required,
            'default' => $default,
            'description' => null,
            'example' => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Undeclared keys / required / defaults
    // -------------------------------------------------------------------------

    public static function test_undeclared_key_is_rejected()
    {
        $specs = [static::__spec('id', 'int', true)];
        $result = Api_Param_Validator::validate($specs, ['id' => '5', 'bogus' => 'x']);

        static::__assert_false($result['valid']);
        static::__assert_array_has_key('bogus', $result['fields']);
        static::__assert_equals([], $result['params'], 'no params returned on failure');
    }

    public static function test_missing_required_reports_field()
    {
        $specs = [static::__spec('id', 'int', true)];
        $result = Api_Param_Validator::validate($specs, []);

        static::__assert_false($result['valid']);
        static::__assert_array_has_key('id', $result['fields']);
    }

    public static function test_absent_optional_without_default_is_absent_from_params()
    {
        $specs = [static::__spec('search', 'string', false, null)];
        $result = Api_Param_Validator::validate($specs, []);

        static::__assert_true($result['valid']);
        static::__assert_false(array_key_exists('search', $result['params']), 'unset optional stays out of params');
    }

    public static function test_absent_optional_with_default_applies_default()
    {
        $specs = [static::__spec('per_page', 'int', false, 20)];
        $result = Api_Param_Validator::validate($specs, []);

        static::__assert_true($result['valid']);
        static::__assert_equals(20, $result['params']['per_page']);
    }

    // -------------------------------------------------------------------------
    // int coercion
    // -------------------------------------------------------------------------

    public static function test_int_coercion_matrix()
    {
        $specs = [static::__spec('n', 'int')];

        static::__assert_equals(5, Api_Param_Validator::validate($specs, ['n' => '5'])['params']['n'], "string '5' -> 5");
        static::__assert_equals(-3, Api_Param_Validator::validate($specs, ['n' => '-3'])['params']['n'], "string '-3' -> -3");
        static::__assert_equals(5, Api_Param_Validator::validate($specs, ['n' => 5])['params']['n'], 'native int passes through');

        static::__assert_false(Api_Param_Validator::validate($specs, ['n' => 'abc'])['valid'], "'abc' is not an int");
        static::__assert_false(Api_Param_Validator::validate($specs, ['n' => ''])['valid'], "'' is not an int");
        static::__assert_false(Api_Param_Validator::validate($specs, ['n' => '1.5'])['valid'], "'1.5' is not an int");
    }

    // -------------------------------------------------------------------------
    // float coercion
    // -------------------------------------------------------------------------

    public static function test_float_coercion_matrix()
    {
        $specs = [static::__spec('f', 'float')];

        static::__assert_equals(1.5, Api_Param_Validator::validate($specs, ['f' => '1.5'])['params']['f'], "string '1.5' -> 1.5");
        static::__assert_equals(3.0, Api_Param_Validator::validate($specs, ['f' => 3])['params']['f'], 'native int -> float');
        static::__assert_equals(2.5, Api_Param_Validator::validate($specs, ['f' => 2.5])['params']['f'], 'native float passes through');

        static::__assert_false(Api_Param_Validator::validate($specs, ['f' => 'nope'])['valid'], 'non-numeric string fails');
        static::__assert_false(Api_Param_Validator::validate($specs, ['f' => ''])['valid'], 'empty string fails');
    }

    // -------------------------------------------------------------------------
    // bool coercion
    // -------------------------------------------------------------------------

    public static function test_bool_truthy_strings()
    {
        $specs = [static::__spec('b', 'bool')];
        foreach (['1', 'true', 'yes', 'on', 'TRUE', 'On'] as $truthy) {
            $result = Api_Param_Validator::validate($specs, ['b' => $truthy]);
            static::__assert_true($result['valid'], "'{$truthy}' is valid");
            static::__assert_true($result['params']['b'], "'{$truthy}' -> true");
        }
    }

    public static function test_bool_falsy_strings()
    {
        $specs = [static::__spec('b', 'bool')];
        foreach (['0', 'false', 'no', 'off', 'FALSE', 'Off'] as $falsy) {
            $result = Api_Param_Validator::validate($specs, ['b' => $falsy]);
            static::__assert_true($result['valid'], "'{$falsy}' is valid");
            static::__assert_false($result['params']['b'], "'{$falsy}' -> false");
        }
    }

    public static function test_bool_native_and_int_values()
    {
        $specs = [static::__spec('b', 'bool')];

        static::__assert_true(Api_Param_Validator::validate($specs, ['b' => true])['params']['b'], 'native true');
        static::__assert_false(Api_Param_Validator::validate($specs, ['b' => false])['params']['b'], 'native false');
        static::__assert_true(Api_Param_Validator::validate($specs, ['b' => 1])['params']['b'], 'int 1 -> true');
        static::__assert_false(Api_Param_Validator::validate($specs, ['b' => 0])['params']['b'], 'int 0 -> false');
    }

    public static function test_bool_rejects_garbage()
    {
        $specs = [static::__spec('b', 'bool')];
        static::__assert_false(Api_Param_Validator::validate($specs, ['b' => 'maybe'])['valid'], "'maybe' is not a bool");
    }

    // -------------------------------------------------------------------------
    // string coercion
    // -------------------------------------------------------------------------

    public static function test_string_coercion_matrix()
    {
        $specs = [static::__spec('s', 'string')];

        static::__assert_equals('5', Api_Param_Validator::validate($specs, ['s' => '5'])['params']['s'], 'string passes through');
        static::__assert_equals('5', Api_Param_Validator::validate($specs, ['s' => 5])['params']['s'], 'int stringified');

        static::__assert_false(Api_Param_Validator::validate($specs, ['s' => true])['valid'], 'bool is not a string');
        static::__assert_false(Api_Param_Validator::validate($specs, ['s' => ['a']])['valid'], 'array is not a string');
    }

    // -------------------------------------------------------------------------
    // compound rejection + happy path
    // -------------------------------------------------------------------------

    public static function test_array_value_rejects_every_scalar_type()
    {
        foreach (['int', 'float', 'bool', 'string'] as $type) {
            $specs = [static::__spec('v', $type)];
            static::__assert_false(
                Api_Param_Validator::validate($specs, ['v' => ['nested' => 1]])['valid'],
                "array value never satisfies a {$type} param"
            );
        }
    }

    public static function test_valid_mixed_returns_only_coerced_params()
    {
        $specs = [
            static::__spec('id', 'int', true),
            static::__spec('active', 'bool'),
            static::__spec('name', 'string'),
            static::__spec('per_page', 'int', false, 20),
        ];

        $result = Api_Param_Validator::validate($specs, [
            'id' => '42',
            'active' => 'yes',
            'name' => 'Ada',
        ]);

        static::__assert_true($result['valid']);
        static::__assert_equals(
            ['id' => 42, 'active' => true, 'name' => 'Ada', 'per_page' => 20],
            $result['params'],
            'coerced values plus the applied default, nothing else'
        );
        static::__assert_equals([], $result['fields']);
    }
}
