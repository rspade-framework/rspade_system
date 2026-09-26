/**
 * _Sys_Tabs
 *
 * See _Sys_Tabs.jqhtml. Owns the active key: resolved on every render from the
 * URL hash, then the key this instance already showed (a re-render must not snap
 * back to the first tab), then the first tab.
 */
class _Sys_Tabs extends Component {
    on_create() {
        this.active_key = null;

        for (const tab of this.args.tabs || []) {
            if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(str(tab.key)) || tab.key === 'default') {
                throw new Error(`_Sys_Tabs: tab key '${tab.key}' is not a usable slot name.`);
            }
        }
    }

    on_render() {
        const that = this;

        // Delegated, namespaced and re-bound on every render (on_render may re-fire).
        this.$.off('click._sys_tabs').on('click._sys_tabs', '._Sys_Tabs__tab', function () {
            that.activate($(this).attr('data-tab-key'));
        });

        let initial = null;

        if (this.args.hash) {
            const from_hash = Rsx.url_hash_get(this.args.hash);

            if (from_hash && this._has_key(from_hash)) {
                initial = str(from_hash);
            }
        }

        if (initial === null && this.active_key !== null && this._has_key(this.active_key)) {
            initial = this.active_key;
        }

        if (initial === null) {
            initial = str(this.args.tabs[0].key);
        }

        this.activate(initial);
    }

    /**
     * Show the panel for a key. An unknown key throws - a caller naming a tab that
     * does not exist is a bug, not a no-op.
     *
     * @param {string} key
     */
    activate(key) {
        if (!this._has_key(key)) {
            throw new Error(`_Sys_Tabs: no tab '${key}'.`);
        }

        key = str(key);
        this.active_key = key;

        this.$.children('._Sys_Tabs__bar').children('._Sys_Tabs__tab').each(function () {
            const $tab = $(this);
            const is_active = $tab.attr('data-tab-key') === key;

            $tab.toggleClass('active', is_active).attr('aria-selected', is_active ? 'true' : 'false');
        });

        this.$.children('._Sys_Tabs__panel').each(function () {
            const $panel = $(this);

            $panel.prop('hidden', $panel.attr('data-tab-key') !== key);
        });

        if (this.args.hash) {
            Rsx.url_hash_set_single(this.args.hash, key);
        }

        this.trigger('tab_change', { key: key });
    }

    get_active_key() {
        return this.active_key;
    }

    _has_key(key) {
        return (this.args.tabs || []).some((tab) => str(tab.key) === str(key));
    }
}
