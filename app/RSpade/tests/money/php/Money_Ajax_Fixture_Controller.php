<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Money\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Tests\Money\Php\Money_Fixture_Model;

/**
 * Ajax endpoints that report what their params arrived as, and answer money values, so a
 * test can see the Ajax boundary rehydrate a {__MONEY} envelope before the endpoint runs and
 * serialize one on the way out.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth].
 */
class Money_Ajax_Fixture_Controller extends Rsx_Controller_Abstract
{
    /** The type and amount of 'amount', and of 'lines' entries, as the endpoint received them. */
    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function describe_params(Request $request, array $params = [])
    {
        $describe = fn ($value) => [
            'type' => is_object($value) ? class_basename($value) : gettype($value),
            'decimal' => $value instanceof Rsx_Money ? $value->to_decimal() : null,
        ];

        return [
            'amount' => $describe($params['amount'] ?? null),
            'lines' => array_map(fn ($line) => $describe($line['price'] ?? null), $params['lines'] ?? []),
        ];
    }

    /** A money value and an unsaved record carrying money columns, as the response body. */
    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function answer_money(Request $request, array $params = [])
    {
        $record = new Money_Fixture_Model();
        $record->amount = '1234.56';
        $record->price = '0.00';

        return [
            'value' => Rsx_Money::from_decimal('-0.05'),
            'record' => $record,
        ];
    }
}
