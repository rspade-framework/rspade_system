<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\TwoFactor;

use lbuchs\WebAuthn\WebAuthn;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Two_Factor_Credential_Model;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;

/**
 * Passkeys - the WebAuthn half of the second factor, wrapped around lbuchs/webauthn.
 *
 * WHAT A PASSKEY IS, in one paragraph: the authenticator holds a private key it will never
 * reveal, and proves possession by signing a challenge this server minted. Nothing
 * reusable crosses the wire in either direction, which is what makes it the only second
 * factor that is phishing-resistant - a proxy site can relay a TOTP code, but it cannot
 * relay a signature bound to the origin it is NOT serving from.
 *
 * THE RELYING PARTY ID IS THE BARE HOSTNAME, no scheme and no port. That is the WebAuthn
 * spec's rule and the library enforces it against the browser's reported origin; a
 * mismatch means every assertion is refused with an origin error. It is derived from
 * Rsx::get_hostname(), which is already port-stripped, so a passkey enrolled on one host
 * does not work on another - correct, and worth knowing before somebody reports it as a
 * bug after moving environments.
 *
 * ATTESTATION IS 'none', DELIBERATELY. Attestation identifies the make and model of the
 * authenticator and lets a relying party demand a particular one. We do not: this is a
 * public application, not an enterprise issuing hardware, so requiring attestation would
 * buy no security while showing the user a browser privacy warning and excluding the
 * platform authenticators (Windows Hello, iCloud Keychain) that most people actually have.
 *
 * THE CHALLENGE LIVES IN THE SESSION, NOT IN THE PAYLOAD. A challenge the client hands
 * back to us is not a challenge - it is whatever the client chose. It is written to a
 * session value under CHALLENGE_KEY and read from there when the response arrives, so the
 * only thing that can satisfy a ceremony is the browser that started it.
 *
 * BINARY CROSSES THE WIRE BASE64URL ENCODED, in both directions. The library is
 * constructed with its base64url mode on, so the args it produces serialize that way
 * without further help, and the browser's ArrayBuffers are decoded back through
 * base64url_decode() below. That is the encoding the WebAuthn ecosystem uses; plain base64
 * would be one '+' away from a corrupt credential id in a URL.
 *
 * ON THE SIGNATURE COUNTER: an authenticator increments a counter on every assertion, and
 * one that does not advance means two copies of a key that was supposed to be
 * unclonable. verify_assertion() refuses that outright. Some authenticators (Apple's
 * among them) never implement a counter and report zero forever, which the library treats
 * as "no counter" rather than as a clone - see processGet().
 *
 * Every method is static. Application code talks to Rsx_Two_Factor, not to this class.
 *
 * See: php artisan rsx:man two_factor
 */
class Passkeys
{
    /**
     * Session value key holding the in-flight ceremony challenge (base64url).
     *
     * ONE key for both ceremonies - registration and assertion. They cannot overlap: a
     * browser is either enrolling a key or logging in with one, never both at once, and
     * sharing the key means an abandoned ceremony is overwritten rather than left lying
     * around next to the live one.
     */
    public const CHALLENGE_KEY = 'two_factor.webauthn_challenge';

    /**
     * The only attestation format accepted. See the class docblock.
     */
    private const FORMATS = ['none'];

    // -------------------------------------------------------------------------
    // The library handle
    // -------------------------------------------------------------------------

    /**
     * A configured WebAuthn instance.
     *
     * Constructed fresh per call rather than cached: the constructor sets the library's
     * GLOBAL base64url flag as a side effect, so a cached instance would leave the encoding
     * mode dependent on which code ran first.
     *
     * @return WebAuthn
     */
    private static function _server(): WebAuthn
    {
        return new WebAuthn(
            Rsx_Two_Factor::issuer(),
            self::relying_party_id(),
            self::FORMATS,
            true
        );
    }

    /**
     * The relying party id: the bare hostname, no scheme, no port.
     *
     * @return string
     */
    public static function relying_party_id(): string
    {
        $hostname = Rsx::get_hostname();

        // get_hostname() already strips a port, but a passkey enrolled against the wrong
        // rpId is unusable forever rather than merely broken today, so this does not trust
        // that and re-checks. Cheap insurance on a value that cannot be corrected later.
        if (str_contains($hostname, ':')) {
            $hostname = explode(':', $hostname)[0];
        }

        return strtolower($hostname);
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * The arguments for navigator.credentials.create(), plus the stored challenge.
     *
     * requireResidentKey is TRUE: the credential is stored on the authenticator itself,
     * which is what makes it a passkey rather than a bare security-key credential and what
     * lets it sync across the user's devices.
     *
     * The identity's ALREADY REGISTERED credentials are excluded, so an authenticator the
     * user has already enrolled refuses politely inside the browser instead of silently
     * minting a duplicate they would then have to tell apart in a list.
     *
     * NO TIMEOUT IS PASSED. The library's own default lands in the args as the WebAuthn
     * ceremony hint - how long the BROWSER keeps its prompt open, a piece of the spec's UI
     * contract with the user and not a deadline on any work of ours. We neither set it nor
     * enforce it.
     *
     * @param Login_User_Model $login_user The identity enrolling.
     * @return array JSON-safe creation args.
     */
    public static function registration_options(Login_User_Model $login_user): array
    {
        $server = self::_server();

        $existing = [];

        $rows = Two_Factor_Credential_Model::where('login_user_id', $login_user->id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_PASSKEY)
            ->whereNotNull('credential_key')
            ->result_set();

        foreach ($rows as $row) {
            $existing[] = self::base64url_decode($row->credential_key);
        }

        $args = $server->getCreateArgs(
            userId: (string) $login_user->id,
            userName: (string) $login_user->email,
            userDisplayName: (string) $login_user->email,
            requireResidentKey: true,
            excludeCredentialIds: $existing
        );

        self::_store_challenge($server);

        return self::_to_array($args);
    }

    /**
     * Verify the browser's attestation and return what the credential row must store.
     *
     * The challenge comes from the SESSION, never from $attestation - see the class
     * docblock. It is forgotten as soon as it is read, so one challenge satisfies exactly
     * one ceremony whether that ceremony succeeds or fails.
     *
     * @param array $attestation {clientDataJSON, attestationObject}, both base64url.
     * @return array {credential_key, public_key, sign_count}
     * @throws Two_Factor_Failed_Exception When the ceremony is stale or malformed.
     * @throws \lbuchs\WebAuthn\WebAuthnException When the attestation does not verify.
     */
    public static function verify_registration(array $attestation): array
    {
        $challenge = self::_consume_challenge();

        if (!isset($attestation['clientDataJSON'], $attestation['attestationObject'])) {
            throw new Two_Factor_Failed_Exception('That security key response was incomplete. Please try again.');
        }

        $server = self::_server();

        $data = $server->processCreate(
            self::base64url_decode($attestation['clientDataJSON']),
            self::base64url_decode($attestation['attestationObject']),
            $challenge
        );

        // credentialId comes back as a raw binary STRING here (unlike the ByteBuffer the
        // args carry), so it is encoded directly.
        return [
            'credential_key' => self::base64url_encode($data->credentialId),
            'public_key' => $data->credentialPublicKey,
            'sign_count' => (int) ($data->signatureCounter ?? 0),
        ];
    }

    // -------------------------------------------------------------------------
    // Assertion
    // -------------------------------------------------------------------------

    /**
     * The arguments for navigator.credentials.get(), for one identity's confirmed passkeys.
     *
     * allowCredentials is populated from the CONFIRMED rows only - an enrollment that never
     * completed must not be able to satisfy a login. An identity with no confirmed passkey
     * gets an empty list, which the library renders as no allowCredentials at all; the
     * caller is responsible for not offering the passkey option in that case, which
     * Rsx_Two_Factor::challenge_pending() reports through has_passkey.
     *
     * @param int $login_user_id
     * @return array JSON-safe request args.
     */
    public static function assertion_options(int $login_user_id): array
    {
        $server = self::_server();

        $credential_ids = [];

        $rows = Two_Factor_Credential_Model::where('login_user_id', $login_user_id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_PASSKEY)
            ->whereNotNull('confirmed_at')
            ->whereNotNull('credential_key')
            ->result_set();

        foreach ($rows as $row) {
            $credential_ids[] = self::base64url_decode($row->credential_key);
        }

        $args = $server->getGetArgs($credential_ids);

        self::_store_challenge($server);

        return self::_to_array($args);
    }

    /**
     * Verify an assertion and return the credential row it authenticated.
     *
     * ORDER MATTERS. The credential is looked up FIRST, by its own globally-unique key, and
     * the identity comes from the row rather than from anything the client said - that is
     * what makes a passkey assertion able to identify the user as well as authenticate
     * them.
     *
     * The stored counter is passed as prevSignatureCnt so the library performs the
     * anti-cloning check, and the row is updated only after the signature verifies.
     *
     * @param array $assertion {id, clientDataJSON, authenticatorData, signature}.
     * @return Two_Factor_Credential_Model The credential that authenticated.
     * @throws Two_Factor_Failed_Exception When the ceremony is stale, malformed, or names
     *                                     a credential this server does not know.
     * @throws \lbuchs\WebAuthn\WebAuthnException When the signature does not verify.
     */
    public static function verify_assertion(array $assertion): Two_Factor_Credential_Model
    {
        $challenge = self::_consume_challenge();

        if (!isset($assertion['id'], $assertion['clientDataJSON'], $assertion['authenticatorData'], $assertion['signature'])) {
            throw new Two_Factor_Failed_Exception('That security key response was incomplete. Please try again.');
        }

        $credential = Two_Factor_Credential_Model::where('credential_key', (string) $assertion['id'])
            ->where('type_id', Two_Factor_Credential_Model::TYPE_PASSKEY)
            ->whereNotNull('confirmed_at')
            ->first();

        if ($credential === null) {
            // Deliberately the same phrasing as every other failure: telling the caller
            // that this particular key is unknown would let them enumerate which keys the
            // server has ever seen.
            throw new Two_Factor_Failed_Exception('That security key is not valid.');
        }

        $server = self::_server();

        $server->processGet(
            self::base64url_decode($assertion['clientDataJSON']),
            self::base64url_decode($assertion['authenticatorData']),
            self::base64url_decode($assertion['signature']),
            $credential->secret,
            $challenge,
            (int) $credential->counter
        );

        $new_counter = $server->getSignatureCounter();

        // An authenticator with no counter reports zero forever; keep what we had rather
        // than writing a regression that would look like a clone on the next assertion.
        if ($new_counter !== null && $new_counter > (int) $credential->counter) {
            $credential->counter = $new_counter;
        }

        $credential->last_used_at = Rsx_Time::now_iso();
        $credential->save();

        return $credential;
    }

    // -------------------------------------------------------------------------
    // Challenge storage
    // -------------------------------------------------------------------------

    /**
     * Write the just-minted challenge to the session, base64url encoded.
     *
     * The expiry is the same security window the login challenge uses - a ceremony nobody
     * completed must not stay satisfiable indefinitely.
     *
     * @param WebAuthn $server
     * @return void
     */
    private static function _store_challenge(WebAuthn $server): void
    {
        Session::put_value(
            self::CHALLENGE_KEY,
            self::base64url_encode($server->getChallenge()->getBinaryString()),
            Rsx_Two_Factor::challenge_expires_at()
        );
    }

    /**
     * Read and immediately forget the in-flight challenge.
     *
     * FORGET FIRST, verify after. A challenge is single-use by definition, so it must be
     * spent even when the ceremony it belongs to is about to fail - otherwise a failed
     * attempt leaves it live for a retry, which is precisely the replay a challenge exists
     * to prevent.
     *
     * @return string The raw challenge bytes.
     * @throws Two_Factor_Failed_Exception When there is nothing in flight.
     */
    private static function _consume_challenge(): string
    {
        $stored = Session::get_value(self::CHALLENGE_KEY);

        Session::forget_value(self::CHALLENGE_KEY);

        if (!is_string($stored) || $stored === '') {
            throw new Two_Factor_Failed_Exception('That security key request has expired. Please try again.');
        }

        return self::base64url_decode($stored);
    }

    // -------------------------------------------------------------------------
    // base64url (RFC 4648 section 5)
    // -------------------------------------------------------------------------

    /**
     * Encode binary as unpadded base64url - the encoding the WebAuthn wire format uses.
     *
     * @param string $binary
     * @return string
     */
    public static function base64url_encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /**
     * Decode unpadded base64url back to binary.
     *
     * Strict decoding: a corrupt credential id must fail here rather than decode to
     * plausible-looking bytes that then fail a signature check for a reason nobody can
     * trace back to the encoding.
     *
     * @param string $base64url
     * @return string
     * @throws Two_Factor_Failed_Exception When the input is not base64url.
     */
    public static function base64url_decode(string $base64url): string
    {
        $padded = strtr($base64url, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        $binary = base64_decode($padded, true);

        if ($binary === false) {
            throw new Two_Factor_Failed_Exception('That security key response was malformed. Please try again.');
        }

        return $binary;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * The library's stdClass args as a plain JSON-safe array.
     *
     * The round trip through json_encode is what invokes every nested ByteBuffer's
     * jsonSerialize(), which is where the base64url encoding actually happens - walking the
     * object graph by hand would have to reimplement that.
     *
     * @param object $args
     * @return array
     */
    private static function _to_array(object $args): array
    {
        return json_decode(json_encode($args), true);
    }
}
