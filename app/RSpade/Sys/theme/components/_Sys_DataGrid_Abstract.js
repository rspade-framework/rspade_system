/**
 * _Sys_DataGrid_Abstract
 *
 * See _Sys_DataGrid_Abstract.jqhtml for the declaration contract. This class owns
 * ALL grid state - page, sort, order, search, filters - in this.state, and never
 * calls Ajax itself: load_page() hands the request to the _Sys_DataGrid_Body child
 * and reloads it, so the toolbar (and the search box the user is typing in) is
 * never re-rendered by a page load.
 *
 * The state is mirrored into the URL hash under the grid's declared $grid_key,
 * writing only what differs from the declared defaults. A filter with a declared
 * default that the user cleared is written as '~', because absence already means
 * "use the default".
 *
 * Public API: load_page(page), reload(), set_filter(key, value), get_filter(key),
 * clear_filters(). Every page load fires "grid_loaded" ({total, page, total_pages}).
 */
class _Sys_DataGrid_Abstract extends Component {
    on_create() {
        const key = str(this.args.grid_key ?? '');

        if (!/^[a-z][a-z0-9_]*$/.test(key)) {
            throw new Error(`${this.component_name()}: $grid_key must be a lowercase identifier (got '${key}').`);
        }

        if (!this.args.data_source) {
            throw new Error(`${this.component_name()}: $data_source (the grid's fetch endpoint) is required.`);
        }

        this.grid_key = key;

        const filters = {};

        for (const filter of this.args.filters || []) {
            if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(str(filter.key ?? ''))) {
                throw new Error(`${this.component_name()}: filter key '${filter.key}' is not a request-param name.`);
            }

            if (filter.default !== undefined && filter.default !== null && filter.default !== '') {
                filters[filter.key] = str(filter.default);
            }
        }

        this.defaults = {
            page: 1,
            per_page: int(this.args.per_page || 25),
            sort: this.args.sort ?? null,
            order: this.args.order || 'desc',
            search: '',
            filters: filters,
        };

        // A copy of its own: the filters object must not be shared with the defaults.
        this.state = clone(this.defaults);
        this.state.filters = Object.assign({}, filters);
        this.state.total = 0;
        this.state.total_pages = 0;

        // The hash wins over every default.
        const page = Rsx.url_hash_get(this._hash_key('page'));
        const sort = Rsx.url_hash_get(this._hash_key('sort'));
        const order = Rsx.url_hash_get(this._hash_key('order'));
        const search = Rsx.url_hash_get(this._hash_key('q'));

        if (page) this.state.page = Math.max(1, int(page));
        if (sort) this.state.sort = sort;
        if (order === 'asc' || order === 'desc') this.state.order = order;
        if (search) this.state.search = search;

        for (const filter of this.args.filters || []) {
            const value = Rsx.url_hash_get(this._hash_key('f_' + filter.key));

            if (value === '~') {
                delete this.state.filters[filter.key];
            } else if (value !== null) {
                this.state.filters[filter.key] = value;
            }
        }
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_dg_sort').on('click._sys_dg_sort', 'thead th[data-sortby]', function (e) {
            const $element = $(this);
            e.preventDefault();
            that.sort_by($element.attr('data-sortby'));
        });

        this.$.off('click._sys_dg_page').on('click._sys_dg_page', '[data-grid-page]', function (e) {
            const $element = $(this);
            e.preventDefault();

            if ($element.closest('.page-item').hasClass('disabled')) {
                return;
            }

            that.load_page(int($element.attr('data-grid-page')));
        });

        this.$.off('click._sys_dg_clear').on('click._sys_dg_clear', '[data-grid-action="clear"]', function (e) {
            e.preventDefault();
            that.clear_filters();
        });

        this.$.off('change._sys_dg_filter').on('change._sys_dg_filter', 'select[data-filter-key]', function () {
            const $element = $(this);
            that.set_filter($element.attr('data-filter-key'), $element.val());
        });

        const $search = this.$sid('search');

        if ($search.length) {
            $search.val(this.state.search);

            const debounced = debounce(function (value) {
                that.state.search = value;
                that.load_page(1);
            }, 200);

            $search.off('input._sys_dg').on('input._sys_dg', function () {
                const $element = $(this);
                debounced($element.val());
            });
        }

        const body = this.sid('body');

        body.on('ready', function () {
            if (!body.data.loaded) {
                return;
            }

            that.state.page = body.data.page;
            that.state.total = body.data.total;
            that.state.total_pages = body.data.total_pages;
            that.state.per_page = body.data.per_page;

            that._persist_to_hash();
            that._paint_footer();
            that.trigger('grid_loaded', {
                total: that.state.total,
                page: that.state.page,
                total_pages: that.state.total_pages,
            });
        });

        this._paint_sort();
        this.load_page(this.state.page);
    }

    /**
     * Load one page with the current sort, search and filters.
     *
     * @param {number} page
     */
    async load_page(page) {
        this.state.page = Math.max(1, int(page));

        const params = {
            page: this.state.page,
            per_page: this.state.per_page,
            sort: this.state.sort,
            order: this.state.order,
            filter: this.state.search,
        };

        // Filters go out as top-level params - the names __build_query() reads. The
        // grid's fixed $base_params (a parent record's id) go last, so no filter or
        // hash value can replace them.
        Object.assign(params, this.state.filters, this.args.base_params || {});

        const body = this.sid('body');
        body.args.params = params;
        body.args.filtered = this.is_filtered();

        // The previous page stays painted until the next one arrives; the modifier
        // dims it meanwhile, so a slow page reads as loading rather than as stuck.
        this.$.addClass('_Sys_DataGrid_Abstract--loading');

        try {
            await body.reload();
        } finally {
            this.$.removeClass('_Sys_DataGrid_Abstract--loading');
        }
    }

    /**
     * Reload the current page - for a caller that knows the rows changed.
     */
    async reload() {
        await this.load_page(this.state.page);
    }

    /**
     * Sort by a column; the same column again flips the order.
     *
     * @param {string} key
     */
    sort_by(key) {
        if (this.state.sort === key) {
            this.state.order = this.state.order === 'asc' ? 'desc' : 'asc';
        } else {
            this.state.sort = key;
            this.state.order = 'asc';
        }

        this._paint_sort();
        this.load_page(1);
    }

    /**
     * Set (or, with null/'', clear) one declared filter and go back to page 1.
     *
     * @param {string} key
     * @param {string|number|null} value
     */
    set_filter(key, value) {
        if (!this._filter_keys().includes(key)) {
            throw new Error(`${this.component_name()}: no declared filter '${key}'.`);
        }

        if (value === null || value === undefined || value === '') {
            delete this.state.filters[key];
        } else {
            this.state.filters[key] = str(value);
        }

        this.$.find(`select[data-filter-key="${key}"]`).val(str(this.state.filters[key] ?? ''));
        this.load_page(1);
    }

    /**
     * @param {string} key
     * @returns {string|null}
     */
    get_filter(key) {
        return this.state.filters[key] ?? null;
    }

    /**
     * Clear the search and every filter - defaults included - and go back to page 1.
     */
    clear_filters() {
        this.state.search = '';
        this.state.filters = {};
        this.$sid('search').val('');
        this.$.find('select[data-filter-key]').val('');
        this.load_page(1);
    }

    /**
     * True when the search or any filter narrows the set - the difference between
     * "nothing matches" and "there is nothing".
     *
     * @returns {boolean}
     */
    is_filtered() {
        return this.state.search !== '' || Object.keys(this.state.filters).length > 0;
    }

    _filter_keys() {
        return (this.args.filters || []).map((filter) => filter.key);
    }

    _hash_key(suffix) {
        return this.grid_key + '_' + suffix;
    }

    _persist_to_hash() {
        const hash = {};
        const defaults = this.defaults;

        hash[this._hash_key('page')] = this.state.page !== defaults.page ? this.state.page : null;
        hash[this._hash_key('sort')] = this.state.sort !== defaults.sort ? this.state.sort : null;
        hash[this._hash_key('order')] = this.state.order !== defaults.order ? this.state.order : null;
        hash[this._hash_key('q')] = this.state.search !== '' ? this.state.search : null;

        for (const key of this._filter_keys()) {
            const value = this.state.filters[key];

            if (value === undefined) {
                hash[this._hash_key('f_' + key)] = defaults.filters[key] !== undefined ? '~' : null;
            } else {
                hash[this._hash_key('f_' + key)] = value === defaults.filters[key] ? null : value;
            }
        }

        Rsx.url_hash_set(hash);
    }

    // Chevron on the sorted column; the header markup itself is the grid's own.
    _paint_sort() {
        const that = this;

        this.$sid('head').find('th[data-sortby]').each(function () {
            const $th = $(this);
            const is_sorted = $th.attr('data-sortby') === that.state.sort;

            $th.addClass('_Sys_DataGrid_Abstract__sortable').toggleClass('is-sorted', is_sorted);
            $th.children('._Sys_DataGrid_Abstract__sort-icon').remove();

            const icon = !is_sorted ? 'bi-chevron-expand' : (that.state.order === 'desc' ? 'bi-chevron-down' : 'bi-chevron-up');
            $th.append($('<i>').addClass('_Sys_DataGrid_Abstract__sort-icon bi ' + icon));
        });
    }

    _paint_footer() {
        const page = this.state.page;
        const total_pages = this.state.total_pages;
        const total = this.state.total;

        // Nothing to count and nothing to page: the empty state says it all.
        this.$sid('footer').prop('hidden', total === 0);

        if (total === 0) {
            this.$sid('range').text('');
        } else {
            const first = (page - 1) * this.state.per_page + 1;
            const last = Math.min(page * this.state.per_page, total);
            this.$sid('range').text(`${first}-${last} of ${total}`);
        }

        const $pager = this.$sid('pager').empty();

        if (total_pages <= 1) {
            return;
        }

        const item = (label, target, extra_class) => {
            const $li = $('<li>').addClass('page-item ' + (extra_class || ''));
            const $link = $('<a href="#">').addClass('page-link').text(label);

            if (target !== null) {
                $link.attr('data-grid-page', target);
            }

            return $li.append($link);
        };

        // A window of up to seven numbers around the current page, plus the ends.
        const window_size = 7;
        let start = Math.max(1, page - Math.floor(window_size / 2));
        let end = Math.min(total_pages, start + window_size - 1);
        start = Math.max(1, end - window_size + 1);

        $pager.append(item('Previous', page - 1, page === 1 ? 'disabled' : ''));

        if (start > 1) {
            $pager.append(item('1', 1));
            if (start > 2) $pager.append(item('...', null, 'disabled'));
        }

        for (let n = start; n <= end; n++) {
            $pager.append(item(str(n), n, n === page ? 'active' : ''));
        }

        if (end < total_pages) {
            if (end < total_pages - 1) $pager.append(item('...', null, 'disabled'));
            $pager.append(item(str(total_pages), total_pages));
        }

        $pager.append(item('Next', page + 1, page === total_pages ? 'disabled' : ''));
    }
}
