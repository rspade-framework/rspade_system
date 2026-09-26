/**
 * _Sys_Dashboard_Health - the Dashboard's health report region.
 *
 * See _Sys_Dashboard_Health.jqhtml. Loads Health_Check_Runner::report() through
 * _Sys_Dashboard_Controller.health and groups the rows by status, worst first.
 *
 * "Re-run checks" reloads THIS region only. The report is slow (every active probe
 * runs), so the region paints its loading state first rather than leaving the stale
 * report on screen while the next one is computed.
 */
class _Sys_Dashboard_Health extends Component {
    /**
     * The groups, worst first. A status the runner adds later with no group here
     * throws in group_rows() rather than vanishing from the screen.
     */
    static GROUPS = [
        {status: 'FAIL', title: 'Failing', tone: 'fail'},
        {status: 'WARN', title: 'Warnings', tone: 'warn'},
        {status: 'INFO', title: 'Information', tone: 'info'},
        {status: 'OK', title: 'Passing', tone: 'ok'},
    ];

    on_create() {
        this.data.report = null;
        this.data.error_data = null;
        this.data.loading = true;
        this.state.rerunning = false;
    }

    async on_load() {
        try {
            this.data.report = await _Sys_Dashboard_Controller.health();
        } catch (e) {
            // The MESSAGE, not the Error: this.data is snapshotted as plain data, and an
            // Error's message is not an enumerable property, so it would not survive.
            console.error(e);
            this.data.error_data = e.message || String(e);
        }
        this.data.loading = false;
    }

    on_render() {
        this.$.off('click.sys_health').on('click.sys_health', '[data-action=rerun]', () => this.rerun());
    }

    /**
     * Run every check again and repaint this region.
     */
    async rerun() {
        if (this.state.rerunning) {
            return;
        }

        this.state.rerunning = true;
        this.render();

        try {
            await this.reload();
        } finally {
            this.state.rerunning = false;
            this.render();
        }
    }

    /**
     * The report's rows split into GROUPS order, each group carrying its rows.
     *
     * @param {Array} rows [{label, status, detail, remediation}]
     * @returns {Array} [{status, title, tone, rows}]
     */
    static group_rows(rows) {
        const groups = _Sys_Dashboard_Health.GROUPS.map((g) => ({...g, rows: []}));

        for (const row of rows) {
            const group = groups.find((g) => g.status === row.status);
            if (!group) {
                throw new Error(`_Sys_Dashboard_Health: health row '${row.label}' has unknown status '${row.status}'`);
            }
            group.rows.push(row);
        }

        return groups;
    }
}
