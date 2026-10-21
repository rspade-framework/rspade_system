/**
 * _Sys_Portal_User_Sessions - a portal user's Sessions tab. See
 * _Sys_Portal_User_Sessions.jqhtml.
 *
 * Three states: loading, error, the table (or its empty state). Refresh reloads the region.
 */
class _Sys_Portal_User_Sessions extends Component {
    on_create() {
        this.data.sessions = [];
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const response = await _Sys_Portal_Users_Controller.sessions({ id: this.args.portal_user_id });
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

        this.$.off('click._sys_portal_user_sessions').on('click._sys_portal_user_sessions', '[data-action="refresh"]', function () {
            that.reload();
        });
    }
}
