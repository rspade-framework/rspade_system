/**
 * Api_Key_Bar - the shared tester-key control for the docs console.
 *
 * Holds ONE key in session-scoped Rsx_Storage (key 'apidocs_tester_key'); every Api_Tester on
 * the page reads it from there. Any change broadcasts 'apidocs:key_changed' so the testers
 * and the code samples refresh.
 *
 * A key can be PASTED, or - for a signed-in account that has API access - MINTED on the
 * spot, one hour long, against that account. The two are offered one at a time and never
 * together: see the template, and _sync_action() below for the live swap.
 *
 * THE KEY IS VALIDATED BEFORE IT IS ACCEPTED, against GET /api/v1/me - the framework's
 * identity endpoint, whose whole purpose is answering "is this key good". Storing an
 * unchecked key means the first failure surfaces later, on some unrelated endpoint, looking
 * like that endpoint's fault. Failing here says plainly that the key is the problem.
 */
class Api_Key_Bar extends Component {
    static STORAGE_KEY = 'apidocs_tester_key';

    /**
     * The framework's key-validation endpoint. A 200 means the key authenticated.
     */
    static VERIFY_PATH = '/api/v1/me';

    on_create() {
        this.state = {
            key: Rsx_Storage.session_get(Api_Key_Bar.STORAGE_KEY),
        };
    }

    on_render() {
        const that = this;

        const $use = this.$sid('use');
        if ($use.exists()) {
            $use.click_async(async () => await that._use());
        }

        const $clear = this.$sid('clear');
        if ($clear.exists()) {
            $clear.off('click.akb').on('click.akb', () => that._clear());
        }

        const $temporary = this.$sid('temporary');
        if ($temporary.exists()) {
            $temporary.click_async(async () => await that._mint());
        }

        const $input = this.$sid('input');
        if ($input.exists()) {
            $input.off('keydown.akb input.akb keyup.akb change.akb')
                .on('keydown.akb', async (e) => {
                    if (e.key === 'Enter') {
                        await that._use();
                    }
                })
                // Any edit clears the failure styling: a red box that persists while the user
                // fixes the value is telling them about a key they no longer have typed.
                .on('input.akb change.akb', () => that._clear_error())
                // Which action is on offer follows what is in the box, keystroke by
                // keystroke. keyup catches typing; change catches the edits that produce no
                // keystroke at all - a paste from the context menu, autofill, a drop.
                .on('keyup.akb change.akb', () => that._sync_action());
        }

        this._sync_action();
    }

    /**
     * Put the right action button on offer for what is currently typed.
     *
     * A CLASS TOGGLE, NOT A RENDER. Re-running the template on every keystroke would replace
     * the input element the user is typing into, taking the focus and the caret with it.
     *
     * With no "Temporary Key" button in the markup there is nothing to swap between - the
     * viewer was never offered minting - so "Use Key" simply stays.
     */
    _sync_action() {
        const $use = this.$sid('use');
        const $temporary = this.$sid('temporary');

        if (!$temporary.exists()) {
            $use.removeClass('Api_Key_Bar__btn--hidden');
            return;
        }

        const is_empty = str(this.$sid('input').val()).trim() === '';

        $temporary.toggleClass('Api_Key_Bar__btn--hidden', !is_empty);
        $use.toggleClass('Api_Key_Bar__btn--hidden', is_empty);
    }

    /**
     * Mint a one-hour key for the signed-in account, after asking.
     *
     * ASKING IS NOT CEREMONY: this writes a real, live credential onto the user's own
     * account, one that shows up in Settings > API Keys and works against every endpoint
     * they can reach. A button that does that on a single click, next to a text box, is one
     * mis-click from a key nobody meant to create.
     *
     * ASKED WITH THE CONSOLE'S OWN DIALOG, never the application's Modal: this is framework
     * code running inside somebody else's page, and an app's dialog system is not the
     * framework's to depend on. See Api_Confirm_Dialog.
     *
     * The minted key is stored down the SAME path a pasted one takes, so 'apidocs:key_changed'
     * broadcasts and every Api_Tester on the page picks it up with no special case.
     */
    async _mint() {
        const confirmed = await Api_Confirm_Dialog.ask({
            title: 'Create a temporary API key?',
            body: 'This creates a real API key on your own user account, valid for one hour. It is held in this browser tab only, and you can revoke it sooner from Settings > API Keys.',
            confirm_label: 'Create Key',
            cancel_label: 'Cancel',
        });

        if (!confirmed) {
            return;
        }

        this._clear_error();

        let result;

        try {
            result = await Api_Docs_Controller.mint_temporary_key();
        } catch (e) {
            this._show_error(e.message ? e.message : 'That temporary key could not be created.');
            return;
        }

        this._store(result.key);
        this.state.key = result.key;
        this.render();
    }

    /**
     * Validate the pasted key, then adopt it. A bad key is never stored.
     */
    async _use() {
        const value = str(this.$sid('input').val()).trim();

        if (value === '') {
            return;
        }

        this._clear_error();

        let response;

        try {
            response = await fetch(Api_Key_Bar.VERIFY_PATH, {
                headers: { 'Authorization': 'Bearer ' + value },
            });
        } catch (e) {
            this._show_error('Could not reach the server to check that key.');
            return;
        }

        if (!response.ok) {
            this._show_error('That key is not valid, or it has been revoked or has expired.');
            return;
        }

        this._store(value);
        this.state.key = value;
        this.render();
    }

    _clear() {
        Rsx_Storage.session_remove(Api_Key_Bar.STORAGE_KEY);
        this.state.key = null;
        this._clear_error();
        this.render();
        this._broadcast();
    }

    _store(k) {
        Rsx_Storage.session_set(Api_Key_Bar.STORAGE_KEY, k);
        this._broadcast();
    }

    _broadcast() {
        document.dispatchEvent(new CustomEvent('apidocs:key_changed'));
    }

    _show_error(message) {
        this.$sid('input').addClass('Api_Key_Bar__input--invalid');
        this.$sid('error').text(message).removeClass('Api_Key_Bar__error--hidden');
    }

    _clear_error() {
        this.$sid('input').removeClass('Api_Key_Bar__input--invalid');
        this.$sid('error').addClass('Api_Key_Bar__error--hidden');
    }
}
