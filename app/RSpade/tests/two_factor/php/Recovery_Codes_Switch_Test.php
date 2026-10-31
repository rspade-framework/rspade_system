<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TwoFactor\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use App\RSpade\Core\Confirmation\Rsx_Password_Confirmation;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\TwoFactor\Passkeys;
use App\RSpade\Core\TwoFactor\Recovery_Codes;
use App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor_Controller;
use App\RSpade\Core\TwoFactor\Totp;
use App\RSpade\Core\TwoFactor\Two_Factor_Credential_Model;
use App\RSpade\Tests\TwoFactor\Php\Webauthn_Authenticator_Fixture;

/**
 * rsx.two_factor.recovery_codes - whether the deployment issues recovery codes at all.
 *
 * THE CONTRACT UNDER TEST has two halves, and the second is the one that is easy to break:
 *
 *  - OFF MEANS NOTHING MINTS. Not at a TOTP enrollment, not at a passkey registration, not
 *    on the operator path, not by regeneration - and Recovery_Codes::store_for(), the one
 *    writer, refuses outright, so a path nobody thought of cannot mint either.
 *  - OFF CHANGES NOTHING ELSE. A code an identity already holds is still there, is still
 *    reported by the challenge state, and still signs its owner in. The switch is not a
 *    second place the challenge reads what it accepts.
 *
 * Default isolation: every write is a row, rolled back with the per-test transaction. The
 * config override is restored in a finally by __with_recovery_codes().
 */
class Recovery_Codes_Switch_Test extends Rsx_Test_Abstract
{
    /** The subject belongs to a browser: each test acts as one (Rsx_Test_Abstract::__as_browser()). */
    protected static $acts_as_browser = true;

    public static function setup()
    {
        // The endpoints under test sit behind a password confirmation, which is not this
        // class's subject (tests/password_confirmation).
        Rsx_Password_Confirmation::$force_confirmed_for_tests = true;
    }

    public static function teardown()
    {
        Rsx_Password_Confirmation::$force_confirmed_for_tests = null;
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    private static function __with_recovery_codes($value, callable $fn): void
    {
        $saved = config('rsx.two_factor');

        config(['rsx.two_factor' => array_merge($saved, ['recovery_codes' => $value])]);

        try {
            $fn();
        } finally {
            config(['rsx.two_factor' => $saved]);
        }
    }

    private static function __start_anonymous(): void
    {
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    /**
     * A fresh, signed-in login identity.
     */
    private static function __signed_in_user(): Login_User_Model
    {
        static::__start_anonymous();

        $login_user = new Login_User_Model();
        $login_user->email = 'rc_switch_' . uniqid() . '@example.com';
        $login_user->password = Hash::make('correct-horse-battery-staple');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        // An enabled site membership is part of being able to sign in: RsxAuth::login()
        // refuses an identity that holds none.
        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->email = $login_user->email;
        $user->first_name = 'Fixture';
        $user->last_name = 'Identity';
        $user->is_enabled = 1;
        $user->save();

        // The failure counter lives in redis, keyed by id, outside the rolled-back transaction.
        Rsx_Two_Factor::clear_failures($login_user);

        Session::set_login_user_id((int) $login_user->id);

        return $login_user;
    }

    /**
     * Enroll an authenticator app for the signed-in identity; returns what
     * confirm_totp_enrollment() returned.
     */
    private static function __enroll_totp(): ?array
    {
        $started = Rsx_Two_Factor::begin_totp_enrollment();

        return Rsx_Two_Factor::confirm_totp_enrollment(
            Totp::code_for($started['secret'], intdiv(time(), Totp::PERIOD))
        );
    }

    private static function __recovery_rows(int $login_user_id): int
    {
        return Two_Factor_Credential_Model::where('login_user_id', $login_user_id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_RECOVERY_CODE)
            ->count();
    }

    // -------------------------------------------------------------------------
    // The switch itself
    // -------------------------------------------------------------------------

    /**
     * tfa-rcs-01: the shipped default issues recovery codes, and both realms read the one key.
     */
    public static function test_the_default_issues_codes_and_both_realms_read_one_key()
    {
        static::__assert_true(Rsx_Two_Factor::issues_recovery_codes(), 'on by default');
        static::__assert_true(Rsx_Portal_Two_Factor::issues_recovery_codes(), 'on by default for the portal');

        static::__with_recovery_codes(false, function () {
            static::__assert_false(Rsx_Two_Factor::issues_recovery_codes());
            static::__assert_false(Rsx_Portal_Two_Factor::issues_recovery_codes(), 'the portal reads the same key');
        });
    }

    /**
     * tfa-rcs-02: a value that is not a boolean fails loud rather than being coerced.
     */
    public static function test_a_non_boolean_value_fails_loud()
    {
        foreach (['false', 0, null] as $value) {
            static::__with_recovery_codes($value, function () {
                static::__assert_throws(
                    RuntimeException::class,
                    fn () => Rsx_Two_Factor::issues_recovery_codes(),
                    'rsx.two_factor.recovery_codes must be true or false'
                );
            });
        }
    }

    // -------------------------------------------------------------------------
    // Off: nothing mints
    // -------------------------------------------------------------------------

    /**
     * tfa-rcs-03: a TOTP enrollment confirms the factor and returns null, with no code row.
     */
    public static function test_off_a_totp_enrollment_mints_no_codes()
    {
        $login_user = static::__signed_in_user();

        static::__with_recovery_codes(false, function () use ($login_user) {
            static::__assert_null(static::__enroll_totp(), 'no sheet to reveal');

            static::__assert_true(Rsx_Two_Factor::is_enabled($login_user), 'the factor is live');
            static::__assert_equals(0, static::__recovery_rows((int) $login_user->id), 'no recovery-code row exists');
            static::__assert_equals(0, Rsx_Two_Factor::recovery_codes_remaining($login_user));
        });
    }

    /**
     * tfa-rcs-04: the endpoint the shipped component posts to answers recovery_codes null,
     * which is what makes <Totp_Enrollment> skip the reveal.
     */
    public static function test_off_the_totp_confirm_endpoint_answers_null_codes()
    {
        static::__signed_in_user();

        static::__with_recovery_codes(false, function () {
            $started = Rsx_Two_Factor::begin_totp_enrollment();

            $payload = Rsx_Two_Factor_Controller::totp_confirm(
                Request::create('/_ajax', 'POST'),
                ['code' => Totp::code_for($started['secret'], intdiv(time(), Totp::PERIOD))]
            );

            static::__assert_equals(['recovery_codes' => null], $payload);
        });
    }

    /**
     * tfa-rcs-05: a first passkey mints no codes either.
     */
    public static function test_off_a_passkey_registration_mints_no_codes()
    {
        $login_user = static::__signed_in_user();

        static::__with_recovery_codes(false, function () use ($login_user) {
            $authenticator = new Webauthn_Authenticator_Fixture(Passkeys::relying_party_id());

            Rsx_Two_Factor::begin_passkey_registration();

            $codes = Rsx_Two_Factor::confirm_passkey_registration(
                $authenticator->attestation_response((string) Session::get_value(Rsx_Two_Factor::WEBAUTHN_CHALLENGE_KEY)),
                'Desk key'
            );

            static::__assert_null($codes);
            static::__assert_true(Rsx_Two_Factor::has_passkey($login_user), 'the passkey is live');
            static::__assert_equals(0, static::__recovery_rows((int) $login_user->id));
        });
    }

    /**
     * tfa-rcs-06: the operator path arms the factor and reports no codes, in both output forms.
     */
    public static function test_off_the_operator_setup_mints_no_codes()
    {
        $login_user = static::__signed_in_user();
        static::__start_anonymous();

        static::__with_recovery_codes(false, function () use ($login_user) {
            $code = Artisan::call('rsx:users:2fa:setup', ['--user' => (string) $login_user->id, '--json' => true]);
            $envelope = json_decode(Artisan::output(), true);

            static::__assert_equals(0, $code);
            static::__assert_true($envelope['ok']);
            static::__assert_null($envelope['data']['recovery_codes'], 'null, not an empty list');
            static::__assert_not_empty($envelope['data']['secret']);
            static::__assert_equals(0, static::__recovery_rows((int) $login_user->id));

            Rsx_Two_Factor::remove_all($login_user);

            $code = Artisan::call('rsx:users:2fa:setup', ['--user' => (string) $login_user->id]);
            $output = Artisan::output();

            static::__assert_equals(0, $code);
            static::__assert_contains('this deployment issues no recovery codes', $output);
            static::__assert_equals(0, static::__recovery_rows((int) $login_user->id));
        });
    }

    /**
     * tfa-rcs-07: regeneration throws and leaves an existing sheet exactly as it was.
     */
    public static function test_off_regeneration_throws_and_changes_nothing()
    {
        $login_user = static::__signed_in_user();
        $codes = static::__enroll_totp();
        static::__assert_count(Recovery_Codes::COUNT, $codes, 'issued while the switch was on');

        static::__with_recovery_codes(false, function () use ($login_user) {
            static::__assert_throws(
                RuntimeException::class,
                fn () => Rsx_Two_Factor::regenerate_recovery_codes(),
                'issues no recovery codes'
            );

            static::__assert_equals(
                Recovery_Codes::COUNT,
                Rsx_Two_Factor::recovery_codes_remaining($login_user),
                'the refusal removed nothing'
            );
        });
    }

    /**
     * tfa-rcs-08: the one writer refuses, in either realm, before it touches a row.
     */
    public static function test_off_the_storage_writer_refuses_in_both_realms()
    {
        $login_user = static::__signed_in_user();

        static::__with_recovery_codes(false, function () use ($login_user) {
            foreach ([Rsx_Two_Factor::class, Rsx_Portal_Two_Factor::class] as $realm) {
                static::__assert_throws(
                    RuntimeException::class,
                    fn () => Recovery_Codes::store_for($realm, (int) $login_user->id, Recovery_Codes::generate()),
                    'issues no recovery codes'
                );
            }

            static::__assert_equals(0, static::__recovery_rows((int) $login_user->id));
        });
    }

    // -------------------------------------------------------------------------
    // Off: what an identity already holds is untouched
    // -------------------------------------------------------------------------

    /**
     * tfa-rcs-09: a code issued while the switch was on is still reported by the challenge
     * state and still signs its owner in after the switch goes off.
     */
    public static function test_off_an_existing_code_is_still_reported_and_still_accepted()
    {
        $login_user = static::__signed_in_user();
        $codes = static::__enroll_totp();

        static::__with_recovery_codes(false, function () use ($login_user, $codes) {
            static::__start_anonymous();
            Rsx_Two_Factor::begin_challenge($login_user);

            $pending = Rsx_Two_Factor::challenge_pending();
            static::__assert_true($pending['has_recovery_codes'], 'derived from what the identity holds');

            Rsx_Two_Factor::verify_challenge(['code' => $codes[0]]);

            static::__assert_equals((int) $login_user->id, (int) Session::get_login_user_id(), 'signed in on the code');
            static::__assert_equals(Recovery_Codes::COUNT - 1, Rsx_Two_Factor::recovery_codes_remaining($login_user));
        });
    }

    /**
     * tfa-rcs-10: an identity enrolled while the switch was off holds no codes, and its
     * challenge does not offer the recovery-code answer.
     */
    public static function test_off_an_identity_with_no_codes_is_not_offered_the_answer()
    {
        $login_user = static::__signed_in_user();

        static::__with_recovery_codes(false, function () use ($login_user) {
            static::__enroll_totp();

            static::__start_anonymous();
            Rsx_Two_Factor::begin_challenge($login_user);

            $pending = Rsx_Two_Factor::challenge_pending();
            static::__assert_false($pending['has_recovery_codes']);
            static::__assert_true($pending['has_totp']);
        });
    }

    /**
     * tfa-rcs-11: the settings payload says whether the deployment issues codes, beside the
     * count of what the identity holds - two separate facts.
     */
    public static function test_the_settings_payload_reports_the_switch_beside_the_count()
    {
        static::__signed_in_user();
        static::__enroll_totp();

        $on = Rsx_Two_Factor_Controller::credentials_list(Request::create('/_ajax', 'POST'));
        static::__assert_true($on['issues_recovery_codes']);
        static::__assert_equals(Recovery_Codes::COUNT, $on['recovery_codes_remaining']);

        static::__with_recovery_codes(false, function () {
            $off = Rsx_Two_Factor_Controller::credentials_list(Request::create('/_ajax', 'POST'));

            static::__assert_false($off['issues_recovery_codes'], 'no regeneration to offer');
            static::__assert_equals(Recovery_Codes::COUNT, $off['recovery_codes_remaining'], 'the held codes are still counted');
        });
    }

    // -------------------------------------------------------------------------
    // Removing what is held, and turning the switch back on
    // -------------------------------------------------------------------------

    /**
     * tfa-rcs-12: remove_recovery_codes() and rsx:users:2fa:remove --recovery-codes take the
     * sheet and leave every factor.
     */
    public static function test_recovery_codes_can_be_removed_without_touching_a_factor()
    {
        $login_user = static::__signed_in_user();
        static::__enroll_totp();

        static::__assert_equals(Recovery_Codes::COUNT, Rsx_Two_Factor::remove_recovery_codes($login_user));
        static::__assert_equals(0, Rsx_Two_Factor::recovery_codes_remaining($login_user));
        static::__assert_true(Rsx_Two_Factor::is_enabled($login_user), 'the factor is kept');
        static::__assert_equals(0, Rsx_Two_Factor::remove_recovery_codes($login_user), 'nothing left to remove');

        Rsx_Two_Factor::regenerate_recovery_codes();

        $code = Artisan::call('rsx:users:2fa:remove', [
            '--user' => (string) $login_user->id,
            '--recovery-codes' => true,
            '--force' => true,
            '--json' => true,
        ]);
        $envelope = json_decode(Artisan::output(), true);

        static::__assert_equals(0, $code);
        static::__assert_equals('removed_recovery_codes', $envelope['data']['action']);
        static::__assert_equals(Recovery_Codes::COUNT, $envelope['data']['recovery_codes_removed']);
        static::__assert_equals([], $envelope['data']['removed'], 'no factor was removed');
        static::__assert_true($envelope['data']['is_enabled']);
        static::__assert_true(Rsx_Two_Factor::is_enabled($login_user));
    }

    /**
     * tfa-rcs-13: --recovery-codes and --id are two different removals and are refused together.
     */
    public static function test_recovery_codes_and_id_cannot_be_combined()
    {
        $login_user = static::__signed_in_user();
        static::__enroll_totp();

        $code = Artisan::call('rsx:users:2fa:remove', [
            '--user' => (string) $login_user->id,
            '--recovery-codes' => true,
            '--id' => '1',
            '--force' => true,
            '--json' => true,
        ]);
        $envelope = json_decode(Artisan::output(), true);

        static::__assert_equals(1, $code);
        static::__assert_equals('options_conflict', $envelope['error']['code']);
        static::__assert_equals(Recovery_Codes::COUNT, Rsx_Two_Factor::recovery_codes_remaining($login_user), 'nothing was removed');
    }

    /**
     * tfa-rcs-14: turned back on, nothing is backfilled - an identity enrolled while it was
     * off holds none until it asks for a sheet, and the next enrollment mints one.
     */
    public static function test_turning_it_back_on_issues_at_the_next_enrollment_with_no_backfill()
    {
        $enrolled_while_off = static::__signed_in_user();

        static::__with_recovery_codes(false, function () {
            static::__enroll_totp();
        });

        static::__assert_equals(0, Rsx_Two_Factor::recovery_codes_remaining($enrolled_while_off), 'no backfill');
        static::__assert_count(Recovery_Codes::COUNT, Rsx_Two_Factor::regenerate_recovery_codes(), 'a sheet on request');

        $enrolled_after = static::__signed_in_user();
        static::__assert_count(Recovery_Codes::COUNT, static::__enroll_totp(), 'the next enrollment mints');
        static::__assert_equals(Recovery_Codes::COUNT, Rsx_Two_Factor::recovery_codes_remaining($enrolled_after));
    }
}
