<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Redis;
use App\RSpade\Core\Auth\Auth_Password_Reset_Required_Exception;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * password_reset_required on the STAFF credential verifier, and the flag's lifecycle on both
 * identity models.
 *
 * WHAT IS PINNED:
 *  - RsxAuth::verify_credentials() THROWS Auth_Password_Reset_Required_Exception for a flagged
 *    identity whose password matched, carrying the identity, signing nobody in, and writing a
 *    STATUS_FAILED_PASSWORD_RESET_REQUIRED row - with record: false too (the outcome is final)
 *    and never a SUCCESS row or a failure count;
 *  - it is asked LAST: a wrong password on a flagged identity is still null, and a flagged
 *    identity with no active membership is still null (FAILED_DISABLED), so nothing is
 *    disclosed to somebody who has not proved the password or whom the installation would
 *    refuse anyway;
 *  - the framework clears the flag when the password changes, on login_users and on
 *    portal_users, and not when anything else changes; a save that assigns the flag together
 *    with a new password keeps the flag (an operator's temporary password).
 *
 * The failure counters are redis keys outside the per-test transaction, so each test uses a
 * unique email and deletes the keys it created (as Rsx_Auth_Verify_Credentials_Test does).
 */
class Password_Reset_Required_Test extends Rsx_Test_Abstract
{
    private const PASSWORD = 'correct-horse-battery-staple';

    private static array $counter_keys_used = [];

    public static function teardown()
    {
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        Portal_Session::_testing_reset();
        Session::logout();
        static::__reset_session();

        if (empty(static::$counter_keys_used)) {
            return;
        }

        $redis = new Redis();
        $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 2.0);
        $redis->select(0);

        foreach (static::$counter_keys_used as $key) {
            $redis->del('cache:' . sha1($key));
        }

        $redis->close();
        static::$counter_keys_used = [];
    }

    private static function __fresh_email(string $suffix): string
    {
        $email = 'reset_required_' . $suffix . '_' . uniqid() . '@example.com';
        static::$counter_keys_used[] = 'login_failures:email:' . sha1(strtolower(trim($email)));

        return $email;
    }

    /**
     * A login identity with an enabled membership, optionally flagged.
     */
    private static function __make_login_user(string $email, bool $flagged, bool $enabled = true): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;

        if ($flagged) {
            $login_user->require_password_reset();
        }

        $login_user->save();

        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Fixture';
        $user->last_name = 'Identity';
        $user->is_enabled = $enabled ? 1 : 0;
        $user->save();

        return $login_user;
    }

    private static function __statuses_for(string $email): array
    {
        return DB::table('_login_history')->where('email_attempted', $email)->orderBy('id')->pluck('status')->all();
    }

    private static function __stored_flag(string $table, int $id): int
    {
        return (int) DB::table($table)->where('id', $id)->value('password_reset_required');
    }

    // -------------------------------------------------------------------------
    // The refusal
    // -------------------------------------------------------------------------

    /**
     * prr-01: a flagged identity's correct password throws, carrying the identity, records the
     * refusal row and signs nobody in.
     */
    public static function test_a_correct_password_on_a_flagged_identity_throws_and_is_recorded()
    {
        $email = static::__fresh_email('throws');
        $login_user = static::__make_login_user($email, true);

        $thrown = null;

        try {
            RsxAuth::verify_credentials(['email' => $email, 'password' => self::PASSWORD]);
        } catch (Auth_Password_Reset_Required_Exception $e) {
            $thrown = $e;
        }

        static::__assert_not_null($thrown, 'the refusal is thrown');
        static::__assert_equals(Auth_Password_Reset_Required_Exception::MESSAGE, $thrown->getMessage());
        static::__assert_instance_of(Login_User_Model::class, $thrown->identity);
        static::__assert_equals((int) $login_user->id, (int) $thrown->identity->id, 'carrying the identity');

        static::__assert_equals(
            [Login_History::STATUS_FAILED_PASSWORD_RESET_REQUIRED],
            static::__statuses_for($email),
            'one refusal row and no success row'
        );
        static::__assert_equals(0, Login_History::get_failed_attempts_count($email), 'never a failure count');
        static::__assert_null(Session::get_login_user_id(), 'nobody is signed in');

        $history = Login_History::get_history_for_user((int) $login_user->id);
        static::__assert_equals('Refused - Password Reset Required', $history[0]['status_label'], 'the reader labels it');
    }

    /**
     * prr-02: record: false defers a SUCCESS, never this final outcome - the row is written.
     */
    public static function test_the_refusal_is_recorded_even_with_record_false()
    {
        $email = static::__fresh_email('norecord');
        static::__make_login_user($email, true);

        static::__assert_throws(
            Auth_Password_Reset_Required_Exception::class,
            fn () => RsxAuth::verify_credentials(['email' => $email, 'password' => self::PASSWORD], record: false)
        );

        static::__assert_equals([Login_History::STATUS_FAILED_PASSWORD_RESET_REQUIRED], static::__statuses_for($email));
    }

    /**
     * prr-03: a WRONG password on a flagged identity is an ordinary null - the flag is
     * disclosed only to somebody who proved the password.
     */
    public static function test_a_wrong_password_on_a_flagged_identity_is_still_null()
    {
        $email = static::__fresh_email('wrongpw');
        static::__make_login_user($email, true);

        static::__assert_null(RsxAuth::verify_credentials(['email' => $email, 'password' => 'not-the-password']));
        static::__assert_equals([], static::__statuses_for($email), 'no row');
        static::__assert_equals(1, Login_History::get_failed_attempts_count($email), 'an ordinary failure');
    }

    /**
     * prr-04: a flagged identity the installation refuses anyway (no active membership) is
     * still the indistinguishable null - the membership check comes first.
     */
    public static function test_a_flagged_identity_with_no_active_membership_is_still_null()
    {
        $email = static::__fresh_email('disabled');
        static::__make_login_user($email, true, false);

        static::__assert_null(RsxAuth::verify_credentials(['email' => $email, 'password' => self::PASSWORD]));
        static::__assert_equals([], static::__statuses_for($email), 'no refusal row');
        static::__assert_equals(1, Login_History::get_failed_attempts_count($email), 'counted as the DISABLED failure');
    }

    /**
     * prr-05: an unflagged identity signs in exactly as before.
     */
    public static function test_an_unflagged_identity_is_unaffected()
    {
        $email = static::__fresh_email('unflagged');
        $login_user = static::__make_login_user($email, false);

        $identity = RsxAuth::verify_credentials(['email' => $email, 'password' => self::PASSWORD]);

        static::__assert_equals((int) $login_user->id, (int) $identity->id);
        static::__assert_false($identity->is_password_reset_required());
        static::__assert_equals([Login_History::STATUS_SUCCESS], static::__statuses_for($email));
    }

    // -------------------------------------------------------------------------
    // The flag's lifecycle - login_users
    // -------------------------------------------------------------------------

    /**
     * prr-06: a password change clears the flag, in the same write.
     */
    public static function test_a_password_change_clears_the_staff_flag()
    {
        $login_user = static::__make_login_user(static::__fresh_email('clear'), true);
        static::__assert_equals(1, static::__stored_flag('login_users', (int) $login_user->id));

        $login_user->password = Login_User_Model::hash_password('a-brand-new-password');
        $login_user->save();

        static::__assert_equals(0, static::__stored_flag('login_users', (int) $login_user->id), 'cleared by the framework');
        static::__assert_false($login_user->is_password_reset_required(), 'and on the instance');
    }

    /**
     * prr-07: any other change leaves the flag alone.
     */
    public static function test_another_change_keeps_the_staff_flag()
    {
        $login_user = static::__make_login_user(static::__fresh_email('keep'), true);

        $login_user->timezone = 'America/Chicago';
        $login_user->save();

        static::__assert_equals(1, static::__stored_flag('login_users', (int) $login_user->id));
    }

    /**
     * prr-08: a save that assigns the flag WITH a new password keeps what it assigned - an
     * operator's temporary password that must itself be replaced.
     */
    public static function test_a_new_password_saved_with_the_flag_keeps_the_flag()
    {
        $login_user = static::__make_login_user(static::__fresh_email('temp'), false);

        $login_user->password = Login_User_Model::hash_password('a-temporary-password');
        $login_user->require_password_reset();
        $login_user->save();

        static::__assert_equals(1, static::__stored_flag('login_users', (int) $login_user->id));
    }

    // -------------------------------------------------------------------------
    // The flag's lifecycle - portal_users
    // -------------------------------------------------------------------------

    private static function __make_portal_user(): Portal_User_Model
    {
        $site = new Site_Model();
        $site->slug = 'reset-required-' . uniqid();
        $site->name = 'Reset Required';
        $site->is_enabled = true;
        $site->save();

        Portal_Session::_testing_reset();
        Portal_Session::set_site_id((int) $site->id);
        Rsx_Portal::set_portal_request(true);

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = (int) $site->id;
        $portal_user->email = 'reset_required_portal_' . uniqid() . '@example.com';
        $portal_user->set_password(self::PASSWORD);
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->require_password_reset();
        $portal_user->save();

        return $portal_user;
    }

    /**
     * prr-09: the portal model clears its flag on a password change, and only then.
     */
    public static function test_a_password_change_clears_the_portal_flag_and_nothing_else_does()
    {
        $portal_user = static::__make_portal_user();
        static::__assert_equals(1, static::__stored_flag('portal_users', (int) $portal_user->id), 'set on create');

        $portal_user->status_id = Portal_User_Model::STATUS_INACTIVE;
        $portal_user->save();
        static::__assert_equals(1, static::__stored_flag('portal_users', (int) $portal_user->id), 'another change keeps it');

        $portal_user->set_password('a-brand-new-password');
        $portal_user->save();
        static::__assert_equals(0, static::__stored_flag('portal_users', (int) $portal_user->id), 'a new password clears it');
    }
}
