/**
 * Document_Preview
 *
 * See Document_Preview.jqhtml for the full contract. Loads the server's viewer resolution in
 * on_load, dispatches to the built-in viewers via the template, instantiates every other viewer
 * (Spreadsheet_Viewer, Markdown_Viewer, app-registered ones) dynamically once ready, and
 * re-triggers the active viewer's events outward.
 *
 * Also owns the async-render waiting states: it subscribes to its attachment id in on_create and
 * swaps out of "Preparing preview..." when the background render worker finishes.
 *
 * And it owns the promise that a host is ALWAYS answered: whatever ends up in the frame - a
 * viewer's document, a waiting or failed notice, the unavailable card - is reported with
 * 'preview_loaded', and reported again each time it is replaced.
 */
class Document_Preview extends Component {
    static BUILTIN_VIEWERS = ['Pdf_Viewer', 'Image_Viewer', 'Text_Viewer', 'Icon_Viewer'];

    on_create() {
        this.data.info = null;
        this.data.error_data = null;
        if (this.args.page === undefined || this.args.page === null) {
            this.args.page = 1;
        }
        if (this.args.fit === undefined || this.args.fit === null) {
            this.args.fit = 'width';
        }
        if (this.args.fit !== 'width' && this.args.fit !== 'contain') {
            throw new Error('Document_Preview: $fit must be "width" or "contain", got "' + this.args.fit + '"');
        }

        // The viewer instance whose events are currently forwarded (see _report_shown).
        this._wired_viewer = null;
        this._render_seq = 0;
        this._reported_seq = 0;

        // Reported from the 'ready' EVENT, not from on_ready(): the event is fired on every path
        // that completes a lifecycle, while on_ready() is not called on all of them (a refresh()
        // that lands while the first load is still in flight finishes the cycle without it) -
        // and this report, and the viewer it may have to instantiate, may never be skipped. 'ready' also fires after
        // a refresh that changed nothing, so the handler acts once per render.
        this.on('ready', () => this._report_shown());

        // A convertible document's rendition is produced by a background worker, so the preview
        // this component paints first may be a waiting state. Subscribing HERE - before the first
        // load, the canonical placement - is what closes the window in which the render could
        // finish between the fetch and the subscription, stranding "Preparing preview..." on
        // screen forever. refresh() refetches and repaints only when the info actually changed,
        // which is exactly the PENDING -> RENDERED swap; reload() would tear down a live
        // Pdf_Viewer on every unrelated frame.
        this.subscribe(File_Attachment_Model, int(this.args.attachment_id), () => this.refresh());
    }

    async on_load() {
        // Read this.args, write this.data only. Server resolves the viewer + urls.
        try {
            this.data.info = await File_Preview_Controller.get_preview_info({
                attachment_id: this.args.attachment_id,
            });
        } catch (e) {
            // SURFACE the failure, never substitute a payload for it (the catch-substitution rule):
            // the template reads error_data and paints the unavailable card, which is the intended
            // end state for the two failures that actually reach here - the endpoint refused (not
            // authorized) or the attachment is gone, exactly as <Attachment_Thumbnail> treats a
            // not-found fetch.
            this.data.error_data = { message: String(e) };
        }
    }

    on_render() {
        // The template has just replaced the frame's contents - viewer included - so the last
        // report describes something that is gone. Dropping the stored firing is what keeps a
        // host that subscribes late from being handed the previous content's payload.
        this._render_seq++;
        this._wired_viewer = null;
        this.invalidate('preview_loaded');
    }

    /**
     * Report what the current render put in the frame: a state this component drew itself, or
     * the viewer - instantiating it first when it is not one the template renders - whose events
     * are forwarded from here on.
     */
    _report_shown() {
        if (this._reported_seq === this._render_seq) {
            return;
        }
        this._reported_seq = this._render_seq;

        const that = this;

        // A state this component drew itself is on screen already, and is reported as such.
        const own_state = this._own_state();
        if (own_state) {
            this._report_own(own_state);
            return;
        }

        const viewer_name = this.data.info.viewer;
        const dynamic = Document_Preview.BUILTIN_VIEWERS.indexOf(viewer_name) === -1;

        // App-registered viewer (config('rsx.preview.viewers') override): the template rendered an
        // empty host div with $sid="viewer"; instantiate the named component into it. Built-in
        // viewers are already rendered by the template.
        if (dynamic && this.$sid('viewer').exists()) {
            console_debug('preview', 'Document_Preview: instantiating app-registered viewer', viewer_name);
            this.$sid('viewer').component(viewer_name, {
                attachment_id: this.args.attachment_id,
                url: this.data.info.urls.inline,
                extension: this.data.info.extension,
                file_name: this.data.info.file_name,
                page: this.args.page || 1,
                fit: this.args.fit || 'width',
            });
        }

        const viewer = this.sid('viewer');
        if (!viewer) {
            console_debug('preview', 'Document_Preview: no viewer component resolved for', viewer_name);
            this._report_own('failed');
            return;
        }

        this._wired_viewer = viewer;

        // Whether this viewer has reported the content of its CURRENT render.
        let viewer_reported = false;

        // ORDER MATTERS: every jqhtml event is sticky, so each registration below is answered at
        // once when the viewer got there first. 'render' is registered first so that a replayed
        // 'preview_loaded' lands after it, not before.
        //
        // A viewer that re-renders itself (its own realtime swap out of a waiting state) has
        // replaced what this component last reported, exactly as on_render() above.
        viewer.on('render', () => {
            if (that._wired_viewer !== viewer) return;
            viewer_reported = false;
            that.invalidate('preview_loaded');
        });

        // Re-emit the active viewer's events outward so the page (pagination UI, status) can listen
        // on Document_Preview itself. The event is named 'preview_loaded' (NOT 'loaded') because
        // 'loaded' is a reserved jqhtml component lifecycle event (fired internally with no
        // payload) - reusing it collides.
        viewer.on('preview_loaded', (comp, data) => {
            if (that._wired_viewer !== viewer) return;
            viewer_reported = true;
            that.trigger('preview_loaded', that._payload(data, (data && data.state) ? data.state : 'loaded'));
        });
        viewer.on('page_changed', (comp, data) => {
            if (that._wired_viewer !== viewer) return;
            that.trigger('page_changed', data);
        });

        // THE VIEWER CONTRACT, and what covers a viewer that does not keep it. A viewer reports
        // 'preview_loaded' itself, by the time it is ready: its final state, or 'preparing' when
        // the content arrives later (and then again when it does). jqhtml fires 'ready' only
        // after on_ready() - an awaited async one included - so a dynamically instantiated viewer
        // that is ready and has said nothing never will, and is reported here as showing its
        // content. No clock is involved: 'ready' is a lifecycle fact.
        if (dynamic) {
            viewer.on('ready', () => {
                if (that._wired_viewer !== viewer || viewer_reported) return;
                viewer_reported = true;
                const pages = is_function(viewer.get_pages) ? int(viewer.get_pages()) : 1;
                that.trigger('preview_loaded', that._payload({ pages: pages }, 'loaded'));
            });
        }
    }

    // -- Reporting -----------------------------------------------------------

    /**
     * The state this component drew ITSELF instead of a viewer, in the 'preview_loaded'
     * vocabulary - or null when the template rendered a viewer. Mirrors the template's branches,
     * in the template's order.
     */
    _own_state() {
        const info = this.data.info;

        if (this.data.error_data || !info || info.preview_unavailable) {
            return 'unavailable';
        }
        if (info.render_status_id === File_Storage_Model.RENDER_STATUS_PENDING) {
            return 'preparing';
        }
        if (info.render_status_id === File_Storage_Model.RENDER_STATUS_FAILED) {
            return 'failed';
        }

        return null;
    }

    /**
     * The blob's RENDER_STATUS_* id, 'none' for a record with no resident blob, 'unavailable'
     * for no record at all - the vocabulary <Attachment_Thumbnail> reports.
     */
    _render_status() {
        const info = this.data.info;

        if (this.data.error_data || !info) {
            return 'unavailable';
        }

        return info.render_status_id ? int(info.render_status_id) : 'none';
    }

    /**
     * A viewer's payload with the fields every 'preview_loaded' carries laid over it.
     */
    _payload(data, state) {
        return Object.assign({}, data, {
            attachment_id: int(this.args.attachment_id),
            state: state,
            render_status: this._render_status(),
        });
    }

    _report_own(state) {
        this.trigger('preview_loaded', this._payload({ pages: 0, width: 0, height: 0, ratio: 0 }, state));
    }

    // -- Public API (delegates to the active viewer) -------------------------

    set_page(n) {
        const viewer = this.sid('viewer');
        if (!viewer || !is_function(viewer.set_page)) {
            console_debug('preview', 'Document_Preview.set_page: viewer not ready');
            return;
        }
        viewer.set_page(n);
    }

    get_page() {
        const viewer = this.sid('viewer');
        if (!viewer || !is_function(viewer.get_page)) {
            return this.args.page || 1;
        }
        return viewer.get_page();
    }

    get_pages() {
        const viewer = this.sid('viewer');
        if (!viewer || !is_function(viewer.get_pages)) {
            return 0;
        }
        return viewer.get_pages();
    }
}
