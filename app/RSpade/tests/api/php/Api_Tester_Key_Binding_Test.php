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
use App\RSpade\Core\Session\Rsx_Session_Cookie;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * AN /apidocs TESTER KEY IS BOUND TO THE BROWSER THAT MINTED IT
 * (Api_Key_Model_Abstract::generate_tester_key(), Rsx_Api_Bearer::authenticate()).
 *
 * The key authenticates only on a request carrying the minting browser's session cookie while
 * that browser is signed in as the key's user; with no cookie, another browser's cookie, the
 * minting browser signed out or signed in as somebody else, or past its expiry, it is the
 * uniform 401 an invalid key gets. The cookie only ever REFUSES: an ordinary key (and a CLI
 * temporary key) works bearer-only exactly as before, and a cookie with no key is still
 * auth_required.
 *
 * Each call is authenticated the way the API dispatcher does it - Rsx_Api_Bearer::authenticate()
 * on a Request carrying the key and, where stated, the browser's cookie.
 */
class Api_Tester_Key_Binding_Test extends Rsx_Test_Abstract
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
     * User 1 with API access on, and its users row.
     */
    private static function __holder(): User_Model
    {
        static::__acting_as_user(1);

        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        $user->is_api_access_enabled = 1;
        $user->save();

        return $user;
    }

    /**
     * A browser row signed in (staff) as $login_user_id on $site_id: {id, token}.
     */
    private static function __browser(int $login_user_id, int $site_id, array $staff = []): array
    {
        return static::__insert_session_fixture([
            'staff' => ['login_user_id' => $login_user_id, 'site_id' => $site_id] + $staff,
        ]);
    }

    /**
     * Authenticate one API call with $key, carrying $cookie_token as the session cookie when
     * given. Answers the error code, or null when the key authenticated.
     */
    private static function __present(?string $key, ?string $cookie_token): ?string
    {
        Session::_reset_api_identity();

        $server = ['REMOTE_ADDR' => '10.41.0.1', 'HTTPS' => 'on'];
        if ($key !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
        }

        $request = Request::create('https://localhost/api/v1/me', 'GET', [], [], [], $server);
        app()->instance('request', $request);

        if ($cookie_token !== null) {
            $request->cookies->set(Rsx_Session_Cookie::name(), $cookie_token);
        }

        $auth = Rsx_Api_Bearer::authenticate($request);

        return $auth['error'] === null ? null : $auth['error'][0];
    }

    public static function test_a_tester_key_is_marked_and_bound_to_its_minting_browser()
    {
        $user = static::__holder();
        $browser = static::__browser((int) $user->login_user_id, (int) $user->site_id);

        $minted = Api_Key_Model::generate_tester_key((int) $user->id, $browser['id'], now()->addHour());

        static::__assert_true($minted['model']->is_tester_key());
        static::__assert_equals($browser['id'], (int) $minted['model']->tester_session_id);
        static::__assert_false((bool) $minted['model']->is_temporary, 'a tester key is not a CLI temporary key');
        static::__assert_equals(Api_Key_Model::TESTER_KEY_NAME, $minted['model']->name);
    }

    public static function test_it_works_from_the_minting_browser_signed_in_as_its_user()
    {
        $user = static::__holder();
        $browser = static::__browser((int) $user->login_user_id, (int) $user->site_id);
        $minted = Api_Key_Model::generate_tester_key((int) $user->id, $browser['id'], now()->addHour());

        static::__assert_null(static::__present($minted['key'], $browser['token']), 'the minting browser\'s cookie admits the key');
        static::__assert_equals((int) $user->id, (int) Session::get_user_id(), 'the identity is the key\'s user');
        static::__assert_true(Session::is_api_request(), 'an API identity, not the browser\'s');
        static::__assert_not_equals($browser['id'], Session::get_session_id(), 'the request runs on the key\'s own row, never the browser');
    }

    public static function test_it_is_refused_with_no_cookie()
    {
        $user = static::__holder();
        $browser = static::__browser((int) $user->login_user_id, (int) $user->site_id);
        $minted = Api_Key_Model::generate_tester_key((int) $user->id, $browser['id'], now()->addHour());

        static::__assert_equals('unauthorized', static::__present($minted['key'], null));
        static::__assert_false(Session::is_api_request(), 'no identity was established');
    }

    public static function test_it_is_refused_from_another_browser_of_the_same_user()
    {
        $user = static::__holder();
        $minting = static::__browser((int) $user->login_user_id, (int) $user->site_id);
        $other = static::__browser((int) $user->login_user_id, (int) $user->site_id);
        $minted = Api_Key_Model::generate_tester_key((int) $user->id, $minting['id'], now()->addHour());

        static::__assert_equals('unauthorized', static::__present($minted['key'], $other['token']));
        static::__assert_equals('unauthorized', static::__present($minted['key'], bin2hex(random_bytes(32))), 'a cookie naming no browser');
    }

    /**
     * The minting browser now signed in as somebody else - another identity, or the same
     * identity on another site - or signed out.
     */
    public static function test_it_is_refused_when_its_browser_is_signed_in_as_another_user_or_signed_out()
    {
        $user = static::__holder();

        $other_identity = static::__browser((int) $user->login_user_id + 100000, (int) $user->site_id);
        $minted = Api_Key_Model::generate_tester_key((int) $user->id, $other_identity['id'], now()->addHour());
        static::__assert_equals('unauthorized', static::__present($minted['key'], $other_identity['token']), 'another identity on the browser');

        $other_site = static::__browser((int) $user->login_user_id, (int) $user->site_id + 100000);
        $minted = Api_Key_Model::generate_tester_key((int) $user->id, $other_site['id'], now()->addHour());
        static::__assert_equals('unauthorized', static::__present($minted['key'], $other_site['token']), 'the same identity on another site');

        $signed_out = static::__browser((int) $user->login_user_id, (int) $user->site_id, [
            'signed_out_at' => now()->subMinute(),
            'sign_out_reason_id' => Session::SIGN_OUT_SIGNED_OUT,
        ]);
        $minted = Api_Key_Model::generate_tester_key((int) $user->id, $signed_out['id'], now()->addHour());
        static::__assert_equals('unauthorized', static::__present($minted['key'], $signed_out['token']), 'the browser signed out');
    }

    public static function test_an_expired_tester_key_is_refused_even_from_its_browser()
    {
        $user = static::__holder();
        $browser = static::__browser((int) $user->login_user_id, (int) $user->site_id);
        $minted = Api_Key_Model::generate_tester_key((int) $user->id, $browser['id'], now()->addHour());

        DB::table('_api_keys')->where('id', $minted['model']->id)->update(['expires_at' => now()->subMinute()->format('Y-m-d H:i:s.v')]);

        static::__assert_equals('unauthorized', static::__present($minted['key'], $browser['token']));
    }

    /**
     * rsx:api:key:temp's key, and any ordinary key, is bearer-only: no browser required, and a
     * browser's cookie beside it changes nothing.
     */
    public static function test_a_cli_temporary_key_still_works_bearer_only()
    {
        $user = static::__holder();
        $minted = Api_Key_Model::generate((int) $user->id, 'Temporary (CLI)', 'live', null, now()->addHour(), null, false, true);

        static::__assert_false($minted['model']->is_tester_key());
        static::__assert_null(static::__present($minted['key'], null), 'no cookie needed');

        $somebody_else = static::__browser((int) $user->login_user_id + 100000, (int) $user->site_id);
        static::__assert_null(static::__present($minted['key'], $somebody_else['token']), 'an unrelated cookie is ignored');
    }

    /**
     * The cookie alone never authorizes an API call.
     */
    public static function test_a_signed_in_cookie_with_no_key_is_still_auth_required()
    {
        $user = static::__holder();
        $browser = static::__browser((int) $user->login_user_id, (int) $user->site_id);

        static::__assert_equals('auth_required', static::__present(null, $browser['token']));
        static::__assert_false(Session::is_api_request());
    }
}
