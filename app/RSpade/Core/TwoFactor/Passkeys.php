<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\TwoFactor;

use lbuchs\WebAuthn\WebAuthn;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
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
 * session value and read from there when the response arrives, so the only thing that can
 * satisfy a ceremony is the browser that started it. There are TWO keys per realm: the
 * realm's WEBAUTHN_CHALLENGE_KEY, shared by registration and the second-factor assertion
 * (a browser is doing one or the other, never both), and its PASSKEY_LOGIN_CHALLENGE_KEY,
 * for a passwordless sign-in. The second is separate because a login page can offer "sign
 * in with a passkey" while a second-factor challenge is pending in the same browser, and
 * one ceremony must never overwrite the other's challenge.
 *
 * EVERY CALL NAMES ITS REALM - the facade class (Rsx_Two_Factor for staff login identities,
 * Rsx_Portal_Two_Factor for portal users). The realm supplies the credential table, the
 * owner column, the session keys, the relying party id and the user handle. Staff and portal
 * credentials live in DIFFERENT TABLES, and that is what makes the lookup in
 * verify_assertion() realm-scoped: both realms usually share one relying party (one host),
 * so a browser will happily offer a staff passkey on the portal's sign-in page, and the only
 * thing standing between that and a staff credential answering a portal challenge is that
 * the portal realm never looks in the staff table.
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
 * Every method is static. Application code talks to Rsx_Two_Factor or Rsx_Portal_Two_Factor,
 * never to this class.
 *
 * See: php artisan rsx:man two_factor
 */
class Passkeys
{
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
     * @param string $realm The realm facade.
     * @return WebAuthn
     */
    private static function _server(string $realm): WebAuthn
    {
        return new WebAuthn(
            Rsx_Two_Factor::issuer(),
            $realm::_relying_party_id(),
            self::FORMATS,
            true
        );
    }

    /**
     * The application's relying party id: the bare APP_URL hostname, no scheme, no port.
     *
     * The staff realm's rpId, and the portal's too unless the portal is served from a
     * dedicated domain (see Rsx_Portal_Two_Factor::_relying_party_id()).
     *
     * @return string
     */
    public static function relying_party_id(): string
    {
        return self::bare_host(Rsx::get_hostname());
    }

    /**
     * A hostname reduced to what WebAuthn accepts as an rpId: lower case, no port.
     *
     * @param string $hostname
     * @return string
     */
    public static function bare_host(string $hostname): string
    {
        // get_hostname() already strips a port, but a passkey enrolled against the wrong
        // rpId is unusable forever rather than merely broken today, so this does not trust
        // any caller and re-checks. Cheap insurance on a value that cannot be corrected later.
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
     * THE USER HANDLE COMES FROM THE REALM. An authenticator files a resident credential
     * under (rpId, user handle) and REPLACES an existing one with the same pair, so a staff
     * identity and a portal user sharing a numeric id on one host would silently overwrite
     * each other's passkey. The portal realm's handle is prefixed for exactly that reason.
     *
     * @param string $realm The realm facade.
     * @param Rsx_Model_Abstract $identity The identity enrolling.
     * @return array JSON-safe creation args.
     */
    public static function registration_options(string $realm, Rsx_Model_Abstract $identity): array
    {
        $server = self::_server($realm);
        $model = $realm::_credential_model();

        $existing = [];

        $rows = $model::where($realm::_owner_column(), $identity->id)
            ->where('type_id', $model::TYPE_PASSKEY)
            ->whereNotNull('credential_key')
            ->result_set();

        foreach ($rows as $row) {
            $existing[] = self::base64url_decode($row->credential_key);
        }

        $args = $server->getCreateArgs(
            userId: $realm::_user_handle((int) $identity->id),
            userName: (string) $identity->email,
            userDisplayName: (string) $identity->email,
            requireResidentKey: true,
            excludeCredentialIds: $existing
        );

        self::_store_challenge($server, $realm::WEBAUTHN_CHALLENGE_KEY);

        return self::_to_array($args);
    }

    /**
     * Verify the browser's attestation and return what the credential row must store.
     *
     * The challenge comes from the SESSION, never from $attestation - see the class
     * docblock. It is forgotten as soon as it is read, so one challenge satisfies exactly
     * one ceremony whether that ceremony succeeds or fails.
     *
     * @param string $realm The realm facade.
     * @param array $attestation {clientDataJSON, attestationObject}, both base64url.
     * @return array {credential_key, public_key, sign_count}
     * @throws Two_Factor_Failed_Exception When the ceremony is stale or malformed.
     * @throws \lbuchs\WebAuthn\WebAuthnException When the attestation does not verify.
     */
    public static function verify_registration(string $realm, array $attestation): array
    {
        $challenge = self::_consume_challenge($realm::WEBAUTHN_CHALLENGE_KEY);

        if (!isset($attestation['clientDataJSON'], $attestation['attestationObject'])) {
            throw new Two_Factor_Failed_Exception('That security key response was incomplete. Please try again.');
        }

        $server = self::_server($realm);

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
     * @param string $realm The realm facade.
     * @param int $identity_id
     * @return array JSON-safe request args.
     */
    public static function assertion_options(string $realm, int $identity_id): array
    {
        $server = self::_server($realm);
        $model = $realm::_credential_model();

        $credential_ids = [];

        $rows = $model::where($realm::_owner_column(), $identity_id)
            ->where('type_id', $model::TYPE_PASSKEY)
            ->whereNotNull('confirmed_at')
            ->whereNotNull('credential_key')
            ->result_set();

        foreach ($rows as $row) {
            $credential_ids[] = self::base64url_decode($row->credential_key);
        }

        $args = $server->getGetArgs($credential_ids);

        self::_store_challenge($server, $realm::WEBAUTHN_CHALLENGE_KEY);

        return self::_to_array($args);
    }

    /**
     * The arguments for navigator.credentials.get() for a PASSWORDLESS sign-in: no identity
     * is named, so there is no allowCredentials list and the authenticator offers whatever
     * discoverable credential it holds for this relying party.
     *
     * USER VERIFICATION IS REQUIRED here and nowhere else. As a second factor a passkey only
     * has to prove possession - the password already proved knowledge. As the ONLY credential
     * it has to be two factors on its own: possession of the authenticator plus the PIN or
     * biometric that unlocked it. 'required' in the options tells the browser to insist; the
     * matching verify_assertion(..., passwordless: true) refuses an assertion whose UV flag is
     * not set, because the options are advice to a client and the flag is the proof.
     *
     * The challenge parks under the realm's PASSKEY_LOGIN_CHALLENGE_KEY, never the key the
     * second-factor ceremony uses - see the class docblock.
     *
     * @param string $realm The realm facade.
     * @return array JSON-safe request args.
     */
    public static function discoverable_assertion_options(string $realm): array
    {
        $server = self::_server($realm);

        $args = $server->getGetArgs([], requireUserVerification: true);

        self::_store_challenge($server, $realm::PASSKEY_LOGIN_CHALLENGE_KEY);

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
     * The lookup is in the REALM's table only, so a credential from the other realm is simply
     * unknown here - see the class docblock.
     *
     * The stored counter is passed as prevSignatureCnt so the library performs the
     * anti-cloning check, and the row is updated only after the signature verifies.
     *
     * @param string $realm The realm facade.
     * @param array $assertion {id, clientDataJSON, authenticatorData, signature}.
     * @param bool $passwordless True for a passwordless sign-in: the challenge is read from
     *                           the realm's PASSKEY_LOGIN_CHALLENGE_KEY and user verification
     *                           is REQUIRED. False for the second-factor assertion.
     * @return Rsx_Model_Abstract The credential row that authenticated.
     * @throws Two_Factor_Failed_Exception When the ceremony is stale, malformed, or names
     *                                     a credential this realm does not know.
     * @throws \lbuchs\WebAuthn\WebAuthnException When the signature does not verify, or a
     *                                           passwordless assertion is not user-verified.
     */
    public static function verify_assertion(string $realm, array $assertion, bool $passwordless = false): Rsx_Model_Abstract
    {
        $challenge = self::_consume_challenge(
            $passwordless ? $realm::PASSKEY_LOGIN_CHALLENGE_KEY : $realm::WEBAUTHN_CHALLENGE_KEY
        );

        if (!isset($assertion['id'], $assertion['clientDataJSON'], $assertion['authenticatorData'], $assertion['signature'])) {
            throw new Two_Factor_Failed_Exception('That security key response was incomplete. Please try again.');
        }

        $model = $realm::_credential_model();

        $credential = $model::where('credential_key', (string) $assertion['id'])
            ->where('type_id', $model::TYPE_PASSKEY)
            ->whereNotNull('confirmed_at')
            ->first();

        if ($credential === null) {
            // Deliberately the same phrasing as every other failure: telling the caller
            // that this particular key is unknown would let them enumerate which keys the
            // server has ever seen.
            throw new Two_Factor_Failed_Exception('That security key is not valid.');
        }

        $server = self::_server($realm);

        $server->processGet(
            self::base64url_decode($assertion['clientDataJSON']),
            self::base64url_decode($assertion['authenticatorData']),
            self::base64url_decode($assertion['signature']),
            $credential->secret,
            $challenge,
            (int) $credential->counter,
            $passwordless
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
     * @param string $key The session value key this ceremony parks under.
     * @return void
     */
    private static function _store_challenge(WebAuthn $server, string $key): void
    {
        Session::put_value(
            $key,
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
     * @param string $key The session value key the ceremony parked under.
     * @return string The raw challenge bytes.
     * @throws Two_Factor_Failed_Exception When there is nothing in flight.
     */
    private static function _consume_challenge(string $key): string
    {
        $stored = Session::get_value($key);

        Session::forget_value($key);

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
