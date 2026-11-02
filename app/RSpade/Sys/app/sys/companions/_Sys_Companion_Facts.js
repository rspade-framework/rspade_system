/**
 * _Sys_Companion_Facts - see the .jqhtml.
 */
class _Sys_Companion_Facts extends Component {
    on_create() {
        this.data.status = null;
        this.data.error_data = null;

        const refresh_soon = debounce(() => {
            if (this.is_stopped()) return;
            return this.refresh();
        }, Rsx_Companion.LIVE_UPDATE_DELAY);
        this.subscribe('Companion_Changed_Topic', { name: str(this.args.name) }, () => refresh_soon());
    }

    async on_load() {
        try {
            this.data.status = (await _Sys_Companions_Controller.detail({ name: str(this.args.name) })).status;
        } catch (e) {
            this.data.error_data = e;
        }
    }

    on_ready() {
        const that = this;
        const actions = this.sid('actions');

        if (actions) {
            actions.on('acted', function () {
                that.refresh();
                that.trigger('acted');
            });
        }
    }
}
