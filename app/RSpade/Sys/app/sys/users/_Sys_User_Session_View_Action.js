/**
 * _Sys_User_Session_View_Action - one user session of a login identity. See the .jqhtml for
 * the layout.
 *
 * End session goes through _Sys_User_Actions.end_session() - the same flow as the Sessions
 * tab's row button - and reloads the screen, which then shows the session signed out.
 */
@route('/_sys/users/:id/sessions/:user_session_id')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Session')
class _Sys_User_Session_View_Action extends Spa_Action {
    on_create() {
        this.data.user = null;
        this.data.session = null;
        this.data.load_error = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const response = await _Sys_Users_Controller.session_detail({ id: this.args.id, user_session_id: this.args.user_session_id });
            this.data.user = response.user;
            this.data.session = response.session;
        } catch (e) {
            // Plain data: this.data keeps no Error object, so the code and the message
            // are copied out. A not_found code renders the missing-record state.
            if (e.code !== Ajax.ERROR_NOT_FOUND) {
                console.error(e);
            }
            this.data.load_error = { code: e.code || null, message: e.message || String(e) };
        }
        this.data.loading = false;
    }

    page_title() {
        return 'Session #' + this.args.user_session_id;
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_user_session_view').on('click._sys_user_session_view', '[data-action="terminate"]', async function () {
            if (await that.alive(_Sys_User_Actions.end_session(that.data.user.id, that.data.user.email, that.data.session))) {
                that.reload();
            }
        });
    }
}
