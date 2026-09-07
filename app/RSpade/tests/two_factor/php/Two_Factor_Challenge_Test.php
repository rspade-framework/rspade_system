<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TwoFactor\Php;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Auth\Auth_Throttled_Exception;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Core\TwoFactor\Passkeys;
use App\RSpade\Core\TwoFactor\Recovery_Codes;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Totp;
use App\RSpade\Core\TwoFactor\Two_Factor_Credential_Model;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;
use App\RSpade\Tests\TwoFactor\Php\Webauthn_Authenticator_Fixture;

/**
 * The login challenge: the half-authenticated state between the password and the second
 * factor, and the method that redeems it.
 *
 * THE CENTRAL PROPERTY, and the one worth reading this file for: begin_challenge() LOGS THE
 * SESSION OUT. Between the two steps the browser must be NOT authenticated, because a
 * session that stayed logged in while carrying a "needs 2FA" flag is a session where every
 * surface in the application has to remember to honour that flag, and forgetting it
 * anywhere makes the second factor optional. The pending state is inert data instead. The
 * mechanism that makes this work is that _session_values rows survive a logout - the
 * _sessions row is not deleted, only its identity is cleared - and that is pinned below.
 *
 * ALSO PINNED:
 *  - the window is a SECURITY window: an expired pending state reads as absent and cannot
 *    be redeemed, and expiry is a working outcome, never a thrown surprise;
 *  - a wrong answer records STATUS_FAILED_2FA through Login_History, which ALREADY feeds
 *    Login_Throttle - so the failure is counted exactly ONCE. Double counting would halve
 *    the real budget and would only be discovered by a user locked out early;
 *  - enough wrong answers reach the throttle, which THROWS rather than returning false;
 *  - a correct answer logs in, stamps last_login, forgets the pending value, and writes
 *    exactly ONE success row.
 *
 * TWO STORES, so two isolation strategies (the same shape as tests/session): success rows
 * are database writes rolled back with the per-test transaction, while the throttle and
 * failure counters are redis keys outside both the transaction and the process. So every
 * test uses a unique email, and teardown clears the per-IP throttle (in CLI the client IP
 * is the literal 'CLI', shared by every test in the run) and the per-email counters it
 * created.
 */
class Two_Factor_Challenge_Test extends Rsx_Test_Abstract
{
    private const PASSWORD = 'correct-horse-battery-staple';

    /**
     * Raw counter keys created by these tests, deleted in teardown. Held raw; the
     * persistent-namespace transform is applied on delete.
     */
    private static array $counter_keys_used = [];

    public static function teardown()
    {
        // The per-IP throttle lives outside the transaction and is shared with every other
        // test in the run. Login_Throttle::reset() is the sanctioned way to clear it.
        Login_Throttle::reset('CLI');

        Session::logout();
        static::__reset_session();

        if (!empty(static::$counter_keys_used)) {
            $redis = new \Redis();
            $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 2.0);
            $redis->select(0);

            foreach (static::$counter_keys_used as $key) {
                $redis->del('cache:' . sha1($key));
            }

            $redis->close();
            static::$counter_keys_used = [];
        }
    }

    /**
     * An email nothing else has attempted with, registered for counter teardown.
     */
    private static function __fresh_email(string $suffix): string
    {
        $email = 'challenge_' . $suffix . '_' . uniqid() . '@example.com';
        static::$counter_keys_used[] = 'login_failures:email:' . sha1(strtolower(trim($email)));

        return $email;
    }

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

    private static function __start_anonymous(): void
    {
        Login_Throttle::reset('CLI');
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    /**
     * An identity with a live TOTP factor, enrolled through the facade, plus the seed so a
     * test can compute a live code. Leaves the session ANONYMOUS.
     *
     * @return array {login_user, secret, email}
     */
    private static function __enrolled_identity(string $suffix): array
    {
        static::__start_anonymous();

        $email = static::__fresh_email($suffix);
        $login_user = static::__make_login_user($email);

        Session::set_login_user_id((int) $login_user->id);

        $started = Rsx_Two_Factor::begin_totp_enrollment();
        Rsx_Two_Factor::confirm_totp_enrollment(Totp::code_for($started['secret'], intdiv(time(), Totp::PERIOD)));

        static::__start_anonymous();

        return ['login_user' => $login_user, 'secret' => $started['secret'], 'email' => $email];
    }

    /**
     * A code that is live for this seed and NOT the timestep the enrollment consumed, so it
     * is not refused by the replay floor.
     */
    private static function __unspent_code(string $secret): string
    {
        return Totp::code_for($secret, intdiv(time(), Totp::PERIOD) + 1);
    }

    private static function __success_rows_for(string $email): int
    {
        return DB::table('_login_history')
            ->where('email_attempted', $email)
            ->where('status', Login_History::STATUS_SUCCESS)
            ->count();
    }

    // -------------------------------------------------------------------------
    // Beginning the challenge
    // -------------------------------------------------------------------------

    /**
     * THE CENTRAL PROPERTY. begin_challenge() parks the identity and logs out: the session
     * ROW still exists (so the parked value is still readable) but nobody is authenticated.
     */
    public static function test_begin_challenge_parks_the_identity_and_logs_out()
    {
        $fixture = static::__enrolled_identity('begin');

        Rsx_Two_Factor::begin_challenge($fixture['login_user']);

        static::__assert_false(Session::is_logged_in(), 'half-authenticated is NOT authenticated');
        static::__assert_true(Session::has_session(), 'but the session row survives, carrying the value');

        $pending = Rsx_Two_Factor::challenge_pending();

        static::__assert_not_null($pending, 'a challenge is pending');
        static::__assert_true($pending['has_totp'], 'the screen knows to offer a code');
        static::__assert_false($pending['has_passkey'], 'and knows not to offer a passkey');
    }

    /**
     * The address is MASKED. The screen has to show the account being signed in to, but the
     * page is reachable by anyone holding the cookie and the full address is not theirs.
     */
    public static function test_the_pending_challenge_masks_the_address()
    {
        $fixture = static::__enrolled_identity('mask');

        Rsx_Two_Factor::begin_challenge($fixture['login_user']);

        $masked = Rsx_Two_Factor::challenge_pending()['email_masked'];

        static::__assert_not_equals($fixture['email'], $masked, 'not the address itself');
        static::__assert_contains('*', $masked, 'it is masked');
        static::__assert_contains('@example.com', $masked, 'the domain is left readable');
        static::__assert_true(str_starts_with($masked, 'c'), 'the first character survives for recognition');
    }

    /**
     * With nothing pending there is no challenge, and asking is not an error.
     */
    public static function test_no_pending_challenge_reads_as_null()
    {
        static::__start_anonymous();

        static::__assert_null(Rsx_Two_Factor::challenge_pending());

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::verify_challenge(['code' => '123456']),
            'expired'
        );
    }

    /**
     * abandon_challenge() discards it - the user pressed cancel.
     */
    public static function test_abandon_challenge_discards_the_pending_state()
    {
        $fixture = static::__enrolled_identity('abandon');

        Rsx_Two_Factor::begin_challenge($fixture['login_user']);
        static::__assert_not_null(Rsx_Two_Factor::challenge_pending());

        Rsx_Two_Factor::abandon_challenge();

        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'nothing is pending');
        static::__assert_false(Session::is_logged_in(), 'and nobody is signed in');
    }

    // -------------------------------------------------------------------------
    // The security window
    // -------------------------------------------------------------------------

    /**
     * AN EXPIRED CHALLENGE IS NOT REDEEMABLE, and expiry is a working outcome rather than a
     * surprise: challenge_pending() simply reads null, and verify_challenge() says so in
     * words the user can act on.
     *
     * The expiry is forced by ageing the stored row's expires_at, because the window is
     * configuration and the clock has no injection seam - which is the correct shape for a
     * security window and the reason the test bends instead.
     */
    public static function test_an_expired_challenge_cannot_be_redeemed()
    {
        $fixture = static::__enrolled_identity('expired');

        Rsx_Two_Factor::begin_challenge($fixture['login_user']);
        static::__assert_not_null(Rsx_Two_Factor::challenge_pending(), 'live to begin with');

        DB::table('_session_values')
            ->where('session_id', Session::get_session_id())
            ->where('value_key', Rsx_Two_Factor::CHALLENGE_KEY)
            ->update(['expires_at' => Rsx_Time::to_database(Rsx_Time::subtract(Rsx_Time::now_iso(), 60))]);

        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'an expired window reads as absent');

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::verify_challenge(['code' => static::__unspent_code($fixture['secret'])]),
            'expired'
        );

        static::__assert_false(Session::is_logged_in(), 'and a correct code does not rescue it');
    }

    /**
     * The window minted is the configured one - a security window that is not the one the
     * operator configured is not a control at all.
     */
    public static function test_the_window_is_the_configured_one()
    {
        $minutes = (int) config('rsx.two_factor.challenge_window_minutes');

        static::__assert_greater_than(0, $minutes, 'the window is configured');

        $expires_at = Rsx_Two_Factor::challenge_expires_at();
        $seconds = Rsx_Time::diff_seconds(Rsx_Time::now_iso(), $expires_at);

        // A second of slack for the clock moving between the two reads.
        static::__assert_greater_than($minutes * 60 - 2, $seconds, 'not shorter than configured');
        static::__assert_less_than($minutes * 60 + 2, $seconds, 'and not longer');
    }

    // -------------------------------------------------------------------------
    // Answering wrongly
    // -------------------------------------------------------------------------

    /**
     * A wrong code is refused, counted ONCE, and leaves the caller unauthenticated with the
     * challenge still pending so they can try again.
     *
     * The counter is read as a DELTA because record_failure() also increments a per-IP key
     * shared with every other test in the run.
     */
    public static function test_a_wrong_code_is_refused_and_counted_once()
    {
        $fixture = static::__enrolled_identity('wrong');
        $email = $fixture['email'];

        Rsx_Two_Factor::begin_challenge($fixture['login_user']);

        $before = Login_History::get_failed_attempts_count($email);

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::verify_challenge(['code' => '000000']),
            'not valid'
        );

        static::__assert_equals(
            $before + 1,
            Login_History::get_failed_attempts_count($email),
            'counted exactly once - record_failure() already feeds the throttle'
        );

        static::__assert_false(Session::is_logged_in(), 'a wrong code authenticates nobody');
        static::__assert_not_null(Rsx_Two_Factor::challenge_pending(), 'the challenge survives for a retry');
        static::__assert_equals(0, static::__success_rows_for($email), 'and no success is recorded');
    }

    /**
     * A code that is valid for a DIFFERENT identity's seed does not answer this challenge.
     */
    public static function test_another_identitys_code_does_not_answer_this_challenge()
    {
        $theirs = static::__enrolled_identity('other_theirs');
        $mine = static::__enrolled_identity('other_mine');

        Rsx_Two_Factor::begin_challenge($mine['login_user']);

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::verify_challenge(['code' => static::__unspent_code($theirs['secret'])])
        );

        static::__assert_false(Session::is_logged_in());
    }

    /**
     * An empty input is not an answer.
     */
    public static function test_an_empty_answer_is_refused()
    {
        $fixture = static::__enrolled_identity('empty');

        Rsx_Two_Factor::begin_challenge($fixture['login_user']);

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::verify_challenge([])
        );

        static::__assert_false(Session::is_logged_in());
    }

    // -------------------------------------------------------------------------
    // The throttle
    // -------------------------------------------------------------------------

    /**
     * THE THROTTLE'S BUDGET IS REAL: enough failures against one address lock it out, and
     * require_not_throttled() THROWS Auth_Throttled_Exception rather than returning a
     * verdict - "we did not check" is a different answer from "that was wrong", and the
     * login screen has to be able to say so. An unthrottled second factor is a six-digit
     * guessing oracle, so this is the test that says the oracle is closed.
     *
     * DRIVEN WITH AN EXPLICIT IP, because Session::get_client_ip() is null in CLI BY DESIGN
     * (there is no remote party to throttle, and a command is not an attack surface). So the
     * ambient call inside verify_challenge() cannot be tripped from a PHP test at all - what
     * is pinned here is the mechanism it calls, and that it is reached ONCE per failure. The
     * end-to-end refusal is verified live over HTTP; see test_catalog.md.
     */
    public static function test_the_throttle_budget_locks_an_address_out()
    {
        $config = config('rsx.sessions.login_throttle');

        if (empty($config['enabled'])) {
            static::__skip('the login throttle is disabled in this environment');
            return;
        }

        // A per-test IP, so this never collides with another test's shared 'CLI' counter.
        $ip = '203.0.113.' . random_int(1, 254);

        Login_Throttle::reset($ip);

        try {
            $budget = (int) $config['attempts'];

            // Inside the budget nothing is refused.
            for ($i = 0; $i < $budget; $i++) {
                Login_Throttle::require_not_throttled($ip);
                Login_Throttle::record_failure($ip);
            }

            static::__assert_throws(
                Auth_Throttled_Exception::class,
                fn () => Login_Throttle::require_not_throttled($ip),
                'too fast'
            );

            static::__assert_greater_than(
                0,
                Login_Throttle::retry_after_seconds($ip),
                'and the refusal says when to come back'
            );
        } finally {
            Login_Throttle::reset($ip);
        }
    }

    /**
     * A FAILED CHALLENGE REACHES THE THROTTLE EXACTLY ONCE. verify_challenge() records its
     * failure through Login_History::record_failure(), which ALREADY calls
     * Login_Throttle::record_failure() - so verify_challenge() must never call it as well.
     * Double counting would halve the real budget, and the halving would only ever be
     * discovered by a user locked out early.
     *
     * The per-IP throttle counter is unreachable in CLI (see above), so the single count is
     * observed on the per-EMAIL failure counter that record_failure() increments in the same
     * breath - one increment per failure means one call to record_failure().
     */
    public static function test_each_failure_is_recorded_exactly_once()
    {
        $fixture = static::__enrolled_identity('once');
        $email = $fixture['email'];

        Rsx_Two_Factor::begin_challenge($fixture['login_user']);

        $before = Login_History::get_failed_attempts_count($email);

        for ($i = 1; $i <= 3; $i++) {
            try {
                Rsx_Two_Factor::verify_challenge(['code' => '000000']);
            } catch (Two_Factor_Failed_Exception $e) {
                // expected
            }

            static::__assert_equals(
                $before + $i,
                Login_History::get_failed_attempts_count($email),
                'failure ' . $i . ' is counted once, not twice'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Answering correctly
    // -------------------------------------------------------------------------

    /**
     * THE HAPPY PATH: the caller is signed in, the pending value is gone, and exactly ONE
     * success row is written.
     *
     * The success row is this method's responsibility because RsxAuth::login() records
     * nothing by design - a caller that logs a user in directly owns its own history row.
     *
     * NOT ASSERTED HERE: the last_login stamp. Session::set_login_user_id() returns from its
     * CLI branch before the stamp, so NO login stamps last_login in a CLI process and the
     * flag is unobservable from a PHP test - the same limit tests/session records for
     * sess-auth-08. verify_challenge() passes RsxAuth::login() its default
     * $touch_last_login = true, which is what makes this a real login rather than an
     * identity swap; it is verified live over HTTP. See test_catalog.md.
     */
    public static function test_a_correct_code_signs_the_user_in_and_records_one_success()
    {
        $fixture = static::__enrolled_identity('success');
        $login_user = $fixture['login_user'];
        $email = $fixture['email'];
        $id = (int) $login_user->id;

        Rsx_Two_Factor::begin_challenge($login_user);
        static::__assert_false(Session::is_logged_in(), 'not yet');

        $returned = Rsx_Two_Factor::verify_challenge(['code' => static::__unspent_code($fixture['secret'])]);

        static::__assert_equals($id, (int) $returned->id, 'the identity is returned');
        static::__assert_equals($id, (int) Session::get_login_user_id(), 'and is signed in');
        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'the pending value is gone');
        static::__assert_equals(1, static::__success_rows_for($email), 'exactly one success row, never two');
    }

    /**
     * THE CODE IS SINGLE USE. The accepted timestep is persisted, so presenting the same
     * code a second time is refused as a replay - which is the attack a shoulder-surfed code
     * inside its own 30-second window is.
     */
    public static function test_a_used_code_cannot_be_replayed()
    {
        $fixture = static::__enrolled_identity('replay');
        $code = static::__unspent_code($fixture['secret']);

        Rsx_Two_Factor::begin_challenge($fixture['login_user']);
        Rsx_Two_Factor::verify_challenge(['code' => $code]);

        $row = Two_Factor_Credential_Model::where('login_user_id', $fixture['login_user']->id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_TOTP)
            ->first();

        static::__assert_equals(
            intdiv(time(), Totp::PERIOD) + 1,
            (int) $row->counter,
            'the accepted timestep is persisted as the new replay floor'
        );

        static::__assert_not_null($row->last_used_at, 'and the use is stamped');

        // A second challenge, the same code.
        static::__start_anonymous();
        Rsx_Two_Factor::begin_challenge($fixture['login_user']);

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::verify_challenge(['code' => $code]),
            'not valid'
        );

        static::__assert_false(Session::is_logged_in(), 'a replayed code signs nobody in');
    }

    // -------------------------------------------------------------------------
    // A passkey as the answer
    // -------------------------------------------------------------------------

    /**
     * A passkey assertion answers the challenge and signs the user in - the other half of
     * verify_challenge()'s dispatch, driven against a real simulated authenticator.
     */
    public static function test_a_passkey_answers_the_challenge()
    {
        static::__start_anonymous();

        $email = static::__fresh_email('passkey');
        $login_user = static::__make_login_user($email);
        $id = (int) $login_user->id;

        Session::set_login_user_id($id);

        $authenticator = new Webauthn_Authenticator_Fixture(Passkeys::relying_party_id());
        Rsx_Two_Factor::begin_passkey_registration();
        Rsx_Two_Factor::confirm_passkey_registration(
            $authenticator->attestation_response((string) Session::get_value(Passkeys::CHALLENGE_KEY)),
            'Test key'
        );

        static::__start_anonymous();
        Rsx_Two_Factor::begin_challenge($login_user);

        $pending = Rsx_Two_Factor::challenge_pending();
        static::__assert_true($pending['has_passkey'], 'the screen knows to offer a passkey');
        static::__assert_false($pending['has_totp'], 'and knows there is no code to type');

        Rsx_Two_Factor::challenge_passkey_options();

        $returned = Rsx_Two_Factor::verify_challenge([
            'assertion' => $authenticator->assertion_response(
                (string) Session::get_value(Passkeys::CHALLENGE_KEY),
                11
            ),
        ]);

        static::__assert_equals($id, (int) $returned->id, 'the identity is returned');
        static::__assert_equals($id, (int) Session::get_login_user_id(), 'and is signed in');
        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'the pending value is gone');
        static::__assert_equals(1, static::__success_rows_for($email), 'exactly one success row');
    }

    /**
     * ANOTHER IDENTITY'S PASSKEY DOES NOT ANSWER THIS CHALLENGE. verify_assertion() proves
     * the signature came from a credential this server issued; it does NOT prove that
     * credential belongs to the account whose password was just entered. Without the
     * ownership comparison in verify_challenge(), anybody holding ANY valid passkey could
     * complete anybody else's challenge - so this is the test guarding that check.
     */
    public static function test_another_identitys_passkey_does_not_answer_this_challenge()
    {
        // The attacker, with a genuine passkey of their own.
        static::__start_anonymous();
        $attacker = static::__make_login_user(static::__fresh_email('pk_attacker'));
        Session::set_login_user_id((int) $attacker->id);

        $their_key = new Webauthn_Authenticator_Fixture(Passkeys::relying_party_id());
        Rsx_Two_Factor::begin_passkey_registration();
        Rsx_Two_Factor::confirm_passkey_registration(
            $their_key->attestation_response((string) Session::get_value(Passkeys::CHALLENGE_KEY)),
            'Attacker key'
        );

        // The victim, whose password has just been accepted.
        static::__start_anonymous();
        $victim_email = static::__fresh_email('pk_victim');
        $victim = static::__make_login_user($victim_email);
        Session::set_login_user_id((int) $victim->id);

        $victim_key = new Webauthn_Authenticator_Fixture(Passkeys::relying_party_id());
        Rsx_Two_Factor::begin_passkey_registration();
        Rsx_Two_Factor::confirm_passkey_registration(
            $victim_key->attestation_response((string) Session::get_value(Passkeys::CHALLENGE_KEY)),
            'Victim key'
        );

        static::__start_anonymous();
        Rsx_Two_Factor::begin_challenge($victim);
        Rsx_Two_Factor::challenge_passkey_options();

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::verify_challenge([
                'assertion' => $their_key->assertion_response(
                    (string) Session::get_value(Passkeys::CHALLENGE_KEY),
                    13
                ),
            ]),
            'not valid'
        );

        static::__assert_false(Session::is_logged_in(), 'a valid key for the wrong account signs nobody in');
        static::__assert_equals(0, static::__success_rows_for($victim_email), 'and records no success');
    }

    // -------------------------------------------------------------------------
    // Recovery codes as the answer
    // -------------------------------------------------------------------------

    /**
     * A recovery code answers the challenge, is spent, and signs the user in.
     */
    public static function test_a_recovery_code_answers_the_challenge_and_is_spent()
    {
        static::__start_anonymous();

        $email = static::__fresh_email('recovery');
        $login_user = static::__make_login_user($email);
        $id = (int) $login_user->id;

        Session::set_login_user_id($id);
        $started = Rsx_Two_Factor::begin_totp_enrollment();
        $codes = Rsx_Two_Factor::confirm_totp_enrollment(
            Totp::code_for($started['secret'], intdiv(time(), Totp::PERIOD))
        );

        static::__start_anonymous();
        Rsx_Two_Factor::begin_challenge($login_user);

        Rsx_Two_Factor::verify_challenge(['code' => $codes[0]]);

        static::__assert_equals($id, (int) Session::get_login_user_id(), 'signed in on a recovery code');
        static::__assert_equals(
            Recovery_Codes::COUNT - 1,
            Rsx_Two_Factor::recovery_codes_remaining($login_user),
            'and the code is spent'
        );
        static::__assert_equals(1, static::__success_rows_for($email), 'one success row');

        // The same code does not work twice.
        static::__start_anonymous();
        Rsx_Two_Factor::begin_challenge($login_user);

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::verify_challenge(['code' => $codes[0]])
        );
    }

    /**
     * A LIVE TOTP CODE NEVER BURNS A RECOVERY CODE. TOTP is tried first, so the ordinary
     * sign-in never quietly consumes one of the ten sheets the user is saving for an
     * emergency.
     */
    public static function test_a_live_totp_code_does_not_spend_a_recovery_code()
    {
        static::__start_anonymous();

        $email = static::__fresh_email('order');
        $login_user = static::__make_login_user($email);
        $id = (int) $login_user->id;

        Session::set_login_user_id($id);
        $started = Rsx_Two_Factor::begin_totp_enrollment();
        Rsx_Two_Factor::confirm_totp_enrollment(Totp::code_for($started['secret'], intdiv(time(), Totp::PERIOD)));

        static::__start_anonymous();
        Rsx_Two_Factor::begin_challenge($login_user);

        Rsx_Two_Factor::verify_challenge(['code' => static::__unspent_code($started['secret'])]);

        static::__assert_equals(
            Recovery_Codes::COUNT,
            Rsx_Two_Factor::recovery_codes_remaining($login_user),
            'the sheet is untouched'
        );
    }
}
