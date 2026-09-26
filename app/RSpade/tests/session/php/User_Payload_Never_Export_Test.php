<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A user's invitation columns never leave the server.
 *
 * invite_code is a bearer credential for claiming a pending account, so it - and the two
 * invite dates that travel with it - is in User_Model_Abstract::$neverExport. toArray() is
 * the one serializer behind window.rsxapp.user, model fetch, relationship and list payloads
 * and the external API, so asserting on it covers every channel.
 */
class User_Payload_Never_Export_Test extends Rsx_Test_Abstract
{
    private const INVITE_COLUMNS = ['invite_code', 'invite_accepted_at', 'invite_expires_at'];

    public static function test_to_array_omits_the_invite_columns()
    {
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        static::__assert_not_null($user, 'the test baseline has user 1');

        $user->invite_code = 'probe-invite-code-' . uniqid();
        $user->invite_expires_at = '2030-01-01 00:00:00';

        $payload = $user->toArray();

        foreach (self::INVITE_COLUMNS as $column) {
            static::__assert_false(array_key_exists($column, $payload), "{$column} is not in the payload");
        }

        static::__assert_true(array_key_exists('id', $payload), 'the rest of the record is still serialized');
    }

    public static function test_the_json_encoding_carries_no_invite_code()
    {
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        $user->invite_code = 'probe-invite-code-json';

        static::__assert_false(
            str_contains(json_encode($user), 'probe-invite-code-json'),
            'json_encode (how window.rsxapp.user is printed) does not carry the code'
        );
    }
}
