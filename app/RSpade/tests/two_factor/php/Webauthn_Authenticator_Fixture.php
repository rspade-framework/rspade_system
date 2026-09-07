<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TwoFactor\Php;

/**
 * Webauthn_Authenticator_Fixture - a simulated FIDO2 authenticator, in software.
 *
 * WHY THIS EXISTS. A passkey ceremony needs a device holding a private key, and the library
 * ships no recorded fixture data - so without this the entire WebAuthn path would be
 * untestable and would have to be taken on faith. It is not a mock: it holds a real P-256
 * keypair, produces a real CBOR attestation object and signs real assertions with OpenSSL.
 * The library verifies it exactly as it verifies a YubiKey, which is what makes a passing
 * test mean something.
 *
 * WHAT IT PRODUCES, per the WebAuthn spec:
 *   attestation - a CBOR map {fmt: 'none', attStmt: {}, authData} whose authData carries the
 *                 rpIdHash, the flag byte, the signature counter and the attested credential
 *                 data (AAGUID, credential id, and the public key in COSE_Key form).
 *   assertion   - authData again (no attested credential data this time) plus an ECDSA
 *                 signature over authData || SHA256(clientDataJSON), which is the exact
 *                 preimage the spec defines.
 *
 * ATTESTATION FORMAT IS 'none' because that is the only format RSpade accepts - see the
 * Passkeys class docblock for why. That makes the attStmt an empty map and means no
 * certificate chain has to be synthesized.
 *
 * THE CBOR ENCODER BELOW IS DELIBERATELY MINIMAL: unsigned and negative integers, byte
 * strings, text strings and definite-length maps, which is everything these two structures
 * need and nothing more. It is a test fixture, not a CBOR library, and it must never grow
 * into one.
 *
 * INSTANTIATABLE, and genuinely so: each instance IS a distinct device, holding its own
 * keypair and its own credential id. A test that needs two authenticators - registering a
 * second key, or presenting a stranger's key - constructs two.
 */
#[Instantiatable]
class Webauthn_Authenticator_Fixture
{
    /**
     * The credential id this authenticator issued, raw binary.
     */
    private string $credential_id;

    /**
     * The OpenSSL private key handle.
     */
    private $private_key;

    /**
     * The relying party id this authenticator was enrolled against.
     */
    private string $rp_id;

    /**
     * @param string $rp_id The relying party id (the bare hostname).
     */
    public function __construct(string $rp_id)
    {
        $this->rp_id = $rp_id;
        $this->credential_id = random_bytes(32);
        $this->private_key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
    }

    /**
     * The credential id, base64url encoded - the spelling the browser sends and the
     * credential_key column stores.
     *
     * @return string
     */
    public function credential_key(): string
    {
        return self::base64url_encode($this->credential_id);
    }

    /**
     * The origin this authenticator claims to be signing for. https, because the library
     * requires it for any rpId that is not localhost.
     *
     * @return string
     */
    public function origin(): string
    {
        return 'https://' . $this->rp_id;
    }

    // -------------------------------------------------------------------------
    // Ceremonies
    // -------------------------------------------------------------------------

    /**
     * A registration response for the given challenge, in the shape
     * Passkeys::verify_registration() takes.
     *
     * @param string $challenge_b64url The challenge, as stored in the session.
     * @param int $sign_count The counter to report.
     * @return array {clientDataJSON, attestationObject}
     */
    public function attestation_response(string $challenge_b64url, int $sign_count = 0): array
    {
        // UP (0x01) user present, UV (0x04) user verified, AT (0x40) attested credential
        // data included - the flag byte a platform authenticator sets on a create.
        $auth_data = $this->_auth_data(0x45, $sign_count)
            . str_repeat("\0", 16)
            . pack('n', strlen($this->credential_id))
            . $this->credential_id
            . $this->_cose_public_key();

        $attestation_object = self::_cbor_map([
            [self::_cbor_text('fmt'), self::_cbor_text('none')],
            [self::_cbor_text('attStmt'), self::_cbor_map([])],
            [self::_cbor_text('authData'), self::_cbor_bytes($auth_data)],
        ]);

        return [
            'clientDataJSON' => self::base64url_encode(
                $this->_client_data('webauthn.create', $challenge_b64url)
            ),
            'attestationObject' => self::base64url_encode($attestation_object),
        ];
    }

    /**
     * An assertion response for the given challenge, in the shape
     * Passkeys::verify_assertion() takes.
     *
     * @param string $challenge_b64url The challenge, as stored in the session.
     * @param int $sign_count The counter to report. Must exceed the stored one or the
     *                        library refuses the assertion as a possible clone.
     * @return array {id, clientDataJSON, authenticatorData, signature}
     */
    public function assertion_response(string $challenge_b64url, int $sign_count): array
    {
        // UP|UV, and no attested credential data on a get.
        $auth_data = $this->_auth_data(0x05, $sign_count);
        $client_data = $this->_client_data('webauthn.get', $challenge_b64url);

        // The spec's signature preimage: authData concatenated with the SHA-256 of the
        // client data JSON.
        openssl_sign(
            $auth_data . hash('sha256', $client_data, true),
            $signature,
            $this->private_key,
            OPENSSL_ALGO_SHA256
        );

        return [
            'id' => $this->credential_key(),
            'clientDataJSON' => self::base64url_encode($client_data),
            'authenticatorData' => self::base64url_encode($auth_data),
            'signature' => self::base64url_encode($signature),
        ];
    }

    // -------------------------------------------------------------------------
    // Structures
    // -------------------------------------------------------------------------

    /**
     * The fixed head of an authenticator data structure: rpIdHash, flags, signature counter.
     *
     * @param int $flags
     * @param int $sign_count
     * @return string
     */
    private function _auth_data(int $flags, int $sign_count): string
    {
        return hash('sha256', $this->rp_id, true) . chr($flags) . pack('N', $sign_count);
    }

    /**
     * The client data JSON the browser would have produced.
     *
     * @param string $type 'webauthn.create' or 'webauthn.get'.
     * @param string $challenge_b64url
     * @return string
     */
    private function _client_data(string $type, string $challenge_b64url): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $challenge_b64url,
            'origin' => $this->origin(),
        ]);
    }

    /**
     * The public key as a COSE_Key map: EC2 / ES256 / P-256, with the affine coordinates
     * left-padded to the curve's 32-byte field size.
     *
     * @return string
     */
    private function _cose_public_key(): string
    {
        $details = openssl_pkey_get_details($this->private_key);

        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        return self::_cbor_map([
            [self::_cbor_int(1), self::_cbor_int(2)],      // kty: EC2
            [self::_cbor_int(3), self::_cbor_int(-7)],     // alg: ES256
            [self::_cbor_int(-1), self::_cbor_int(1)],     // crv: P-256
            [self::_cbor_int(-2), self::_cbor_bytes($x)],
            [self::_cbor_int(-3), self::_cbor_bytes($y)],
        ]);
    }

    // -------------------------------------------------------------------------
    // Minimal CBOR (RFC 8949) - see the class docblock
    // -------------------------------------------------------------------------

    /**
     * A CBOR head: the major type in the top three bits, the argument inline or in a
     * following 1, 2 or 4 byte field.
     *
     * @param int $major
     * @param int $value
     * @return string
     */
    private static function _cbor_head(int $major, int $value): string
    {
        if ($value < 24) {
            return chr(($major << 5) | $value);
        }

        if ($value < 256) {
            return chr(($major << 5) | 24) . chr($value);
        }

        if ($value < 65536) {
            return chr(($major << 5) | 25) . pack('n', $value);
        }

        return chr(($major << 5) | 26) . pack('N', $value);
    }

    /**
     * An integer. A negative n is major type 1 encoding -n - 1, per RFC 8949.
     *
     * @param int $value
     * @return string
     */
    private static function _cbor_int(int $value): string
    {
        return $value >= 0
            ? self::_cbor_head(0, $value)
            : self::_cbor_head(1, -$value - 1);
    }

    private static function _cbor_bytes(string $value): string
    {
        return self::_cbor_head(2, strlen($value)) . $value;
    }

    private static function _cbor_text(string $value): string
    {
        return self::_cbor_head(3, strlen($value)) . $value;
    }

    /**
     * A definite-length map from already-encoded [key, value] pairs.
     *
     * @param array $pairs
     * @return string
     */
    private static function _cbor_map(array $pairs): string
    {
        $out = self::_cbor_head(5, count($pairs));

        foreach ($pairs as $pair) {
            $out .= $pair[0] . $pair[1];
        }

        return $out;
    }

    /**
     * Unpadded base64url, the WebAuthn wire encoding.
     *
     * @param string $binary
     * @return string
     */
    public static function base64url_encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
