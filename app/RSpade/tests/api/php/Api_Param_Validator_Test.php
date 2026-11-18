<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Http\UploadedFile;
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

    // -------------------------------------------------------------------------
    // A string param is a plain string: the API carries no text envelope
    // -------------------------------------------------------------------------

    public static function test_a_string_param_reaches_the_endpoint_as_the_string_sent()
    {
        $specs = [static::__spec('body', 'string', true)];
        $envelope = json_encode(['__TEXT' => 'Text_Fixture_Wrapped_Text', 'raw' => '<p>hi</p>', 'empty' => false]);

        foreach (['<p>hi</p>', 'plain <b>text</b>', '{not json', '{"raw": "x"}', $envelope] as $sent) {
            $result = Api_Param_Validator::validate($specs, ['body' => $sent]);
            static::__assert_true($result['valid'], json_encode($result['fields']));
            static::__assert_equals($sent, $result['params']['body'], 'untouched - an envelope-shaped string included: ' . $sent);
        }
    }

    public static function test_a_json_object_in_a_string_param_is_refused()
    {
        $specs = [static::__spec('body', 'string', true)];

        $result = Api_Param_Validator::validate($specs, ['body' => ['__TEXT' => 'Text_Fixture_Wrapped_Text', 'raw' => '<p>hi</p>', 'empty' => false]]);

        static::__assert_false($result['valid']);
        static::__assert_equals('Expected string', $result['fields']['body'] ?? null);
    }

    // -------------------------------------------------------------------------
    // A json param: any JSON value, checked only for parsing
    // -------------------------------------------------------------------------

    public static function test_a_json_param_decodes_an_object_sent_as_text()
    {
        $specs = [static::__spec('filters', 'json', true)];
        $result = Api_Param_Validator::validate($specs, ['filters' => '{"status": 1, "tags": ["a", "b"]}']);

        static::__assert_true($result['valid'], json_encode($result['fields']));
        static::__assert_equals(['status' => 1, 'tags' => ['a', 'b']], $result['params']['filters'], 'an object decodes to an associative array');
    }

    public static function test_a_json_param_decodes_a_list_sent_as_text()
    {
        $specs = [static::__spec('ids', 'json', true)];
        $result = Api_Param_Validator::validate($specs, ['ids' => '[3, 1, 2]']);

        static::__assert_true($result['valid'], json_encode($result['fields']));
        static::__assert_equals([3, 1, 2], $result['params']['ids']);
    }

    public static function test_a_json_param_takes_a_value_structured_in_a_json_body_as_it_is()
    {
        $specs = [static::__spec('filters', 'json', true)];
        $structured = ['status' => 1, 'nested' => ['deep' => [true, null, 2.5]]];
        $result = Api_Param_Validator::validate($specs, ['filters' => $structured]);

        static::__assert_true($result['valid'], json_encode($result['fields']));
        static::__assert_equals($structured, $result['params']['filters']);
    }

    public static function test_a_json_param_accepts_scalars()
    {
        $specs = [static::__spec('v', 'json', true)];

        // [raw value as it arrives, decoded value the endpoint receives]
        $cases = [
            ['5', 5],
            ['"x"', 'x'],
            ['true', true],
            ['null', null],
            [7, 7],
            [false, false],
            [null, null],
        ];

        foreach ($cases as [$sent, $expected]) {
            $result = Api_Param_Validator::validate($specs, ['v' => $sent]);
            static::__assert_true($result['valid'], 'valid JSON scalar: ' . var_export($sent, true));
            static::__assert_true(array_key_exists('v', $result['params']), 'the scalar reaches the endpoint: ' . var_export($sent, true));
            static::__assert_equals($expected, $result['params']['v'], 'scalar: ' . var_export($sent, true));
        }
    }

    public static function test_a_json_param_refuses_text_that_does_not_parse()
    {
        $specs = [static::__spec('filters', 'json', true)];

        foreach (['{status: 1}', '{"a": 1', 'x', '', "{'a': 1}"] as $sent) {
            $result = Api_Param_Validator::validate($specs, ['filters' => $sent]);
            static::__assert_false($result['valid'], 'refused: ' . $sent);
            static::__assert_equals(['filters' => 'Expected valid JSON'], $result['fields'], 'the field error: ' . $sent);
            static::__assert_equals([], $result['params']);
        }
    }

    public static function test_a_json_param_refuses_an_uploaded_file()
    {
        $specs = [static::__spec('filters', 'json', true)];
        $path = tempnam(sys_get_temp_dir(), 'json-param-');
        file_put_contents($path, '{"a": 1}');

        try {
            $file = new UploadedFile($path, 'filters.json', 'application/json', null, true);
            $result = Api_Param_Validator::validate($specs, ['filters' => $file]);
        } finally {
            unlink($path);
        }

        static::__assert_false($result['valid']);
        static::__assert_equals('Expected valid JSON', $result['fields']['filters'] ?? null);
    }

    public static function test_a_required_json_param_that_is_absent_is_reported()
    {
        $specs = [static::__spec('filters', 'json', true)];
        $result = Api_Param_Validator::validate($specs, []);

        static::__assert_false($result['valid']);
        static::__assert_equals('This field is required', $result['fields']['filters'] ?? null);
    }

    public static function test_an_optional_json_param_without_a_default_stays_absent()
    {
        $specs = [static::__spec('filters', 'json')];
        $result = Api_Param_Validator::validate($specs, []);

        static::__assert_true($result['valid']);
        static::__assert_false(array_key_exists('filters', $result['params']));
    }

    public static function test_a_json_default_is_applied_decoded()
    {
        $specs = [
            static::__spec('filters', 'json', false, ['status' => 1]),
            static::__spec('sort', 'json', false, '["name", "id"]'),
            static::__spec('empty', 'json', false, []),
        ];
        $result = Api_Param_Validator::validate($specs, []);

        static::__assert_true($result['valid']);
        static::__assert_equals(['status' => 1], $result['params']['filters'], 'an array default as written');
        static::__assert_equals(['name', 'id'], $result['params']['sort'], 'a JSON-text default decoded');
        static::__assert_equals([], $result['params']['empty'], 'an empty-array default applied');
    }

    public static function test_a_sent_json_value_overrides_the_default()
    {
        $specs = [static::__spec('filters', 'json', false, ['status' => 1])];
        $result = Api_Param_Validator::validate($specs, ['filters' => '{"status": 2}']);

        static::__assert_true($result['valid']);
        static::__assert_equals(['status' => 2], $result['params']['filters']);
    }
}
