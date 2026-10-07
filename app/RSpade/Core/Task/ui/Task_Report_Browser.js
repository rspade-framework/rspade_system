/**
 * Task_Report_Browser - see the .jqhtml. The list of report kinds is the run's
 * available_reports, ordered by Rsx_Task.REPORT_KINDS; refresh() on each frame repaints only
 * when that list changed, so a run reporting progress does not rebuild the pane.
 */
class Task_Report_Browser extends Component {
    on_create() {
        this.data.reports = null;
        this.data.error_data = null;
        this.state.selected = this.args.kind || null;

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

        this.$.off('click.task_report_browser').on('click.task_report_browser', '[data-kind]', function () {
            that.select($(this).attr('data-kind'));
        });
    }

    /** Show one report kind. */
    select(kind) {
        if (kind === this.state.selected) {
            return;
        }

        this.state.selected = kind;
        this.render();
    }
}
