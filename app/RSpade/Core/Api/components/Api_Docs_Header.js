/**
 * Api_Docs_Header - the docs tool's top bar.
 *
 * args: version (int) - the selected catalog version.
 */
class Api_Docs_Header extends Component {
    on_ready() {
        const $sel = this.$sid('version_select');

        if ($sel && $sel.exists()) {
            $sel.off('change.apidocs').on('change.apidocs', function () {
                const $element = $(this);
                // Land on the version's HOME, not the same endpoint: an endpoint present in
                // one version need not exist in another, and a "not found, showing home"
                // bounce is worse than going there directly.
                //
                // An ordinary link, not Spa.dispatch: the console is mounted by the
                // application's own #[Route] and there is no SPA router on this page. The
                // console's delegated click handler intercepts it and redraws in place.
                window.location.href = Api_Docs_Catalog.home_url(int($element.val()));
            });
        }
    }
}
