/**
 * Sso_Buttons
 *
 * See Sso_Buttons.jqhtml for the contract. There is almost nothing here on purpose: the
 * buttons are anchors, so the browser does the navigating and this class only decides what
 * they point at.
 *
 * THE ROSTER IS TAKEN IN on_create() AND NOT FETCHED. It was exported into window.rsxapp.sso
 * with the page, and it cannot change while the page is open - a provider is switched on in
 * config, which is a new document away. Reading it in on_load() would put the buttons behind
 * an await for data that was already in the document, which is a flash of nothing for no
 * reason.
 */
class Sso_Buttons extends Component {
    on_create() {
        this.data.providers = Rsx_Sso.enabled_providers();
    }

    /**
     * Where one provider's button points.
     *
     * The link intent rides as a query parameter rather than a second route, because it is a
     * variation on the same ceremony and the server treats it as one - and because a settings
     * screen's Connect button and a login page's Continue button must not be able to drift
     * apart into two spellings of one URL.
     *
     * @param {object} provider One entry from the exported roster.
     * @returns {string}
     */
    begin_url(provider) {
        if (this.args.intent === 'link') {
            return provider.begin_url + '?intent=link';
        }

        return provider.begin_url;
    }
}
