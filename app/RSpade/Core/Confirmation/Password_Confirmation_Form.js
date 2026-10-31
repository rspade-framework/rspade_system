/**
 * Password_Confirmation_Form - see Password_Confirmation_Form.jqhtml for the contract.
 */
class Password_Confirmation_Form extends Component {
    on_create() {
        // null until the load answers: "not loaded" and "nothing to confirm with" render
        // differently.
        this.data.options = null;

        // Taken once: whether this browser can run a passkey ceremony does not change.
        this.state.passkeys_supported = Rsx_Two_Factor.is_supported();
    }

    async on_load() {
        this.data.options = await Rsx_Password_Confirmation_Controller.options();
    }

    on_render() {
        const that = this;
        const $passkey = this.$sid('passkey');

        $passkey.off('.pcf').on('click.pcf', async function () {
            await that._confirm_with_passkey();
        });
    }

    on_ready() {
        const that = this;
        const form = this.sid('form');

        if (form) {
            // Every successful submit, whatever started it - submit() below, or Enter in the
            // field on a page that hosts the form directly.
            form.on('submitted', function (result) {
                Rsx_Password_Confirmation._accept(result);
                that.trigger('confirmed', result);
            });
        }

        const password = this.sid('password');
        if (password) {
            password.focus();
        }
    }

    /**
     * Submit the password.
     *
     * @returns {Promise<boolean>} True when confirmed; false on a refusal, already rendered.
     */
    async submit() {
        const form = this.sid('form');

        if (!form || !this.data.options || !this.data.options.has_password) {
            return false;
        }

        return (await form.submit()) !== false;
    }

    /** @returns {boolean} Whether to offer the passkey button. */
    _passkey_available() {
        return !!(this.data.options && this.data.options.has_passkey && this.state.passkeys_supported);
    }

    async _confirm_with_passkey() {
        const $error = this.$sid('passkey_error');
        $error.text('');

        let confirmed;

        try {
            confirmed = await this.alive(Rsx_Password_Confirmation.verify_passkey());
        } catch (error) {
            $error.text(error.message || 'That passkey could not confirm it is you.');
            return;
        }

        // false: the user dismissed the browser's prompt - nothing to say.
        if (confirmed) {
            this.trigger('confirmed');
        }
    }
}
