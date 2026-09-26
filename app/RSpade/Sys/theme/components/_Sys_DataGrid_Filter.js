/**
 * _Sys_DataGrid_Filter
 *
 * See _Sys_DataGrid_Filter.jqhtml. A static option list renders at once; an
 * endpoint is called in on_load() and the select repaints with its answer.
 */
class _Sys_DataGrid_Filter extends Component {
    on_create() {
        const options = this.args.filter.options;
        this.data.options = Array.isArray(options) ? options : [];
    }

    async on_load() {
        const options = this.args.filter.options;

        if (typeof options === 'function') {
            this.data.options = await options();
        }
    }

    on_render() {
        this.$.attr('data-filter-key', this.args.filter.key).attr('aria-label', this.args.filter.label);
        this.$.val(str(this.args.value ?? ''));
    }
}
