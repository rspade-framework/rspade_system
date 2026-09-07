<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use Redis;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for Login_History: recording outcomes and retrieving history.
 *
 * TWO STORES, so two isolation strategies. SUCCESSES are database rows, and each test runs in a
 * transaction that is rolled back. FAILURES are redis counters, which live OUTSIDE the
 * transaction and outside the process: every failure test therefore uses a unique email, reads
 * the shared per-IP counter as a DELTA (in CLI the client IP is always the literal 'CLI'), and
 * deletes the keys it created in teardown.
 */
class Login_History_Test extends Rsx_Test_Abstract
{
    /**
     * Counter keys created by these tests, deleted in teardown. Held as the RAW key; the
     * persistent-namespace transform (RsxCache::_make_key_persistent) is applied on delete.
     */
    private static array $counter_keys_used = [];

    public static function teardown()
    {
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
     * An email nothing else has ever failed with, registered for counter teardown.
     */
    private static function _fresh_email(string $suffix): string
    {
        $email = 'fail_' . $suffix . '_' . uniqid() . '@example.com';
        static::$counter_keys_used[] = 'login_failures:email:' . sha1(strtolower(trim($email)));

        return $email;
    }

    // -------------------------------------------------------------------------
    // Status constants
    // -------------------------------------------------------------------------

    public static function test_status_constants_defined()
    {
        static::__assert_equals('success', Login_History::STATUS_SUCCESS);
        static::__assert_equals('failed_password', Login_History::STATUS_FAILED_PASSWORD);
        static::__assert_equals('failed_2fa', Login_History::STATUS_FAILED_2FA);
        static::__assert_equals('failed_locked', Login_History::STATUS_FAILED_LOCKED);
        static::__assert_equals('failed_disabled', Login_History::STATUS_FAILED_DISABLED);
        static::__assert_equals('failed_not_found', Login_History::STATUS_FAILED_NOT_FOUND);
    }

    // -------------------------------------------------------------------------
    // record_success()
    // -------------------------------------------------------------------------

    public static function test_record_success_inserts_row()
    {
        $count_before = DB::table('_login_history')->count();
        Login_History::record_success(1, 'test@example.com');
        $count_after = DB::table('_login_history')->count();
        static::__assert_equals($count_before + 1, $count_after);
    }

    public static function test_record_success_sets_status_success()
    {
        Login_History::record_success(1, 'alice@example.com');
        $row = DB::table('_login_history')
            ->where('email_attempted', 'alice@example.com')
            ->orderBy('id', 'desc')
            ->first();
        static::__assert_not_null($row);
        static::__assert_equals(Login_History::STATUS_SUCCESS, $row->status);
    }

    public static function test_record_success_stores_login_user_id()
    {
        Login_History::record_success(42, 'bob@example.com');
        $row = DB::table('_login_history')
            ->where('email_attempted', 'bob@example.com')
            ->orderBy('id', 'desc')
            ->first();
        static::__assert_equals(42, (int) $row->login_user_id);
    }

    // -------------------------------------------------------------------------
    // record_failure()
    // -------------------------------------------------------------------------

    /**
     * The whole point of the 2026-08-12 rework: /login is anonymous-reachable, so a failure must
     * never become a database row.
     */
    public static function test_record_failure_writes_no_database_row()
    {
        $email = static::_fresh_email('norow');

        $count_before = DB::table('_login_history')->count();
        Login_History::record_failure($email, Login_History::STATUS_FAILED_PASSWORD);
        static::__assert_equals($count_before, DB::table('_login_history')->count());

        static::__assert_equals(
            0,
            DB::table('_login_history')->where('email_attempted', $email)->count(),
            'not even a row that only the email would find'
        );
    }

    /**
     * Every status, reason and user id is accepted and none of them produces a row - the status
     * vocabulary now describes what the LOG line says, not what a column holds.
     */
    public static function test_record_failure_writes_no_row_for_any_status()
    {
        $email = static::_fresh_email('statuses');
        $count_before = DB::table('_login_history')->count();

        Login_History::record_failure($email, Login_History::STATUS_FAILED_NOT_FOUND);
        Login_History::record_failure($email, Login_History::STATUS_FAILED_LOCKED, 'too many attempts');
        Login_History::record_failure($email, Login_History::STATUS_FAILED_2FA, 'bad code', 4242);

        static::__assert_equals($count_before, DB::table('_login_history')->count());
        static::__assert_equals(3, Login_History::get_failed_attempts_count($email), 'all three counted');
    }

    public static function test_record_failure_increments_the_email_counter()
    {
        $email = static::_fresh_email('counter');

        static::__assert_equals(0, Login_History::get_failed_attempts_count($email));

        Login_History::record_failure($email, Login_History::STATUS_FAILED_PASSWORD);
        static::__assert_equals(1, Login_History::get_failed_attempts_count($email));

        Login_History::record_failure($email, Login_History::STATUS_FAILED_PASSWORD);
        static::__assert_equals(2, Login_History::get_failed_attempts_count($email));
    }

    /**
     * The email counter is normalized, so casing and stray whitespace cannot open a fresh window
     * per attempt.
     */
    public static function test_record_failure_email_counter_is_normalized()
    {
        $email = static::_fresh_email('normalized');

        Login_History::record_failure(' ' . strtoupper($email) . ' ');

        static::__assert_equals(1, Login_History::get_failed_attempts_count($email));
        static::__assert_equals(1, Login_History::get_failed_attempts_count(strtoupper($email)));
    }

    /**
     * The per-IP counter is shared by every caller from that address, so it is read as a delta.
     * In CLI the client IP is the literal 'CLI'.
     */
    public static function test_record_failure_increments_the_ip_counter()
    {
        static::$counter_keys_used[] = 'login_failures:ip:CLI';

        $before = Login_History::get_failed_attempts_count_by_ip('CLI');

        Login_History::record_failure(static::_fresh_email('ip_a'));
        Login_History::record_failure(static::_fresh_email('ip_b'));

        static::__assert_equals($before + 2, Login_History::get_failed_attempts_count_by_ip('CLI'));
    }

    // -------------------------------------------------------------------------
    // get_history_for_user()
    // -------------------------------------------------------------------------

    public static function test_get_history_for_user_returns_array()
    {
        $history = Login_History::get_history_for_user(9999);
        static::__assert_true(is_array($history));
    }

    public static function test_get_history_for_user_returns_recorded_entries()
    {
        Login_History::record_success(777, 'hist@example.com');
        $history = Login_History::get_history_for_user(777);
        static::__assert_count(1, $history);
    }

    public static function test_get_history_entry_has_expected_keys()
    {
        Login_History::record_success(888, 'keys@example.com');
        $history = Login_History::get_history_for_user(888);
        $entry = $history[0];

        static::__assert_array_has_key('id', $entry);
        static::__assert_array_has_key('email', $entry);
        static::__assert_array_has_key('ip_address', $entry);
        static::__assert_array_has_key('user_agent', $entry);
        static::__assert_array_has_key('user_agent_parsed', $entry);
        static::__assert_array_has_key('status', $entry);
        static::__assert_array_has_key('status_label', $entry);
        static::__assert_array_has_key('failure_reason', $entry);
        static::__assert_array_has_key('created_at', $entry);
        static::__assert_array_has_key('location', $entry);
    }

    public static function test_get_history_entry_email_matches()
    {
        Login_History::record_success(111, 'match@example.com');
        $history = Login_History::get_history_for_user(111);
        static::__assert_equals('match@example.com', $history[0]['email']);
    }

    public static function test_get_history_respects_limit()
    {
        for ($i = 0; $i < 5; $i++) {
            Login_History::record_success(222, "limit{$i}@example.com");
        }
        $history = Login_History::get_history_for_user(222, 3);
        static::__assert_count(3, $history);
    }

    public static function test_get_history_status_label_success()
    {
        Login_History::record_success(333, 'label@example.com');
        $history = Login_History::get_history_for_user(333);
        static::__assert_equals('Success', $history[0]['status_label']);
    }

    public static function test_get_history_user_agent_parsed_has_summary()
    {
        Login_History::record_success(444, 'ua@example.com');
        $history = Login_History::get_history_for_user(444);
        static::__assert_array_has_key('summary', $history[0]['user_agent_parsed']);
    }

    // -------------------------------------------------------------------------
    // get_failed_attempts_count()
    // -------------------------------------------------------------------------

    public static function test_failed_attempts_count_starts_at_zero()
    {
        $count = Login_History::get_failed_attempts_count(static::_fresh_email('zero'), 15);
        static::__assert_equals(0, $count);
    }

    public static function test_failed_attempts_count_counts_failures()
    {
        $email = static::_fresh_email('rate');

        Login_History::record_failure($email, Login_History::STATUS_FAILED_PASSWORD);
        Login_History::record_failure($email, Login_History::STATUS_FAILED_PASSWORD);

        static::__assert_equals(2, Login_History::get_failed_attempts_count($email, 15));
    }

    public static function test_failed_attempts_count_ignores_success()
    {
        $email = static::_fresh_email('succ');

        Login_History::record_success(555, $email);
        Login_History::record_failure($email, Login_History::STATUS_FAILED_PASSWORD);

        static::__assert_equals(
            1,
            Login_History::get_failed_attempts_count($email, 15),
            'a success is a row, never a failure count'
        );
    }

    /**
     * The counter window is the horizon: a request for a LARGER window cannot resurrect history
     * an ephemeral store never kept, and answers the window's count instead.
     */
    public static function test_failed_attempts_count_is_bounded_by_the_configured_window()
    {
        $email = static::_fresh_email('window');

        Login_History::record_failure($email, Login_History::STATUS_FAILED_PASSWORD);

        static::__assert_equals(
            1,
            Login_History::get_failed_attempts_count($email, 30 * 24 * 60),
            'a 30-day request returns the window count, not a 30-day count'
        );
    }

    // -------------------------------------------------------------------------
    // get_failed_attempts_count_by_ip()
    // -------------------------------------------------------------------------

    public static function test_failed_attempts_by_ip_counts_failures_from_same_ip()
    {
        // In CLI mode the recorded IP is the literal "CLI", shared by every caller - read as a
        // delta, and registered for teardown.
        static::$counter_keys_used[] = 'login_failures:ip:CLI';

        $before = Login_History::get_failed_attempts_count_by_ip('CLI', 15);

        Login_History::record_failure(static::_fresh_email('ip1'), Login_History::STATUS_FAILED_PASSWORD);
        Login_History::record_failure(static::_fresh_email('ip2'), Login_History::STATUS_FAILED_PASSWORD);

        static::__assert_equals($before + 2, Login_History::get_failed_attempts_count_by_ip('CLI', 15));
    }

    public static function test_failed_attempts_by_ip_returns_zero_for_unknown_ip()
    {
        $count = Login_History::get_failed_attempts_count_by_ip('0.0.0.1', 15);
        static::__assert_equals(0, $count);
    }
}
