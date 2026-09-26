/**
 * _Sys_Sms_Summary - the SMS tab's headline. See _Sys_Sms_Summary.jqhtml.
 *
 * Three states: loading, error, tiles + delivery facts. A status tile's click is the
 * screen's (_Sys_Email_Action), which narrows the grid in place.
 */
class _Sys_Sms_Summary extends Component {
    on_create() {
        this.data.summary = null;
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            this.data.summary = await _Sys_Sms_Controller.summary();
        } catch (e) {
            // The MESSAGE: this.data keeps plain data, and an Error's message would not survive.
            console.error(e);
            this.data.error_data = e.message || String(e);
        }
        this.data.loading = false;
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_sms_summary').on('click._sys_sms_summary', '[data-action="refresh"]', function () {
            that.reload();
        });
    }
}
