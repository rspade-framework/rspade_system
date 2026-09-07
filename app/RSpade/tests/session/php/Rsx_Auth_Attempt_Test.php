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
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * RsxAuth::attempt() is the one place that can classify a login outcome - unknown address, wrong
 * password, or success - so it is the place that records it. These tests pin that contract:
 *
 * - unknown address           -> false, a FAILED_NOT_FOUND record (counter + log line), no row
 * - soft-deleted identity     -> the same, via the SoftDeletes global scope
 * - wrong password            -> false, a FAILED_PASSWORD record, no row
 * - correct credentials       -> true, exactly ONE `_login_history` SUCCESS row, session identity set
 * - $record = false           -> nothing recorded on either path
 * - missing email or password -> false and NOTHING recorded (malformed input is not an attempt)
 *
 * TWO STORES, so two isolation strategies (same as Login_History_Test): the SUCCESS rows are
 * database writes rolled back with the per-test transaction, while the failure counters are redis
 * keys living outside both the transaction and the process - every failure test therefore uses a
 * unique email, reads the shared per-IP counter as a delta (in CLI the client IP is the literal
 * 'CLI'), and deletes the keys it created in teardown.
 *
 * NOT COVERED HERE: the $touch_last_login flag. Session::set_login_user_id() returns from its CLI
 * branch before the last_login stamp, so NO login stamps last_login in a CLI process and the flag
 * is unobservable from a PHP test. It is verified live over HTTP instead (a real login stamps, and
 * a dev-auth harness login does not) - see tests/session/test_catalog.md.
 */
class Rsx_Auth_Attempt_Test extends Rsx_Test_Abstract
{
    private const PASSWORD = 'correct-horse-battery-staple';

    /**
     * Counter keys created by these tests, deleted in teardown. Held as the RAW key; the
     * persistent-namespace transform (RsxCache::_make_key_persistent) is applied on delete.
     */
    private static array $counter_keys_used = [];

    public static function teardown()
    {
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

    /**
     * An email nothing else has ever attempted with, registered for counter teardown.
     */
    private static function __fresh_email(string $suffix): string
    {
        $email = 'attempt_' . $suffix . '_' . uniqid() . '@example.com';
        static::$counter_keys_used[] = 'login_failures:email:' . sha1(strtolower(trim($email)));

        return $email;
    }

    /**
     * A live login identity with a real bcrypt hash of self::PASSWORD.
     */
    private static function __make_login_user(string $email): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    private static function __history_rows_for(string $email): int
    {
        return DB::table('_login_history')->where('email_attempted', $email)->count();
    }

    /**
     * The persisted last_login, re-read from the database - Rsx_Model_Abstract refuses the
     * relation reload that $model->refresh() performs, so query it fresh instead.
     * withTrashed(): the soft-delete test reads a trashed fixture.
     */
    private static function __persisted_last_login(int $login_user_id): ?string
    {
        return Login_User_Model::withTrashed()->where('id', $login_user_id)->value('last_login');
    }

    /**
     * Start every test from a known anonymous state - attempt() logs identities in.
     */
    private static function __start_anonymous(): void
    {
        Session::logout();
        static::__reset_session();
    }

    /**
     * The tail of the application log, where record_failure() writes its forensic line.
     */
    private static function __log_tail(int $bytes = 65536): string
    {
        $path = storage_path('logs/laravel.log');

        if (!is_readable($path)) {
            return '';
        }

        $size = filesize($path);
        $handle = fopen($path, 'rb');
        fseek($handle, max(0, $size - $bytes));
        $tail = stream_get_contents($handle);
        fclose($handle);

        return $tail === false ? '' : $tail;
    }

    // -------------------------------------------------------------------------
    // Classification
    // -------------------------------------------------------------------------

    /**
     * An address nobody owns is classified NOT_FOUND: counted for throttling, written to the log,
     * and never written to the database (/login is anonymous-reachable).
     */
    public static function test_unknown_email_is_recorded_as_not_found()
    {
        static::__start_anonymous();
        $email = static::__fresh_email('unknown');
        $rows_before = DB::table('_login_history')->count();

        static::__assert_false(
            RsxAuth::attempt(['email' => $email, 'password' => self::PASSWORD]),
            'an unknown address cannot authenticate'
        );

        static::__assert_equals($rows_before, DB::table('_login_history')->count(), 'no row is written');
        static::__assert_equals(1, Login_History::get_failed_attempts_count($email), 'the failure is counted');

        $log = static::__log_tail();
        if ($log === '') {
            static::__skip('application log is not readable in this environment');
            return;
        }

        static::__assert_contains($email, $log, 'the failure is logged');
        static::__assert_contains(Login_History::STATUS_FAILED_NOT_FOUND, $log, 'classified NOT_FOUND');
    }

    /**
     * A wrong password against a REAL identity is the other failure classification. The contrast
     * with the test above is the point: the caller never has to repeat the lookup to learn which
     * of the two happened.
     */
    public static function test_wrong_password_is_recorded_as_failed_password()
    {
        static::__start_anonymous();
        $email = static::__fresh_email('wrongpw');
        $login_user = static::__make_login_user($email);
        $rows_before = DB::table('_login_history')->count();

        static::__assert_false(
            RsxAuth::attempt(['email' => $email, 'password' => 'not-the-password']),
            'a wrong password cannot authenticate'
        );

        static::__assert_equals($rows_before, DB::table('_login_history')->count(), 'no row is written');
        static::__assert_equals(1, Login_History::get_failed_attempts_count($email), 'the failure is counted');
        static::__assert_null(Session::get_login_user_id(), 'a failed attempt establishes no identity');

        static::__assert_null(
            static::__persisted_last_login((int) $login_user->id),
            'a failed attempt cannot stamp last_login'
        );

        $log = static::__log_tail();
        if ($log === '') {
            static::__skip('application log is not readable in this environment');
            return;
        }

        static::__assert_contains($email, $log, 'the failure is logged');
        static::__assert_contains(Login_History::STATUS_FAILED_PASSWORD, $log, 'classified FAILED_PASSWORD');
    }

    /**
     * A soft-deleted identity is refused by the SoftDeletes global scope on the lookup, which is
     * what makes it NOT_FOUND rather than a fourth outcome - and NOT_FOUND is also the right thing
     * to tell a stranger about a deleted account.
     */
    public static function test_soft_deleted_identity_is_classified_not_found()
    {
        static::__start_anonymous();
        $email = static::__fresh_email('deleted');
        $login_user = static::__make_login_user($email);
        $login_user->delete();

        $rows_before = DB::table('_login_history')->count();

        static::__assert_false(
            RsxAuth::attempt(['email' => $email, 'password' => self::PASSWORD]),
            'a deleted identity cannot authenticate even with the right password'
        );

        static::__assert_equals($rows_before, DB::table('_login_history')->count(), 'no row is written');
        static::__assert_equals(1, Login_History::get_failed_attempts_count($email), 'the failure is counted');
        static::__assert_null(Session::get_login_user_id(), 'no identity is established');

        $log = static::__log_tail();
        if ($log === '') {
            static::__skip('application log is not readable in this environment');
            return;
        }

        static::__assert_contains($email, $log, 'the failure is logged');
        static::__assert_contains(Login_History::STATUS_FAILED_NOT_FOUND, $log, 'classified NOT_FOUND');
    }

    /**
     * The success path: exactly ONE history row, and the caller is logged in.
     */
    public static function test_successful_attempt_records_one_success_row_and_logs_in()
    {
        static::__start_anonymous();
        $email = static::__fresh_email('success');
        $login_user = static::__make_login_user($email);

        static::__assert_true(
            RsxAuth::attempt(['email' => $email, 'password' => self::PASSWORD]),
            'correct credentials authenticate'
        );

        static::__assert_equals(1, static::__history_rows_for($email), 'exactly one row, never two');

        $row = DB::table('_login_history')->where('email_attempted', $email)->first();
        static::__assert_equals(Login_History::STATUS_SUCCESS, $row->status);
        static::__assert_equals((int) $login_user->id, (int) $row->login_user_id);

        static::__assert_equals(
            (int) $login_user->id,
            (int) Session::get_login_user_id(),
            'the session carries the authenticated identity'
        );
        static::__assert_equals(
            0,
            Login_History::get_failed_attempts_count($email),
            'a success is never a failure count'
        );
    }

    // -------------------------------------------------------------------------
    // $record = false
    // -------------------------------------------------------------------------

    /**
     * The opt-out: a harness (or a second-factor pre-check) must be able to test a credential pair
     * without writing anything a real audit trail would have to explain.
     */
    public static function test_record_false_records_no_failure()
    {
        static::__start_anonymous();
        $email = static::__fresh_email('norecord_fail');
        static::__make_login_user($email);
        $rows_before = DB::table('_login_history')->count();

        static::__assert_false(
            RsxAuth::attempt(['email' => $email, 'password' => 'not-the-password'], record: false)
        );

        static::__assert_equals($rows_before, DB::table('_login_history')->count(), 'no row');
        static::__assert_equals(0, Login_History::get_failed_attempts_count($email), 'no counter increment');
    }

    /**
     * The 2FA pre-check shape: verify the pair, record nothing, stamp nothing - but the identity
     * IS established, so the caller decides what happens next.
     */
    public static function test_record_false_success_logs_in_without_recording()
    {
        static::__start_anonymous();
        $email = static::__fresh_email('norecord_ok');
        $login_user = static::__make_login_user($email);

        static::__assert_true(RsxAuth::attempt(
            ['email' => $email, 'password' => self::PASSWORD],
            record: false,
            touch_last_login: false
        ));

        static::__assert_equals(0, static::__history_rows_for($email), 'nothing recorded');
        static::__assert_null(
            static::__persisted_last_login((int) $login_user->id),
            'nothing stamped'
        );
        static::__assert_equals(
            (int) $login_user->id,
            (int) Session::get_login_user_id(),
            'the caller is still authenticated'
        );
    }

    // -------------------------------------------------------------------------
    // Malformed input
    // -------------------------------------------------------------------------

    /**
     * Nobody offered a credential pair, so there is nothing to classify: no row, no counter, no
     * log line. Recording these would let a bare GET-shaped POST inflate a throttle counter.
     */
    public static function test_missing_credentials_record_nothing()
    {
        static::__start_anonymous();
        $email = static::__fresh_email('malformed');
        $rows_before = DB::table('_login_history')->count();
        $ip_before = Login_History::get_failed_attempts_count_by_ip('CLI');
        static::$counter_keys_used[] = 'login_failures:ip:CLI';

        static::__assert_false(RsxAuth::attempt([]), 'no credentials at all');
        static::__assert_false(RsxAuth::attempt(['email' => $email]), 'email without a password');
        static::__assert_false(RsxAuth::attempt(['password' => self::PASSWORD]), 'password without an email');
        static::__assert_false(RsxAuth::attempt(['email' => $email, 'password' => '']), 'an empty password');

        static::__assert_equals($rows_before, DB::table('_login_history')->count(), 'no row');
        static::__assert_equals(0, Login_History::get_failed_attempts_count($email), 'no email counter');
        static::__assert_equals(
            $ip_before,
            Login_History::get_failed_attempts_count_by_ip('CLI'),
            'no IP counter either'
        );
    }
}
