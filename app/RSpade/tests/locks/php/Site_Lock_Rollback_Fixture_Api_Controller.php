<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Rsx_Api;
use App\RSpade\Core\Api\Rsx_Api_Controller_Abstract;
use App\RSpade\Core\Response\Rollback_Response;
use App\RSpade\Lib\Messaging\Mail\Email_Blocked_Address_Model;

/**
 * External API endpoint for Site_Lock_Hold_Discipline_Test: a writer that writes one framework
 * site-scoped row (_email_blocked_addresses, the key's site) and answers a validation error -
 * returned ('return') or thrown as Rollback_Response ('throw'). Present only while the suite runs.
 */
#[Auth('is_logged_in')]
class Site_Lock_Rollback_Fixture_Api_Controller extends Rsx_Api_Controller_Abstract
{
    #[Writer]
    #[Api_Endpoint('/api/v1/test-probe/site-lock/answer-error', methods: ['POST'])]
    #[Api_Param('email', type: 'string', required: true, description: 'Address to block')]
    #[Api_Param('how', type: 'string', required: true, description: 'return | throw')]
    public static function answer_error(Request $request, array $params = [])
    {
        $row = new Email_Blocked_Address_Model();
        $row->email = $params['email'];
        $row->reason = 'site lock hold discipline test';
        $row->save();

        $answer = Rsx_Api::validation_error(['row_2' => 'Missing a name'], 'Row 2 is invalid');

        if ($params['how'] === 'throw') {
            throw new Rollback_Response($answer);
        }

        return $answer;
    }
}
