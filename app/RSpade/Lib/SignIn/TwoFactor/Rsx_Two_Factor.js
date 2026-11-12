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
 * authenticate_passkey() and sign_in_with_passkey() RETURN the assertion rather than posting
 * it. The verification endpoint belongs to the application, because where a signed-in user
 * lands is application logic - see Rsx_Two_Factor_Controller's docblock, <Two_Factor_Challenge>
 * and <Passkey_Sign_In>.
 *
 * ONE CLASS, BOTH REALMS. The server has a controller per realm - Rsx_Two_Factor_Controller
 * for staff, Rsx_Portal_Two_Factor_Controller for the client portal, same method names - and
 * controller() picks the one this page belongs to from its experience (Rsx_Portal.is_portal()).
 * Every ceremony here, and every shipped two-factor component, goes through it, so none of
 * them takes a realm argument and a portal page can never start a staff ceremony.
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

    /**
     * The second-factor controller for THIS page's realm.
     *
     * @returns {object} Rsx_Portal_Two_Factor_Controller on a portal page, else
     *                   Rsx_Two_Factor_Controller.
     */
    static controller() {
        return Rsx_Portal.is_portal() ? Rsx_Portal_Two_Factor_Controller : Rsx_Two_Factor_Controller;
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
        const options = await Rsx_Two_Factor.controller().passkey_register_begin();

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

        return await Rsx_Two_Factor.controller().passkey_register_confirm({
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
        const options = await Rsx_Two_Factor.controller().challenge_passkey_options();

        return await Rsx_Two_Factor._get_assertion(options);
    }

    /**
     * Sign in with a passkey ALONE - no password, nothing pending: the authenticator offers
     * whatever discoverable credential it holds for this site, and the wire-ready assertion
     * is handed back for the application's own verification endpoint, which calls
     * verify_passkey_login() on the realm's facade.
     *
     * @returns {Promise<object|null>} {id, clientDataJSON, authenticatorData, signature}, or
     *                                 null if the user dismissed the browser's prompt.
     */
    static async sign_in_with_passkey() {
        const options = await Rsx_Two_Factor.controller().passkey_login_options();

        return await Rsx_Two_Factor._get_assertion(options);
    }

    /**
     * Run navigator.credentials.get() over the server's request args and encode the result.
     *
     * @param {object} options The server's {publicKey} request args.
     * @returns {Promise<object|null>} The assertion, or null for a dismissed prompt.
     */
    static async _get_assertion(options) {
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
