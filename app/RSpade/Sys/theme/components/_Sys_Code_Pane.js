/**
 * _Sys_Code_Pane
 *
 * See _Sys_Code_Pane.jqhtml. The class owns the one pretty-printer every panel
 * surface uses for "show me this body" - the pane itself and the API console's
 * response renderer alike.
 */
class _Sys_Code_Pane extends Component {
    /**
     * The text a pane shows for its arguments; '' when there is nothing to show.
     *
     * @param {object} args The component's args ($json / $text / $pretty)
     * @returns {string}
     */
    static text_of(args) {
        if (args.json !== undefined && args.text !== undefined) {
            throw new Error('_Sys_Code_Pane takes $json OR $text, not both.');
        }

        if (args.json !== undefined) {
            if (args.json === null || args.json === '') {
                return '';
            }

            return JSON.stringify(args.json, null, 2);
        }

        if (args.text === undefined || args.text === null) {
            return '';
        }

        const text = str(args.text);

        return args.pretty ? _Sys_Code_Pane.pretty(text) : text;
    }

    /**
     * Re-indent a body when it is JSON, and return it exactly as received when it is
     * not. A non-JSON body (an HTML error page, a plain-text refusal) is shown
     * verbatim: it is the useful evidence, and reformatting it would hide what
     * actually came back.
     *
     * @param {string} text
     * @returns {string}
     */
    static pretty(text) {
        try {
            return JSON.stringify(JSON.parse(text), null, 2);
        } catch (e) {
            return text;
        }
    }
}
