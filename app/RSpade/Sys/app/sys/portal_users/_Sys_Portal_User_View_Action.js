/**
 * _Sys_Portal_User_View_Action - one client-portal user. See the .jqhtml for the layout.
 *
 * Read-only: the facts load here, and each tab's region loads its own data.
 */
@route('/_sys/portal-users/:id')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Portal User')
class _Sys_Portal_User_View_Action extends Spa_Action {
    on_create() {
        this.data.user = null;
        this.data.load_error = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const response = await _Sys_Portal_Users_Controller.detail({ id: this.args.id });
            this.data.user = response.user;
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
        return 'Portal User #' + this.args.id;
    }
}
