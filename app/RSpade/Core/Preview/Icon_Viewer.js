/**
 * Icon_Viewer - see Icon_Viewer.jqhtml for the contract. The terminal fallthrough viewer.
 */
class Icon_Viewer extends Component {
    on_render() {
        this.invalidate('preview_loaded');
    }

    on_ready() {
        // The icon and the file name ARE this viewer's content, so it has loaded as soon as it
        // is on the page.
        this.trigger('preview_loaded', {
            pages: 1,
            state: 'loaded',
            attachment_id: this.args.attachment_id ? int(this.args.attachment_id) : null,
        });
    }

    set_page() { /* single page - no-op */ }

    get_page() {
        return 1;
    }

    get_pages() {
        return 1;
    }
}
