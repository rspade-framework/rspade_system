<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Rsx_Api_Bearer;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Session\Session_Cleanup_Service;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * ONE SESSION ROW PER API KEY (Session::_set_api_identity()).
 *
 * A key's first authenticated call mints its own _sessions row (TYPE_API, api_key_id) with a
 * REALM_API user session for the key's owner; every later call reuses both, and
 * get_session_id() / get_user_session_id() answer them. The call's audit session is keyed on
 * that user session x client address x user agent, so the same key from two addresses is one
 * session row and two audit sessions. The key's use is listed where its owner is signed in,
 * revoking the key signs that user session out, an EXPIRED key signs it out (when presented,
 * or by the hourly cleanup), and deleting the key deletes both rows.
 *
 * Each call is authenticated the way the API dispatcher does it - Rsx_Api_Bearer::authenticate()
 * on a Request carrying the key - with that Request bound as the current request, which is what
 * Session::get_client_ip() reads for an API call answered in-process.
 */
class Api_Key_Session_Test extends Rsx_Test_Abstract
{
    /** The current request the class found, put back when it ends. */
    private static $original_request = null;

    public static function setup()
    {
        Session::_reset_api_identity();
        static::$original_request = app('request');
    }

    public static function teardown()
    {
        Session::_reset_api_identity();
        app()->instance('request', static::$original_request);
    }

    /**
     * A key for user 1, its plaintext and model.
     *
     * @return array{key: string, model: Api_Key_Model}
     */
    private static function __mint_key(): array
    {
        static::__acting_as_user(1);

        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        $user->is_api_access_enabled = 1;
        $user->save();

        return Api_Key_Model::generate(1, 'Key session probe (test)');
    }

    /**
     * Authenticate one call with $key from $ip, as the current request, leaving its identity
     * installed.
     */
    private static function __api_call(string $key, string $ip): void
    {
        Session::_reset_api_identity();

        $request = Request::create('/api/v1/me', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key,
            'REMOTE_ADDR' => $ip,
        ]);

        app()->instance('request', $request);

        $auth = Rsx_Api_Bearer::authenticate($request);

        static::__assert_null($auth['error'], 'the key authenticates');
    }

    public static function test_the_first_call_mints_the_keys_row_and_user_session()
    {
        $minted = static::__mint_key();

        static::__api_call($minted['key'], '10.40.0.1');

        $session_id = Session::get_session_id();
        $row = DB::table('_sessions')->where('id', $session_id)->first();

        static::__assert_not_null($row, 'get_session_id() answers the key\'s row in an API request');
        static::__assert_equals(Session::TYPE_API, (int) $row->type_id);
        static::__assert_equals((int) $minted['model']->id, (int) $row->api_key_id);
        static::__assert_false(Session::has_session(), 'the key\'s row is not a browser session');

        $user_session = DB::table('_user_sessions')->where('id', Session::get_user_session_id())->first();

        static::__assert_not_null($user_session, 'get_user_session_id() answers the key owner\'s user session');
        static::__assert_equals(Session::REALM_API, (int) $user_session->realm_id);
        static::__assert_equals($session_id, (int) $user_session->session_id);
        static::__assert_equals(1, (int) $user_session->login_user_id);
        static::__assert_null($user_session->signed_out_at, 'signed in');
    }

    /**
     * Two calls from two addresses: one session row, one user session, two audit sessions.
     */
    public static function test_two_addresses_are_one_session_and_two_audit_sessions()
    {
        $minted = static::__mint_key();

        static::__api_call($minted['key'], '10.40.0.1');
        $first_session_id = Session::get_session_id();
        $first_user_session_id = Session::get_user_session_id();
        $first_audit = Session::get_audit_session_id();

        static::__api_call($minted['key'], '10.40.0.2');
        $second_audit = Session::get_audit_session_id();

        static::__assert_equals($first_session_id, Session::get_session_id(), 'the second call reuses the key\'s row');
        static::__assert_equals($first_user_session_id, Session::get_user_session_id(), 'and its user session');
        static::__assert_equals(1, DB::table('_sessions')->where('api_key_id', $minted['model']->id)->count(), 'one row per key');
        static::__assert_equals(1, (int) DB::table('_user_sessions')->where('id', $first_user_session_id)->value('sign_in_count'), 'reuse is not a new sign-in');

        static::__assert_not_null($first_audit);
        static::__assert_not_null($second_audit);
        static::__assert_not_equals($first_audit, $second_audit, 'a new address is a new audit session');

        foreach ([$first_audit => '10.40.0.1', $second_audit => '10.40.0.2'] as $audit_session_id => $ip) {
            $described = Session::get_audit_session($audit_session_id);

            static::__assert_equals($ip, $described['ip_address']);
            static::__assert_equals($first_session_id, $described['session_id'], 'keyed on the key\'s row');
            static::__assert_equals($first_user_session_id, $described['user_session_id'], 'and its user session');
            static::__assert_equals(Session::TYPE_API, $described['session_type_id']);
            static::__assert_equals(1, $described['login_user_id'], 'acting as the key\'s owner');
        }

        static::__api_call($minted['key'], '10.40.0.1');
        static::__assert_equals($first_audit, Session::get_audit_session_id(), 'the same address again is the same audit session');
    }

    public static function test_the_keys_use_is_listed_where_its_owner_is_signed_in()
    {
        $minted = static::__mint_key();

        static::__api_call($minted['key'], '10.40.0.1');
        $user_session_id = Session::get_user_session_id();
        Session::_reset_api_identity();

        $entries = array_values(array_filter(Session::get_sessions_for_user(1), fn ($entry) => $entry['id'] === $user_session_id));

        static::__assert_count(1, $entries, 'the key is one entry of its owner\'s sessions');
        static::__assert_true($entries[0]['is_api'], 'marked as an API key\'s use');
        static::__assert_true($entries[0]['is_signed_in']);
    }

    public static function test_revoking_the_key_signs_its_user_session_out()
    {
        $minted = static::__mint_key();

        static::__api_call($minted['key'], '10.40.0.1');
        $user_session_id = Session::get_user_session_id();
        Session::_reset_api_identity();

        $minted['model']->revoke();

        $user_session = DB::table('_user_sessions')->where('id', $user_session_id)->first();

        static::__assert_not_null($user_session->signed_out_at, 'the key\'s user session is signed out');
        static::__assert_equals(Session::SIGN_OUT_API_KEY_REVOKED, (int) $user_session->sign_out_reason_id);
    }

    /**
     * A key past its expires_at is refused at authentication, and the user session it was
     * signed in through is signed out there and then: SIGN_OUT_API_KEY_EXPIRED.
     */
    public static function test_presenting_an_expired_key_signs_its_user_session_out()
    {
        $minted = static::__mint_key();

        static::__api_call($minted['key'], '10.40.0.1');
        $user_session_id = Session::get_user_session_id();
        Session::_reset_api_identity();

        DB::table('_api_keys')->where('id', $minted['model']->id)->update(['expires_at' => now()->subMinute()->format('Y-m-d H:i:s.v')]);

        $request = Request::create('/api/v1/me', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $minted['key'], 'REMOTE_ADDR' => '10.40.0.1']);
        app()->instance('request', $request);
        $auth = Rsx_Api_Bearer::authenticate($request);

        static::__assert_not_null($auth['error'], 'the expired key is refused');

        $user_session = DB::table('_user_sessions')->where('id', $user_session_id)->first();

        static::__assert_not_null($user_session->signed_out_at, 'its user session is signed out');
        static::__assert_equals(Session::SIGN_OUT_API_KEY_EXPIRED, (int) $user_session->sign_out_reason_id);
        static::__assert_equals('API key expired', Session::SIGN_OUT_REASON_LABELS[Session::SIGN_OUT_API_KEY_EXPIRED]);
    }

    /**
     * A key that expires and is never presented again is signed out by the hourly
     * cleanup_sessions sweep; a live key's user session is untouched by it.
     */
    public static function test_the_hourly_sweep_signs_out_an_expired_keys_user_session()
    {
        $expiring = static::__mint_key();
        static::__api_call($expiring['key'], '10.40.0.1');
        $expiring_user_session_id = Session::get_user_session_id();

        $live = static::__mint_key();
        static::__api_call($live['key'], '10.40.0.1');
        $live_user_session_id = Session::get_user_session_id();
        Session::_reset_api_identity();

        DB::table('_api_keys')->where('id', $expiring['model']->id)->update(['expires_at' => now()->subMinute()->format('Y-m-d H:i:s.v')]);

        $state = static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');

        $expired = DB::table('_user_sessions')->where('id', $expiring_user_session_id)->first();

        static::__assert_true($state['api_key_expired_signed_out'] >= 1, 'the sweep reports what it signed out');
        static::__assert_equals(Session::SIGN_OUT_API_KEY_EXPIRED, (int) $expired->sign_out_reason_id, 'the expired key is signed out');
        static::__assert_null(DB::table('_user_sessions')->where('id', $live_user_session_id)->value('signed_out_at'), 'the live key is not');
    }

    public static function test_deleting_the_key_deletes_its_row_and_user_session()
    {
        $minted = static::__mint_key();

        static::__api_call($minted['key'], '10.40.0.1');
        $session_id = Session::get_session_id();
        $user_session_id = Session::get_user_session_id();
        Session::_reset_api_identity();

        DB::table('_api_keys')->where('id', $minted['model']->id)->delete();

        static::__assert_equals(0, DB::table('_sessions')->where('id', $session_id)->count(), 'the row goes with the key');
        static::__assert_equals(0, DB::table('_user_sessions')->where('id', $user_session_id)->count(), 'and its user session');
    }
}
