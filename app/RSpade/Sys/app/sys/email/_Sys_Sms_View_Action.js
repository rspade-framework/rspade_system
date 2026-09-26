/**
 * _Sys_Sms_View_Action - one queued SMS, any site's. See the .jqhtml for the layout.
 *
 * Read-only: the SMS queue has no resend contract, so this screen only shows the row.
 */
@route('/_sys/email/sms/:id')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('SMS')
class _Sys_Sms_View_Action extends Spa_Action {
    on_create() {
        this.data.sms = null;
        this.data.load_error = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const response = await _Sys_Sms_Controller.detail({ id: this.args.id });
            this.data.sms = response.sms;
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
        return 'SMS #' + this.args.id;
    }
}
