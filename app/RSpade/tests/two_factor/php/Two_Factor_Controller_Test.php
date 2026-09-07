<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TwoFactor\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor_Controller;
use App\RSpade\Core\TwoFactor\Totp;
use App\RSpade\Core\TwoFactor\Two_Factor_Credential_Model;

/**
 * The Ajax surface: Rsx_Two_Factor_Controller, driven exactly as the browser drives it.
 *
 * WHAT THIS TIER ADDS over Two_Factor_Enrollment_Test and Two_Factor_Challenge_Test, which
 * already pin the facade: the TRANSPORT decisions the controller makes and the facade does
 * not. Which gate each endpoint declares, what a payload looks like on the wire, which
 * failures are turned into a user-safe Error_Response and which are left to surface, and the
 * refusals the controller adds on top of the facade's own.
 *
 * ENDPOINTS ARE CALLED AS STATIC METHODS, which is what the dispatcher does after the gate
 * has passed. That means the #[Auth] attribute is NOT evaluated by these calls - so the gate
 * declarations are asserted separately, through Auth_Gates::surface_gates(), which reads the
 * same manifest index the dispatcher enforces from. Testing the two halves separately is the
 * only honest way to do it in-process: a test that called the method and saw it work would
 * prove nothing about the gate.
 *
 * SESSIONS IN CLI behave as they do in the sibling tests - see Two_Factor_Enrollment_Test's
 * docblock for the reset discipline these helpers follow.
 */
class Two_Factor_Controller_Test extends Rsx_Test_Abstract
{
    private const PASSWORD = 'correct-horse-battery-staple';

    public static function teardown()
    {
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    /**
     * A request object for an endpoint that never reads one. Every #[Ajax_Endpoint] takes
     * the same (Request, array) pair and these endpoints read only $params.
     */
    private static function __ajax_request(): Request
    {
        return Request::create('/_ajax/Rsx_Two_Factor_Controller/x', 'POST');
    }

    private static function __make_login_user(): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'tfa_ctl_' . uniqid() . '@example.com';
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    private static function __start_anonymous(): void
    {
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    /**
     * A fresh identity, signed in and ready to enroll.
     */
    private static function __signed_in_user(): Login_User_Model
    {
        static::__start_anonymous();

        $login_user = static::__make_login_user();
        Session::set_login_user_id((int) $login_user->id);

        return $login_user;
    }

    /**
     * Enroll a TOTP factor through the ENDPOINTS, which is the pair under test in the happy
     * path and the setup step everywhere else.
     *
     * @return array {login_user, secret, recovery_codes}
     */
    private static function __enroll_totp(): array
    {
        $login_user = static::__signed_in_user();

        $setup = Rsx_Two_Factor_Controller::totp_begin(static::__ajax_request());

        $confirmed = Rsx_Two_Factor_Controller::totp_confirm(static::__ajax_request(), [
            'code' => Totp::code_for($setup['secret'], intdiv(time(), Totp::PERIOD)),
        ]);

        return [
            'login_user' => $login_user,
            'secret' => $setup['secret'],
            'recovery_codes' => $confirmed['recovery_codes'],
        ];
    }

    // -------------------------------------------------------------------------
    // Gate declarations
    // -------------------------------------------------------------------------

    /**
     * tfa-ctl-01: every enrollment endpoint demands a login, and every challenge endpoint is
     * public.
     *
     * THE SPLIT IS THE SECURITY DESIGN, not a convenience: the challenge endpoints serve the
     * half-authenticated state, where the session is deliberately logged OUT, so a gate
     * demanding a login could never be satisfied by the screen that exists to produce one.
     * Reversing either half silently breaks the feature - enrollment would become anonymous,
     * or the challenge screen would become unreachable - so the declarations are pinned.
     */
    public static function test_gate_declarations()
    {
        $enrollment = [
            'totp_begin',
            'totp_confirm',
            'passkey_register_begin',
            'passkey_register_confirm',
            'credentials_list',
            'credential_remove',
            'recovery_regenerate',
        ];

        foreach ($enrollment as $method) {
            static::__assert_equals(
                ['is_logged_in'],
                Auth_Gates::surface_gates('Rsx_Two_Factor_Controller::' . $method),
                $method . ' is gated on is_logged_in'
            );
        }

        foreach (['challenge_state', 'challenge_passkey_options'] as $method) {
            static::__assert_equals(
                ['public'],
                Auth_Gates::surface_gates('Rsx_Two_Factor_Controller::' . $method),
                $method . ' is public - it serves the logged-out challenge'
            );
        }
    }

    // -------------------------------------------------------------------------
    // TOTP enrollment over the endpoints
    // -------------------------------------------------------------------------

    /**
     * tfa-ctl-02: begin then confirm, exactly as the enrollment component drives it.
     */
    public static function test_totp_begin_then_confirm()
    {
        $login_user = static::__signed_in_user();

        $setup = Rsx_Two_Factor_Controller::totp_begin(static::__ajax_request());

        static::__assert_array_has_key('secret', $setup);
        static::__assert_array_has_key('otpauth_uri', $setup);
        static::__assert_array_has_key('qr_svg', $setup);
        static::__assert_contains('<svg', $setup['qr_svg'], 'the QR is inline-embeddable SVG');

        static::__assert_false(
            Rsx_Two_Factor::is_enabled($login_user),
            'begin alone enrolls nothing'
        );

        $confirmed = Rsx_Two_Factor_Controller::totp_confirm(static::__ajax_request(), [
            'code' => Totp::code_for($setup['secret'], intdiv(time(), Totp::PERIOD)),
        ]);

        static::__assert_count(10, $confirmed['recovery_codes'], 'the sheet comes back with the confirmation');
        static::__assert_true(Rsx_Two_Factor::is_enabled($login_user), 'the factor is live');
    }

    /**
     * tfa-ctl-03: a wrong code is a VALIDATION error carrying the facade's user-safe message,
     * never an exception escaping to the global handler.
     *
     * The component renders that message beside the input, so the endpoint has to produce one
     * rather than a 500 the user cannot act on.
     */
    public static function test_totp_confirm_rejects_a_wrong_code()
    {
        static::__signed_in_user();

        Rsx_Two_Factor_Controller::totp_begin(static::__ajax_request());

        $response = Rsx_Two_Factor_Controller::totp_confirm(static::__ajax_request(), ['code' => '000000']);

        static::__assert_instance_of(Error_Response::class, $response);
        static::__assert_equals(Ajax::ERROR_VALIDATION, $response->get_error_code());
        static::__assert_not_empty($response->get_reason(), 'the refusal carries a message for the user');
    }

    /**
     * tfa-ctl-04: a blank code is refused BEFORE the facade is asked.
     *
     * Blank is a value, not an absence: the component always serializes its input, so an empty
     * box must produce the instruction to fill it in rather than a generic verification
     * failure.
     */
    public static function test_totp_confirm_rejects_a_blank_code()
    {
        static::__signed_in_user();

        Rsx_Two_Factor_Controller::totp_begin(static::__ajax_request());

        foreach ([[], ['code' => ''], ['code' => '   ']] as $params) {
            $response = Rsx_Two_Factor_Controller::totp_confirm(static::__ajax_request(), $params);

            static::__assert_instance_of(Error_Response::class, $response);
            static::__assert_equals(Ajax::ERROR_VALIDATION, $response->get_error_code());
        }

        static::__assert_count(
            0,
            Two_Factor_Credential_Model::where('login_user_id', Session::get_login_user_id())->get()->all(),
            'nothing was written'
        );
    }

    /**
     * tfa-ctl-05: an enrollment endpoint reached with nobody signed in fails LOUDLY.
     *
     * The gate has already refused this in production; a call that got here anyway means the
     * gate and the session disagree, which is a broken assumption and not a user error. The
     * facade's RuntimeException is deliberately NOT converted into a friendly response.
     */
    public static function test_enrollment_refuses_with_nobody_signed_in()
    {
        static::__start_anonymous();

        static::__assert_throws(RuntimeException::class, function () {
            Rsx_Two_Factor_Controller::totp_begin(static::__ajax_request());
        });

        static::__assert_throws(RuntimeException::class, function () {
            Rsx_Two_Factor_Controller::passkey_register_begin(static::__ajax_request());
        });

        static::__assert_throws(RuntimeException::class, function () {
            Rsx_Two_Factor_Controller::recovery_regenerate(static::__ajax_request());
        });
    }

    // -------------------------------------------------------------------------
    // The settings surface
    // -------------------------------------------------------------------------

    /**
     * tfa-ctl-06: credentials_list answers the whole settings screen in one call, and the
     * credential rows are metadata only.
     */
    public static function test_credentials_list_shape()
    {
        $enrolled = static::__enroll_totp();

        $payload = Rsx_Two_Factor_Controller::credentials_list(static::__ajax_request());

        static::__assert_equals(
            ['credentials', 'recovery_codes_remaining', 'is_enabled'],
            array_keys($payload),
            'one call, three answers'
        );

        static::__assert_true($payload['is_enabled']);
        static::__assert_equals(10, $payload['recovery_codes_remaining']);
        static::__assert_count(1, $payload['credentials']);

        static::__assert_equals(
            ['id', 'type_id', 'type_id__label', 'label', 'confirmed_at', 'last_used_at'],
            array_keys($payload['credentials'][0]),
            'metadata only - no secret, no counter, no public key'
        );

        // The seed must not be reachable through this endpoint by any spelling.
        static::__assert_false(
            str_contains(json_encode($payload), $enrolled['secret']),
            'the seed appears nowhere in the settings payload'
        );
    }

    /**
     * tfa-ctl-07: removal returns the refreshed settings state, and removing the last factor
     * takes the recovery codes with it.
     */
    public static function test_credential_remove_returns_refreshed_state()
    {
        static::__enroll_totp();

        $before = Rsx_Two_Factor_Controller::credentials_list(static::__ajax_request());

        $after = Rsx_Two_Factor_Controller::credential_remove(static::__ajax_request(), [
            'id' => $before['credentials'][0]['id'],
        ]);

        static::__assert_false($after['is_enabled'], 'the factor is gone');
        static::__assert_count(0, $after['credentials']);
        static::__assert_equals(
            0,
            $after['recovery_codes_remaining'],
            'the codes cascade - they are a recovery path, not standalone bearer tokens'
        );
    }

    /**
     * tfa-ctl-08: a removal naming no credential is refused rather than treated as "remove
     * something".
     */
    public static function test_credential_remove_requires_an_id()
    {
        static::__enroll_totp();

        foreach ([[], ['id' => 0], ['id' => 'x']] as $params) {
            $response = Rsx_Two_Factor_Controller::credential_remove(static::__ajax_request(), $params);

            static::__assert_instance_of(Error_Response::class, $response);
            static::__assert_equals(Ajax::ERROR_VALIDATION, $response->get_error_code());
        }

        static::__assert_true(
            Rsx_Two_Factor::is_enabled(Session::get_login_user_id()),
            'the factor survives a malformed removal'
        );
    }

    /**
     * tfa-ctl-09: removal is REFUSED while impersonating.
     *
     * The facade already refuses every enrollment path this way; removal is the other half of
     * the same rule and is guarded in the controller. An impersonator who could strip a
     * victim's second factor has turned impersonation into an authentication backdoor.
     */
    public static function test_credential_remove_refuses_while_impersonating()
    {
        $enrolled = static::__enroll_totp();

        $credentials = Rsx_Two_Factor_Controller::credentials_list(static::__ajax_request());
        $credential_id = $credentials['credentials'][0]['id'];

        Session::cli_set_impersonator_login_user_id((int) $enrolled['login_user']->id);

        static::__assert_throws(RuntimeException::class, function () use ($credential_id) {
            Rsx_Two_Factor_Controller::credential_remove(static::__ajax_request(), ['id' => $credential_id]);
        });

        Session::cli_set_impersonator_login_user_id(null);

        static::__assert_true(
            Rsx_Two_Factor::is_enabled((int) $enrolled['login_user']->id),
            'the victim keeps their factor'
        );
    }

    /**
     * tfa-ctl-10: regenerating replaces the sheet and hands back the new plaintext set.
     */
    public static function test_recovery_regenerate()
    {
        $enrolled = static::__enroll_totp();

        $result = Rsx_Two_Factor_Controller::recovery_regenerate(static::__ajax_request());

        static::__assert_count(10, $result['recovery_codes']);
        static::__assert_count(
            0,
            array_intersect($enrolled['recovery_codes'], $result['recovery_codes']),
            'the previous sheet is not reissued'
        );
    }

    // -------------------------------------------------------------------------
    // Passkey registration transport
    // -------------------------------------------------------------------------

    /**
     * tfa-ctl-11: a malformed attestation is refused before any crypto runs, with a message.
     *
     * The browser is the only thing that produces this payload, so a non-array here means a
     * broken client - but the challenge screen still has to say something to the person
     * looking at it.
     */
    public static function test_passkey_register_confirm_rejects_a_malformed_attestation()
    {
        static::__signed_in_user();

        foreach ([[], ['attestation' => null], ['attestation' => 'nonsense']] as $params) {
            $response = Rsx_Two_Factor_Controller::passkey_register_confirm(static::__ajax_request(), $params);

            static::__assert_instance_of(Error_Response::class, $response);
            static::__assert_equals(Ajax::ERROR_VALIDATION, $response->get_error_code());
        }
    }

    /**
     * tfa-ctl-12: the creation args reach the browser in the shape navigator.credentials
     * .create() needs, with the binary fields base64url encoded for Rsx_Two_Factor.js to
     * decode.
     */
    public static function test_passkey_register_begin_shape()
    {
        static::__signed_in_user();

        $options = Rsx_Two_Factor_Controller::passkey_register_begin(static::__ajax_request());

        static::__assert_array_has_key('publicKey', $options);
        static::__assert_not_empty($options['publicKey']['challenge']);
        static::__assert_not_empty($options['publicKey']['user']['id']);
        static::__assert_array_has_key('pubKeyCredParams', $options['publicKey']);
    }

    // -------------------------------------------------------------------------
    // The challenge surface
    // -------------------------------------------------------------------------

    /**
     * tfa-ctl-13: with nothing pending, challenge_state is NULL and not an error.
     *
     * "No challenge" is the answer the screen gets when the window expired, when the user
     * already signed in, or when they simply navigated there - and the screen's response to
     * all three is the same, so null passes straight through.
     */
    public static function test_challenge_state_is_null_when_nothing_is_pending()
    {
        static::__start_anonymous();

        static::__assert_null(Rsx_Two_Factor_Controller::challenge_state(static::__ajax_request()));
    }

    /**
     * tfa-ctl-14: with a challenge parked, the endpoint answers exactly what the screen
     * renders - and the address is MASKED.
     */
    public static function test_challenge_state_when_pending()
    {
        $enrolled = static::__enroll_totp();

        static::__start_anonymous();
        Rsx_Two_Factor::begin_challenge($enrolled['login_user']);

        $state = Rsx_Two_Factor_Controller::challenge_state(static::__ajax_request());

        static::__assert_equals(
            ['email_masked', 'has_totp', 'has_passkey'],
            array_keys($state),
            'only what the screen renders'
        );

        static::__assert_true($state['has_totp']);
        static::__assert_false($state['has_passkey']);

        static::__assert_not_equals(
            (string) $enrolled['login_user']->email,
            $state['email_masked'],
            'the full address is not handed to a caller holding only the cookie'
        );
        static::__assert_contains('*', $state['email_masked']);
    }

    /**
     * tfa-ctl-15: asking for passkey options with nothing pending is a user-safe VALIDATION
     * refusal, not an exception.
     *
     * The endpoint is public, so anybody can reach it. The refusal has to be something the
     * challenge screen can render, and it must not say whether an account exists.
     */
    public static function test_challenge_passkey_options_refuses_with_nothing_pending()
    {
        static::__start_anonymous();

        $response = Rsx_Two_Factor_Controller::challenge_passkey_options(static::__ajax_request());

        static::__assert_instance_of(Error_Response::class, $response);
        static::__assert_equals(Ajax::ERROR_VALIDATION, $response->get_error_code());
        static::__assert_not_empty($response->get_reason());
    }

    /**
     * tfa-ctl-16: with a passkey-less identity parked, the options endpoint still answers a
     * ceremony shape rather than leaking that the identity has no key.
     *
     * has_passkey on challenge_state is what tells the screen not to offer the button; the
     * options endpoint itself must not become an oracle for the same question.
     */
    public static function test_challenge_passkey_options_when_pending()
    {
        $enrolled = static::__enroll_totp();

        static::__start_anonymous();
        Rsx_Two_Factor::begin_challenge($enrolled['login_user']);

        $options = Rsx_Two_Factor_Controller::challenge_passkey_options(static::__ajax_request());

        static::__assert_array_has_key('publicKey', $options);
        static::__assert_not_empty($options['publicKey']['challenge']);
    }
}
