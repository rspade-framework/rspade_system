/**
 * Api_Docs_Home - the landing pane of the API reference.
 *
 * Carries the key gate shown when the console restricts its listing to an API key's
 * permissions: with no adopted key the sidebar lists nothing, so the landing page is where
 * a key is supplied.
 *
 * ADOPTING A KEY RELOADS THE PAGE, deliberately. The endpoint list is baked into the
 * document at render time (Rsx_Api_Docs::rsxapp_data), so a key adopted afterwards
 * changes nothing until the page is built again. Reloading is honest about that; repainting
 * from a second source of truth would not be.
 *
 * The key is sent to the server so it can answer "which endpoints may this caller use" at
 * render, AND kept in browser session storage so the tester can put it in an Authorization
 * header - it cannot come back from the database, which stores only its hash.
 *
 * $version - the selected catalog version (int)
 */
class Api_Docs_Home extends Component {
    on_ready() {
        const that = this;

        const $adopt = this.$sid('adopt_key');
        if ($adopt.exists()) {
            $adopt.click_async(async () => await that._adopt());
        }

        const $input = this.$sid('key_input');
        if ($input.exists()) {
            $input.off('keydown.apidocs').on('keydown.apidocs', async (e) => {
                if (e.key === 'Enter') {
                    await that._adopt();
                }
            });
        }

        const $forget = this.$sid('forget_key');
        if ($forget.exists()) {
            $forget.click_async(async () => await that._forget());
        }
    }

    async _adopt() {
        const key = str(this.$sid('key_input').val()).trim();

        if (key === '') {
            return;
        }

        this._hide_error();

        try {
            await Api_Docs_Controller.adopt_tester_key({ key: key });
        } catch (e) {
            this._show_error(e.message ? e.message : 'That API key could not be used.');
            return;
        }

        // The tester needs the plaintext; the server only ever had the hash.
        Rsx_Storage.session_set(Api_Key_Bar.STORAGE_KEY, key);

        window.location.reload();
    }

    async _forget() {
        await Api_Docs_Controller.forget_tester_key();
        Rsx_Storage.session_remove(Api_Key_Bar.STORAGE_KEY);

        window.location.reload();
    }

    _show_error(message) {
        this.$sid('key_error')
            .text(message)
            .removeClass('Api_Docs_Home__keygate-error--hidden');
    }

    _hide_error() {
        this.$sid('key_error').addClass('Api_Docs_Home__keygate-error--hidden');
    }
}
