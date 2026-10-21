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
 *
 * THE EMAIL LINK. The framework's /_email_link/:token route sends the browser that owns the
 * challenge back to this page with #rsx_email_link=<token>. The token is taken out of the
 * URL at once (on_create) and submitted as {email_link} once the challenge has loaded, to the
 * same endpoint a typed code goes to - so the application's verification endpoint needs no
 * change to accept it.
 *
 * OTHER TABS OF THIS BROWSER. A sign-in that completes here is announced on the
 * BroadcastChannel 'rsx_two_factor' ({type: 'signed_in', realm, challenge_id, redirect,
 * by_email_link}); a screen for the same challenge open in another tab replaces its controls
 * with "You have signed in using the email link." (or "... in another tab.") and a Continue
 * button to that redirect. Two backstops for a tab that missed the message (a browser without
 * BroadcastChannel, a tab that was asleep), both of which must work WITHOUT asking the server
 * anything, because the sign-in rotated this browser's CSRF token and this page still holds
 * the old one:
 *   - while mounted the screen claims the realtime session-refresh reload
 *     (Rsx_Realtime._claim_session_refresh) that every sign-in pushes to the browser's other
 *     tabs, and shows "This sign-in continued in another tab." instead of being reloaded out
 *     from under the user;
 *   - when the tab becomes visible again it asks challenge_state(), and takes the CSRF
 *     refusal that comes back as the same answer ({completed} is the answer only when nothing
 *     rotated, which is a fresh page load).
 * Those two swaps' Continue reloads the page - the host's challenge page sends a signed-in
 * visitor on, and shows a new challenge if that is what moved. The host wires none of this.
 */
class Two_Factor_Challenge extends Component {
    /** The BroadcastChannel a completed sign-in is announced on, to this browser's other tabs. */
    static CHANNEL = 'rsx_two_factor';

    /** The URL-fragment key the email link hands its token back in (EMAIL_LINK_FRAGMENT_KEY). */
    static EMAIL_LINK_KEY = 'rsx_email_link';

    on_create() {
        if (!this.args.controller || !this.args.method) {
            throw new Error('Two_Factor_Challenge requires $controller and $method');
        }

        this.data.loaded = false;
        this.data.challenge = null;
        this.data.completed = null;

        this.state = {
            // Taken once: whether this browser can run a passkey ceremony does not change
            // while the page is open, and the button must not be offered if it cannot.
            passkeys_supported: Rsx_Two_Factor.is_supported(),

            error: null,

            // A user-facing note that is not an error - "Your code has been sent."
            notice: null,

            // The user asked for the plain recovery-code box in place of the digit boxes.
            // Only meaningful when the challenge takes both kinds of answer.
            recovery_mode: false,

            // The email link token this page was opened with, until it is submitted.
            email_link: null,

            // This browser's sign-in moved on elsewhere: {redirect (null = reload), how} - see
            // _show_completed().
            completed: null,

            // This screen's own answer passed and the page is navigating away.
            leaving: false,

            // on_ready() has taken the first step (see on_ready).
            first_step_taken: false,
        };

        // Taken out of the address bar at once, so a reload, a bookmark or a copied URL never
        // carries it; submitted in on_ready, once the challenge it answers has loaded.
        const link = Rsx.url_hash_get(Two_Factor_Challenge.EMAIL_LINK_KEY);
        if (link) {
            this.state.email_link = String(link);
            Rsx.url_hash_set({ [Two_Factor_Challenge.EMAIL_LINK_KEY]: null });
        }

        this._listen_for_other_tabs();
    }

    async on_load() {
        const state = await Rsx_Two_Factor.controller().challenge_state();

        // {completed} is not a challenge: this browser already finished it (see challenge_state).
        if (state !== null && state.completed) {
            this.data.completed = { redirect: null, how: state.by_email_link ? 'email_link' : 'other_tab' };
            this.data.challenge = null;
        } else {
            this.data.challenge = state;
        }

        this.data.loaded = true;
    }

    on_loaded() {
        // Sticky events: a host that subscribes after this fires still hears it, so there is
        // no race between mounting the component and listening for the redirect it asks for.
        if (this.data.challenge === null && !this.data.completed) {
            this.trigger('no_challenge');
        }

        if (this.data.completed) {
            this.trigger('completed', { how: this.data.completed.how });
        }
    }

    async on_ready() {
        // null is a state, not a missing value: nothing is pending (see on_loaded).
        const challenge = this.data.challenge;
        if (challenge === null) {
            return;
        }

        // The first step - the link's answer, or the first code - is taken once per screen.
        // on_ready() runs again after every render() and reload() (a refusal re-renders), and
        // must not repeat it.
        if (this.state.first_step_taken) {
            return;
        }
        this.state.first_step_taken = true;

        // Opened from the email link: that IS the answer, so no code is sent for it.
        if (this.state.email_link) {
            const token = this.state.email_link;
            this.state.email_link = null;
            await this._submit({ email_link: token });
            return;
        }

        // The first code is sent for the user when the host named a send endpoint and none
        // has been issued yet. A host that issues the first code itself, server-side, before
        // the page loads, never reaches this branch (codes_issued is already 1).
        if (challenge.has_issued_code && challenge.codes_issued === 0 && this._can_send()) {
            await this._send_code(false);
        }
    }

    on_stop() {
        if (this._channel) {
            this._channel.close();
            this._channel = null;
        }

        if (this._on_visible) {
            document.removeEventListener('visibilitychange', this._on_visible);
            this._on_visible = null;
        }

        if (this._release_refresh) {
            this._release_refresh();
            this._release_refresh = null;
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

        // The answer is one of two controls: the digit boxes (a Code_Input component) or the
        // plain recovery-code box. Enter verifies from either; the digit boxes swallow the key
        // (preventDefault) while any is empty, so only a complete code arrives unprevented.
        // The cursor starts in the answer, and a refusal's re-render puts it back.
        const code = this.sid('code');
        if (code) {
            code.$.toggleClass('is-invalid', !!this.state.error);
            code.$.off('keydown.tfa').on('keydown.tfa', async function (e) {
                if (e.key === 'Enter' && !e.isDefaultPrevented()) {
                    await that._verify_code();
                }
            });
            code.focus();
        }

        const $recovery = this.$sid('recovery');
        if ($recovery.exists()) {
            $recovery.off('keydown.tfa').on('keydown.tfa', async function (e) {
                if (e.key === 'Enter') {
                    await that._verify_code();
                }
            });
            $recovery.focus();
        }

        const $switch = this.$sid('switch');
        if ($switch.exists()) {
            $switch.off('click.tfa').on('click.tfa', function () {
                that.state.recovery_mode = !that.state.recovery_mode;
                that.state.error = null;
                that.render();
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

        const $continue = this.$sid('continue');
        if ($continue.exists()) {
            $continue.off('click.tfa').on('click.tfa', function () {
                that._continue();
            });
        }
    }

    // -------------------------------------------------------------------------
    // Other tabs of this browser
    // -------------------------------------------------------------------------

    /**
     * This page's realm, as the BroadcastChannel message names it.
     */
    _realm() {
        return Rsx_Portal.is_portal() ? 'portal' : 'staff';
    }

    /**
     * Wire the three ways this screen learns its sign-in moved on elsewhere - see the class
     * docblock. Undone in on_stop().
     */
    _listen_for_other_tabs() {
        const that = this;

        // Feature-detected like the passkey button: a browser without BroadcastChannel still
        // has the two backstops below.
        if (window.BroadcastChannel) {
            this._channel = new BroadcastChannel(Two_Factor_Challenge.CHANNEL);
            this._channel.onmessage = function (e) {
                const message = e.data;
                const challenge = that.data.challenge;

                if (!message || message.type !== 'signed_in' || message.realm !== that._realm()) {
                    return;
                }

                if (!challenge || challenge.challenge_id !== message.challenge_id) {
                    return;
                }

                that._show_completed(message.redirect || null, message.by_email_link ? 'email_link' : 'other_tab');
            };
        }

        this._on_visible = function () {
            if (document.visibilityState === 'visible') {
                that._check_on_return();
            }
        };
        document.addEventListener('visibilitychange', this._on_visible);

        // The sign-in's realtime frame. On a challenge page it can only mean this browser's
        // session moved on - a sign-in in this realm, in another tab - and the page's tokens
        // went with it (every sign-in rotates them), so nothing is asked of the server: the
        // screen says so and offers Continue, which reloads. The BroadcastChannel message,
        // when it arrives, replaces that with the exact wording and destination.
        this._release_refresh = Rsx_Realtime._claim_session_refresh(function (proceed) {
            // Our own answer passed: this page is already on its way out.
            if (that.state.leaving) {
                return;
            }

            // Nothing was waiting here: the frame is not this screen's to answer.
            if (!that.data.loaded || that.data.challenge === null) {
                proceed();
                return;
            }

            that._show_completed(null, 'moved');
        });
    }

    /**
     * The tab became visible again: ask the server what became of this browser's challenge.
     *
     * Still pending - nothing to do. {completed} - the sign-in finished. A CSRF refusal
     * (Rsx_Csrf answers ERROR_UNAUTHORIZED) is the other expected answer and the likelier one:
     * a sign-in in another tab rotated this browser's tokens, so this page can no longer ask,
     * and that alone says the sign-in moved on. Every other failure surfaces.
     */
    async _check_on_return() {
        if (this.state.completed || this.state.leaving || !this.data.loaded || this.data.challenge === null) {
            return;
        }

        let state;

        try {
            state = await Rsx_Two_Factor.controller().challenge_state();
        } catch (e) {
            if (e.code === Ajax.ERROR_UNAUTHORIZED) {
                this._show_completed(null, 'moved');
                return;
            }
            throw e;
        }

        if (state !== null && state.completed) {
            this._show_completed(null, state.by_email_link ? 'email_link' : 'other_tab');
        }
    }

    /**
     * Replace the controls with the signed-in message and Continue.
     *
     * A screen already showing the vague 'moved' message takes the exact one when it
     * arrives; anything else is shown once.
     *
     * @param {string|null} redirect Where Continue goes; null reloads the page.
     * @param {string} how 'email_link' | 'other_tab' (this browser's sign-in completed, and
     *                     how) | 'moved' (the session moved on under this page; the details
     *                     are on the other side of a reload)
     */
    _show_completed(redirect, how) {
        if (this.state.leaving) {
            return;
        }

        if (this.state.completed && (this.state.completed.how !== 'moved' || how === 'moved')) {
            return;
        }

        this.state.completed = { redirect: redirect, how: how };
        this.state.error = null;
        this.state.notice = null;
        this.render();

        // The host's own copy about answering the challenge ("lost your device?") is now
        // beside a message saying the sign-in is done; this is its cue to change.
        this.trigger('completed', { how: how });
    }

    /**
     * Continue from the signed-in message.
     */
    _continue() {
        const completed = this.state.completed;

        if (completed.redirect) {
            window.location = completed.redirect;
            return;
        }

        window.location.reload();
    }

    /**
     * Whether the host named an endpoint that issues and delivers a code.
     */
    _can_send() {
        return !!(this.args.send_controller && this.args.send_method);
    }

    /**
     * Ask the host's send endpoint to issue and deliver a code, then re-read the challenge.
     *
     * @param {boolean} announce Say "Your code has been sent." afterwards (a resend the
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
            this.state.notice = 'Your code has been sent.';
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
     * Answer with the typed string - six digits or a recovery code, the server decides
     * which. A blank answer is not an attempt and is not sent.
     */
    async _verify_code() {
        const digits = this.sid('code');
        const code = digits ? digits.val() : str(this.$sid('recovery').val()).trim();

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

        this.state.leaving = true;

        // Tell this browser's other tabs showing the same challenge (see the class docblock).
        if (this._channel && this.data.challenge) {
            this._channel.postMessage({
                type: 'signed_in',
                realm: this._realm(),
                challenge_id: this.data.challenge.challenge_id,
                redirect: result.redirect,
                by_email_link: !!payload.email_link,
            });
        }

        window.location = result.redirect;
    }

    /**
     * Render a failure inline. The render rebuilds the answer empty and puts the cursor
     * back in it, so the retry starts from nothing.
     */
    _show_error(e) {
        this.state.error = e.message ? e.message : 'That code is not valid.';
        this.render();
    }
}
