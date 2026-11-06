<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Revisions\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Rsx_Api_Controller_Abstract;
use App\RSpade\Core\Models\Email_Blocked_Address_Model;

/**
 * External API endpoints for Request_History_Test: two writers that write one framework
 * site-scoped row each run (_email_blocked_addresses, the key's site) and a reader. Each
 * counts its runs in $runs. Present only while the suite runs.
 */
#[Auth('is_logged_in')]
class Request_History_Fixture_Api_Controller extends Rsx_Api_Controller_Abstract
{
    /** endpoint => times its body ran. */
    public static array $runs = [];

    #[Writer]
    #[Api_Endpoint('/api/v1/test-probe/history/block', methods: ['POST'])]
    #[Api_Param('email', type: 'string', required: true, description: 'Address to block')]
    public static function block(Request $request, array $params = [])
    {
        return static::__block('block', $params['email']);
    }

    #[Writer]
    #[Api_Endpoint('/api/v1/test-probe/history/block-elsewhere', methods: ['POST'])]
    #[Api_Param('email', type: 'string', required: true, description: 'Address to block')]
    public static function block_elsewhere(Request $request, array $params = [])
    {
        return static::__block('block_elsewhere', $params['email']);
    }

    #[Api_Endpoint('/api/v1/test-probe/history/read', methods: ['GET'])]
    public static function read(Request $request, array $params = [])
    {
        static::$runs['read'] = (static::$runs['read'] ?? 0) + 1;

        return ['read' => static::$runs['read'], 'params' => array_keys($params)];
    }

    private static function __block(string $endpoint, string $email): array
    {
        static::$runs[$endpoint] = (static::$runs[$endpoint] ?? 0) + 1;

        $row = new Email_Blocked_Address_Model();
        $row->email = $email;
        $row->reason = 'request history test';
        $row->save();

        return ['email' => $email, 'run' => static::$runs[$endpoint]];
    }
}
