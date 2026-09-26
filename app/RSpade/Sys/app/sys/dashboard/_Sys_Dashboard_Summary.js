/**
 * _Sys_Dashboard_Summary - the Dashboard's headline tiles.
 *
 * See _Sys_Dashboard_Summary.jqhtml. One call, three states: loading, error, tiles.
 */
class _Sys_Dashboard_Summary extends Component {
    on_create() {
        this.data.summary = null;
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            this.data.summary = await _Sys_Dashboard_Controller.summary();
        } catch (e) {
            // The MESSAGE, not the Error: this.data is snapshotted as plain data, and an
            // Error's message is not an enumerable property, so it would not survive.
            console.error(e);
            this.data.error_data = e.message || String(e);
        }
        this.data.loading = false;
    }
}
