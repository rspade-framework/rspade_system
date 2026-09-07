/**
 * _Sys_Sidebar_Nav
 *
 * See _Sys_Sidebar_Nav.jqhtml. The class owns active-item detection: the
 * layout hands it the section list once and calls on_action() on every SPA
 * navigation, which re-marks the matching link in place rather than re-rendering
 * (a re-render would drop the element the layout keeps a $sid handle on).
 */
class _Sys_Sidebar_Nav extends Component {
    on_create() {
        const that = this;

        let sections = that.args.sections || [];

        if (typeof sections === 'string') {
            sections = json_decode(sections);
        }

        that.data = {
            sections: that.mark_active(sections, window.location.pathname),
        };
    }

    on_ready() {
        this.on_action(window.location.pathname);
    }

    /**
     * Re-mark the active link for a URL. Called by the layout on every navigation.
     */
    async on_action(url, action_name, args) {
        await this.ready();

        const that = this;

        that.$.find('._Sys_Sidebar_Nav__link').each(function () {
            const $element = $(this);

            $element.toggleClass('active', that.is_url_active($element.attr('href'), url));
        });
    }

    mark_active(sections, current_path) {
        const that = this;

        return sections.map((section) => ({
            title: section.title,
            items: (section.items || []).map((item) => ({
                label: item.label,
                icon: item.icon,
                href: item.href,
                active: that.is_url_active(item.href, current_path),
            })),
        }));
    }

    /**
     * The panel's dashboard lives at the tree root ('/_sys'), so a prefix match
     * would mark it active on every page. An exact match is what a one-level
     * navigation actually needs.
     */
    is_url_active(url, current_path = null) {
        if (current_path === null) {
            current_path = window.location.pathname;
        }

        return current_path.replace(/\/+$/, '') === String(url || '').replace(/\/+$/, '');
    }
}
