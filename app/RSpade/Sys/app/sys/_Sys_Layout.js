/**
 * _Sys_Layout - the control panel's SPA layout.
 *
 * Builds the navigation once in on_create() and, on every navigation, updates the
 * active item, the page title and the top bar's page actions. Deliberately narrower
 * than the template app's Frontend_Spa_Layout: no breadcrumbs, no title caching -
 * the panel's pages are few and their titles are static.
 *
 * PAGE ACTIONS. An action that defines page_actions() puts buttons in the top bar,
 * beside the title:
 *
 *     page_actions() {
 *         return `<button class="btn btn-primary btn-sm" data-action="rerun">Re-run</button>`;
 *     }
 *
 * It returns an HTML string (or a jQuery element), may be async, and is asked again
 * on every navigation; the bar is emptied first, so an action without the method
 * leaves it empty. The markup lives in the LAYOUT, not in the action's element, so
 * the action binds its handlers on the layout's bar - namespaced, and removed in
 * on_stop():
 *
 *     on_ready() {
 *         Spa.layout.$sid('page_actions').on('click.my_page', '[data-action=rerun]', () => this.reload());
 *     }
 *     on_stop() {
 *         Spa.layout.$sid('page_actions').off('click.my_page');
 *     }
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
                            label: 'Email & SMS',
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

    on_ready() {
        const that = this;

        // The narrow-screen drawer. Delegated and namespaced, so a re-render re-binds
        // cleanly. On a wide screen the toggle and the backdrop are not displayed.
        this.$.off('click._sys_nav').on('click._sys_nav', '[data-nav="toggle"]', function () {
            that.set_nav_open(!that.$.hasClass('_Sys_Layout--nav-open'));
        });

        this.$.on('click._sys_nav', '[data-nav="close"]', function () {
            that.set_nav_open(false);
        });

        this.$.off('keydown._sys_nav').on('keydown._sys_nav', function (e) {
            if (e.key === 'Escape' && that.$.hasClass('_Sys_Layout--nav-open')) {
                that.set_nav_open(false);
            }
        });
    }

    /**
     * Open or close the narrow-screen navigation drawer.
     *
     * @param {boolean} open
     */
    set_nav_open(open) {
        this.$.toggleClass('_Sys_Layout--nav-open', !!open);
        this.$.find('[data-nav="toggle"]').attr('aria-expanded', open ? 'true' : 'false');
    }

    on_action(url, action_name, args) {
        // A navigation is the drawer's answer: the page it opened is now showing.
        this.set_nav_open(false);

        this.sid('sidebar_nav').on_action(url, action_name, args);

        this._update_page_title();
        this._render_page_actions();
    }

    /**
     * Fill the top bar's action cluster from the current action's page_actions().
     * Not wrapped: a page_actions() that throws is a broken page and fails loud.
     */
    async _render_page_actions() {
        const $actions = this.$sid('page_actions');
        const action = this.action;

        $actions.empty();

        if (!action || typeof action.page_actions !== 'function') {
            return;
        }

        const html = await action.page_actions();

        // A slower page_actions() from an earlier navigation must not paint over the
        // current action's bar.
        if (this.action !== action) {
            return;
        }

        if (html) {
            $actions.empty().append(html);
        }
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
            document.title = 'System - ' + title;
        });
    }
}
