<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Portal\Php;

use Illuminate\Support\Facades\DB;
use Redis;
use App\RSpade\Core\Auth\Auth_Password_Reset_Required_Exception;
use App\RSpade\Core\Auth\Portal_Auth;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Portal_Login_History;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Portal_Auth::verify_credentials() - the client portal's credential front door.
 *
 * WHAT IS PINNED:
 *  - correct credentials answer the portal user, sign nobody in, and write ONE SUCCESS row to
 *    _portal_login_history carrying the portal user's site; record: false writes nothing;
 *  - an unknown address and a wrong password are both null, counted in the PORTAL counters
 *    (never the staff ones) and written as no row;
 *  - the lookup reads exactly the site it is given: a portal user of another site is
 *    NOT_FOUND, whatever tenant the process is scoped to;
 *  - a portal user flagged password_reset_required THROWS once the password matched, with a
 *    refusal row (record: false included), and a wrong password on it is an ordinary null;
 *  - malformed input records nothing.
 *
 * Every test runs as a PORTAL REQUEST declaring its site, the context the verifier serves in
 * production. Failure counters are redis keys outside the transaction: each test uses a
 * unique email and deletes its keys in teardown.
 */
class Portal_Auth_Test extends Rsx_Test_Abstract
{
    private const PASSWORD = 'correct-horse-battery-staple';

    private static array $counter_keys_used = [];

    public static function teardown(): void
    {
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        Portal_Session::_testing_reset();
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

    private static function __make_site(string $tag): int
    {
        $site = new Site_Model();
        $site->slug = 'portal-auth-' . $tag . '-' . uniqid();
        $site->name = 'Portal Auth ' . $tag;
        $site->is_enabled = true;
        $site->save();

        return (int) $site->id;
    }

    private static function __as_portal(int $site_id): void
    {
        Portal_Session::_testing_reset();
        Portal_Session::set_site_id($site_id);
        Rsx_Portal::set_portal_request(true);
        Portal_Session::cli_set_portal_user_id(0);
    }

    private static function __fresh_email(string $suffix): string
    {
        $email = 'portal_auth_' . $suffix . '_' . uniqid() . '@example.com';
        static::$counter_keys_used[] = 'portal_login_failures:email:' . sha1($email);
        static::$counter_keys_used[] = 'login_failures:email:' . sha1($email);

        return $email;
    }

    private static function __make_portal_user(int $site_id, string $email, bool $flagged = false): Portal_User_Model
    {
        static::__as_portal($site_id);

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = $site_id;
        $portal_user->email = $email;
        $portal_user->set_password(self::PASSWORD);
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;

        if ($flagged) {
            $portal_user->require_password_reset();
        }

        $portal_user->save();

        return $portal_user;
    }

    private static function __statuses_for(string $email): array
    {
        return DB::table('_portal_login_history')->where('email_attempted', $email)->orderBy('id')->pluck('status')->all();
    }

    /**
     * pa-01: the success path - the portal user, one SUCCESS row with its site, nobody signed in.
     */
    public static function test_correct_credentials_answer_the_portal_user_and_record_one_row()
    {
        $site_id = static::__make_site('ok');
        $email = static::__fresh_email('ok');
        $portal_user = static::__make_portal_user($site_id, $email);

        $verified = Portal_Auth::verify_credentials($site_id, ['email' => $email, 'password' => self::PASSWORD]);

        static::__assert_instance_of(Portal_User_Model::class, $verified);
        static::__assert_equals((int) $portal_user->id, (int) $verified->id);
        static::__assert_false(Portal_Session::is_logged_in(), 'verifying signs nobody in');

        $rows = DB::table('_portal_login_history')->where('email_attempted', $email)->get();
        static::__assert_equals(1, $rows->count(), 'exactly one row');
        static::__assert_equals(Portal_Login_History::STATUS_SUCCESS, $rows[0]->status);
        static::__assert_equals((int) $portal_user->id, (int) $rows[0]->portal_user_id);
        static::__assert_equals($site_id, (int) $rows[0]->site_id, 'the row carries the portal user\'s site');
        static::__assert_equals(0, DB::table('_login_history')->where('email_attempted', $email)->count(), 'never a staff row');
    }

    /**
     * pa-02: record: false answers the portal user and writes nothing.
     */
    public static function test_record_false_writes_nothing()
    {
        $site_id = static::__make_site('norecord');
        $email = static::__fresh_email('norecord');
        static::__make_portal_user($site_id, $email);

        static::__assert_not_null(Portal_Auth::verify_credentials($site_id, ['email' => $email, 'password' => self::PASSWORD], record: false));
        static::__assert_null(Portal_Auth::verify_credentials($site_id, ['email' => $email, 'password' => 'nope'], record: false));

        static::__assert_equals([], static::__statuses_for($email));
        static::__assert_equals(0, Portal_Login_History::get_failed_attempts_count($email));
    }

    /**
     * pa-03: unknown address and wrong password are the same null, counted in the PORTAL
     * statistics and never the staff ones, and no row.
     */
    public static function test_unknown_address_and_wrong_password_are_null_and_counted()
    {
        $site_id = static::__make_site('fail');
        $email = static::__fresh_email('fail');
        static::__make_portal_user($site_id, $email);
        $stranger = static::__fresh_email('stranger');

        static::__assert_null(Portal_Auth::verify_credentials($site_id, ['email' => $email, 'password' => 'not-the-password']));
        static::__assert_null(Portal_Auth::verify_credentials($site_id, ['email' => $stranger, 'password' => self::PASSWORD]));

        static::__assert_equals(1, Portal_Login_History::get_failed_attempts_count($email), 'the wrong password is counted');
        static::__assert_equals(1, Portal_Login_History::get_failed_attempts_count($stranger), 'the unknown address is counted');
        static::__assert_equals(0, Login_History::get_failed_attempts_count($email), 'in the portal statistics only');
        static::__assert_equals([], static::__statuses_for($email), 'failures are never rows');
    }

    /**
     * pa-04: the verifier reads exactly the site it is given.
     */
    public static function test_a_portal_user_of_another_site_is_not_found()
    {
        $home = static::__make_site('home');
        $other = static::__make_site('other');
        $email = static::__fresh_email('foreign');
        static::__make_portal_user($other, $email);

        static::__as_portal($home);

        static::__assert_null(
            Portal_Auth::verify_credentials($home, ['email' => $email, 'password' => self::PASSWORD]),
            'the right password for another tenant\'s account proves nothing here'
        );
        static::__assert_not_null(
            Portal_Auth::verify_credentials($other, ['email' => $email, 'password' => self::PASSWORD], record: false),
            'and the site argument wins over the declared site'
        );
    }

    /**
     * pa-05: a flagged portal user's correct password throws, carrying the portal user, with a
     * refusal row even under record: false; a wrong password on it is an ordinary null.
     */
    public static function test_a_flagged_portal_user_is_refused_only_after_the_password()
    {
        $site_id = static::__make_site('flagged');
        $email = static::__fresh_email('flagged');
        $portal_user = static::__make_portal_user($site_id, $email, true);

        static::__assert_null(Portal_Auth::verify_credentials($site_id, ['email' => $email, 'password' => 'not-the-password']));
        static::__assert_equals([], static::__statuses_for($email), 'a wrong password discloses nothing');

        $thrown = null;

        try {
            Portal_Auth::verify_credentials($site_id, ['email' => $email, 'password' => self::PASSWORD], record: false);
        } catch (Auth_Password_Reset_Required_Exception $e) {
            $thrown = $e;
        }

        static::__assert_not_null($thrown, 'the refusal is thrown');
        static::__assert_equals((int) $portal_user->id, (int) $thrown->identity->id, 'carrying the portal user');
        static::__assert_equals(
            [Portal_Login_History::STATUS_FAILED_PASSWORD_RESET_REQUIRED],
            static::__statuses_for($email),
            'recorded as a row, record: false notwithstanding'
        );
        static::__assert_false(Portal_Session::is_logged_in());
    }

    /**
     * pa-06: malformed input is not an attempt.
     */
    public static function test_missing_credentials_record_nothing()
    {
        $site_id = static::__make_site('malformed');
        $email = static::__fresh_email('malformed');
        static::__as_portal($site_id);

        static::__assert_null(Portal_Auth::verify_credentials($site_id, []));
        static::__assert_null(Portal_Auth::verify_credentials($site_id, ['email' => $email]));
        static::__assert_null(Portal_Auth::verify_credentials($site_id, ['email' => $email, 'password' => '']));

        static::__assert_equals(0, Portal_Login_History::get_failed_attempts_count($email));
    }
}
