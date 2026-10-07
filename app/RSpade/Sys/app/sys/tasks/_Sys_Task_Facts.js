/**
 * _Sys_Task_Facts - see the .jqhtml.
 */
class _Sys_Task_Facts extends Component {
    on_create() {
        this.data.task = null;
        this.data.error_data = null;

        // Every frame refetches through one debounced refresh (Rsx_Task.LIVE_UPDATE_DELAY).
        const refresh_soon = debounce(() => this.refresh(), Rsx_Task.LIVE_UPDATE_DELAY);
        this.subscribe('Task_Changed_Topic', { id: int(this.args.task_id) }, () => refresh_soon());
    }

    async on_load() {
        try {
            this.data.task = (await _Sys_Tasks_Controller.detail({ id: int(this.args.task_id) })).task;
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
