<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Rsx_Api;
use App\RSpade\Core\Api\Rsx_Api_Controller_Abstract;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Tests\Api\Php\Api_Plain_Values_Fixture_Model;
use App\RSpade\Tests\TextTypes\Php\Text_Fixture_Wrapped_Text;

/**
 * API endpoints answering money and text-type values in every position a body can hold them,
 * for Api_Plain_Values_Test: a returned model, a list and a nested list of records, a value
 * object placed directly in an array, a model's toArray() an endpoint built by hand, a 201,
 * a document, look-alike objects that are not envelopes, and a writer that takes a 'money'
 * param and a text body. Present only while the suite runs (the test tree enters the manifest
 * then).
 */
#[Auth('is_logged_in')]
class Api_Plain_Values_Fixture_Api_Controller extends Rsx_Api_Controller_Abstract
{
    /**
     * One record, returned as the model.
     */
    #[Api_Endpoint('/api/v1/test-probe/plain/record/:id', methods: ['GET'])]
    #[Api_Param('id', type: 'int', required: true)]
    public static function record(Request $request, array $params = [])
    {
        $record = Api_Plain_Values_Fixture_Model::find($params['id']);

        return $record ?? Rsx_Api::not_found();
    }

    /**
     * Every record as a list, the same list nested two levels down, and one record's
     * toArray() an endpoint assembled itself.
     */
    #[Api_Endpoint('/api/v1/test-probe/plain/list', methods: ['GET'])]
    public static function list(Request $request, array $params = [])
    {
        $records = Api_Plain_Values_Fixture_Model::query()->orderBy('id')->get();

        return [
            'items' => $records,
            'nested' => ['groups' => [['records' => $records->all()]]],
            'built' => $records->first()?->toArray(),
        ];
    }

    /**
     * Value objects placed directly in the response, a null, and objects that resemble an
     * envelope but are not one.
     */
    #[Api_Endpoint('/api/v1/test-probe/plain/values', methods: ['GET'])]
    public static function values(Request $request, array $params = [])
    {
        return [
            'total' => Rsx_Money::from_decimal('7708.8'),
            'body' => Text_Fixture_Wrapped_Text::from_untrusted_encoded('<p>direct</p>'),
            'lines' => [['price' => Rsx_Money::from_decimal('-0.05')], ['price' => null]],
            'none' => null,
            'with_raw' => ['raw' => '<p>x</p>', 'empty' => false],
            'with_more' => ['__TEXT' => 'Text_Fixture_Wrapped_Text', 'raw' => '<p>x</p>', 'empty' => false, 'note' => 'kept'],
            'money_and_more' => ['__MONEY' => '1.00', 'currency' => 'USD'],
        ];
    }

    /**
     * A 201 carrying a record.
     */
    #[Api_Endpoint('/api/v1/test-probe/plain/created/:id', methods: ['GET'])]
    #[Api_Param('id', type: 'int', required: true)]
    public static function created(Request $request, array $params = [])
    {
        return Rsx_Api::created(['record' => Api_Plain_Values_Fixture_Model::find($params['id'])]);
    }

    /**
     * A document carrying value objects.
     */
    #[Api_Endpoint('/api/v1/test-probe/plain/document', methods: ['GET'])]
    public static function document(Request $request, array $params = [])
    {
        return Rsx_Api::document([
            'x-total' => Rsx_Money::from_decimal('12'),
            'x-body' => Text_Fixture_Wrapped_Text::from_untrusted_encoded('<p>doc</p>'),
        ]);
    }

    /**
     * Update a record: a 'money' amount and a body written as the type's ENCODED form.
     */
    #[Writer]
    #[Api_Endpoint('/api/v1/test-probe/plain/record/:id/update', methods: ['POST'])]
    #[Api_Param('id', type: 'int', required: true)]
    #[Api_Param('amount', type: 'money')]
    #[Api_Param('body', type: 'string')]
    public static function update(Request $request, array $params = [])
    {
        $record = Api_Plain_Values_Fixture_Model::find($params['id']);
        if (!$record) {
            return Rsx_Api::not_found();
        }

        if (array_key_exists('amount', $params)) {
            $record->amount = $params['amount'];
        }

        if (array_key_exists('body', $params)) {
            $record->body = $params['body'] === null ? null : Text_Fixture_Wrapped_Text::from_untrusted_encoded($params['body']);
        }

        $record->save();

        return $record;
    }
}
