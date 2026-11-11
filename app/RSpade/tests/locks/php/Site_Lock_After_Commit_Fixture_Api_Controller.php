<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Rsx_Api_Controller_Abstract;
use App\RSpade\Tests\Locks\Php\Site_Lock_After_Commit_Fixture_Controller;

/**
 * External API writer for Site_Lock_After_Commit_Test: writes one framework site-scoped row (the
 * key's site) and registers the fixture's two after-commit callbacks, the first throwing.
 * Present only while the suite runs.
 */
#[Auth('is_logged_in')]
class Site_Lock_After_Commit_Fixture_Api_Controller extends Rsx_Api_Controller_Abstract
{
    #[Writer]
    #[Api_Endpoint('/api/v1/test-probe/site-lock/after-commit-throws', methods: ['POST'])]
    #[Api_Param('email', type: 'string', required: true, description: 'Address to block')]
    public static function after_commit_throws(Request $request, array $params = [])
    {
        Site_Lock_After_Commit_Fixture_Controller::block($params['email']);
        Site_Lock_After_Commit_Fixture_Controller::register_callbacks();

        return ['email' => $params['email']];
    }
}
