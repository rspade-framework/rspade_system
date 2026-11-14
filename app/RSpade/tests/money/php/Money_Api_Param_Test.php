<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Money\Php;

use App\RSpade\Core\Api\Api_Endpoint_ManifestSupport;
use App\RSpade\Core\Api\Api_Param_Validator;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Money\Php\Money_Fixture_Model;
use App\RSpade\Tests\Money\Php\Money_Fixture_Tables;

/**
 * #[Api_Param(type: 'money')]: the external API reads an amount as a plain decimal string, a
 * JSON int of whole units or a JSON float without residue; null and blank are null. The
 * endpoint receives an Rsx_Money, and a value that is not an amount - the browser's
 * {"__MONEY": "..."} envelope included, as a JSON object or encoded into a string - is the
 * field's error with the refusal's own message. A param declared column: 'Model.column' is
 * also refused beyond that column's money_limit(), and the declaration is checked at scan.
 */
class Money_Api_Param_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function setup()
    {
        Money_Fixture_Tables::create();
    }

    public static function teardown()
    {
        Money_Fixture_Tables::drop();
    }

    private static function __spec($default = null, ?string $column = null): array
    {
        return [
            'name' => 'amount',
            'type' => 'money',
            'required' => false,
            'default' => $default,
            'description' => null,
            'example' => null,
            'column' => $column,
        ];
    }

    /** The validated param: the Rsx_Money's decimal, or null. */
    private static function __accepted($raw): ?string
    {
        $result = Api_Param_Validator::validate([static::__spec()], ['amount' => $raw]);

        static::__assert_true($result['valid'], 'accepted ' . json_encode($raw) . ': ' . json_encode($result['fields']));

        $value = $result['params']['amount'];
        if ($value === null) {
            return null;
        }

        static::__assert_instance_of(Rsx_Money::class, $value);

        return $value->to_decimal();
    }

    /** The field error for a refused value. */
    private static function __refused($raw): string
    {
        $result = Api_Param_Validator::validate([static::__spec()], ['amount' => $raw]);

        static::__assert_false($result['valid'], 'refused ' . json_encode($raw));

        return $result['fields']['amount'];
    }

    // MONEY-API-ACCEPT
    public static function test_each_accepted_form()
    {
        static::__assert_equals('1234.50', static::__accepted('1234.50'), 'a plain decimal string');
        static::__assert_equals('1234.50', static::__accepted('1234.5'));
        static::__assert_equals('-7.00', static::__accepted('-7'));
        static::__assert_equals('350.00', static::__accepted(350), 'a JSON int is whole units');
        static::__assert_equals('12.25', static::__accepted(12.25), 'a JSON float without residue');
        static::__assert_null(static::__accepted(''), 'blank is null, never zero');
        static::__assert_null(static::__accepted('   '), 'whitespace is blank');
        static::__assert_null(static::__accepted(null));
        static::__assert_equals('0.00', static::__accepted('0'), 'zero is a value');
    }

    // MONEY-API-REFUSE
    public static function test_a_value_that_is_not_an_amount_is_the_field_error()
    {
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_SCALE, static::__refused('12.345'));
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_FORMAT, static::__refused('abc'));
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_FORMAT, static::__refused('$12.00'), 'a plain decimal: no symbol');
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_FORMAT, static::__refused('{not json'));
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_RESIDUE, static::__refused(0.1 + 0.2));
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_FORMAT, static::__refused(true));
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_FORMAT, static::__refused(['amount' => '1.00']), 'an object that is not the envelope');
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_FORMAT, static::__refused(['1.00']), 'a list');
    }

    // MONEY-API-NO-ENVELOPE: the API carries plain values. The envelope the browser's Ajax
    // channel uses is not an amount here, as a JSON object or encoded into a string.
    public static function test_an_envelope_is_not_an_amount()
    {
        $body = json_decode('{"amount": {"__MONEY": "1.00"}}', true);
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_FORMAT, static::__refused($body['amount']), 'the envelope as a JSON object');
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_FORMAT, static::__refused('{"__MONEY": "99.99"}'), 'the envelope as a JSON string');
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_FORMAT, static::__refused(json_encode(Rsx_Money::from_decimal('-1234.5'))), 'what json_encode() writes');
    }

    // MONEY-API-DEFAULT: a declared default reaches the endpoint as the Rsx_Money a submitted
    // value would be.
    public static function test_a_default_is_an_rsx_money()
    {
        $result = Api_Param_Validator::validate([static::__spec('0.00')], []);

        static::__assert_true($result['valid']);
        static::__assert_instance_of(Rsx_Money::class, $result['params']['amount']);
        static::__assert_equals('0.00', $result['params']['amount']->to_decimal());
    }

    // MONEY-API-COLUMN: with column:, an amount beyond the column's limit is the field's
    // error - the refusal money_from_submitted() gives - so the endpoint receives one that fits.
    public static function test_a_column_param_refuses_an_amount_beyond_the_column()
    {
        // money_fixtures.price is DECIMAL(10,2): 99999999.99 either way.
        $spec = static::__spec(null, 'Money_Fixture_Model.price');
        $validate = fn ($raw) => Api_Param_Validator::validate([$spec], ['amount' => $raw]);

        static::__assert_equals('99999999.99', Money_Fixture_Model::money_limit('price')->to_decimal(), 'sanity: the limit');

        foreach (['99999999.99', '-99999999.99', '0', 99999999] as $raw) {
            $result = $validate($raw);
            static::__assert_true($result['valid'], 'within the column: ' . json_encode($raw));
        }

        foreach (['100000000.00', '-100000000', 100000000, '92233720368547758.07'] as $raw) {
            $result = $validate($raw);
            static::__assert_false($result['valid'], 'beyond the column: ' . json_encode($raw));
            static::__assert_equals(Rsx_Money_Exception::MESSAGE_COLUMN_RANGE, $result['fields']['amount']);
        }

        static::__assert_null($validate('')['params']['amount'], 'blank is still null');
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_SCALE, $validate('12.345')['fields']['amount'], 'the amount is read first');

        $result = Api_Param_Validator::validate([static::__spec(null)], ['amount' => '100000000.00']);
        static::__assert_true($result['valid'], 'without column: the type\'s range is the only bound');
    }

    // MONEY-API-COLUMN-DEFAULT: a default beyond the column is the declaration's mistake.
    public static function test_a_default_beyond_the_column_throws()
    {
        $result = Api_Param_Validator::validate([static::__spec('99999999.99', 'Money_Fixture_Model.price')], []);
        static::__assert_equals('99999999.99', $result['params']['amount']->to_decimal());

        $e = static::__assert_throws(
            Rsx_Money_Exception::class,
            fn () => Api_Param_Validator::validate([static::__spec('100000000.00', 'Money_Fixture_Model.price')], [])
        );
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_COLUMN_RANGE, $e->getMessage());
    }

    /** A manifest declaring one POST endpoint whose one param carries $param_args. */
    private static function __scan(array $param_args): array
    {
        class_exists(Money_Fixture_Model::class);

        $manifest = [
            'data' => [
                'files' => [
                    'rsx_probe_money_column_temp.php' => [
                        'fqcn' => 'Rsx\\App\\Api\\V1\\Money_Probe_Api_Controller',
                        'class' => 'Money_Probe_Api_Controller',
                        'extends_fqcn' => 'App\\RSpade\\Core\\Api\\Rsx_Api_Controller_Abstract',
                        'public_static_methods' => [
                            'save' => ['attributes' => [
                                'Api_Endpoint' => [[0 => '/api/v1/money_probe', 1 => ['POST']]],
                                'Api_Param' => [$param_args],
                            ]],
                        ],
                    ],
                ],
                'routes' => [],
                'routes_by_target' => [],
                'api_endpoints' => [],
                'php_subclass_index' => [
                    'Rsx_Api_Controller_Abstract' => ['Money_Probe_Api_Controller'],
                    'Rsx_Model_Abstract' => ['Money_Fixture_Model', 'Abstract_Probe_Model'],
                ],
                'php_classes' => [
                    'Money_Fixture_Model' => ['fqcn' => Money_Fixture_Model::class, 'file' => 'x.php', 'extends' => 'Rsx_Model_Abstract', 'abstract' => false],
                    'Abstract_Probe_Model' => ['fqcn' => 'Abstract_Probe_Model', 'file' => 'y.php', 'extends' => 'Rsx_Model_Abstract', 'abstract' => true],
                ],
            ],
        ];

        Api_Endpoint_ManifestSupport::process($manifest, array_keys($manifest['data']['files']), []);

        return $manifest['data']['api_endpoints']['/api/v1/money_probe']['api_params'][0];
    }

    // MONEY-API-COLUMN-SCAN: the column: argument is checked when the manifest is built.
    public static function test_the_column_argument_is_checked_at_scan()
    {
        $param = static::__scan(['amount', 'type' => 'money', 'column' => 'Money_Fixture_Model.price']);
        static::__assert_equals('Money_Fixture_Model.price', $param['column'], 'baked as declared');

        $param = static::__scan(['amount', 'type' => 'money']);
        static::__assert_null($param['column'], 'absent, null');

        $refusals = [
            'on a string param' => [['amount', 'type' => 'string', 'column' => 'Money_Fixture_Model.price'], "accepted on a type 'money' param only"],
            'not Model.column' => [['amount', 'type' => 'money', 'column' => 'Money_Fixture_Model::price'], "must be a literal string 'Model_Name.column'"],
            'not a string' => [['amount', 'type' => 'money', 'column' => ['Money_Fixture_Model', 'price']], "must be a literal string 'Model_Name.column'"],
            'no such model' => [['amount', 'type' => 'money', 'column' => 'No_Such_Probe_Model.price'], 'which is not a concrete model'],
            'an abstract model' => [['amount', 'type' => 'money', 'column' => 'Abstract_Probe_Model.price'], 'which is not a concrete model'],
            'not a money column' => [['amount', 'type' => 'money', 'column' => 'Money_Fixture_Model.score'], "is not a money column"],
            'no such column' => [['amount', 'type' => 'money', 'column' => 'Money_Fixture_Model.rsx_probe_none'], "is not a money column"],
        ];

        foreach ($refusals as $label => [$args, $needle]) {
            static::__assert_throws(\RuntimeException::class, fn () => static::__scan($args), $needle);
        }
    }
}
