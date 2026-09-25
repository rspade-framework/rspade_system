<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Ajax\Exceptions\AjaxFormErrorException;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * _Apidocs_Controller::adopt_tester_key - the API console's "use this key" call.
 *
 * Answering "is this a working key" is a key-validity oracle, so the call is refused to an
 * anonymous caller, and a key is accepted only when the API itself would accept it: a key
 * whose holder has lost API access is rejected with the same message as an unknown key.
 * (A rejection also feeds Login_Throttle for the caller's address; a CLI caller has no
 * address and is never throttled, so that half is not observable in process.)
 */
class Api_Tester_Key_Adopt_Test extends Rsx_Test_Abstract
{
    private const USER_ID = 1;

    public static function test_an_anonymous_caller_is_refused()
    {
        static::__reset_session();

        static::__assert_throws(AjaxUnauthorizedException::class, function () {
            Ajax::internal('_Apidocs_Controller', 'adopt_tester_key', ['key' => 'rsx_live_anything']);
        });
    }

    public static function test_a_working_key_is_adopted()
    {
        static::__acting_as_user(self::USER_ID);
        $key = static::__mint(true);

        $result = Ajax::internal('_Apidocs_Controller', 'adopt_tester_key', ['key' => $key]);

        static::__assert_true($result['adopted']);
        Ajax::internal('_Apidocs_Controller', 'forget_tester_key');
    }

    public static function test_a_key_whose_holder_lost_api_access_is_rejected()
    {
        static::__acting_as_user(self::USER_ID);
        $key = static::__mint(false);

        $e = static::__assert_throws(AjaxFormErrorException::class, function () use ($key) {
            Ajax::internal('_Apidocs_Controller', 'adopt_tester_key', ['key' => $key]);
        });
        static::__assert_contains('not valid', json_encode($e->get_details()));
    }

    public static function test_an_unknown_key_is_rejected_with_the_same_message()
    {
        static::__acting_as_user(self::USER_ID);

        $e = static::__assert_throws(AjaxFormErrorException::class, function () {
            Ajax::internal('_Apidocs_Controller', 'adopt_tester_key', ['key' => 'rsx_live_' . str_repeat('x', 32)]);
        });
        static::__assert_contains('not valid', json_encode($e->get_details()));
    }

    /**
     * Mint a key for user 1, leaving the holder's API access as $api_access.
     */
    private static function __mint(bool $api_access): string
    {
        $user = User_Model::without_site_scope(fn () => User_Model::find(self::USER_ID));
        static::__assert_not_null($user, 'the test baseline carries user 1');

        $user->is_api_access_enabled = 1;
        $user->save();

        $key = Api_Key_Model::generate(self::USER_ID, 'Adopt probe (test)')['key'];

        $user->is_api_access_enabled = $api_access ? 1 : 0;
        $user->save();

        return $key;
    }
}
