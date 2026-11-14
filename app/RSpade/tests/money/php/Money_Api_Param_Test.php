<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Money\Php;

use App\RSpade\Core\Api\Api_Param_Validator;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * #[Api_Param(type: 'money')]: the external API reads an amount as a plain decimal string,
 * the {"__MONEY": "..."} envelope as a JSON object or JSON-encoded into a string, a JSON int
 * of whole units or a JSON float without residue; null and blank are null. The endpoint
 * receives an Rsx_Money, and a value that is not an amount is the field's error with the
 * refusal's own message.
 */
class Money_Api_Param_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __spec($default = null): array
    {
        return [
            'name' => 'amount',
            'type' => 'money',
            'required' => false,
            'default' => $default,
            'description' => null,
            'example' => null,
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
        static::__assert_equals('99.99', static::__accepted('{"__MONEY": "99.99"}'), 'the envelope as a JSON string');
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
        static::__assert_contains('Rsx_Money::from_storage()', static::__refused('{"__MONEY": "1.5"}'), 'a malformed envelope');
        static::__assert_contains('from_envelope', static::__refused('{"__MONEY": "1.50", "x": 1}'));
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_FORMAT, static::__refused(['amount' => '1.00']), 'an object that is not the envelope');
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_FORMAT, static::__refused(['1.00']), 'a list');
    }

    // MONEY-API-ENVELOPE-OBJECT: the envelope as a JSON object in a JSON body - exactly what a
    // read of the value returned - is accepted; a malformed one is the field error.
    public static function test_the_envelope_as_a_json_object()
    {
        $body = json_decode('{"amount": {"__MONEY": "1.00"}}', true);
        static::__assert_equals('1.00', static::__accepted($body['amount']), 'sent back exactly as read');

        $read = json_decode(json_encode(['amount' => Rsx_Money::from_decimal('-1234.5')]), true);
        static::__assert_equals('-1234.50', static::__accepted($read['amount']), 'a read value round-trips');

        static::__assert_contains('Rsx_Money::from_storage()', static::__refused(['__MONEY' => '1.5']), 'not canonical');
        static::__assert_contains('from_envelope', static::__refused(['__MONEY' => '1.50', 'x' => 1]), 'a key beside __MONEY');
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
}
