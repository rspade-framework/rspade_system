/**
 * Rsx_Password_Confirmation - the browser half of "confirm it's you".
 *
 * Before a sensitive action (adding a passkey, removing a factor, minting an API key - see
 * Rsx_Password_Confirmation.php) the server requires the user to have re-entered their
 * password, or used one of their passkeys, in the last few minutes. This class is how a page
 * asks whether that is still true, gets it done, and gives it up:
 *
 *     if (Rsx_Password_Confirmation.status() === Rsx_Password_Confirmation.STATUS_REQUIRED) {
 *         // show the application's dialog hosting <Password_Confirmation_Form>
 *     }
 *     await Rsx_Password_Confirmation.clear();   // leaving the screen that needed it
 *
 * THE STATUS IS HELD IN MEMORY, for exactly the lifetime the server gave it
 * (expires_in_seconds from the verifying answer). A page that has not confirmed - or a fresh
 * page load - answers STATUS_REQUIRED and asks; the server's own check is the authority, and a
 * gated endpoint refuses with a "refresh and try again" error if the two ever disagree.
 *
 * THE DIALOG IS THE APPLICATION'S. The framework ships the form (<Password_Confirmation_Form>)
 * and the endpoints; where the form appears, how many tries a user gets and what happens after
 * are application decisions.
 *
 * ONE CLASS, BOTH REALMS: the endpoints answer for the page's own experience.
 *
 * See: php artisan rsx:man password_confirmation
 */
class Rsx_Password_Confirmation {
    static STATUS_CONFIRMED = 'confirmed';
    static STATUS_REQUIRED = 'required';

    // When the confirmation this page holds runs out (Date.now() milliseconds); null for none.
    static _expires_at_ms = null;

    /**
     * Whether a gated action can go ahead now, or needs the user to confirm first.
     *
     * @returns {string} STATUS_CONFIRMED or STATUS_REQUIRED
     */
    static status() {
        return Rsx_Password_Confirmation.is_confirmed()
            ? Rsx_Password_Confirmation.STATUS_CONFIRMED
            : Rsx_Password_Confirmation.STATUS_REQUIRED;
    }

    /**
     * @returns {boolean} True while this page holds a live confirmation.
     */
    static is_confirmed() {
        return Rsx_Password_Confirmation._expires_at_ms !== null
            && Date.now() < Rsx_Password_Confirmation._expires_at_ms;
    }

    /**
     * Confirm with the password. <Password_Confirmation_Form> does this through its own form;
     * this is the same call for a page that collects the password itself.
     *
     * @param {object} params {password, __turnstile}
     * @returns {Promise<boolean>} True when confirmed. A refusal (wrong password, the rate
     *          limit, a failed Turnstile check) REJECTS with the Ajax error, whose message is
     *          user-safe.
     */
    static async verify(params) {
        const result = await Rsx_Password_Confirmation_Controller.verify(params);

        Rsx_Password_Confirmation._accept(result);

        return true;
    }

    /**
     * Confirm with one of the account's passkeys: the browser's passkey prompt, then the
     * server's check that the key belongs to the signed-in account.
     *
     * @returns {Promise<boolean>} True when confirmed; false when the user dismissed the
     *          browser's prompt. A refusal REJECTS with the Ajax error.
     */
    static async verify_passkey() {
        const options = await Rsx_Password_Confirmation_Controller.passkey_options();
        const assertion = await Rsx_Two_Factor._get_assertion(options);

        if (assertion === null) {
            return false;
        }

        const result = await Rsx_Password_Confirmation_Controller.verify_passkey({ assertion: assertion });

        Rsx_Password_Confirmation._accept(result);

        return true;
    }

    /**
     * Give the confirmation up, here and on the server. Call it when the user leaves the screen
     * that needed it; the server's expiry bounds it either way.
     *
     * @returns {Promise<void>}
     */
    static async clear() {
        Rsx_Password_Confirmation._expires_at_ms = null;

        await Rsx_Password_Confirmation_Controller.clear();
    }

    /**
     * Record a verifying answer: {confirmed: true, expires_in_seconds}.
     *
     * @param {object} result
     */
    static _accept(result) {
        Rsx_Password_Confirmation._expires_at_ms = Date.now() + int(result.expires_in_seconds) * 1000;
    }
}
