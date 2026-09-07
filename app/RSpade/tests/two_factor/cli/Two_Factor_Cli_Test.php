<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TwoFactor\Cli;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\TwoFactor\Recovery_Codes;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Totp;
use App\RSpade\Core\TwoFactor\Two_Factor_Credential_Model;

/**
 * rsx:users:2fa:setup / :dump / :remove - the operator path, end to end.
 *
 * THE SUBJECT IS THE OPERATOR'S TRUST IN WHAT THESE COMMANDS PRINT. Each of them makes a
 * claim that nothing else in the system will contradict until somebody tries to sign in:
 * setup says "this seed works", dump says "this is the seed", remove says "that account is
 * unlocked now". A seed printed that does not verify, or an id reported removed that
 * belonged to somebody else, is discovered at a login screen days later by a person who
 * cannot get in - so the printed seed is carried into a REAL login challenge here, and the
 * cross-identity removal is proved to refuse rather than to silently no-op.
 *
 * WHY A LIVE CHALLENGE RATHER THAN Totp::verify(). The command bypasses the session-bound
 * enrollment ceremony, which is exactly the part that normally proves a seed before it is
 * stored. Verifying the printed secret against the verifier directly would only re-test
 * Totp; running it through Rsx_Two_Factor::verify_challenge() tests what the operator
 * actually promised the user - that this seed answers this account's challenge.
 *
 * Run in-process through Artisan::call(): these commands write ordinary rows on this
 * connection, so the per-test transaction rolls every credential back. The session dance
 * mirrors tests/two_factor/php - the CLI session is a process static, so it is reset before
 * a challenge is begun and in teardown.
 */
class Two_Factor_Cli_Test extends Rsx_Test_Abstract
{
    private const PASSWORD = 'correct-horse-battery-staple';

    public static function teardown()
    {
        // The per-IP throttle lives outside the transaction, shared with every other test in
        // the run; a challenge here touches it even when it never fails.
        Login_Throttle::reset('CLI');

        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    /**
     * A live login identity, enrolled in nothing.
     */
    private static function __make_login_user(): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'tfa_cli_' . uniqid() . '@example.com';
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    /**
     * Run a command with --json, returning [exit_code, decoded_envelope].
     */
    private static function __json_call(string $command, array $args): array
    {
        $code = Artisan::call($command, $args + ['--json' => true]);

        return [$code, json_decode(Artisan::output(), true)];
    }

    /**
     * Run a command in its human form, returning [exit_code, output].
     */
    private static function __human_call(string $command, array $args): array
    {
        $code = Artisan::call($command, $args);

        return [$code, Artisan::output()];
    }

    /**
     * Arm an identity through the command, returning [login_user, envelope data].
     */
    private static function __setup(Login_User_Model $login_user): array
    {
        [$code, $envelope] = static::__json_call('rsx:users:2fa:setup', [
            '--user' => (string) $login_user->id,
        ]);

        static::__assert_equals(0, $code, 'the setup command succeeds');

        return $envelope['data'];
    }

    // -------------------------------------------------------------------------
    // setup
    // -------------------------------------------------------------------------

    /**
     * tfa-cli-01: setup writes a CONFIRMED credential, mints a full recovery set, and the
     * secret it prints ANSWERS A REAL LOGIN CHALLENGE for that identity.
     */
    public static function test_setup_mints_a_confirmed_factor_whose_printed_secret_works()
    {
        $login_user = static::__make_login_user();
        $data = static::__setup($login_user);

        static::__assert_equals(32, strlen($data['secret']), '160 bits of base32');
        static::__assert_contains('otpauth://totp/', $data['otpauth_uri']);
        static::__assert_contains('secret=' . $data['secret'], $data['otpauth_uri']);
        static::__assert_count(Recovery_Codes::COUNT, $data['recovery_codes'], 'a full recovery sheet');
        static::__assert_equals(
            Recovery_Codes::COUNT,
            Rsx_Two_Factor::recovery_codes_remaining($login_user),
            'and it is stored'
        );

        $rows = Two_Factor_Credential_Model::where('login_user_id', $login_user->id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_TOTP)
            ->whereNotNull('confirmed_at')
            ->get();

        static::__assert_count(1, $rows, 'exactly one confirmed TOTP credential');
        static::__assert_equals('CLI setup', $rows->first()->label);
        static::__assert_equals(0, (int) $rows->first()->counter, 'nothing spent, so the live code still works');
        static::__assert_true(Rsx_Two_Factor::is_enabled($login_user));

        // The claim the operator repeats to the user: this seed signs that account in.
        Session::logout();
        static::__reset_session();
        Rsx_Two_Factor::begin_challenge($login_user);

        $signed_in = Rsx_Two_Factor::verify_challenge([
            'code' => Totp::code_for($data['secret'], intdiv(time(), Totp::PERIOD)),
        ]);

        static::__assert_equals((int) $login_user->id, (int) $signed_in->id, 'the printed secret verifies');
        static::__assert_true(Session::is_logged_in(), 'and it completes the login');
    }

    /**
     * tfa-cli-02: a second setup is REFUSED rather than stacking a second seed, and it names
     * the command that clears the first.
     */
    public static function test_setup_refuses_a_second_seed()
    {
        $login_user = static::__make_login_user();
        static::__setup($login_user);

        [$code, $envelope] = static::__json_call('rsx:users:2fa:setup', [
            '--user' => (string) $login_user->id,
        ]);

        static::__assert_equals(1, $code, 'refused');
        static::__assert_false($envelope['ok']);
        static::__assert_equals('totp_already_enrolled', $envelope['error']['code']);
        static::__assert_contains('rsx:users:2fa:remove', $envelope['error']['message'], 'it names the way out');

        static::__assert_equals(
            1,
            Two_Factor_Credential_Model::where('login_user_id', $login_user->id)
                ->where('type_id', Two_Factor_Credential_Model::TYPE_TOTP)
                ->count(),
            'and nothing was stacked'
        );
    }

    // -------------------------------------------------------------------------
    // dump
    // -------------------------------------------------------------------------

    /**
     * tfa-cli-03: dump reads the seed back DECRYPTED, with the provisioning URI rebuilt - the
     * escape hatch for a user whose phone is gone and whose QR code cannot be rescanned.
     */
    public static function test_dump_reads_the_secret_and_uri_back()
    {
        $login_user = static::__make_login_user();
        $created = static::__setup($login_user);

        [$code, $envelope] = static::__json_call('rsx:users:2fa:dump', [
            '--user' => (string) $login_user->id,
        ]);

        static::__assert_equals(0, $code);
        static::__assert_count(1, $envelope['data']['credentials']);

        $credential = $envelope['data']['credentials'][0];

        static::__assert_equals($created['secret'], $credential['secret'], 'the same seed comes back');
        static::__assert_equals($created['otpauth_uri'], $credential['otpauth_uri']);
        static::__assert_equals(Two_Factor_Credential_Model::TYPE_TOTP, $credential['type_id']);
        static::__assert_not_null($credential['confirmed_at']);
        static::__assert_null($credential['last_used_at'], 'never used yet');
        static::__assert_equals(0, $credential['counter']);
        static::__assert_equals(Recovery_Codes::COUNT, $envelope['data']['recovery_codes_remaining']);
        static::__assert_true($envelope['data']['is_enabled']);

        // The email lookup names the same identity as the id did.
        [$by_email_code, $by_email] = static::__json_call('rsx:users:2fa:dump', [
            '--user' => $login_user->email,
        ]);

        static::__assert_equals(0, $by_email_code);
        static::__assert_equals((int) $login_user->id, $by_email['data']['user']['id']);
    }

    /**
     * tfa-cli-04: an identity enrolled in nothing is a normal, successful answer - an empty
     * list and a zero count, not an error.
     */
    public static function test_dump_of_an_unenrolled_identity_is_empty_and_succeeds()
    {
        $login_user = static::__make_login_user();

        [$code, $envelope] = static::__json_call('rsx:users:2fa:dump', [
            '--user' => (string) $login_user->id,
        ]);

        static::__assert_equals(0, $code);
        static::__assert_true($envelope['ok']);
        static::__assert_equals([], $envelope['data']['credentials']);
        static::__assert_equals(0, $envelope['data']['recovery_codes_remaining']);
        static::__assert_false($envelope['data']['is_enabled']);

        [$human_code, $output] = static::__human_call('rsx:users:2fa:dump', [
            '--user' => (string) $login_user->id,
        ]);

        static::__assert_equals(0, $human_code);
        static::__assert_contains('[OK] No two-factor credentials', $output);
    }

    // -------------------------------------------------------------------------
    // remove
    // -------------------------------------------------------------------------

    /**
     * tfa-cli-05: removing everything leaves the identity signing in with a password alone,
     * recovery codes included.
     */
    public static function test_remove_all_empties_the_identity()
    {
        $login_user = static::__make_login_user();
        static::__setup($login_user);

        [$code, $envelope] = static::__json_call('rsx:users:2fa:remove', [
            '--user' => (string) $login_user->id,
            '--force' => true,
        ]);

        static::__assert_equals(0, $code);
        static::__assert_equals('removed_all', $envelope['data']['action']);
        static::__assert_count(1, $envelope['data']['removed']);
        static::__assert_equals(Recovery_Codes::COUNT, $envelope['data']['recovery_codes_removed']);
        static::__assert_false($envelope['data']['is_enabled']);

        static::__assert_equals(
            0,
            Two_Factor_Credential_Model::where('login_user_id', $login_user->id)->count(),
            'no rows of any kind survive'
        );
        static::__assert_false(Rsx_Two_Factor::is_enabled($login_user));
        static::__assert_equals(0, Rsx_Two_Factor::recovery_codes_remaining($login_user));
    }

    /**
     * tfa-cli-06: --id removes exactly that credential, and the recovery codes cascade with
     * it because it was the last factor.
     */
    public static function test_remove_by_id_removes_that_credential_and_cascades()
    {
        $login_user = static::__make_login_user();
        static::__setup($login_user);

        $credential_id = (int) Two_Factor_Credential_Model::where('login_user_id', $login_user->id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_TOTP)
            ->value('id');

        [$code, $envelope] = static::__json_call('rsx:users:2fa:remove', [
            '--user' => (string) $login_user->id,
            '--id' => (string) $credential_id,
            '--force' => true,
        ]);

        static::__assert_equals(0, $code);
        static::__assert_equals('removed', $envelope['data']['action']);
        static::__assert_equals($credential_id, $envelope['data']['removed'][0]['id']);
        static::__assert_equals(Recovery_Codes::COUNT, $envelope['data']['recovery_codes_removed'], 'the cascade');
        static::__assert_false(Rsx_Two_Factor::is_enabled($login_user));
    }

    /**
     * tfa-cli-07: THE OWNERSHIP CHECK. The facade treats another identity's credential id as
     * a no-op, which would print "removed" over an account that is still locked - so the
     * command resolves the id against this identity first and REFUSES.
     */
    public static function test_remove_by_id_refuses_another_identitys_credential()
    {
        $victim = static::__make_login_user();
        static::__setup($victim);

        $victim_credential_id = (int) Two_Factor_Credential_Model::where('login_user_id', $victim->id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_TOTP)
            ->value('id');

        $other = static::__make_login_user();
        static::__setup($other);

        [$code, $envelope] = static::__json_call('rsx:users:2fa:remove', [
            '--user' => (string) $other->id,
            '--id' => (string) $victim_credential_id,
            '--force' => true,
        ]);

        static::__assert_equals(1, $code, 'refused, never a silent no-op');
        static::__assert_false($envelope['ok']);
        static::__assert_equals('credential_not_found', $envelope['error']['code']);

        static::__assert_true(Rsx_Two_Factor::is_enabled($victim), 'the victim keeps their factor');
        static::__assert_true(Rsx_Two_Factor::is_enabled($other), 'and the caller keeps theirs');
    }

    /**
     * tfa-cli-08: --json cannot prompt, so it REFUSES without --force rather than destroying
     * anything on an implied confirmation.
     */
    public static function test_remove_json_without_force_refuses_and_destroys_nothing()
    {
        $login_user = static::__make_login_user();
        static::__setup($login_user);

        [$code, $envelope] = static::__json_call('rsx:users:2fa:remove', [
            '--user' => (string) $login_user->id,
        ]);

        static::__assert_equals(1, $code);
        static::__assert_equals('confirmation_required', $envelope['error']['code']);
        static::__assert_true(Rsx_Two_Factor::is_enabled($login_user), 'nothing was removed');
    }

    // -------------------------------------------------------------------------
    // Argument failures, in both output forms
    // -------------------------------------------------------------------------

    /**
     * tfa-cli-09: an unresolvable --user is a non-zero exit in BOTH forms - a script that
     * gets parseable JSON back on a failure but a zero exit carries on as though the account
     * had been armed.
     */
    public static function test_unknown_user_fails_loudly_in_both_forms()
    {
        foreach (['rsx:users:2fa:setup', 'rsx:users:2fa:dump'] as $command) {
            [$json_code, $envelope] = static::__json_call($command, ['--user' => 'nobody_' . uniqid() . '@example.com']);

            static::__assert_equals(1, $json_code, $command . ' exits non-zero');
            static::__assert_false($envelope['ok']);
            static::__assert_equals('user_not_found', $envelope['error']['code']);

            [$human_code, $output] = static::__human_call($command, ['--user' => '99999999']);

            static::__assert_equals(1, $human_code, $command . ' exits non-zero for a human too');
            static::__assert_contains('[ERROR]', $output);
        }
    }

    /**
     * tfa-cli-10: --user is REQUIRED and is never defaulted - a command that quietly picked
     * identity 1 would arm or strip the wrong account.
     */
    public static function test_user_is_required_and_never_defaulted()
    {
        foreach (['rsx:users:2fa:setup', 'rsx:users:2fa:dump'] as $command) {
            [$code, $envelope] = static::__json_call($command, []);

            static::__assert_equals(1, $code);
            static::__assert_equals('user_required', $envelope['error']['code']);
            static::__assert_contains('--user', $envelope['error']['message']);
        }

        [$remove_code, $remove] = static::__json_call('rsx:users:2fa:remove', ['--force' => true]);

        static::__assert_equals(1, $remove_code);
        static::__assert_equals('user_required', $remove['error']['code']);

        static::__assert_false(
            Rsx_Two_Factor::is_enabled(1),
            'and identity 1 was never touched'
        );
    }
}
