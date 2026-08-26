/**
 * Api_Docs_Sidebar - endpoint filter + resource-grouped navigation.
 *
 * FILTERING IS DOM-LEVEL, NOT A RE-RENDER. Re-rendering on every keystroke would rebuild
 * the input that has focus, losing the caret. So the filter toggles a hidden class on the
 * links, and hides a group whose links have all gone - the markup is built once and the
 * filter only decides what is shown.
 *
 * The filter value lives in this.state so it survives the render() the layout performs on
 * every navigation (to move the active highlight); on_render re-applies it, because a fresh
 * render paints every link visible again.
 *
 * args:  version (int), active (string|null)
 * state: filter (string) - the current filter text, '' when unfiltered.
 */
class Api_Docs_Sidebar extends Component {
    on_create() {
        this.state = { filter: '' };
    }

    /**
     * Fires after EVERY render, so both binds are idempotent and the current filter is
     * re-applied to the freshly painted links.
     */
    on_render() {
        const that = this;

        const $input = this.$sid('filter_input');
        if ($input.exists()) {
            // change AND keyup: keyup covers typing, change covers a paste that is committed
            // by blurring rather than by a key.
            $input.off('keyup.apidocs change.apidocs')
                .on('keyup.apidocs change.apidocs', function () {
                    const $element = $(this);
                    that.state.filter = str($element.val());
                    that._apply_filter();
                });
        }

        const $clear = this.$sid('filter_clear');
        if ($clear.exists()) {
            $clear.off('click.apidocs').on('click.apidocs', () => that._clear());
        }

        this._apply_filter();
    }

    /**
     * Show the links matching the current filter, hide the rest, and hide any group left
     * with nothing in it. An empty filter shows everything.
     */
    _apply_filter() {
        const needle = str(this.state.filter).trim().toLowerCase();
        let visible = 0;

        this.$.find('.Api_Docs_Page__nav-group').each(function () {
            const $group = $(this);
            let group_visible = 0;

            $group.find('.Api_Docs_Page__nav-link').each(function () {
                const $link = $(this);
                const matches = needle === '' || str($link.attr('data-search')).indexOf(needle) !== -1;

                $link.toggleClass('Api_Docs_Page__nav-link--filtered', !matches);

                if (matches) {
                    group_visible++;
                }
            });

            $group.toggleClass('Api_Docs_Page__nav-group--filtered', group_visible === 0);
            visible += group_visible;
        });

        this.$sid('filter_clear').toggleClass('Api_Docs_Page__filter-clear--hidden', needle === '');

        // "No endpoint matches that filter" only when a filter is actually FILTERING. An
        // empty sidebar with no filter typed means something else entirely - restricted
        // listing with no key adopted - and blaming the filter for it would send the reader
        // hunting for a filter they never set.
        const blame_filter = needle !== '' && visible === 0;

        this.$sid('filter_empty').toggleClass('Api_Docs_Page__filter-empty--hidden', !blame_filter);
    }

    /**
     * Clear the filter, restore every link, and hand focus back to the input so the next
     * keystroke starts a new filter rather than going nowhere.
     */
    _clear() {
        this.state.filter = '';
        this.$sid('filter_input').val('');
        this._apply_filter();
        this.$sid('filter_input').focus();
    }
}
