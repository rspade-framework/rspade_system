/**
 * _Sys_DataGrid_Body
 *
 * See _Sys_DataGrid_Body.jqhtml. Loads one page in on_load() from the params the
 * grid wrote onto this.args, and turns every plain cell of a <tr data-href> row into
 * a link, so a row is a real anchor (middle-click, the SPA's own interception).
 */
class _Sys_DataGrid_Body extends Component {
    on_create() {
        this.data.loaded = false;
        this.data.records = [];
        this.data.page = 1;
        this.data.per_page = 0;
        this.data.total = 0;
        this.data.total_pages = 0;
    }

    async on_load() {
        // The grid arms the body from its on_ready(); a render before that is the
        // loading state, not a request.
        if (!this.args.params) {
            return;
        }

        const response = await Ajax.call(this.args.data_source, this.args.params);

        this.data.records = response.records;
        this.data.page = response.page;
        this.data.per_page = response.per_page;
        this.data.total = response.total;
        this.data.total_pages = response.total_pages;
        this.data.loaded = true;
    }

    on_render() {
        this.$.find('tr[data-href]').each(function () {
            const $row = $(this);
            const href = $row.attr('data-href');

            $row.addClass('_Sys_DataGrid_Abstract__link-row');

            $row.children('td').each(function () {
                const $cell = $(this);

                // A cell holding its own control keeps it; a link inside a link is invalid.
                if ($cell.find('a, button, input, select, textarea').length > 0) {
                    return;
                }

                const $anchor = $('<a>', { href: href, class: '_Sys_DataGrid_Abstract__row-link' });
                $cell.contents().appendTo($anchor);
                $cell.addClass('_Sys_DataGrid_Abstract__link-cell').append($anchor);
            });
        });
    }
}
