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
use App\RSpade\Core\Models\Email_Blocked_Address_Model;

/**
 * API endpoints answering every shape the response contract rules on, for
 * Api_Response_Contract_Test: the non-object answers the dispatcher refuses (null, a list,
 * an empty array, a scalar, a collection, a JSON list response), the object it accepts, a
 * non-JSON download it passes through, a document it sends as written, a fault, a declared param to provoke a 422, a path
 * introduced in v2, and a writer to record and replay. Present only while the suite runs (the test tree enters the
 * manifest then).
 */
#[Auth('is_logged_in')]
class Api_Response_Contract_Fixture_Api_Controller extends Rsx_Api_Controller_Abstract
{
    /**
     * Answer null.
     */
    #[Api_Endpoint('/api/v1/test-probe/contract/null', methods: ['GET'])]
    public static function answer_null(Request $request, array $params = [])
    {
        return null;
    }

    /**
     * Answer a bare list.
     */
    #[Api_Endpoint('/api/v1/test-probe/contract/list', methods: ['GET'])]
    public static function answer_list(Request $request, array $params = [])
    {
        return [1, 2, 3];
    }

    /**
     * Answer an empty array (encodes as a JSON list).
     */
    #[Api_Endpoint('/api/v1/test-probe/contract/empty', methods: ['GET'])]
    public static function answer_empty(Request $request, array $params = [])
    {
        return [];
    }

    /**
     * Answer a scalar.
     */
    #[Api_Endpoint('/api/v1/test-probe/contract/scalar', methods: ['GET'])]
    public static function answer_scalar(Request $request, array $params = [])
    {
        return 'a bare string';
    }

    /**
     * Answer a collection (a list).
     */
    #[Api_Endpoint('/api/v1/test-probe/contract/collection', methods: ['GET'])]
    public static function answer_collection(Request $request, array $params = [])
    {
        return collect([['id' => 1], ['id' => 2]]);
    }

    /**
     * Answer a built JSON response whose body is a list.
     */
    #[Api_Endpoint('/api/v1/test-probe/contract/json-list', methods: ['GET'])]
    public static function answer_json_list(Request $request, array $params = [])
    {
        return response()->json([1, 2]);
    }

    /**
     * Answer an object.
     */
    #[Api_Endpoint('/api/v1/test-probe/contract/object', methods: ['GET'])]
    #[Api_Param('n', type: 'int', required: false, description: 'Echoed back')]
    public static function answer_object(Request $request, array $params = [])
    {
        return ['ok' => true, 'items' => [1, 2], 'n' => $params['n'] ?? null];
    }

    /**
     * Answer a required int param (a 422 when it is missing).
     */
    #[Api_Endpoint('/api/v1/test-probe/contract/needs-int', methods: ['GET'])]
    #[Api_Param('n', type: 'int', required: true, description: 'Required')]
    public static function needs_int(Request $request, array $params = [])
    {
        return ['n' => $params['n']];
    }

    /**
     * Answer a non-JSON download.
     */
    #[Api_Endpoint('/api/v1/test-probe/contract/download', methods: ['GET'])]
    public static function download(Request $request, array $params = [])
    {
        return response("a,b\n1,2\n", 200, ['Content-Type' => 'text/csv']);
    }

    /**
     * Answer a document (Rsx_Api::document()): sent exactly as given, never stamped.
     */
    #[Api_Endpoint('/api/v1/test-probe/contract/document', methods: ['GET'])]
    public static function document(Request $request, array $params = [])
    {
        return Rsx_Api::document(['openapi' => '3.1.0', 'x-probe' => true]);
    }

    /**
     * Fail with an uncoded exception (the channel's 500).
     */
    #[Api_Endpoint('/api/v1/test-probe/contract/fault', methods: ['GET'])]
    public static function fault(Request $request, array $params = [])
    {
        throw new \RuntimeException('Contract probe fault');
    }

    /**
     * A path introduced in v2 (v1 does not serve it).
     */
    #[Api_Endpoint('/api/v2/test-probe/contract/items/:id/extra', methods: ['GET'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Item id')]
    public static function v2_extra(Request $request, array $params = [])
    {
        return ['id' => $params['id']];
    }

    /**
     * Write one row and answer an object (records itself; replays by _request_ref).
     */
    #[Writer]
    #[Api_Endpoint('/api/v1/test-probe/contract/write', methods: ['POST'])]
    #[Api_Param('email', type: 'string', required: true, description: 'Address to block')]
    public static function write(Request $request, array $params = [])
    {
        $row = new Email_Blocked_Address_Model();
        $row->email = $params['email'];
        $row->reason = 'api response contract test';
        $row->save();

        return ['email' => $params['email']];
    }
}
