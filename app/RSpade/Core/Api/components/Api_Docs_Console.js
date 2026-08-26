/**
 * Api_Docs_Console - the API reference page, owning its own in-page navigation.
 *
 * NOT an SPA action. The application declares the route (Rsx_Api_Docs::page), so this is a
 * plain page component that does its own history handling: clicking a sidebar link pushes
 * state and redraws ONLY the body, and Back/Forward restores the previous endpoint. Spa's
 * link interception does not apply - the route is an ordinary #[Route], not an SPA route -
 * so this handler is unambiguous rather than competing with the router.
 *
 * The catalog is baked into the page (the app bundle's load_rsxapp_data -> Rsx_Api_Docs::rsxapp_data), so every lookup is
 * synchronous and there is no loading state anywhere in the UI.
 */
class Api_Docs_Console extends Component {
    on_create() {
        this.state = {
            version: Api_Docs_Catalog.resolve_version(Api_Docs_Console.__version_from_url()),
            slug: Api_Docs_Console.__slug_from_url(),
        };
    }

    on_ready() {
        const that = this;

        // Delegated, so it survives a body redraw and covers the sidebar's links without
        // rebinding per link. Namespaced and re-bound idempotently.
        this.$.off('click.apidocs_nav').on('click.apidocs_nav', 'a[href]', function (e) {
            const $link = $(this);
            const href = $link.attr('href');

            // Leave modified clicks, new-tab links and anything off-page to the browser:
            // a middle-click that silently navigated in place would be a bug.
            if (e.ctrlKey || e.metaKey || e.shiftKey || e.which !== 1 || $link.attr('target')) {
                return;
            }

            const parsed = Api_Docs_Console.__parse(href);

            if (parsed === null) {
                return;
            }

            e.preventDefault();
            that.__navigate(parsed.version, parsed.slug, href);
        });

        // Back/Forward. Bound on window rather than this.$, so it is removed in on_stop -
        // a page component that leaked a window handler would keep answering after it died.
        this._popstate_handler = () => {
            that.__show(
                Api_Docs_Catalog.resolve_version(Api_Docs_Console.__version_from_url()),
                Api_Docs_Console.__slug_from_url()
            );
        };

        window.addEventListener('popstate', this._popstate_handler);
    }

    on_stop() {
        if (this._popstate_handler) {
            window.removeEventListener('popstate', this._popstate_handler);
            this._popstate_handler = null;
        }
    }

    /**
     * Navigate to an endpoint: update the address bar, then repaint.
     */
    __navigate(version, slug, href) {
        history.pushState({ version: version, slug: slug }, '', href);
        this.__show(version, slug);
    }

    /**
     * Repaint for a version + endpoint WITHOUT touching history.
     *
     * Only the body is redrawn ($redrawable). The sidebar is re-rendered separately so the
     * active highlight moves, which preserves its filter text because Api_Docs_Sidebar keeps
     * that in its own state and re-applies it on render.
     */
    __show(version, slug) {
        this.state.version = version;
        this.state.slug = slug;

        this.render('main');

        const sidebar = this.sid('sidebar');
        if (sidebar) {
            sidebar.args.version = version;
            sidebar.args.active = slug;
            sidebar.render();
        }

        const header = this.sid('header');
        if (header.args.version !== version) {
            header.args.version = version;
            header.render();
        }

        window.scrollTo(0, 0);
    }

    /**
     * Parse an in-console href into {version, slug}, or null when it is not one of ours.
     *
     * Matched against the page's OWN base path rather than a hardcoded '/apidocs', because
     * the application chose where this console lives.
     */
    static __parse(href) {
        const base = Api_Docs_Catalog.base_path();

        if (!href || href.indexOf(base) !== 0) {
            return null;
        }

        const url = new URL(href, window.location.origin);

        if (url.pathname !== base) {
            return null;
        }

        return {
            version: Api_Docs_Catalog.resolve_version(url.searchParams.get('version')),
            slug: url.searchParams.get('endpoint') || null,
        };
    }

    static __version_from_url() {
        return new URL(window.location.href).searchParams.get('version');
    }

    static __slug_from_url() {
        return new URL(window.location.href).searchParams.get('endpoint') || null;
    }
}
