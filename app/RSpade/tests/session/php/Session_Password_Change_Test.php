<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A PASSWORD CHANGE SIGNS THE IDENTITY OUT EVERYWHERE ELSE.
 *
 * The consequence lives in the identity model's own `updated` hook
 * (Login_User_Model_Abstract / Portal_User_Model_Abstract booted() ->
 * Session::_end_sessions_for_password_change()), so the tests change the password the way
 * every caller does - assign a new hash, save the model - and read the user sessions back:
 *
 *   - every other signed-in web sign-in of the identity ends, as SIGN_OUT_PASSWORD_CHANGED;
 *   - the browser making the change (Session::$_browser_session_id_for_tests) stays signed in;
 *   - the identity's API key session (REALM_API) is never touched;
 *   - with no browser (a command, a task) every web sign-in of the identity ends;
 *   - another identity's sessions, and a save that does not change the password, end nothing.
 *
 * Rows are inserted directly (__insert_session_fixture(), plus a raw REALM_API user session
 * on a TYPE_API row) - the termination contract does not involve cookies or tokens.
 *
 * Default per-test transaction: every write rolls back.
 */
class Session_Password_Change_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function teardown(): void
    {
        Session::$_browser_session_id_for_tests = null;
        static::__reset_session();
    }

    private static function __make_login_user(string $tag): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'password_change_' . $tag . '_' . uniqid() . '@example.com';
        $login_user->password = Login_User_Model::hash_password('the-old-password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    /**
     * A browser signed in as $login_user_id; answers the fixture (id = browser, staff_user_session_id).
     */
    private static function __web_session(int $login_user_id): array
    {
        return static::__insert_session_fixture([
            'staff' => ['login_user_id' => $login_user_id, 'site_id' => self::SITE_ID],
        ]);
    }

    /**
     * An API key's session for $login_user_id: a TYPE_API row carrying a signed-in REALM_API
     * user session, the shape Session::_set_api_identity() mints. Answers the user session id.
     */
    private static function __api_session(int $login_user_id): int
    {
        $browser = static::__insert_session_fixture(['type_id' => Session::TYPE_API]);
        $identity = ['login_user_id' => $login_user_id, 'site_id' => self::SITE_ID, 'impersonator_login_user_id' => null];
        $now = now()->format('Y-m-d H:i:s.v');

        return (int) DB::table('_user_sessions')->insertGetId(array_merge($identity, [
            'session_id' => $browser['id'],
            'realm_id' => Session::REALM_API,
            'tuple_hash' => Session::_user_session_tuple_hash($browser['id'], Session::REALM_API, $identity),
            'first_signed_in_at' => $now,
            'signed_in_at' => $now,
            'started_at' => $now,
            'last_page_view_at' => $now,
            'last_active_at' => $now,
        ]));
    }

    private static function __change_password(Login_User_Model $login_user, string $password): void
    {
        $login_user->password = Login_User_Model::hash_password($password);
        $login_user->save();
    }

    private static function __is_signed_in(int $user_session_id): bool
    {
        return DB::table('_user_sessions')->where('id', $user_session_id)->value('signed_out_at') === null;
    }

    private static function __reason(int $user_session_id): ?int
    {
        $reason = DB::table('_user_sessions')->where('id', $user_session_id)->value('sign_out_reason_id');

        return $reason === null ? null : (int) $reason;
    }

    /**
     * spc-01: changed from one browser - the other web sign-in ends with the reason, the
     * changing browser and the API key session stay signed in.
     */
    public static function test_a_change_from_a_browser_ends_the_other_web_sessions_only()
    {
        $login_user = static::__make_login_user('browser');

        $changing = static::__web_session((int) $login_user->id);
        $other = static::__web_session((int) $login_user->id);
        $api = static::__api_session((int) $login_user->id);

        Session::$_browser_session_id_for_tests = $changing['id'];

        static::__change_password($login_user, 'the-new-password');

        static::__assert_true(static::__is_signed_in($changing['staff_user_session_id']), 'the browser that changed it stays signed in');
        static::__assert_false(static::__is_signed_in($other['staff_user_session_id']), 'the other browser is signed out');
        static::__assert_equals(Session::SIGN_OUT_PASSWORD_CHANGED, static::__reason($other['staff_user_session_id']));
        static::__assert_equals(
            null,
            DB::table('_sessions')->where('id', $other['id'])->value('staff_user_session_id'),
            'the other browser no longer points at a staff sign-in'
        );
        static::__assert_true(static::__is_signed_in($api), 'the API key session is left alone');
        static::__assert_equals('Password changed', Session::sign_out_reason_label(Session::SIGN_OUT_PASSWORD_CHANGED));
    }

    /**
     * spc-02: with no browser (a command, a task) every web sign-in of the identity ends;
     * the API key session still stays.
     */
    public static function test_a_change_with_no_browser_ends_every_web_session()
    {
        $login_user = static::__make_login_user('cli');

        $first = static::__web_session((int) $login_user->id);
        $second = static::__web_session((int) $login_user->id);
        $api = static::__api_session((int) $login_user->id);

        Session::$_browser_session_id_for_tests = null;

        static::__change_password($login_user, 'set-by-a-command');

        static::__assert_false(static::__is_signed_in($first['staff_user_session_id']));
        static::__assert_false(static::__is_signed_in($second['staff_user_session_id']));
        static::__assert_equals(Session::SIGN_OUT_PASSWORD_CHANGED, static::__reason($first['staff_user_session_id']));
        static::__assert_true(static::__is_signed_in($api), 'the API key session is left alone');
    }

    /**
     * spc-03: an impersonation OF the identity on another browser ends with it; another
     * identity's sign-in, and a save that leaves the password alone, end nothing.
     */
    public static function test_only_the_identitys_sessions_end_and_only_on_a_password_change()
    {
        $login_user = static::__make_login_user('target');
        $bystander = static::__make_login_user('bystander');

        $own = static::__web_session((int) $login_user->id);
        $impersonation = static::__insert_session_fixture([
            'staff' => [
                'login_user_id' => (int) $login_user->id,
                'site_id' => self::SITE_ID,
                'impersonator_login_user_id' => (int) $bystander->id,
            ],
        ]);
        $other_identity = static::__web_session((int) $bystander->id);

        $login_user->is_verified = false;
        $login_user->save();

        static::__assert_true(static::__is_signed_in($own['staff_user_session_id']), 'a save without a new password ends nothing');

        static::__change_password($login_user, 'the-new-password');

        static::__assert_false(static::__is_signed_in($own['staff_user_session_id']));
        static::__assert_false(static::__is_signed_in($impersonation['staff_user_session_id']), 'an impersonation of the identity ends');
        static::__assert_true(static::__is_signed_in($other_identity['staff_user_session_id']), 'another identity keeps its sign-in');
    }

    /**
     * spc-04: the portal twin - a portal user's password change ends its other portal
     * sign-ins, spares the changing browser, and leaves a staff sign-in on the same browser.
     */
    public static function test_a_portal_password_change_ends_the_other_portal_sessions()
    {
        static::__acting_as_site(self::SITE_ID);

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = self::SITE_ID;
        $portal_user->email = 'password_change_portal_' . uniqid() . '@example.com';
        $portal_user->set_password('the-old-password');
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        $staff = static::__make_login_user('portal_staff');

        $changing = static::__insert_session_fixture([
            'portal' => ['portal_user_id' => (int) $portal_user->id, 'portal_site_id' => self::SITE_ID],
        ]);
        $other = static::__insert_session_fixture([
            'portal' => ['portal_user_id' => (int) $portal_user->id, 'portal_site_id' => self::SITE_ID],
            'staff' => ['login_user_id' => (int) $staff->id, 'site_id' => self::SITE_ID],
        ]);

        Session::$_browser_session_id_for_tests = $changing['id'];

        $portal_user->set_password('the-new-password');
        $portal_user->save();

        static::__assert_true(static::__is_signed_in($changing['portal_user_session_id']), 'the changing browser stays signed in');
        static::__assert_false(static::__is_signed_in($other['portal_user_session_id']), 'the other portal sign-in ends');
        static::__assert_equals(Session::SIGN_OUT_PASSWORD_CHANGED, static::__reason($other['portal_user_session_id']));
        static::__assert_true(static::__is_signed_in($other['staff_user_session_id']), 'a staff sign-in on that browser is another realm');
    }
}
