/**
 * _Sys_User_Sessions - an identity's Sessions tab. See _Sys_User_Sessions.jqhtml.
 *
 * Three states: loading, error, the table (or its empty state). A row's End goes
 * through _Sys_User_Actions (refusal explained, confirm, call) and reloads the region.
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
            const session_id = Number($(this).attr('data-session-id'));
            that._terminate(that.data.sessions.find((row) => row.id === session_id));
        });
    }

    async _terminate(session) {
        if (await _Sys_User_Actions.refused(session.terminate_refusal)) {
            return;
        }

        const confirmed = await _Sys_Modal.confirm(
            'End this session of ' + this.args.email + '?',
            session.device_summary + ' at ' + session.ip_address + ' is signed out now and reloads to the sign-in page. ' +
                (session.impersonator_login_user_id
                    ? 'This also ends the impersonation by ' + (session.impersonator_email || '#' + session.impersonator_login_user_id) + '. '
                    : '') +
                'The identity can sign in again.',
            'End session'
        );

        if (!confirmed) {
            return;
        }

        const result = await _Sys_User_Actions.call(() => _Sys_Users_Controller.terminate_session({ id: this.args.login_user_id, session_id: session.id }));
        if (result === null) {
            this.reload();
            return;
        }

        Flash_Alert.success('The session is ended.');
        this.reload();
    }
}
