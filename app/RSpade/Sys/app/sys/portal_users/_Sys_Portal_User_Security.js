/**
 * _Sys_Portal_User_Security - a portal user's Security tab. See
 * _Sys_Portal_User_Security.jqhtml.
 *
 * Three states: loading, error, the two sections. Read-only: no handlers.
 */
class _Sys_Portal_User_Security extends Component {
    on_create() {
        this.data.two_factor = null;
        this.data.sso = null;
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const response = await _Sys_Portal_Users_Controller.security({ id: this.args.portal_user_id });
            this.data.two_factor = response.two_factor;
            this.data.sso = response.sso;
        } catch (e) {
            // The MESSAGE: this.data keeps plain data, and an Error's message would not survive.
            console.error(e);
            this.data.error_data = e.message || String(e);
        }
        this.data.loading = false;
    }
}
