/**
 * _Sys_User_Sessions - an identity's Sessions tab. See _Sys_User_Sessions.jqhtml.
 *
 * Three states: loading, error, the table (or its empty state). A row's End goes
 * through _Sys_User_Actions.end_session() (refusal explained, confirm, call) and reloads the region.
 */
class _Sys_User_Sessions extends Component {
    on_create() {
        this.data.sessions = [];
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const response = await _Sys_Users_Controller.sessions({ id: this.args.login_user_id });
            this.data.sessions = response.sessions;
        } catch (e) {
            // The MESSAGE: this.data keeps plain data, and an Error's message would not survive.
            console.error(e);
            this.data.error_data = e.message || String(e);
        }
        this.data.loading = false;
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_user_sessions');

        this.$.on('click._sys_user_sessions', '[data-action="refresh"]', function () {
            that.reload();
        });

        this.$.on('click._sys_user_sessions', '[data-action="terminate"]', function () {
            const user_session_id = Number($(this).attr('data-user-session-id'));
            that._terminate(that.data.sessions.find((row) => row.id === user_session_id));
        });
    }

    async _terminate(session) {
        if (await this.alive(_Sys_User_Actions.end_session(this.args.login_user_id, this.args.email, session))) {
            this.reload();
        }
    }
}
