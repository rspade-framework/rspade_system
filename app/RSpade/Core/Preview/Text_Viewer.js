/**
 * Text_Viewer
 *
 * See Text_Viewer.jqhtml for the full contract. Three responsibilities: the realtime
 * subscription, the fetch, and reporting itself loaded to the host Document_Preview.
 */
class Text_Viewer extends Component {
    on_create() {
        // Fail before subscribing: this.subscribe(Model, id, cb) with a missing id would open a
        // subscription on record 0 that no frame can ever match. The template guard would throw a
        // moment later anyway, but the earliest honest failure is the useful one.
        if (!this.args.attachment_id) {
            throw new Error('Text_Viewer requires $attachment_id');
        }

        this.data.info = null;
        this.data.error_data = null;

        // Extraction is produced by a background worker, so the state this viewer paints first is
        // usually a waiting state. Subscribing HERE - before the first load, the canonical
        // placement - closes the window in which the extraction could finish between the fetch and
        // the subscription, stranding "(Extracting Text...)" on screen forever. The callback is
        // refresh() (refetch, repaint only if this.data changed), never reload(): the server saying
        // "something changed" must not tear down rendered text on every frame.
        this.subscribe(File_Attachment_Model, int(this.args.attachment_id), () => this.refresh());
    }

    async on_load() {
        try {
            this.data.info = await File_Preview_Controller.get_extracted_text({
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
        // Emitted in EVERY state, including the waiting and failure ones. The host re-emits this
        // outward and callers size themselves on it, so staying silent while extraction is pending
        // would leave a page waiting on an event that only arrives if the file happens to succeed.
        // No width/height/ratio: text has no intrinsic page geometry to report, and Document_Preview
        // does not pass $fit to a viewer that cannot act on it.
        this.trigger('preview_loaded', { pages: 1 });
    }

    set_page() { /* single page - no-op */ }

    get_page() {
        return 1;
    }

    get_pages() {
        return 1;
    }
}
