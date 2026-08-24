/**
 * Attachment_Thumbnail
 *
 * See Attachment_Thumbnail.jqhtml for the argument contract and the state table. This class owns
 * three things: the realtime subscription, the fetch, and the idempotent swap.
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

    // No on_stop(): there is deliberately no post-await DOM or event work here to guard. on_load
    // writes this.data and nothing else, the subscription is ref-counted and stopped for us when
    // the component is destroyed, and the <img> is owned by the template. A _stopped flag nothing
    // reads would just be a lie about how much state this component holds.
}
