<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TwoFactor\Php;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Session\Session_Values_Cleanup_Service;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Core\TwoFactor\Passkeys;
use App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;
use App\RSpade\Tests\TwoFactor\Php\Webauthn_Authenticator_Fixture;

/**
 * The WebAuthn CEREMONY around a passkey: the timeout hint the browser is sent, the server's
 * challenge window derived from it, and the recorded outcome of every enrollment ceremony.
 *
 * WHAT IS PINNED:
 *  - every ceremony - registration, the second-factor assertion and passwordless sign-in, in
 *    BOTH realms - carries publicKey.timeout = 300000 ms. Left unset, the library writes a
 *    20-second default, which the cross-device (QR) flow cannot finish inside;
 *  - the challenge window is the ceremony timeout plus a margin, never shorter than the
 *    browser's prompt, and is what the stored challenge actually expires on;
 *  - an enrollment writes a BEGUN row when its options are issued and an ENROLLED row when it
 *    is confirmed; a refused confirmation writes FAILED; a ceremony never confirmed is
 *    ABANDONED - when the next begin supersedes it, when the next begin finds it expired, when
 *    its confirmation arrives after the window, and when the hourly session-values sweep
 *    finds it expired - exactly once each;
 *  - the portal realm, which has no login history, logs the same outcomes and writes no row.
 *
 * SESSIONS IN CLI: as in Passkeys_Test, each test resets the process's CLI session so the
 * values Session::put_value() writes live inside the current transaction.
 */
class Passkey_Ceremony_Test extends Rsx_Test_Abstract
{
    public static function teardown()
    {
        Event::forget(MessageLogged::class);
        Login_Throttle::reset('CLI');
        Portal_Session::cli_set_impersonator_user_id(null);
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        Portal_Session::_testing_reset();
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private static function __signed_in_user(): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'ceremony_' . uniqid() . '@example.com';
        $login_user->password = Hash::make('correct-horse-battery-staple');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
        Session::set_login_user_id((int) $login_user->id);

        return $login_user;
    }

    /**
     * A portal user on a fresh site, signed in to the portal on a portal request.
     */
    private static function __signed_in_portal_user(): Portal_User_Model
    {
        $site = new Site_Model();
        $site->slug = 'ceremony-' . uniqid();
        $site->name = 'Ceremony';
        $site->is_enabled = true;
        $site->save();

        Login_Throttle::reset('CLI');
        Portal_Session::_testing_reset();
        Portal_Session::set_site_id((int) $site->id);
        Rsx_Portal::set_portal_request(true);
        Portal_Session::cli_set_impersonator_user_id(null);

        $user = new Portal_User_Model();
        $user->site_id = (int) $site->id;
        $user->email = 'ceremony_portal_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        Portal_Session::cli_set_portal_user_id((int) $user->id);

        return $user;
    }

    /**
     * This identity's _login_history statuses, oldest first.
     */
    private static function __statuses(int $login_user_id): array
    {
        return DB::table('_login_history')
            ->where('login_user_id', $login_user_id)
            ->orderBy('id')
            ->pluck('status')
            ->all();
    }

    /**
     * The newest _login_history row of one status for this identity.
     */
    private static function __row(int $login_user_id, string $status): object
    {
        $row = DB::table('_login_history')
            ->where('login_user_id', $login_user_id)
            ->where('status', $status)
            ->orderByDesc('id')
            ->first();

        static::__assert_not_null($row, "a {$status} row");

        return $row;
    }

    /**
     * Move this session's parked enrollment marker (and its challenge) into the past, as
     * though the window had closed without a confirmation.
     */
    private static function __expire_enrollment(string $realm): void
    {
        DB::table('_session_values')
            ->where('session_id', Session::get_session_id())
            ->whereIn('value_key', [$realm::PASSKEY_ENROLLMENT_KEY, $realm::WEBAUTHN_CHALLENGE_KEY])
            ->update(['expires_at' => Rsx_Time::to_database(Rsx_Time::subtract(Rsx_Time::now_iso(), 60))]);
    }

    private static function __marker_row(string $realm): ?object
    {
        return DB::table('_session_values')
            ->where('session_id', Session::get_session_id())
            ->where('value_key', $realm::PASSKEY_ENROLLMENT_KEY)
            ->first();
    }

    // -------------------------------------------------------------------------
    // The ceremony timeout
    // -------------------------------------------------------------------------

    /**
     * Registration, the second-factor assertion and passwordless sign-in each carry the
     * framework's 300-second hint, in milliseconds - never the library's 20-second default.
     */
    public static function test_every_staff_ceremony_carries_the_300_second_timeout()
    {
        $login_user = static::__signed_in_user();

        static::__assert_equals(300, Passkeys::CEREMONY_TIMEOUT_SECONDS);

        $registration = Rsx_Two_Factor::begin_passkey_registration();
        static::__assert_equals(300000, $registration['publicKey']['timeout'], 'registration');

        $assertion = Passkeys::assertion_options(Rsx_Two_Factor::class, (int) $login_user->id);
        static::__assert_equals(300000, $assertion['publicKey']['timeout'], 'second-factor assertion');

        $passwordless = Rsx_Two_Factor::begin_passkey_login();
        static::__assert_equals(300000, $passwordless['publicKey']['timeout'], 'passwordless sign-in');
    }

    /**
     * The portal realm runs the same ceremonies through the same code and gets the same hint.
     */
    public static function test_every_portal_ceremony_carries_the_300_second_timeout()
    {
        $portal_user = static::__signed_in_portal_user();

        $registration = Rsx_Portal_Two_Factor::begin_passkey_registration();
        static::__assert_equals(300000, $registration['publicKey']['timeout'], 'registration');

        $assertion = Passkeys::assertion_options(Rsx_Portal_Two_Factor::class, (int) $portal_user->id);
        static::__assert_equals(300000, $assertion['publicKey']['timeout'], 'second-factor assertion');

        $passwordless = Rsx_Portal_Two_Factor::begin_passkey_login();
        static::__assert_equals(300000, $passwordless['publicKey']['timeout'], 'passwordless sign-in');
    }

    // -------------------------------------------------------------------------
    // The challenge window
    // -------------------------------------------------------------------------

    /**
     * The window is DERIVED - ceremony timeout plus margin - so it can never be shorter than
     * the browser's prompt, and it stays inside the ten minutes the second-factor window
     * allows.
     */
    public static function test_the_challenge_window_is_derived_from_the_ceremony_timeout()
    {
        $window = Passkeys::challenge_window_seconds();

        static::__assert_equals(Passkeys::CEREMONY_TIMEOUT_SECONDS + Passkeys::CHALLENGE_MARGIN_SECONDS, $window);
        static::__assert_greater_than(Passkeys::CEREMONY_TIMEOUT_SECONDS, $window, 'longer than the prompt');
        static::__assert_greater_than(0, Passkeys::CHALLENGE_MARGIN_SECONDS, 'a real margin');
        static::__assert_less_than(601, $window, 'no longer than ten minutes');
    }

    /**
     * The stored challenge and the enrollment marker both expire on that window - it is the
     * number the server actually enforces, not a constant beside it.
     */
    public static function test_the_stored_challenge_expires_on_the_derived_window()
    {
        static::__signed_in_user();

        Rsx_Two_Factor::begin_passkey_registration();

        foreach ([Rsx_Two_Factor::WEBAUTHN_CHALLENGE_KEY, Rsx_Two_Factor::PASSKEY_ENROLLMENT_KEY] as $key) {
            $expires_at = DB::table('_session_values')
                ->where('session_id', Session::get_session_id())
                ->where('value_key', $key)
                ->value('expires_at');

            static::__assert_not_null($expires_at, "{$key} carries an expiry");

            $remaining = Rsx_Time::seconds_until($expires_at);

            static::__assert_greater_than(Passkeys::challenge_window_seconds() - 10, $remaining, "{$key} lasts the window");
            static::__assert_less_than(Passkeys::challenge_window_seconds() + 1, $remaining, "{$key} lasts no longer");
        }
    }

    // -------------------------------------------------------------------------
    // Enrollment outcomes - staff
    // -------------------------------------------------------------------------

    /**
     * A ceremony that completes writes BEGUN then ENROLLED, and spends its marker.
     */
    public static function test_begin_and_confirm_write_begun_and_enrolled_rows()
    {
        $login_user = static::__signed_in_user();
        $authenticator = new Webauthn_Authenticator_Fixture(Passkeys::relying_party_id());

        Rsx_Two_Factor::begin_passkey_registration();

        static::__assert_equals([Login_History::STATUS_PASSKEY_ENROLL_BEGUN], static::__statuses((int) $login_user->id));
        static::__assert_equals($login_user->email, static::__row((int) $login_user->id, Login_History::STATUS_PASSKEY_ENROLL_BEGUN)->email_attempted);

        Rsx_Two_Factor::confirm_passkey_registration(
            $authenticator->attestation_response((string) Session::get_value(Rsx_Two_Factor::WEBAUTHN_CHALLENGE_KEY)),
            'Desk key'
        );

        static::__assert_equals(
            [Login_History::STATUS_PASSKEY_ENROLL_BEGUN, Login_History::STATUS_PASSKEY_ENROLLED],
            static::__statuses((int) $login_user->id)
        );
        static::__assert_null(static::__marker_row(Rsx_Two_Factor::class), 'the marker is spent');
    }

    /**
     * A confirmation that does not verify writes FAILED with the reason, and still throws.
     */
    public static function test_a_refused_confirmation_writes_a_failed_row()
    {
        $login_user = static::__signed_in_user();

        Rsx_Two_Factor::begin_passkey_registration();

        static::__assert_throws(Two_Factor_Failed_Exception::class, function () {
            Rsx_Two_Factor::confirm_passkey_registration(['clientDataJSON' => 'AA'], null);
        });

        $row = static::__row((int) $login_user->id, Login_History::STATUS_PASSKEY_ENROLL_FAILED);
        static::__assert_contains('incomplete', $row->failure_reason);
        static::__assert_null(static::__marker_row(Rsx_Two_Factor::class), 'the marker is spent');
    }

    /**
     * A second begin while the first is still pending supersedes it: ABANDONED, then a new
     * BEGUN.
     */
    public static function test_a_new_begin_abandons_the_pending_enrollment()
    {
        $login_user = static::__signed_in_user();

        Rsx_Two_Factor::begin_passkey_registration();
        Rsx_Two_Factor::begin_passkey_registration();

        static::__assert_equals(
            [
                Login_History::STATUS_PASSKEY_ENROLL_BEGUN,
                Login_History::STATUS_PASSKEY_ENROLL_ABANDONED,
                Login_History::STATUS_PASSKEY_ENROLL_BEGUN,
            ],
            static::__statuses((int) $login_user->id)
        );

        static::__assert_contains(
            'Superseded',
            static::__row((int) $login_user->id, Login_History::STATUS_PASSKEY_ENROLL_ABANDONED)->failure_reason
        );
    }

    /**
     * A begin that finds the previous marker EXPIRED records it as expired, not superseded.
     */
    public static function test_a_new_begin_records_an_expired_enrollment_as_abandoned()
    {
        $login_user = static::__signed_in_user();

        Rsx_Two_Factor::begin_passkey_registration();
        static::__expire_enrollment(Rsx_Two_Factor::class);
        Rsx_Two_Factor::begin_passkey_registration();

        $row = static::__row((int) $login_user->id, Login_History::STATUS_PASSKEY_ENROLL_ABANDONED);
        static::__assert_contains('the challenge expired', $row->failure_reason);
        static::__assert_contains('begun ', $row->failure_reason);
    }

    /**
     * A confirmation arriving after the window is an ABANDONED ceremony - the browser took
     * longer than the server waits - not a refused attestation.
     */
    public static function test_a_late_confirmation_is_recorded_as_abandoned()
    {
        $login_user = static::__signed_in_user();
        $authenticator = new Webauthn_Authenticator_Fixture(Passkeys::relying_party_id());

        Rsx_Two_Factor::begin_passkey_registration();
        $challenge = (string) Session::get_value(Rsx_Two_Factor::WEBAUTHN_CHALLENGE_KEY);
        static::__expire_enrollment(Rsx_Two_Factor::class);

        static::__assert_throws(Two_Factor_Failed_Exception::class, function () use ($authenticator, $challenge) {
            Rsx_Two_Factor::confirm_passkey_registration($authenticator->attestation_response($challenge), null);
        }, 'expired');

        static::__assert_equals(
            [Login_History::STATUS_PASSKEY_ENROLL_BEGUN, Login_History::STATUS_PASSKEY_ENROLL_ABANDONED],
            static::__statuses((int) $login_user->id)
        );
        static::__assert_contains(
            'after the challenge expired',
            static::__row((int) $login_user->id, Login_History::STATUS_PASSKEY_ENROLL_ABANDONED)->failure_reason
        );
    }

    /**
     * The hourly session-values sweep records an expired, never-confirmed enrollment as
     * ABANDONED - against the browser that began it - before deleting the marker, and
     * records it once.
     */
    public static function test_the_session_values_sweep_records_an_expired_enrollment()
    {
        $login_user = static::__signed_in_user();

        Rsx_Two_Factor::begin_passkey_registration();
        $begun = static::__row((int) $login_user->id, Login_History::STATUS_PASSKEY_ENROLL_BEGUN);
        static::__expire_enrollment(Rsx_Two_Factor::class);

        $result = Session_Values_Cleanup_Service::cleanup_expired_values(
            new Task_Instance(Session_Values_Cleanup_Service::class, 'cleanup_expired_values')
        );

        static::__assert_greater_than(0, $result['abandoned_passkey_enrollments']);

        $row = static::__row((int) $login_user->id, Login_History::STATUS_PASSKEY_ENROLL_ABANDONED);
        static::__assert_contains('the challenge expired', $row->failure_reason);
        static::__assert_equals($begun->ip_address, $row->ip_address, 'attributed to the browser that began it');
        static::__assert_null(static::__marker_row(Rsx_Two_Factor::class), 'the marker is gone');

        static::__assert_equals(0, Rsx_Two_Factor::record_expired_passkey_enrollments(), 'recorded once');

        static::__assert_equals(
            [Login_History::STATUS_PASSKEY_ENROLL_BEGUN, Login_History::STATUS_PASSKEY_ENROLL_ABANDONED],
            static::__statuses((int) $login_user->id)
        );
    }

    /**
     * A marker still inside its window is not the sweep's to record.
     */
    public static function test_the_sweep_leaves_a_live_enrollment_alone()
    {
        static::__signed_in_user();

        Rsx_Two_Factor::begin_passkey_registration();

        Rsx_Two_Factor::record_expired_passkey_enrollments();

        static::__assert_not_null(static::__marker_row(Rsx_Two_Factor::class), 'the live marker stays');
    }

    // -------------------------------------------------------------------------
    // Enrollment outcomes - portal
    // -------------------------------------------------------------------------

    /**
     * The portal has no login history: its outcomes are log lines, and no row is written.
     */
    public static function test_portal_enrollment_outcomes_are_logged_not_stored()
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            if ($event->message === 'Portal passkey enrollment') {
                $logged[] = $event->context;
            }
        });

        $portal_user = static::__signed_in_portal_user();
        $history_before = DB::table('_login_history')->count();

        Rsx_Portal_Two_Factor::begin_passkey_registration();
        Rsx_Portal_Two_Factor::begin_passkey_registration();

        static::__assert_equals(
            [
                Login_History::STATUS_PASSKEY_ENROLL_BEGUN,
                Login_History::STATUS_PASSKEY_ENROLL_ABANDONED,
                Login_History::STATUS_PASSKEY_ENROLL_BEGUN,
            ],
            array_column($logged, 'status')
        );
        static::__assert_equals((int) $portal_user->id, $logged[0]['portal_user_id']);
        static::__assert_equals($history_before, DB::table('_login_history')->count(), 'no _login_history row');
    }
}
