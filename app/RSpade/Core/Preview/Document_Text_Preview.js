/**
 * Document_Text_Preview
 *
 * See Document_Text_Preview.jqhtml for the full contract. Four responsibilities: the realtime
 * subscription, the fetch, the idempotent swap out of the waiting state, and reporting what
 * ended up on screen ('preview_loaded').
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

        this._render_seq = 0;
        this._reported_seq = 0;

        // Reported from the 'ready' EVENT, not from on_ready(): the event is fired on every path
        // that completes a lifecycle, while on_ready() is not called on all of them (a refresh()
        // that lands while the first load is still in flight finishes the cycle without it) -
        // and this report is the one thing that may never be skipped. 'ready' also fires after
        // a refresh that changed nothing, so the handler acts once per render.
        this.on('ready', () => this._report_shown());

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
            this.data.error_data = { message: String(e) };
        }
    }

    on_render() {
        // The template has just replaced what was on screen, so the last report describes text
        // (or a notice) that is gone; a late subscriber waits for the report of this render.
        this._render_seq++;
        this.invalidate('preview_loaded');
    }

    _report_shown() {
        if (this._reported_seq === this._render_seq) {
            return;
        }
        this._reported_seq = this._render_seq;

        // Every state reports, the notices included - a host waiting on this component must
        // always be answered - and the realtime swap out of "(Extracting Text...)" reports again.
        const state = this._shown_state();

        this.trigger('preview_loaded', {
            attachment_id: int(this.args.attachment_id),
            state: state,
            length: state === 'loaded' ? this.data.info.text.length : 0,
        });
    }

    /**
     * What the template drew, in the 'preview_loaded' vocabulary. Mirrors the template's own
     * tests. "(Document Text Unavailable)" covers two different facts and they are reported
     * apart: 'failed' when an extraction that should have produced text did not (status
     * 'error'), 'unavailable' when there is simply no text to have - no record this user can
     * read, a file type that carries none, or a document that contains none. The two waiting
     * notices, "(Loading...)" and "(Extracting Text...)", are both 'preparing'.
     */
    _shown_state() {
        const info = this.data.info;

        if (this.data.error_data) {
            return 'unavailable';
        }
        if (!info || info.status === 'pending') {
            return 'preparing';
        }
        if (info.status === 'available' && info.text !== null && info.text !== '') {
            return 'loaded';
        }

        return info.status === 'error' ? 'failed' : 'unavailable';
    }
}
