/**
 * Passkey_Sign_In
 *
 * See Passkey_Sign_In.jqhtml for the contract. The ceremony itself lives in
 * Rsx_Two_Factor.sign_in_with_passkey(); this file is the button around it and the handoff to
 * the application's verification endpoint.
 *
 * A NULL ASSERTION IS A DISMISSED PROMPT, NOT A FAILURE. The user closed the browser's passkey
 * sheet, or had no passkey for this site to offer. Nothing is said and the button is simply
 * available again.
 *
 * THE ENDPOINT IS RESOLVED THROUGH window[controller][method], as <Two_Factor_Challenge>
 * resolves it: every RSX Ajax controller is a global, so a wrong name fails here with a
 * message naming what was not found.
 */
class Passkey_Sign_In extends Component {
    on_create() {
        if (!this.args.controller || !this.args.method) {
            throw new Error('Passkey_Sign_In requires $controller and $method');
        }

        this.state = {
            // Taken once: whether this browser can run a passkey ceremony does not change
            // while the page is open.
            supported: Rsx_Two_Factor.is_supported(),

            error: null,
        };
    }

    on_render() {
        const that = this;

        // The unsupported state is marked ON THE ROOT, which the template cannot reach: it
        // hides the element, and it is what lets a host page hide the "or" divider it placed
        // above this control when nothing below that divider is left to show.
        this.$.toggleClass('Passkey_Sign_In--unsupported', !this.state.supported);

        const $sign_in = this.$sid('sign_in');
        if ($sign_in.exists()) {
            $sign_in.click_async(async function () {
                await that._sign_in();
            });
        }
    }

    /**
     * Run the ceremony, post the assertion, and follow where the endpoint points.
     */
    async _sign_in() {
        const controller = window[this.args.controller];

        if (!controller || typeof controller[this.args.method] !== 'function') {
            throw new Error(
                'Passkey_Sign_In could not resolve the endpoint ' +
                    this.args.controller + '::' + this.args.method
            );
        }

        this.state.error = null;

        let assertion;

        try {
            assertion = await Rsx_Two_Factor.sign_in_with_passkey();
        } catch (e) {
            this._show_error(e);
            return;
        }

        if (assertion === null) {
            return;
        }

        let result;

        try {
            result = await controller[this.args.method]({ assertion: assertion });
        } catch (e) {
            // A refused assertion, and the throttle refusal - both user-safe by contract,
            // both belong on screen.
            this._show_error(e);
            return;
        }

        if (!result || !result.redirect) {
            throw new Error(
                'The verification endpoint ' + this.args.controller + '::' + this.args.method +
                    ' returned no redirect.'
            );
        }

        this.trigger('signed_in');

        window.location = result.redirect;
    }

    /**
     * Render a failure inline.
     */
    _show_error(e) {
        this.state.error = e.message ? e.message : 'That passkey could not sign you in.';
        this.render();
    }
}
