/**
 * Two_Factor_Challenge
 *
 * See Two_Factor_Challenge.jqhtml for the contract. This file owns the state load, the two
 * ways to answer, and the handoff to the application's verification endpoint.
 *
 * WHY data.loaded EXISTS ALONGSIDE data.challenge. null means two different things here -
 * "the load has not answered yet" and "there is nothing pending" - and they render
 * differently. A separate boolean is the honest way to tell them apart; overloading the
 * payload would make the loading state indistinguishable from the expired one, which is the
 * state most likely to be misread by a user who has just walked away and come back.
 *
 * THE ENDPOINT IS RESOLVED THROUGH THE MANIFEST - Manifest.get_class_by_name(controller)
 * [method] - because the whole point of the two args is that this component does not know
 * the application's controller. A bundle's classes are lexical globals, not window
 * properties, so window[controller] holds nothing; the manifest is the registry every
 * generated controller stub is defined into, in every bundle, on a Blade page and in a SPA
 * alike. A wrong name fails here with a message naming exactly what was not found rather
 * than as an undefined-is-not-a-function three frames deeper.
 *
 * ON SUCCESS THE PAGE NAVIGATES, and nothing is rendered afterwards: the user is signed in
 * and this screen is finished. window.location, not Spa.dispatch - the destination is a
 * fresh authenticated document, and the challenge page is not part of the SPA it leads into.
 */
class Two_Factor_Challenge extends Component {
    on_create() {
        if (!this.args.controller || !this.args.method) {
            throw new Error('Two_Factor_Challenge requires $controller and $method');
        }

        this.data.loaded = false;
        this.data.challenge = null;

        this.state = {
            // Taken once: whether this browser can run a passkey ceremony does not change
            // while the page is open, and the button must not be offered if it cannot.
            passkeys_supported: Rsx_Two_Factor.is_supported(),

            error: null,

            // A user-facing note that is not an error - "A new code has been sent."
            notice: null,
        };
    }

    async on_load() {
        this.data.challenge = await Rsx_Two_Factor.controller().challenge_state();
        this.data.loaded = true;
    }

    on_loaded() {
        // Sticky events: a host that subscribes after this fires still hears it, so there is
        // no race between mounting the component and listening for the redirect it asks for.
        if (this.data.challenge === null) {
            this.trigger('no_challenge');
        }
    }

    async on_ready() {
        // The first code is sent for the user when the host named a send endpoint and none
        // has been issued yet. A host that issues the first code itself, server-side, before
        // the page loads, never reaches this branch (codes_issued is already 1).
        // null is a state, not a missing value: nothing is pending (see on_loaded).
        const challenge = this.data.challenge;
        if (challenge === null) {
            return;
        }

        if (challenge.has_issued_code && challenge.codes_issued === 0 && this._can_send()) {
            await this._send_code(false);
        }
    }

    on_render() {
        const that = this;

        const $verify = this.$sid('verify');
        if ($verify.exists()) {
            $verify.click_async(async function () {
                await that._verify_code();
            });
        }

        const $code = this.$sid('code');
        if ($code.exists()) {
            $code.off('keydown.tfa').on('keydown.tfa', async function (e) {
                if (e.key === 'Enter') {
                    await that._verify_code();
                }
            });
        }

        const $cancel = this.$sid('cancel');
        if ($cancel.exists()) {
            $cancel.click_async(async function () {
                await that._cancel();
            });
        }

        const $passkey = this.$sid('passkey');
        if ($passkey.exists()) {
            $passkey.click_async(async function () {
                await that._verify_passkey();
            });
        }

        const $send = this.$sid('send');
        if ($send.exists()) {
            $send.click_async(async function () {
                await that._send_code(true);
            });
        }
    }

    /**
     * Whether the host named an endpoint that issues and delivers a code.
     */
    _can_send() {
        return !!(this.args.send_controller && this.args.send_method);
    }

    /**
     * The code box's placeholder: $placeholder, else what this identity can type - see the
     * template's ONE INPUT FOR TWO KINDS OF ANSWER.
     */
    placeholder() {
        if (this.args.placeholder) {
            return this.args.placeholder;
        }

        const challenge = this.data.challenge;
        if (challenge.has_issued_code) {
            return challenge.has_recovery_codes ? '6-digit or recovery code' : '6-digit code';
        }

        if (challenge.has_totp && challenge.has_recovery_codes) {
            return 'Authenticator or recovery code';
        }

        return challenge.has_totp ? '6-digit code' : 'Recovery code';
    }

    /**
     * Ask the host's send endpoint to issue and deliver a code, then re-read the challenge.
     *
     * @param {boolean} announce Say "A new code has been sent." afterwards (a resend the
     *                           user asked for; the automatic first send says nothing).
     */
    async _send_code(announce) {
        const controller = this._resolve(this.args.send_controller, this.args.send_method);

        this.state.error = null;
        this.state.notice = null;

        try {
            await controller[this.args.send_method]({});
        } catch (e) {
            this._show_error(e);
            return;
        }

        if (announce) {
            this.state.notice = 'A new code has been sent.';
        }

        await this.reload();
    }

    /**
     * An application controller's endpoint, through the manifest - see the class docblock.
     */
    _resolve(controller_name, method) {
        const controller = Manifest.get_class_by_name(controller_name);

        if (!controller || typeof controller[method] !== 'function') {
            throw new Error('Two_Factor_Challenge could not resolve the endpoint ' + controller_name + '::' + method);
        }

        return controller;
    }

    /**
     * Cancel: discard the pending challenge, then go where the host said Cancel leads.
     */
    async _cancel() {
        await Rsx_Two_Factor.controller().challenge_abandon();
        window.location = this.args.cancel_url;
    }

    /**
     * Answer with the typed string - an authenticator code or a recovery code, the server
     * decides which.
     */
    async _verify_code() {
        const code = str(this.$sid('code').val()).trim();

        if (code === '') {
            return;
        }

        await this._submit({ code: code });
    }

    /**
     * Answer with a passkey assertion.
     */
    async _verify_passkey() {
        this.state.error = null;

        let assertion;

        try {
            assertion = await Rsx_Two_Factor.authenticate_passkey();
        } catch (e) {
            this._show_error(e);
            return;
        }

        // The user dismissed the browser prompt. Nothing happened; say nothing.
        if (assertion === null) {
            return;
        }

        await this._submit({ assertion: assertion });
    }

    /**
     * Hand one answer to the application's verification endpoint and follow where it points.
     */
    async _submit(payload) {
        const controller = this._resolve(this.args.controller, this.args.method);

        this.state.error = null;
        this.state.notice = null;

        let result;

        try {
            result = await controller[this.args.method](payload);
        } catch (e) {
            // Everything the challenge can legitimately answer with lands here: a wrong code,
            // an expired window, and the throttle refusal. All three are user-safe messages
            // and all three belong on screen.
            this._show_error(e);
            return;
        }

        if (!result || !result.redirect) {
            throw new Error(
                'The verification endpoint ' + this.args.controller + '::' + this.args.method +
                    ' returned no redirect.'
            );
        }

        window.location = result.redirect;
    }

    /**
     * Render a failure inline and clear the box, so the retry starts from empty.
     */
    _show_error(e) {
        this.state.error = e.message ? e.message : 'That code is not valid.';
        this.render();

        const $code = this.$sid('code');
        if ($code.exists()) {
            $code.val('').focus();
        }
    }
}
