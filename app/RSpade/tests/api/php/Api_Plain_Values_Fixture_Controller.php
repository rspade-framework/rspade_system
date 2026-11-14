<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Tests\Api\Php\Api_Plain_Values_Fixture_Model;

/**
 * The browser Ajax side of Api_Plain_Values_Test: the same record the API answers with plain
 * strings, answered over Ajax, where it keeps its {__MONEY} / {__TEXT} envelopes.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth].
 */
class Api_Plain_Values_Fixture_Controller extends Rsx_Controller_Abstract
{
    /** One record, as the Ajax channel answers it. */
    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function record(Request $request, array $params = [])
    {
        return ['record' => Api_Plain_Values_Fixture_Model::find($params['id'])];
    }
}
