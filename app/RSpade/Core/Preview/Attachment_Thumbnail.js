/**
 * Attachment_Thumbnail
 *
 * See Attachment_Thumbnail.jqhtml for the argument contract, the state table and the
 * 'preview_loaded' event. This class owns four things: the realtime subscription, the fetch, the
 * idempotent swap, and reporting what ended up on screen.
 *
 * WHY A COMPONENT AND NOT A FUNCTION
 * A helper that returned a URL string would be smaller and would be wrong. A thumbnail is not a
 * value, it is a live view of a record whose picture may not exist yet: the URL depends on render
 * state, and render state changes underneath the page. Only a component has the lifecycle to
 * subscribe when it mounts, unsubscribe when it is destroyed, refetch when a frame says something
 * changed, and repaint only when the answer actually differs. That is why every thumbnail in an
 * RSX app goes through here and nothing builds a thumbnail URL by hand.
 *
 * WHAT THIS PARADIGM MAKES POSSIBLE
 * All of the following are UNBUILT - listed because routing every thumbnail through one component
 * is what puts them within reach, and because each one would otherwise be re-invented per app:
 *   - A PENDING spinner. render_status_id === 2 is already on the host element; an animated
 *     progress affordance could replace the flat extension icon while the worker converts.
 *   - Re-render on resize. A ResizeObserver on the host could notice the box grew and request a
 *     larger variant, so a thumbnail dragged into a bigger slot stops looking soft.
 *   - Preset negotiation. The component could pick the named preset closest to its measured box
 *     instead of taking a width from the caller, which would collapse the long tail of one-off
 *     dynamic variants filling the thumbnail cache.
 *   - Any thumbnail, replaced by the server at any time. The swap path is not specific to document
 *     rendering: a re-crop, a moderation blur, a regenerated avatar - anything that emits on the
 *     attachment reaches every mounted thumbnail of it.
 */
class Attachment_Thumbnail extends Component {
    on_create() {
        // Fail before subscribing: this.subscribe(Model, id, cb) with a missing id would open a
        // subscription on record 0 that no frame can ever match. The template guard would still
        // throw a moment later, but the earliest honest failure is the useful one.
        if (!this.args.attachment_id) {
            throw new Error('Attachment_Thumbnail requires $attachment_id');
        }

        this.data.attachment = null;

        // Counts renders. A report is only ever made for the picture of the CURRENT render: an
        // <img> a later render replaced can still finish loading, and must say nothing.
        this._picture_seq = 0;
        this._watched_seq = 0;

        // Reported from the 'ready' EVENT, not from on_ready(): the event is fired on every path
        // that completes a lifecycle, while on_ready() is not called on all of them (a refresh()
        // that lands while the first load is still in flight finishes the cycle without it) -
        // and this report is the one thing that may never be skipped. 'ready' also fires after
        // a refresh that changed nothing, so the handler acts once per render.
        this.on('ready', () => this._watch_picture());

        // Realtime subscription in on_create, not on_ready: this is the canonical placement,
        // because it gates the first load on the subscription being live. Subscribe later and
        // there is a window between the fetch and the subscription in which a render could
        // complete unseen, leaving a placeholder on screen forever. The callback is refresh()
        // (refetch, repaint only if this.data changed), never reload() - the server saying
        // "something changed" must not destroy child DOM on every frame.
        this.subscribe(File_Attachment_Model, int(this.args.attachment_id), () => this.refresh());
    }

    async on_load() {
        // fetch_or_null, not fetch: a deleted attachment emits a frame and then fetches as
        // not-found, and that is the INTENDED end state - the template paints its unavailable
        // box. The same answer covers "this user may not see it" (the gate denies as not-found,
        // by design - see File_Attachment_Model::fetch).
        this.data.attachment = await File_Attachment_Model.fetch_or_null(int(this.args.attachment_id));
    }

    on_render() {
        // The template has just replaced whatever was on screen, so the last report describes a
        // picture that is gone. Dropping the stored firing here is what keeps a host that
        // subscribes late from being handed the previous picture's payload; it waits for the
        // report of this one instead.
        this._picture_seq++;
        this.invalidate('preview_loaded');
    }

    /**
     * Report the picture the current render put on the page, once it has settled. Runs after
     * every render - the first load, a realtime swap, a reload onto another $attachment_id - and
     * the <img> is a new element each time, so the handlers are bound here, namespaced, on the
     * element this render produced.
     */
    _watch_picture() {
        if (this._watched_seq === this._picture_seq) {
            return;
        }
        this._watched_seq = this._picture_seq;

        const that = this;
        const seq = this._picture_seq;
        const $img = this.$.children('.Attachment_Thumbnail__img');

        if (!$img.exists()) {
            // The unavailable placeholder. It is on screen already: that counts as rendered.
            this._report('unavailable', null, null);
            return;
        }

        const img = $img[0];
        const variant = $img.hasClass('Attachment_Thumbnail__img--animated') ? 'original' : 'thumbnail';

        const settle = (decoded) => {
            if (that._picture_seq !== seq) {
                return;
            }
            that._report(decoded ? that._decoded_state() : 'failed', variant, decoded ? img : null);
        };

        $img.off('load.attachment_thumbnail error.attachment_thumbnail');

        if (img.complete) {
            // Already settled before anything could bind - the ordinary case for a cached
            // picture. A complete image with no natural width is one the browser could not
            // decode.
            settle(img.naturalWidth > 0);
            return;
        }

        $img.on('load.attachment_thumbnail', () => settle(true));
        $img.on('error.attachment_thumbnail', () => settle(false));
    }

    /**
     * What a decoded picture IS, from the blob's render status: the extension icon served while a
     * render is queued is a placeholder this component replaces by itself ('preparing'), the icon
     * served for a failed render says the real picture could not be made ('failed'), and
     * everything else is this attachment's final picture ('loaded') - a raster, or the type icon
     * of a file that has no raster.
     */
    _decoded_state() {
        const status = this._render_status();

        if (status === File_Storage_Model.RENDER_STATUS_PENDING) {
            return 'preparing';
        }
        if (status === File_Storage_Model.RENDER_STATUS_FAILED) {
            return 'failed';
        }

        return 'loaded';
    }

    /**
     * The value the template writes to data-render-status: the blob's RENDER_STATUS_* id, 'none'
     * for a record with no resident blob, 'unavailable' for no record at all.
     */
    _render_status() {
        const record = this.data.attachment;

        if (!record) {
            return 'unavailable';
        }

        return record.file_storage ? int(record.file_storage.render_status_id) : 'none';
    }

    _report(state, variant, img) {
        this.trigger('preview_loaded', {
            attachment_id: int(this.args.attachment_id),
            state: state,
            render_status: this._render_status(),
            variant: variant,
            natural_width: img ? img.naturalWidth : 0,
            natural_height: img ? img.naturalHeight : 0,
        });
    }

    // No on_stop(): the subscription is ref-counted and stopped for us when the component is
    // destroyed, the <img> and its handlers go with the template's DOM, and a report for a
    // superseded render is refused by the sequence check above.
}
