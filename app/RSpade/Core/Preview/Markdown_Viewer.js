/**
 * Markdown_Viewer
 *
 * See Markdown_Viewer.jqhtml for the full contract. Three responsibilities: the realtime
 * subscription, the fetch, and reporting itself loaded to the host Document_Preview.
 */
class Markdown_Viewer extends Component {
    on_create() {
        // Fail before subscribing: this.subscribe(Model, id, cb) with a missing id would open a
        // subscription on record 0 that no frame can ever match. The template guard would throw a
        // moment later anyway, but the earliest honest failure is the useful one.
        if (!this.args.attachment_id) {
            throw new Error('Markdown_Viewer requires $attachment_id');
        }

        this.data.info = null;
        this.data.error_data = null;

        // Unlike its siblings this viewer waits on no background worker - the render happens
        // inside the request. The subscription is here for the ordinary reason: the attachment
        // can be replaced or deleted underneath an open page, and the canonical placement is
        // on_create, which gates the first load on the subscription (one race-free fetch).
        // refresh(), never reload(): the server saying "something changed" must not tear down a
        // rendered document and the reader's scroll position with it.
        this.subscribe(File_Attachment_Model, int(this.args.attachment_id), () => this.refresh());
    }

    async on_load() {
        try {
            this.data.info = await File_Preview_Controller.get_markdown_html({
                attachment_id: int(this.args.attachment_id),
            });
        } catch (e) {
            // SURFACE the failure, never substitute a payload for it (the catch-substitution rule):
            // the template reads error_data and paints the unavailable notice, which is the
            // intended end state for the two failures that actually reach here - the endpoint
            // refused (not authorized) or the attachment is gone.
            this.data.error_data = e;
        }
    }

    on_ready() {
        // Emitted in EVERY state, including the failure ones. The host re-emits this outward and
        // callers size themselves on it, so staying silent on a failure would leave a page
        // waiting on an event that only arrives if the file happens to succeed. No
        // width/height/ratio: a rendered document has no intrinsic page geometry to report.
        this.trigger('preview_loaded', { pages: 1 });
    }

    /**
     * The host's page contract. A rendered document scrolls rather than paginating, so this is
     * deliberately inert rather than absent - Document_Preview calls it on every viewer.
     */
    set_page() { /* single page - no-op */ }

    get_page() {
        return 1;
    }

    get_pages() {
        return 1;
    }
}
