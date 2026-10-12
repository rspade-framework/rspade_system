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

        this._render_seq = 0;
        this._reported_seq = 0;

        // Reported from the 'ready' EVENT, not from on_ready(): the event is fired on every path
        // that completes a lifecycle, while on_ready() is not called on all of them (a refresh()
        // that lands while the first load is still in flight finishes the cycle without it) -
        // and this report is the one thing that may never be skipped. 'ready' also fires after
        // a refresh that changed nothing, so the handler acts once per render.
        this.on('ready', () => this._report_shown());

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
            this.data.error_data = { message: String(e) };
        }
    }

    on_render() {
        this._render_seq++;
        this.invalidate('preview_loaded');
    }

    _report_shown() {
        if (this._reported_seq === this._render_seq) {
            return;
        }
        this._reported_seq = this._render_seq;

        // Emitted in EVERY state, including the waiting and failure ones, and again after the
        // realtime swap out of the waiting one. The host re-emits this outward and callers size
        // themselves on it, so staying silent while a render is pending would leave a page
        // waiting on an event that only arrives if the file happens to succeed. One "page": the
        // grid scrolls rather than paginating.
        const that = this;
        const seq = this._render_seq;
        const report = (state) => {
            if (that._render_seq !== seq) return;
            that.trigger('preview_loaded', {
                pages: 1,
                state: state,
                attachment_id: int(that.args.attachment_id),
            });
        };

        const $host = this.$sid('sheet_host');
        if (!$host.exists()) {
            // The template drew a notice instead of the grid; that notice is what is on screen.
            report(this._notice_state());
            return;
        }

        // The grid. The iframe is built here, handler first and source second, because an iframe
        // cannot be asked afterwards whether it already loaded - and a missed load would be a
        // report that never comes. sandbox is set before src so the document is never loaded
        // unsandboxed (see the template for why it is sandboxed at all).
        const iframe = document.createElement('iframe');
        iframe.className = 'Spreadsheet_Viewer__sheet';
        iframe.setAttribute('sandbox', '');
        iframe.setAttribute('title', this.args.file_name || 'Spreadsheet preview');
        $(iframe).on('load.spreadsheet_viewer', () => report('loaded'));
        iframe.setAttribute('src', this.data.info.urls.rendition);
        $host.empty().append(iframe);

        // The grid is on its way but not on screen: say so now, and 'loaded' follows from the
        // handler above. This is the viewer contract - something is reported by the time the
        // viewer is ready - and what stops the host answering on this viewer's behalf.
        report('preparing');
    }

    /**
     * Which notice the template drew, in the 'preview_loaded' vocabulary. Mirrors the template's
     * own tests: no record to read is 'unavailable', a FAILED render is 'failed', and a rendition
     * that does not exist yet is 'preparing' - this viewer swaps the grid in by itself.
     */
    _notice_state() {
        const info = this.data.info;

        if (this.data.error_data) {
            return 'unavailable';
        }
        if (!info) {
            // The first answer is not in yet.
            return 'preparing';
        }
        if (info.render_status_id === File_Storage_Model.RENDER_STATUS_FAILED) {
            return 'failed';
        }

        return 'preparing';
    }

    /**
     * The host's page contract. A grid does not paginate, so this is deliberately inert rather
     * than absent - Document_Preview calls it on every viewer.
     */
    set_page() {
        // No pages to set.
    }
}
