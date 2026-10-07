/**
 * _Sys_Task_Reports_Pane - see the .jqhtml. The report list is the run's available reports
 * in Rsx_Task.REPORT_KINDS order, which puts the state object first and the queue second.
 */
class _Sys_Task_Reports_Pane extends Component {
    on_create() {
        this.data.reports = null;
        this.data.error_data = null;
        this.state.selected = null;

        // Every frame refetches through one debounced refresh (Rsx_Task.LIVE_UPDATE_DELAY).
        const refresh_soon = debounce(() => this.refresh(), Rsx_Task.LIVE_UPDATE_DELAY);
        this.subscribe('Task_Changed_Topic', { id: int(this.args.task_id) }, () => refresh_soon());
    }

    /**
     * The report to show: the one selected, while the run still has it, else the first.
     */
    selected_kind() {
        if (!this.data.reports || !this.data.reports.length) {
            return null;
        }

        return this.data.reports.includes(this.state.selected) ? this.state.selected : this.data.reports[0];
    }

    async on_load() {
        try {
            const status = await Rsx_Task.get(this.args.task_id);
            this.data.reports = Rsx_Task.REPORT_KINDS.filter((kind) => status.reports.includes(kind));
        } catch (e) {
            this.data.error_data = e;
        }
    }

    on_ready() {
        const that = this;

        this.$sid('kind').off('change._sys_reports').on('change._sys_reports', function () {
            const $element = $(this);
            that.state.selected = $element.val();
            that.render();
        });
    }
}
