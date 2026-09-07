<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TwoFactor\Php;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\TwoFactor\Recovery_Codes;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Totp;
use App\RSpade\Core\TwoFactor\Two_Factor_Credential_Model;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;

/**
 * Enrollment, through the facade, exactly as an application would drive it.
 *
 * THE CONTRACT UNDER TEST is that a factor becomes usable only when it has been PROVEN:
 * begin_totp_enrollment() parks a seed and writes no row, and confirm_totp_enrollment()
 * writes a confirmed row only against a live code. A failed confirmation must leave the
 * identity with no second factor at all - if it left an unconfirmed row that a login
 * challenge could later match, the ceremony would have been decorative.
 *
 * ALSO PINNED: the seed is ENCRYPTED at rest and decrypts back to the value the user
 * scanned (the whole subsystem breaks quietly if it does not - codes would simply never
 * match), the QR code is inline-embeddable, and enrollment REFUSES while impersonating.
 *
 * THE IMPERSONATION SEAM. Session::cli_set_impersonator_login_user_id() is the raw CLI
 * setter that makes Session::is_impersonating() true without a web request; it mirrors what
 * begin_impersonation() records on a real session row, and it is the seam this harness
 * supports. It is cleared in teardown and by static::__reset_session().
 *
 * SESSIONS IN CLI. Identity is declared through Session::set_login_user_id(), which in CLI
 * sets a static and creates nothing. The session ROW arrives on demand, at the first
 * Session::put_value() - so every test calls static::__reset_session() first, which ends
 * the process's cached CLI session. Without that reset the row minted inside one test's
 * transaction would be rolled back while its id stayed cached, and the next put_value()
 * would insert against a session that no longer exists.
 */
class Two_Factor_Enrollment_Test extends Rsx_Test_Abstract
{
    private const PASSWORD = 'correct-horse-battery-staple';

    public static function teardown()
    {
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    /**
     * A live login identity.
     */
    private static function __make_login_user(): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'enroll_' . uniqid() . '@example.com';
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    /**
     * Start from a known state and sign the given identity in.
     */
    private static function __sign_in(Login_User_Model $login_user): void
    {
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
        Session::set_login_user_id((int) $login_user->id);
    }

    /**
     * A fresh signed-in identity, ready to enroll.
     */
    private static function __signed_in_user(): Login_User_Model
    {
        $login_user = static::__make_login_user();
        static::__sign_in($login_user);

        return $login_user;
    }

    private static function __confirmed_rows(int $login_user_id, int $type_id): int
    {
        return Two_Factor_Credential_Model::where('login_user_id', $login_user_id)
            ->where('type_id', $type_id)
            ->whereNotNull('confirmed_at')
            ->count();
    }

    /**
     * A code that is live right now for the given seed.
     */
    private static function __live_code(string $secret): string
    {
        return Totp::code_for($secret, intdiv(time(), Totp::PERIOD));
    }

    // -------------------------------------------------------------------------
    // Beginning a TOTP enrollment
    // -------------------------------------------------------------------------

    /**
     * begin_totp_enrollment() hands back everything the setup screen needs and writes NO
     * row: an enrollment the user walks away from must leave nothing behind.
     */
    public static function test_begin_totp_enrollment_parks_a_seed_and_writes_no_row()
    {
        $login_user = static::__signed_in_user();

        $started = Rsx_Two_Factor::begin_totp_enrollment();

        static::__assert_array_has_key('secret', $started);
        static::__assert_array_has_key('otpauth_uri', $started);
        static::__assert_array_has_key('qr_svg', $started);

        static::__assert_equals(32, strlen($started['secret']), '160 bits of base32');
        static::__assert_contains('otpauth://totp/', $started['otpauth_uri']);
        static::__assert_contains('secret=' . $started['secret'], $started['otpauth_uri']);

        static::__assert_equals(
            0,
            Two_Factor_Credential_Model::where('login_user_id', $login_user->id)->count(),
            'nothing is written until the code is proven'
        );

        static::__assert_false(
            Rsx_Two_Factor::is_enabled($login_user),
            'a parked seed is not a second factor'
        );

        static::__assert_equals(
            $started['secret'],
            Session::get_value(Rsx_Two_Factor::TOTP_PENDING_KEY),
            'the seed is parked on the session'
        );
    }

    /**
     * The QR code is an SVG document that starts at <svg - the XML prologue the renderer
     * emits is stripped, because the string is embedded inside an existing HTML page where a
     * prologue partway down is invalid markup.
     */
    public static function test_the_qr_code_is_inline_embeddable_svg()
    {
        static::__signed_in_user();

        $svg = Rsx_Two_Factor::begin_totp_enrollment()['qr_svg'];

        static::__assert_true(str_starts_with($svg, '<svg'), 'starts at the svg element');
        static::__assert_false(str_contains($svg, '<?xml'), 'no XML declaration survives');
        static::__assert_contains('</svg>', $svg, 'and it is a complete document');
        static::__assert_greater_than(500, strlen($svg), 'it actually encodes something');
    }

    // -------------------------------------------------------------------------
    // Confirming a TOTP enrollment
    // -------------------------------------------------------------------------

    /**
     * The happy path: a live code confirms the factor, the seed round-trips through Crypt,
     * the consumed timestep is persisted, and the recovery codes are minted in the same
     * breath.
     */
    public static function test_confirming_with_a_live_code_enrolls_the_factor()
    {
        $login_user = static::__signed_in_user();
        $id = (int) $login_user->id;

        $started = Rsx_Two_Factor::begin_totp_enrollment();
        $secret = $started['secret'];

        // Capture the timestep and mint the code from the SAME value, so the replay-floor
        // assertion below cannot race a 30-second TOTP boundary. verify() persists the step
        // the code was generated FOR (it matches that step within its drift window and
        // returns it), which is exactly $step - NOT intdiv(time(),PERIOD) re-read after the
        // confirm, which advances by one whenever the wall clock crosses a period boundary
        // between minting and asserting. That boundary crossing was a ~1-in-N flake.
        $step = intdiv(time(), Totp::PERIOD);
        $codes = Rsx_Two_Factor::confirm_totp_enrollment(Totp::code_for($secret, $step));

        static::__assert_true(Rsx_Two_Factor::is_enabled($login_user), 'the identity now has 2FA');
        static::__assert_equals(1, static::__confirmed_rows($id, Two_Factor_Credential_Model::TYPE_TOTP));

        $row = Two_Factor_Credential_Model::where('login_user_id', $id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_TOTP)
            ->first();

        static::__assert_not_null($row->confirmed_at, 'the row is confirmed');

        // ENCRYPTED, not hashed: the server has to regenerate the same codes the phone does.
        static::__assert_not_equals($secret, $row->secret, 'the seed is not stored in the clear');
        static::__assert_equals($secret, Crypt::decryptString($row->secret), 'and it decrypts back');

        static::__assert_equals(
            $step,
            (int) $row->counter,
            'the consumed timestep is persisted as the replay floor'
        );

        static::__assert_count(Recovery_Codes::COUNT, $codes, 'recovery codes are minted with it');
        static::__assert_equals(Recovery_Codes::COUNT, Rsx_Two_Factor::recovery_codes_remaining($login_user));

        static::__assert_null(
            Session::get_value(Rsx_Two_Factor::TOTP_PENDING_KEY),
            'the pending seed is forgotten'
        );
    }

    /**
     * A WRONG code refuses and leaves NO confirmed row. This is the test that would catch an
     * implementation which wrote the row first and verified afterwards.
     */
    public static function test_confirming_with_a_wrong_code_leaves_no_confirmed_row()
    {
        $login_user = static::__signed_in_user();
        $id = (int) $login_user->id;

        $started = Rsx_Two_Factor::begin_totp_enrollment();

        // A code that is well-formed and belongs to this seed, but at a timestep far outside
        // the drift window - so this is a genuine verification failure, not a shape rejection.
        $wrong = Totp::code_for($started['secret'], intdiv(time(), Totp::PERIOD) + 5000);

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::confirm_totp_enrollment($wrong),
            'not valid'
        );

        static::__assert_equals(
            0,
            Two_Factor_Credential_Model::where('login_user_id', $id)->count(),
            'no row of any kind was written'
        );

        static::__assert_false(Rsx_Two_Factor::is_enabled($login_user), 'still no second factor');
        static::__assert_equals(0, Rsx_Two_Factor::recovery_codes_remaining($login_user), 'and no codes');
    }

    /**
     * A mistyped code does NOT discard the parked seed - making the user rescan the QR code
     * for a typo would be hostile, and the window on the session value is what bounds the
     * retries. The next attempt with the right code succeeds.
     */
    public static function test_a_failed_confirmation_keeps_the_pending_seed()
    {
        $login_user = static::__signed_in_user();

        $started = Rsx_Two_Factor::begin_totp_enrollment();

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::confirm_totp_enrollment('000000')
        );

        static::__assert_equals(
            $started['secret'],
            Session::get_value(Rsx_Two_Factor::TOTP_PENDING_KEY),
            'the seed is still parked'
        );

        Rsx_Two_Factor::confirm_totp_enrollment(static::__live_code($started['secret']));

        static::__assert_true(Rsx_Two_Factor::is_enabled($login_user), 'the retry enrolls it');
    }

    /**
     * Confirming with nothing parked is refused - there is no seed to have proved anything
     * about.
     */
    public static function test_confirming_with_nothing_parked_is_refused()
    {
        static::__signed_in_user();

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::confirm_totp_enrollment('123456'),
            'expired'
        );
    }

    // -------------------------------------------------------------------------
    // Who may enroll
    // -------------------------------------------------------------------------

    /**
     * ENROLLMENT REFUSES WHILE IMPERSONATING. An administrator viewing an account must not
     * be able to attach a second factor to it - that would be an authentication backdoor
     * wearing a support tool's clothes.
     */
    public static function test_enrollment_refuses_while_impersonating()
    {
        $administrator = static::__make_login_user();
        $target = static::__signed_in_user();

        Session::cli_set_impersonator_login_user_id((int) $administrator->id);
        static::__assert_true(Session::is_impersonating(), 'the seam is in effect');

        static::__assert_throws(
            RuntimeException::class,
            fn () => Rsx_Two_Factor::begin_totp_enrollment(),
            'impersonating'
        );

        static::__assert_throws(
            RuntimeException::class,
            fn () => Rsx_Two_Factor::begin_passkey_registration(),
            'impersonating'
        );

        static::__assert_throws(
            RuntimeException::class,
            fn () => Rsx_Two_Factor::regenerate_recovery_codes(),
            'impersonating'
        );

        static::__assert_equals(
            0,
            Two_Factor_Credential_Model::where('login_user_id', $target->id)->count(),
            'and nothing was written on the way to refusing'
        );
    }

    /**
     * Enrollment operates on the SIGNED-IN identity, so with nobody signed in there is
     * nothing to enroll.
     */
    public static function test_enrollment_refuses_when_nobody_is_signed_in()
    {
        Session::logout();
        static::__reset_session();

        static::__assert_throws(
            RuntimeException::class,
            fn () => Rsx_Two_Factor::begin_totp_enrollment(),
            'signed-in'
        );
    }

    // -------------------------------------------------------------------------
    // Listing, regenerating and removing
    // -------------------------------------------------------------------------

    /**
     * list_credentials() is METADATA ONLY. Its output reaches a settings screen, so no
     * column of this table may appear in it - the seed above all.
     */
    public static function test_list_credentials_never_leaks_a_secret()
    {
        $login_user = static::__signed_in_user();

        $started = Rsx_Two_Factor::begin_totp_enrollment();
        Rsx_Two_Factor::confirm_totp_enrollment(static::__live_code($started['secret']));

        $listed = Rsx_Two_Factor::list_credentials($login_user);

        static::__assert_count(1, $listed, 'the recovery codes are a count, not a list');

        $entry = $listed[0];

        static::__assert_equals(
            ['id', 'type_id', 'type_id__label', 'label', 'confirmed_at', 'last_used_at'],
            array_keys($entry),
            'exactly the metadata keys, and no others'
        );

        static::__assert_equals(Two_Factor_Credential_Model::TYPE_TOTP, $entry['type_id']);
        static::__assert_equals('Authenticator App', $entry['type_id__label']);

        static::__assert_false(
            str_contains(json_encode($listed), $started['secret']),
            'the seed appears nowhere in the payload'
        );
    }

    /**
     * regenerate_recovery_codes() replaces the set and returns the new plaintext - the old
     * sheet stops working immediately, which is what a user who thinks theirs was seen is
     * asking for.
     */
    public static function test_regenerate_recovery_codes_replaces_the_set()
    {
        $login_user = static::__signed_in_user();
        $id = (int) $login_user->id;

        $started = Rsx_Two_Factor::begin_totp_enrollment();
        $original = Rsx_Two_Factor::confirm_totp_enrollment(static::__live_code($started['secret']));

        $replacement = Rsx_Two_Factor::regenerate_recovery_codes();

        static::__assert_count(Recovery_Codes::COUNT, $replacement);
        static::__assert_empty(array_intersect($original, $replacement), 'a genuinely new set');
        static::__assert_equals(Recovery_Codes::COUNT, Rsx_Two_Factor::recovery_codes_remaining($login_user));

        static::__assert_false(Recovery_Codes::consume($id, $original[0]), 'the old sheet is dead');
        static::__assert_true(Recovery_Codes::consume($id, $replacement[0]), 'the new one works');
    }

    /**
     * REMOVING THE LAST FACTOR TAKES THE RECOVERY CODES WITH IT. Left behind they would not
     * be a recovery path - they would be ten bearer tokens that sign somebody in with no
     * second step at all.
     */
    public static function test_removing_the_last_factor_removes_the_recovery_codes()
    {
        $login_user = static::__signed_in_user();
        $id = (int) $login_user->id;

        $started = Rsx_Two_Factor::begin_totp_enrollment();
        Rsx_Two_Factor::confirm_totp_enrollment(static::__live_code($started['secret']));

        static::__assert_equals(Recovery_Codes::COUNT, Rsx_Two_Factor::recovery_codes_remaining($login_user));

        $credential_id = Rsx_Two_Factor::list_credentials($login_user)[0]['id'];
        Rsx_Two_Factor::remove_credential($login_user, $credential_id);

        static::__assert_false(Rsx_Two_Factor::is_enabled($login_user), 'the factor is gone');
        static::__assert_equals(0, Rsx_Two_Factor::recovery_codes_remaining($login_user), 'and so are the codes');

        static::__assert_equals(
            0,
            Two_Factor_Credential_Model::where('login_user_id', $id)->count(),
            'the identity holds no credential of any kind'
        );
    }

    /**
     * Removing a credential that belongs to somebody else does nothing - the scope is part
     * of the query, not something a caller can talk past.
     */
    public static function test_remove_credential_is_scoped_to_its_own_identity()
    {
        $victim = static::__signed_in_user();
        $started = Rsx_Two_Factor::begin_totp_enrollment();
        Rsx_Two_Factor::confirm_totp_enrollment(static::__live_code($started['secret']));

        $victim_credential_id = Rsx_Two_Factor::list_credentials($victim)[0]['id'];

        $attacker = static::__signed_in_user();
        Rsx_Two_Factor::remove_credential($attacker, $victim_credential_id);

        static::__assert_true(
            Rsx_Two_Factor::is_enabled($victim),
            'another identity cannot remove my second factor'
        );
    }

    /**
     * remove_all() clears everything, recovery codes included - the administrative unlock.
     */
    public static function test_remove_all_clears_every_credential()
    {
        $login_user = static::__signed_in_user();

        $started = Rsx_Two_Factor::begin_totp_enrollment();
        Rsx_Two_Factor::confirm_totp_enrollment(static::__live_code($started['secret']));

        Rsx_Two_Factor::remove_all($login_user);

        static::__assert_false(Rsx_Two_Factor::is_enabled($login_user));
        static::__assert_equals(0, Rsx_Two_Factor::recovery_codes_remaining($login_user));
        static::__assert_empty(Rsx_Two_Factor::list_credentials($login_user));
    }

    /**
     * is_enabled() asks about FACTORS. An identity holding only recovery codes has no second
     * factor to be challenged for, so it must answer false - otherwise a login challenge
     * would be raised that only a recovery code could answer, burning the sheet on every
     * sign-in.
     */
    public static function test_recovery_codes_alone_are_not_a_second_factor()
    {
        $login_user = static::__signed_in_user();

        Recovery_Codes::store_for((int) $login_user->id, Recovery_Codes::generate());

        static::__assert_equals(Recovery_Codes::COUNT, Rsx_Two_Factor::recovery_codes_remaining($login_user));
        static::__assert_false(Rsx_Two_Factor::is_enabled($login_user), 'codes alone are not a factor');
        static::__assert_empty(Rsx_Two_Factor::list_credentials($login_user), 'and are not listed as one');
    }
}
