/**
 * _Sys_Layout - the control panel's SPA layout.
 *
 * Builds the navigation once in on_create() and updates the active item and the
 * page title on every navigation. Deliberately narrower than the template app's
 * Frontend_Spa_Layout: no breadcrumbs, no page-action slot, no title caching -
 * the panel's pages are few and their titles are static.
 */
class _Sys_Layout extends Spa_Layout {
    on_create() {
        this.state = {
            nav_sections: [
                {
                    title: 'Panel',
                    items: [
                        {
                            label: 'Dashboard',
                            icon: 'bi-speedometer2',
                            route: '_Sys_Dashboard_Action',
                            href: Rsx.Route('_Sys_Dashboard_Action'),
                        },
                        {
                            label: 'Debug Flags',
                            icon: 'bi-toggles',
                            route: '_Sys_Debug_Flags_Action',
                            href: Rsx.Route('_Sys_Debug_Flags_Action'),
                        },
                        {
                            label: 'Tasks',
                            icon: 'bi-list-check',
                            route: '_Sys_Tasks_Action',
                            href: Rsx.Route('_Sys_Tasks_Action'),
                        },
                        {
                            label: 'Email',
                            icon: 'bi-envelope',
                            route: '_Sys_Email_Action',
                            href: Rsx.Route('_Sys_Email_Action'),
                        },
                        {
                            label: 'Logs',
                            icon: 'bi-journal-text',
                            route: '_Sys_Logs_Action',
                            href: Rsx.Route('_Sys_Logs_Action'),
                        },
                        {
                            label: 'Sites',
                            icon: 'bi-diagram-3',
                            route: '_Sys_Sites_Action',
                            href: Rsx.Route('_Sys_Sites_Action'),
                        },
                        {
                            label: 'Users',
                            icon: 'bi-person-badge',
                            route: '_Sys_Users_Action',
                            href: Rsx.Route('_Sys_Users_Action'),
                        },
                    ],
                },
            ],
        };

        // NAV HONESTY. A link this user's gates would deny must not appear.
        // Permission.can_access() answers from the target action's own @auth list,
        // so tightening a page's gates removes its nav entry with no edit here.
        for (const section of this.state.nav_sections) {
            section.items = section.items.filter(
                (item) => !item.route || Permission.can_access(item.route)
            );
        }

        this.state.nav_sections = this.state.nav_sections.filter(
            (section) => section.items.length > 0
        );
    }

    on_action(url, action_name, args) {
        this.sid('sidebar_nav').on_action(url, action_name, args);

        this._update_page_title();
    }

    /**
     * Paint the page title. Spa_Layout.resolve_page_title() paints the action's
     * static @title synchronously and replaces it with the awaited page_title()
     * only when the two differ.
     */
    async _update_page_title() {
        const that = this;

        const $title = this.$sid('page_title');

        if (!$title || !$title.exists()) {
            return;
        }

        $title.html('&nbsp;');

        await that.resolve_page_title((title) => {
            $title.text(title);
            document.title = 'Root - ' + title;
        });
    }
}
