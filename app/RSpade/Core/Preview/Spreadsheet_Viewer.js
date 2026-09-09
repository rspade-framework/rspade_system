/**
 * Spreadsheet_Viewer
 *
 * See Spreadsheet_Viewer.jqhtml for the full contract. Three responsibilities: the realtime
 * subscription, the fetch, and reporting itself loaded to the host Document_Preview.
 */
class Spreadsheet_Viewer extends Component {
    on_create() {
        // Fail before subscribing: this.subscribe(Model, id, cb) with a missing id would open a
        // subscription on record 0 that no frame can ever match.
        if (!this.args.attachment_id) {
            throw new Error('Spreadsheet_Viewer requires $attachment_id');
        }

        this.data.info = null;
        this.data.error_data = null;

        // The rendition is produced by a background worker, so the first state this viewer
        // paints is usually the waiting one. Subscribing HERE - before the first load, the
        // canonical placement - closes the window in which the render could finish between the
        // fetch and the subscription, stranding "(Preparing Preview...)" on screen forever.
        // refresh(), never reload(): the server saying "something changed" must not tear down a
        // rendered grid, and reloading the iframe would throw away the reader's scroll position.
        this.subscribe(File_Attachment_Model, int(this.args.attachment_id), () => this.refresh());
    }

    async on_load() {
        try {
            this.data.info = await File_Preview_Controller.get_preview_info({
                attachment_id: int(this.args.attachment_id),
            });
        } catch (e) {
            // SURFACE the failure, never substitute a payload for it: the template reads
            // error_data and paints the unavailable notice, which is the intended end state for
            // the two failures that actually reach here - the endpoint refused, or the
            // attachment is gone.
            this.data.error_data = e;
        }
    }

    on_ready() {
        // Emitted in EVERY state, including the waiting and failure ones. The host re-emits this
        // outward and callers size themselves on it, so staying silent while a render is pending
        // would leave a page waiting on an event that only arrives if the file happens to
        // succeed. One "page": the grid scrolls rather than paginating.
        this.trigger('preview_loaded', { pages: 1 });
    }

    /**
     * The host's page contract. A grid does not paginate, so this is deliberately inert rather
     * than absent - Document_Preview calls it on every viewer.
     */
    set_page() {
        // No pages to set.
    }
}
