/**
 * Api_Endpoint_Card - one endpoint: verb pills + pattern, description, params table,
 * collapsible example response, code samples, and inline tester.
 *
 * args: endpoint (object) - one resolved catalog endpoint. The card IS the page body now,
 * so there is no scroll anchor: the sidebar navigates rather than scrolling.
 *
 * Above WIDE_AT the card is two columns (see the SCSS): reference on the left, the code
 * samples and the tester on the right.
 */
class Api_Endpoint_Card extends Component {
    /**
     * The width at which the card splits into two columns. Matches the SCSS breakpoint - the
     * JS decides only whether the example response starts OPEN, which CSS cannot express
     * because <details open> is markup, not style.
     */
    static WIDE_AT = 1850;

    on_create() {
        // Evaluated once, at render, deliberately: re-opening a panel the reader deliberately
        // collapsed because they resized a window would be worse than leaving it as they set it.
        this.args.example_open = window.innerWidth >= Api_Endpoint_Card.WIDE_AT;
    }

    on_ready() {
        // <pre> is a raw-text element in jqhtml; set the example text here, then highlight.
        const $ex = this.$sid('response_example');
        if ($ex && $ex.exists()) {
            $ex[0].textContent = this.args.endpoint.response_example || '';
            if (typeof hljs !== 'undefined') {
                hljs.highlightElement($ex[0]);
            }
        }
    }
}
