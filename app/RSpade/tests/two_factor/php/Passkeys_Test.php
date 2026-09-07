<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TwoFactor\Php;

use Illuminate\Support\Facades\Hash;
use lbuchs\WebAuthn\WebAuthnException;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\TwoFactor\Passkeys;
use App\RSpade\Core\TwoFactor\Recovery_Codes;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Two_Factor_Credential_Model;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;
use App\RSpade\Tests\TwoFactor\Php\Webauthn_Authenticator_Fixture;

/**
 * The WebAuthn path, driven end to end against a SIMULATED AUTHENTICATOR
 * (Webauthn_Authenticator_Fixture) that holds a real P-256 keypair and produces real CBOR
 * and real ECDSA signatures. The library verifies it exactly as it verifies a hardware key,
 * so these are not shape assertions - the cryptography actually runs.
 *
 * WHAT IS PINNED:
 *  - the ceremony args have the shape the browser requires, and the rpId is the BARE
 *    hostname (a passkey enrolled against a wrong rpId is unusable forever, not merely
 *    broken today);
 *  - the challenge is stored SERVER-SIDE and is SINGLE USE, spent even by a ceremony that
 *    fails - a challenge left live for a retry is the replay a challenge exists to prevent;
 *  - a full registration then assertion round trip stores and verifies correctly;
 *  - the SIGNATURE COUNTER is enforced: an assertion that does not advance it is refused as
 *    a possible clone;
 *  - an unknown credential is refused with the SAME message as every other failure, so the
 *    challenge cannot be used to enumerate which keys the server has seen.
 *
 * SESSIONS IN CLI: as in Two_Factor_Enrollment_Test, every test resets the process's CLI
 * session first so the row minted by Session::put_value() lives inside the current
 * transaction.
 */
class Passkeys_Test extends Rsx_Test_Abstract
{
    public static function teardown()
    {
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    private static function __make_login_user(): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'passkey_' . uniqid() . '@example.com';
        $login_user->password = Hash::make('correct-horse-battery-staple');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    private static function __signed_in_user(): Login_User_Model
    {
        $login_user = static::__make_login_user();

        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
        Session::set_login_user_id((int) $login_user->id);

        return $login_user;
    }

    /**
     * An authenticator enrolled against this install's own relying party id.
     */
    private static function __authenticator(): Webauthn_Authenticator_Fixture
    {
        return new Webauthn_Authenticator_Fixture(Passkeys::relying_party_id());
    }

    /**
     * The challenge the server just minted, read from where it claims to store it.
     */
    private static function __stored_challenge(): string
    {
        return (string) Session::get_value(Passkeys::CHALLENGE_KEY);
    }

    /**
     * Register a passkey for the signed-in identity and return the authenticator that owns
     * it.
     */
    private static function __register_passkey(?string $label = null): Webauthn_Authenticator_Fixture
    {
        $authenticator = static::__authenticator();

        Rsx_Two_Factor::begin_passkey_registration();

        Rsx_Two_Factor::confirm_passkey_registration(
            $authenticator->attestation_response(static::__stored_challenge()),
            $label
        );

        return $authenticator;
    }

    // -------------------------------------------------------------------------
    // The relying party
    // -------------------------------------------------------------------------

    /**
     * The rpId is the BARE hostname - no scheme, no port. The spec requires it and the
     * library enforces it against the browser's reported origin.
     */
    public static function test_the_relying_party_id_is_the_bare_hostname()
    {
        $rp_id = Passkeys::relying_party_id();

        static::__assert_not_empty($rp_id);
        static::__assert_false(str_contains($rp_id, ':'), 'no scheme and no port');
        static::__assert_false(str_contains($rp_id, '/'), 'not a URL');
        static::__assert_equals(strtolower($rp_id), $rp_id, 'lower cased');
        static::__assert_contains($rp_id, strtolower(Rsx::get_hostname()), 'derived from the app hostname');
    }

    // -------------------------------------------------------------------------
    // Registration options
    // -------------------------------------------------------------------------

    /**
     * The creation args carry what navigator.credentials.create() needs, and the challenge
     * is stored SERVER-SIDE - a challenge the client hands back is not a challenge.
     */
    public static function test_registration_options_shape_and_stored_challenge()
    {
        $login_user = static::__signed_in_user();

        $options = Rsx_Two_Factor::begin_passkey_registration();

        static::__assert_array_has_key('publicKey', $options);
        $public_key = $options['publicKey'];

        static::__assert_equals(Passkeys::relying_party_id(), $public_key['rp']['id'], 'rpId is the hostname');
        static::__assert_equals(Rsx_Two_Factor::issuer(), $public_key['rp']['name'], 'rp name is the issuer');
        // The user handle is BINARY in the spec, so it crosses the wire base64url encoded
        // like every other binary field - not as the bare decimal string.
        static::__assert_equals(
            Webauthn_Authenticator_Fixture::base64url_encode((string) $login_user->id),
            $public_key['user']['id'],
            'the identity is named, base64url encoded'
        );
        static::__assert_equals($login_user->email, $public_key['user']['name']);
        static::__assert_not_empty($public_key['challenge'], 'a challenge is issued');
        static::__assert_not_empty($public_key['pubKeyCredParams'], 'algorithms are offered');

        static::__assert_equals(
            'required',
            $public_key['authenticatorSelection']['residentKey'],
            'a discoverable credential - that is what makes it a passkey'
        );

        static::__assert_equals(
            $public_key['challenge'],
            static::__stored_challenge(),
            'the challenge is held server-side, not merely handed to the client'
        );
    }

    /**
     * An already-registered authenticator is EXCLUDED, so the browser refuses politely
     * instead of silently minting a duplicate the user would have to tell apart in a list.
     */
    public static function test_registration_excludes_already_registered_credentials()
    {
        static::__signed_in_user();

        $authenticator = static::__register_passkey('First key');

        $options = Rsx_Two_Factor::begin_passkey_registration();

        static::__assert_array_has_key('excludeCredentials', $options['publicKey']);

        $excluded = array_column($options['publicKey']['excludeCredentials'], 'id');

        static::__assert_contains(
            $authenticator->credential_key(),
            implode(',', $excluded),
            'the enrolled key is excluded from a second registration'
        );
    }

    // -------------------------------------------------------------------------
    // The registration round trip
    // -------------------------------------------------------------------------

    /**
     * A real attestation from the simulated authenticator enrolls a confirmed passkey, and
     * mints the recovery codes that back it up because this identity had none.
     */
    public static function test_a_passkey_registers_and_is_confirmed()
    {
        $login_user = static::__signed_in_user();
        $id = (int) $login_user->id;

        $authenticator = static::__authenticator();

        Rsx_Two_Factor::begin_passkey_registration();

        $codes = Rsx_Two_Factor::confirm_passkey_registration(
            $authenticator->attestation_response(static::__stored_challenge()),
            'My laptop'
        );

        static::__assert_true(Rsx_Two_Factor::is_enabled($login_user), 'the identity now has 2FA');

        $row = Two_Factor_Credential_Model::where('login_user_id', $id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_PASSKEY)
            ->first();

        static::__assert_not_null($row, 'a passkey row exists');
        static::__assert_not_null($row->confirmed_at, 'and it is confirmed');
        static::__assert_equals($authenticator->credential_key(), $row->credential_key, 'keyed by credential id');
        static::__assert_equals('My laptop', $row->label, 'the user label is kept');
        static::__assert_contains('BEGIN PUBLIC KEY', $row->secret, 'the public key is stored as PEM');

        static::__assert_count(Recovery_Codes::COUNT, $codes, 'recovery codes are minted with the first factor');

        static::__assert_null(
            Session::get_value(Passkeys::CHALLENGE_KEY),
            'the challenge is spent'
        );
    }

    /**
     * A SECOND passkey does NOT re-mint recovery codes - the sheet the user already wrote
     * down must not be silently invalidated. confirm_passkey_registration() returns null to
     * say there is nothing new to reveal.
     */
    public static function test_a_second_passkey_does_not_reissue_recovery_codes()
    {
        $login_user = static::__signed_in_user();

        static::__register_passkey('First key');
        $original_remaining = Rsx_Two_Factor::recovery_codes_remaining($login_user);

        $second = static::__authenticator();
        Rsx_Two_Factor::begin_passkey_registration();

        $codes = Rsx_Two_Factor::confirm_passkey_registration(
            $second->attestation_response(static::__stored_challenge()),
            'Second key'
        );

        static::__assert_null($codes, 'nothing new to reveal');
        static::__assert_equals($original_remaining, Rsx_Two_Factor::recovery_codes_remaining($login_user));
        static::__assert_count(2, Rsx_Two_Factor::list_credentials($login_user), 'both keys are listed');
    }

    /**
     * A registration with no ceremony in flight is refused - there is nothing the
     * attestation could be an answer to.
     */
    public static function test_registration_without_a_challenge_is_refused()
    {
        static::__signed_in_user();

        $authenticator = static::__authenticator();

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::confirm_passkey_registration(
                $authenticator->attestation_response('AAAAAAAAAAAAAAAAAAAAAA'),
                null
            ),
            'expired'
        );
    }

    /**
     * An incomplete response is refused before any crypto runs.
     */
    public static function test_an_incomplete_registration_response_is_refused()
    {
        static::__signed_in_user();

        Rsx_Two_Factor::begin_passkey_registration();

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::confirm_passkey_registration(['clientDataJSON' => 'x'], null),
            'incomplete'
        );
    }

    // -------------------------------------------------------------------------
    // Assertion
    // -------------------------------------------------------------------------

    /**
     * The assertion args offer exactly this identity's CONFIRMED passkeys.
     */
    public static function test_assertion_options_offer_the_identitys_confirmed_keys()
    {
        $login_user = static::__signed_in_user();

        $authenticator = static::__register_passkey();

        $options = Passkeys::assertion_options((int) $login_user->id);

        static::__assert_equals(Passkeys::relying_party_id(), $options['publicKey']['rpId']);
        static::__assert_not_empty($options['publicKey']['challenge']);

        $allowed = array_column($options['publicKey']['allowCredentials'], 'id');

        static::__assert_count(1, $allowed, 'exactly this identity\'s one key');
        static::__assert_equals($authenticator->credential_key(), $allowed[0]);
    }

    /**
     * THE FULL ROUND TRIP: register, then assert, and the signature verifies. The credential
     * returned is the one that signed, the counter advances and the use is stamped.
     */
    public static function test_a_real_assertion_verifies_and_advances_the_counter()
    {
        $login_user = static::__signed_in_user();
        $id = (int) $login_user->id;

        $authenticator = static::__register_passkey();

        Passkeys::assertion_options($id);

        $credential = Passkeys::verify_assertion(
            $authenticator->assertion_response(static::__stored_challenge(), 7)
        );

        static::__assert_equals($id, (int) $credential->login_user_id, 'the identity is identified by the key');
        static::__assert_equals(7, (int) $credential->counter, 'the counter advanced');
        static::__assert_not_null($credential->last_used_at, 'and the use is stamped');
    }

    /**
     * THE ANTI-CLONING CHECK. An assertion whose counter does not advance means two copies
     * of a key that was supposed to be unclonable, and is refused.
     */
    public static function test_a_counter_that_does_not_advance_is_refused_as_a_clone()
    {
        $login_user = static::__signed_in_user();
        $id = (int) $login_user->id;

        $authenticator = static::__register_passkey();

        Passkeys::assertion_options($id);
        Passkeys::verify_assertion($authenticator->assertion_response(static::__stored_challenge(), 9));

        // The same counter again - a genuine authenticator would never do this.
        Passkeys::assertion_options($id);

        static::__assert_throws(
            WebAuthnException::class,
            fn () => Passkeys::verify_assertion($authenticator->assertion_response(static::__stored_challenge(), 9))
        );

        $credential = Two_Factor_Credential_Model::where('login_user_id', $id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_PASSKEY)
            ->first();

        static::__assert_equals(9, (int) $credential->counter, 'the stored counter did not regress');
    }

    /**
     * A CHALLENGE IS SINGLE USE, and is spent even by a ceremony that FAILS. Leaving it live
     * for a retry is precisely the replay a challenge exists to prevent.
     */
    public static function test_a_challenge_is_spent_even_by_a_failed_ceremony()
    {
        $login_user = static::__signed_in_user();
        $id = (int) $login_user->id;

        $authenticator = static::__register_passkey();
        $stranger = static::__authenticator();

        Passkeys::assertion_options($id);
        $challenge = static::__stored_challenge();

        // A credential this server has never seen.
        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Passkeys::verify_assertion($stranger->assertion_response($challenge, 1))
        );

        static::__assert_null(Session::get_value(Passkeys::CHALLENGE_KEY), 'the challenge was spent anyway');

        // And the genuine key cannot now reuse that same challenge.
        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Passkeys::verify_assertion($authenticator->assertion_response($challenge, 3)),
            'expired'
        );
    }

    /**
     * AN UNKNOWN CREDENTIAL IS REFUSED WITH THE SAME MESSAGE AS EVERYTHING ELSE. Saying "we
     * have never seen that key" would let the challenge be used to enumerate which keys the
     * server has issued.
     */
    public static function test_an_unknown_credential_is_refused_without_disclosing_that()
    {
        $login_user = static::__signed_in_user();

        static::__register_passkey();

        Passkeys::assertion_options((int) $login_user->id);

        $stranger = static::__authenticator();

        $thrown = static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Passkeys::verify_assertion($stranger->assertion_response(static::__stored_challenge(), 1))
        );

        static::__assert_false(
            str_contains(strtolower($thrown->getMessage()), 'unknown'),
            'the message does not disclose that the credential is unknown'
        );

        static::__assert_false(
            str_contains(strtolower($thrown->getMessage()), 'not found'),
            'nor that it was not found'
        );
    }

    /**
     * An UNCONFIRMED passkey cannot satisfy an assertion. An enrollment that never completed
     * must not be able to log anybody in.
     */
    public static function test_an_unconfirmed_passkey_cannot_assert()
    {
        $login_user = static::__signed_in_user();
        $id = (int) $login_user->id;

        $authenticator = static::__register_passkey();

        Two_Factor_Credential_Model::where('login_user_id', $id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_PASSKEY)
            ->update(['confirmed_at' => null]);

        Passkeys::assertion_options($id);

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Passkeys::verify_assertion($authenticator->assertion_response(static::__stored_challenge(), 4))
        );
    }

    // -------------------------------------------------------------------------
    // base64url
    // -------------------------------------------------------------------------

    /**
     * base64url round-trips arbitrary binary and is URL-safe - no '+', no '/', no padding.
     */
    public static function test_base64url_round_trips_and_is_url_safe()
    {
        foreach (['', "\0", "\xff\xfe\xfd", random_bytes(64)] as $binary) {
            $encoded = Passkeys::base64url_encode($binary);

            static::__assert_equals(0, preg_match('/[+\/=]/', $encoded), 'URL safe and unpadded');
            static::__assert_equals(
                bin2hex($binary),
                bin2hex(Passkeys::base64url_decode($encoded)),
                'round trips'
            );
        }
    }

    /**
     * Input that is not base64url THROWS rather than decoding to plausible bytes that then
     * fail a signature check for a reason nobody can trace back to the encoding.
     */
    public static function test_base64url_decode_refuses_garbage()
    {
        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Passkeys::base64url_decode('!!!! not base64 !!!!'),
            'malformed'
        );
    }
}
