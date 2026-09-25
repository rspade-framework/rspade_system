<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Portal\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Fixture for Portal_Impersonation_Read_Only_Test: two portal-realm Ajax endpoints, one
 * marked #[Portal_Impersonation_Readable] and one not. Each records that it ran.
 */
#[Auth('public')]
#[Auth_Realm('portal')]
class Portal_Impersonation_Fixture_Controller extends Rsx_Controller_Abstract
{
    public static int $reads = 0;

    public static int $writes = 0;

    #[Ajax_Endpoint]
    #[Portal_Impersonation_Readable]
    public static function read(Request $request, array $params = [])
    {
        static::$reads++;

        return ['ok' => true];
    }

    #[Ajax_Endpoint]
    public static function write(Request $request, array $params = [])
    {
        static::$writes++;

        return ['ok' => true];
    }
}
