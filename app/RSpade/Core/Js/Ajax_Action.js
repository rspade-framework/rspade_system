/**
 * Ajax_Action - one USER ACTION's writer calls, each carrying a request reference that
 * survives the action's retries. Made by Ajax.action(); see rsx:man request_history.
 *
 * A writer called straight from a stub (a status chip, a kanban drop, a Complete button) has
 * the lost-answer problem a form has: the write committed, the answer never arrived, the user
 * presses the button again and the write runs twice. Sending the same _request_ref on the
 * retry makes the server answer it from the request history instead - the stored answer,
 * nothing run again.
 *
 *     on_create() { this.complete_action = Ajax.action(); }
 *
 *     async complete() {
 *         await this.complete_action.call(Tasks_Controller.complete, { id: this.args.id });
 *     }
 *
 * THE REFERENCE LIFECYCLE
 *
 *   - one reference per ENDPOINT: a reference names one request to one endpoint, so an action
 *     making two writer calls sends each its own;
 *   - kept while that endpoint's calls FAIL (a network failure, a 5xx, a validation error, an
 *     answer that never arrived), so every retry resends it;
 *   - replaced once that endpoint SUCCEEDS - a replay included - so the next press of the
 *     button is a new act.
 *
 * So the usual shape is one Ajax_Action per control, made once, kept for the control's life.
 * An action that runs several writer calls in sequence and is retried from the top re-sends
 * the references of the calls that failed and fresh ones for those that already succeeded;
 * a retry that must RESUME at the failed step is the caller's flow to write.
 *
 * A replay resolves exactly as a fresh run does; act.replayed and act.completed_at say which
 * it was for the most recent success. The refusals reach the caller as Ajax errors, like any
 * other: Ajax.ERROR_REQUEST_REF_REUSED (the same reference with different parameters -
 * metadata {completed_at, original}) and Ajax.ERROR_REQUEST_RESPONSE_EXPIRED.
 *
 * Each instance IS one user action and holds that action's references.
 *
 * @Instantiatable
 */
class Ajax_Action {
    constructor() {
        /** endpoint ('Controller::method') => the reference its next call sends */
        this._refs = {};

        /** Was the most recent successful call answered from the request history? */
        this.replayed = false;

        /** The most recent success's completion (ISO UTC; for a replay, the original), or null. */
        this.completed_at = null;
    }

    /**
     * Ajax.call() with this action's reference for that endpoint as _request_ref.
     *
     * @param {string|object|function} url - As Ajax.call(): a stub method, or an Ajax URL
     * @param {object} [params]
     * @returns {Promise<*>} The endpoint's value, exactly as Ajax.call() resolves it
     */
    async call(url, params = {}) {
        const { controller, action } = Ajax.ajax_url_to_controller_action(url);
        const endpoint = controller + '::' + action;

        if (!this._refs[endpoint]) {
            this._refs[endpoint] = Ajax.request_ref();
        }

        const ref = this._refs[endpoint];
        const detail = await Ajax.call_detailed(url, Object.assign({}, params, { _request_ref: ref }));

        // Done: the next call to this endpoint is a new act. Compared first, so a concurrent
        // success that already rotated it is not undone.
        if (this._refs[endpoint] === ref) {
            delete this._refs[endpoint];
        }

        this.replayed = detail.replayed;
        this.completed_at = detail.completed_at;

        return detail.value;
    }
}
