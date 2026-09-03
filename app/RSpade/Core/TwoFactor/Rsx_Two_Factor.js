/**
 * Rsx_Two_Factor - the browser half of the passkey ceremonies.
 *
 * WebAuthn is the one part of this subsystem the server cannot do alone: only the browser can
 * talk to an authenticator, and it will only do so through navigator.credentials, which
 * speaks ArrayBuffers. The server speaks base64url (see Passkeys.php - it is what the whole
 * WebAuthn ecosystem serializes to, and plain base64 would be one '+' away from a corrupt
 * credential id in a URL). So the entire job of this class is the translation between the
 * two, in both directions, plus the two ceremonies themselves.
 *
 * WHICH FIELDS ARE BINARY, and therefore which ones get converted - this is the list that
 * silently breaks a passkey when it is wrong:
 *   create: publicKey.challenge, publicKey.user.id, publicKey.excludeCredentials[].id
 *   get:    publicKey.challenge, publicKey.allowCredentials[].id
 * Everything else in the args is an ordinary string or number and is passed through
 * untouched.
 *
 * A CANCELLED CEREMONY IS NOT AN ERROR. The user dismissing the browser's passkey sheet
 * raises NotAllowedError, and that is expected input - a person changing their mind - so
 * register_passkey() answers null and the caller simply carries on. It is the ONE thing
 * caught here; every other failure (no authenticator, a refused attestation, a server
 * rejection) surfaces.
 *
 * authenticate_passkey() RETURNS the assertion rather than posting it. The verification
 * endpoint belongs to the application, because where a signed-in user lands is application
 * logic - see Rsx_Two_Factor_Controller's docblock and <Two_Factor_Challenge>.
 *
 * See: php artisan rsx:man two_factor
 */
class Rsx_Two_Factor {
    /**
     * Can this browser do WebAuthn at all?
     *
     * A false here is a UI question, not an error: the screen offers the authenticator-app
     * factor instead and says why the passkey button is missing.
     *
     * @returns {boolean}
     */
    static is_supported() {
        return !!window.PublicKeyCredential;
    }

    // -------------------------------------------------------------------------
    // Ceremonies
    // -------------------------------------------------------------------------

    /**
     * Register a new passkey against the signed-in identity.
     *
     * @param {string|null} label What the user calls this key in their settings.
     * @returns {Promise<object|null>} {recovery_codes: array|null}, or null if the user
     *                                 dismissed the browser's prompt.
     */
    static async register_passkey(label) {
        const options = await Rsx_Two_Factor_Controller.passkey_register_begin();

        const public_key = Rsx_Two_Factor._decode_creation_options(options.publicKey);

        let credential = null;

        try {
            credential = await navigator.credentials.create({ publicKey: public_key });
        } catch (e) {
            // The user closed the sheet, or the platform declined to offer one. Expected
            // input - see the class docblock. Anything else is a real failure and rethrows.
            if (e.name === 'NotAllowedError') {
                return null;
            }
            throw e;
        }

        // A create() that resolves with nothing is not a documented outcome, but reading
        // .response off null would report it as a type error three frames from the cause.
        if (!credential) {
            throw new Error('The browser returned no credential.');
        }

        const attestation = {
            clientDataJSON: Rsx_Two_Factor._to_base64url(credential.response.clientDataJSON),
            attestationObject: Rsx_Two_Factor._to_base64url(credential.response.attestationObject),
        };

        return await Rsx_Two_Factor_Controller.passkey_register_confirm({
            attestation: attestation,
            label: label === undefined ? null : label,
        });
    }

    /**
     * Answer a pending login challenge with a passkey, and hand the wire-ready assertion
     * back to the caller.
     *
     * @returns {Promise<object|null>} {id, clientDataJSON, authenticatorData, signature}, or
     *                                 null if the user dismissed the browser's prompt.
     */
    static async authenticate_passkey() {
        const options = await Rsx_Two_Factor_Controller.challenge_passkey_options();

        const public_key = Rsx_Two_Factor._decode_request_options(options.publicKey);

        let credential = null;

        try {
            credential = await navigator.credentials.get({ publicKey: public_key });
        } catch (e) {
            if (e.name === 'NotAllowedError') {
                return null;
            }
            throw e;
        }

        if (!credential) {
            throw new Error('The browser returned no credential.');
        }

        // The id is encoded from rawId rather than read off credential.id: both are the same
        // base64url string by spec, but encoding the bytes ourselves means one implementation
        // of the encoding decides what the server matches on.
        return {
            id: Rsx_Two_Factor._to_base64url(credential.rawId),
            clientDataJSON: Rsx_Two_Factor._to_base64url(credential.response.clientDataJSON),
            authenticatorData: Rsx_Two_Factor._to_base64url(credential.response.authenticatorData),
            signature: Rsx_Two_Factor._to_base64url(credential.response.signature),
        };
    }

    // -------------------------------------------------------------------------
    // Argument translation
    // -------------------------------------------------------------------------

    /**
     * The server's creation args with every binary field turned back into an ArrayBuffer.
     *
     * @param {object} public_key
     * @returns {object}
     */
    static _decode_creation_options(public_key) {
        const decoded = clone(public_key);

        decoded.challenge = Rsx_Two_Factor._from_base64url(public_key.challenge);
        decoded.user = clone(public_key.user);
        decoded.user.id = Rsx_Two_Factor._from_base64url(public_key.user.id);
        decoded.excludeCredentials = Rsx_Two_Factor._decode_descriptors(public_key.excludeCredentials);

        return decoded;
    }

    /**
     * The server's request args with every binary field turned back into an ArrayBuffer.
     *
     * @param {object} public_key
     * @returns {object}
     */
    static _decode_request_options(public_key) {
        const decoded = clone(public_key);

        decoded.challenge = Rsx_Two_Factor._from_base64url(public_key.challenge);
        decoded.allowCredentials = Rsx_Two_Factor._decode_descriptors(public_key.allowCredentials);

        return decoded;
    }

    /**
     * A credential-descriptor list ({id, type, transports}) with its ids decoded.
     *
     * An absent list stays absent: an empty allowCredentials means "any credential this
     * authenticator holds", which is a different request from not sending the key at all.
     *
     * @param {Array|undefined} descriptors
     * @returns {Array|undefined}
     */
    static _decode_descriptors(descriptors) {
        if (!is_array(descriptors)) {
            return descriptors;
        }

        return descriptors.map(function (descriptor) {
            const decoded = clone(descriptor);
            decoded.id = Rsx_Two_Factor._from_base64url(descriptor.id);
            return decoded;
        });
    }

    // -------------------------------------------------------------------------
    // base64url (RFC 4648 section 5) - the mirror of Passkeys::base64url_*()
    // -------------------------------------------------------------------------

    /**
     * An ArrayBuffer (or typed array) as an unpadded base64url string.
     *
     * @param {ArrayBuffer|Uint8Array} buffer
     * @returns {string}
     */
    static _to_base64url(buffer) {
        const bytes = new Uint8Array(buffer);

        let binary = '';

        for (let i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }

        return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    /**
     * An unpadded base64url string as an ArrayBuffer.
     *
     * @param {string} value
     * @returns {ArrayBuffer}
     */
    static _from_base64url(value) {
        const padded = str(value).replace(/-/g, '+').replace(/_/g, '/');
        const binary = window.atob(padded + '='.repeat((4 - (padded.length % 4)) % 4));

        const bytes = new Uint8Array(binary.length);

        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }

        return bytes.buffer;
    }
}
