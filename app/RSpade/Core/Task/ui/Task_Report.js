/**
 * Task_Report - see the .jqhtml. Loads the run's status (and, for the stored reports, the
 * report's value) and repaints on every Task_Changed_Topic frame through refresh(), which
 * repaints only when the loaded data actually changed.
 *
 * A QUEUE IS SIZED TO ITS BOX. state_list rows are one fixed-height line each, so the widget
 * knows how many it can show: its own height over LIST_ROW_HEIGHT. It asks the server for
 * only that many items - the first, oldest ones - and the list's total, and the last row says
 * how many more there are. on_viewport_resize() (the framework's own debounced window-resize
 * notification, also fired after every render - the first one before the load) measures and
 * hands the capacity to on_load() through this.args.list_limit, since on_load() may read only
 * args and data; a changed capacity after the load refetches. A box too short for one row -
 * unsized, or hidden - asks for every item, as an unsized host expects.
 */
class Task_Report extends Component {
    /** Every kind the widget draws. */
    static KINDS = ['status_text', 'progress', 'progress_count', 'progress_text', 'eta', 'heartbeat', 'state_json', 'state_list', 'messages', 'summary', 'return_code'];

    /** The height of one state_list row, in px - Task_Report.scss sets the same. */
    static LIST_ROW_HEIGHT = 24;

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
        this.data.total = null;
        this.data.limit = null;
        this.data.error_data = null;

        // Every frame, and every change of a queue's capacity, refetches through one debounced
        // refresh (Rsx_Task.LIVE_UPDATE_DELAY).
        this._refresh_soon = debounce(() => this.refresh(), Rsx_Task.LIVE_UPDATE_DELAY);
        this.subscribe('Task_Changed_Topic', { id: int(this.args.task_id) }, () => this._refresh_soon());
    }

    /**
     * How many state_list rows this widget's box holds, or null when it holds none (an unsized
     * or hidden box) - then every item is asked for.
     */
    list_capacity() {
        const rows = Math.floor(this.$[0].clientHeight / Task_Report.LIST_ROW_HEIGHT);

        return rows >= 1 ? rows : null;
    }

    /** Measure the queue's box; refetch when the number of rows it holds changed. */
    on_viewport_resize() {
        if (this.args.kind !== 'state_list') {
            return;
        }

        const capacity = this.list_capacity();
        if (capacity === (this.args.list_limit ?? null)) {
            return;
        }

        this.args.list_limit = capacity;
        if (this.data.status) {
            this._refresh_soon();
        }
    }

    async on_load() {
        try {
            if (Task_Report.STORED_KINDS.includes(this.args.kind)) {
                const limit = this.args.kind === 'state_list' ? (this.args.list_limit ?? null) : null;
                const response = await Rsx_Task.report(this.args.task_id, this.args.kind, limit);
                this.data.status = response.status;
                this.data.value = response.value;
                this.data.total = response.total;
                this.data.limit = limit;
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
