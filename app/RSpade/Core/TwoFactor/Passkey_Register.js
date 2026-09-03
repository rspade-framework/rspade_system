/**
 * Passkey_Register
 *
 * See Passkey_Register.jqhtml for the contract. The ceremony itself lives in
 * Rsx_Two_Factor.register_passkey(); this file is the button around it and the two outcomes
 * that need a screen: a sheet of recovery codes, or nothing to show at all.
 *
 * A NULL RESULT IS A CANCELLED PROMPT, NOT A FAILURE. register_passkey() answers null when
 * the user dismissed the browser's passkey sheet. Nothing is written, nothing is said, and
 * the button is simply available again - a person changing their mind is not an error
 * condition and must not be reported as one.
 *
 * WHY THE SUPPORT CHECK IS TAKEN ONCE, in on_create: whether this browser has WebAuthn does
 * not change while the page is open, and re-asking on every render would make the template's
 * first branch depend on a call rather than on state.
 */
class Passkey_Register extends Component {
    on_create() {
        this.state = {
            supported: Rsx_Two_Factor.is_supported(),

            // The plaintext recovery codes, when this registration minted a sheet. Non-null
            // IS the reveal state.
            recovery_codes: null,

            error: null,
            copied: false,
        };
    }

    on_render() {
        const that = this;

        const $register = this.$sid('register');
        if ($register.exists()) {
            $register.click_async(async function () {
                await that._register();
            });
        }

        const $copy = this.$sid('copy');
        if ($copy.exists()) {
            $copy.click_async(async function () {
                await that._copy();
            });
        }

        const $acknowledge = this.$sid('acknowledge');
        if ($acknowledge.exists()) {
            $acknowledge.off('click.tfa').on('click.tfa', function () {
                that.trigger('registered');
            });
        }
    }

    /**
     * Run the ceremony, then either reveal the codes or report that we are done.
     */
    async _register() {
        const label = str(this.$sid('label').val()).trim();

        this.state.error = null;

        let result;

        try {
            result = await Rsx_Two_Factor.register_passkey(label === '' ? null : label);
        } catch (e) {
            // A stale ceremony or a refused attestation - the server's message is user-safe
            // by contract. Anything the browser itself throws also lands here, which is the
            // only place the user can be told about it.
            this.state.error = e.message ? e.message : 'That passkey could not be registered.';
            this.render();
            return;
        }

        // The user dismissed the browser prompt. Nothing happened; say nothing.
        if (result === null) {
            return;
        }

        if (!result.recovery_codes) {
            this.trigger('registered');
            return;
        }

        this.state.recovery_codes = result.recovery_codes;
        this.state.copied = false;
        this.render();
    }

    /**
     * Put the codes on the clipboard, one per line.
     */
    async _copy() {
        await navigator.clipboard.writeText(this.state.recovery_codes.join('\n'));

        this.state.copied = true;
        this.render();
    }
}
