/**
 * Task_Status_Badge - see the .jqhtml. With $task_id it loads the run and repaints on every
 * Task_Changed_Topic frame (refresh(): a frame for a report that left the status alone
 * repaints nothing); with $task it draws what it was handed.
 */
class Task_Status_Badge extends Component {
    on_create() {
        this.data.status = null;
        this.data.error_data = null;

        if (!this.args.task && this.args.task_id) {
            // Every frame refetches through one debounced refresh (Rsx_Task.LIVE_UPDATE_DELAY).
            const refresh_soon = debounce(() => this.refresh(), Rsx_Task.LIVE_UPDATE_DELAY);
            this.subscribe('Task_Changed_Topic', { id: int(this.args.task_id) }, () => refresh_soon());
        }
    }

    async on_load() {
        if (this.args.task || !this.args.task_id) {
            return;
        }

        try {
            const status = await Rsx_Task.get(this.args.task_id);
            this.data.status = { status_id: status.status_id, status: status.status, status_reason: status.status_reason };
        } catch (e) {
            this.data.error_data = e;
        }
    }
}
