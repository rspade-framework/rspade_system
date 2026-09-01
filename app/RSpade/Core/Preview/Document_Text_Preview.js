/**
 * Document_Text_Preview
 *
 * See Document_Text_Preview.jqhtml for the full contract. Three responsibilities: the realtime
 * subscription, the fetch, and the idempotent swap out of the waiting state.
 */
class Document_Text_Preview extends Component {
    on_create() {
        // Fail before subscribing: this.subscribe(Model, id, cb) with a missing id would open a
        // subscription on record 0 that no frame can ever match. The template guard would throw a
        // moment later anyway, but the earliest honest failure is the useful one.
        if (!this.args.attachment_id) {
            throw new Error('Document_Text_Preview requires $attachment_id');
        }

        this.data.info = null;
        this.data.error_data = null;

        // Text extraction is produced by a background worker, so the state this component paints
        // first is usually a waiting state. Subscribing HERE - before the first load, the canonical
        // placement - is what closes the window in which the extraction could finish between the
        // fetch and the subscription, stranding "(Extracting Text...)" on screen forever. The
        // callback is refresh() (refetch, repaint only if this.data changed), never reload(): the
        // server saying "something changed" must not tear down the rendered text on every frame.
        this.subscribe(File_Attachment_Model, int(this.args.attachment_id), () => this.refresh());
    }

    async on_load() {
        try {
            this.data.info = await File_Preview_Controller.get_extracted_text({
                attachment_id: int(this.args.attachment_id),
            });
        } catch (e) {
            // SURFACE the failure, never substitute a payload for it (the catch-substitution rule):
            // the template reads error_data and paints the unavailable notice, which is the intended
            // end state for the two failures that actually reach here - the endpoint refused
            // (not authorized) or the attachment is gone, exactly as <Attachment_Thumbnail>
            // treats a not-found fetch.
            this.data.error_data = e;
        }
    }
}
