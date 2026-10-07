/**
 * Task_Report - see the .jqhtml. Loads the run's status (and, for the stored reports, the
 * report's value) and repaints on every Task_Changed_Topic frame through refresh(), which
 * repaints only when the loaded data actually changed.
 */
class Task_Report extends Component {
    /** Every kind the widget draws. */
    static KINDS = ['status_text', 'progress', 'progress_count', 'progress_text', 'eta', 'heartbeat', 'state_json', 'state_list', 'messages', 'summary', 'return_code'];

    /** The kinds whose value is stored apart from the run's status. */
    static STORED_KINDS = ['state_json', 'state_list', 'messages', 'summary'];

    /** The percentage a status reports, or derives from its count; null when neither. */
    static percent_of(status) {
        if (status.progress_percent !== null && status.progress_percent !== undefined) {
            return float(status.progress_percent);
        }
        if (status.progress_count && status.progress_count.total > 0) {
            return Math.min(100, (status.progress_count.done * 100) / status.progress_count.total);
        }

        return null;
    }

    on_create() {
        this.data.status = null;
        this.data.value = null;
        this.data.error_data = null;

        // Every frame refetches through one debounced refresh (Rsx_Task.LIVE_UPDATE_DELAY).
        const refresh_soon = debounce(() => this.refresh(), Rsx_Task.LIVE_UPDATE_DELAY);
        this.subscribe('Task_Changed_Topic', { id: int(this.args.task_id) }, () => refresh_soon());
    }

    async on_load() {
        try {
            if (Task_Report.STORED_KINDS.includes(this.args.kind)) {
                const response = await Rsx_Task.report(this.args.task_id, this.args.kind);
                this.data.status = response.status;
                this.data.value = response.value;
            } else {
                this.data.status = await Rsx_Task.get(this.args.task_id);
            }
        } catch (e) {
            this.data.error_data = e;
        }
    }

    on_render() {
        if (this._countdown) {
            this._countdown.stop();
            this._countdown = null;
        }
    }

    on_ready() {
        const $countdown = this.$sid('countdown');
        if ($countdown.length && this.data.status && this.data.status.eta_at) {
            this._countdown = Rsx_Time.countdown($countdown, this.data.status.eta_at, { short: true });
        }
    }

    on_stop() {
        if (this._countdown) {
            this._countdown.stop();
        }
    }
}
