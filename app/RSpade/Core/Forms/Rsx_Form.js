/**
 * Rsx_Form - the form component. The ONE submission pipeline in the system.
 *
 * A form owns value state, dirty tracking, client validation, submission and error
 * rendering. Presentation lives elsewhere: Form_Field wraps one input with a label,
 * Modal.form() is chrome that hosts a form and drives THIS class's submit(). Neither
 * of those has a pipeline of its own.
 *
 * Contract of record: rsx:man form_conventions - this docblock is the implementation
 * summary.
 *
 * ── Declaration ──────────────────────────────────────────────────────────────────────
 *
 *   <Rsx_Form $controller="Frontend_Tasks_Controller" $method="save" $data=this.data.form_data>
 *       <Form_Field $label="Title" $required=true>
 *           <Text_Input $name="title" />
 *       </Form_Field>
 *       <button type="submit" class="btn btn-primary">Save</button>
 *   </Rsx_Form>
 *
 * $controller + $method name the Ajax endpoint, and this declaration is the ONLY place
 * the endpoint is named - modals do not repeat it, buttons do not repeat it. $data
 * seeds an EDIT form (typically the record's fetch() payload); a CREATE form omits it
 * and starts pristine. Inputs still initializing when the seed applies receive their
 * values through Form_Input_Abstract's pending buffer.
 *
 * ── Submission ───────────────────────────────────────────────────────────────────────
 *
 * submit() returns a promise resolving with the server result on success and false on
 * any failure (client validation, server validation, transport). The pipeline:
 * re-entrancy guard -> clear errors -> client _validate() pass -> serialize ->
 * before_submit hook -> spinner -> Ajax -> success (clear dirty/parked, fire
 * 'submitted', honor result.redirect, resolve result) or failure (render errors, fire
 * 'submit_error', resolve false). Buttons of type="submit" inside the form are
 * auto-wired; a modal's primary button calls submit() directly.
 *
 * ── Validation lives on the SERVER, once ─────────────────────────────────────────────
 *
 * This form performs NO client-side validation of server-checkable rules - no required
 * checks, no formats, no lengths. WHY, because this departs from common wisdom: a
 * client check that duplicates a server rule MASKS the absence of the server rule.
 * Blank never reaches the endpoint, the missing server validator is never exercised,
 * and the gap surfaces only when an API caller or script hits the endpoint directly -
 * silently, in production. (This is not hypothetical: the blank-title-on-edit bug that
 * shaped this contract was a server-side gap that a client required-check would have
 * concealed indefinitely.) The server round-trip is fast enough that submitting and
 * rendering the server's validation error IS the responsive UX, and the rule then
 * exists in exactly one place. $required on Form_Field is an asterisk - presentation
 * of a server rule, enforcing nothing.
 *
 * The ONE exception is _validate() on an input: an ARCHITECTURAL constraint whose
 * invalid state cannot be expressed to the server at all (a pick-at-most-2 multiselect
 * where a third selection is unrepresentable; a structured input whose malformed value
 * cannot serialize). Prefer expressing even those as interaction design - the widget
 * refusing the third selection - over a submit-time check.
 *
 * ── Blank is a value; absent means untouched ─────────────────────────────────────────
 *
 * The form serializes EVERY input on every submit, so a blank field always reaches the
 * endpoint as '' - blank is something the user did, never an omission. The endpoint
 * contract follows: an ABSENT key means "leave it untouched" (partial update); a
 * PRESENT-but-blank value must validate - a required field rejects it identically on
 * create and edit, an optional field saves it. "Keep the old value when blank" is
 * forbidden server-side: it makes a failed clear look like a success.
 *
 * ── Loading (edit forms whose data arrives after render) ─────────────────────────────
 *
 * The loading overlay is STATE (_loading), not a DOM operation: set_loading() flips it
 * and syncs immediately, and on_render() re-syncs - renders rebuild the DOM, so state
 * is the only thing an overlay can survive on. While loading, submit() refuses: a
 * half-populated form must never serialize, because blank is a value and would validly
 * clear unfetched fields.
 *
 * CLEARING IS EXPLICIT, owned by whoever set it - never automatic. on_ready() is the
 * WRONG hook by definition (ready fires after on_load completes and children are
 * ready: "fully loaded" is precisely when a loader must already be gone), and a
 * data-set auto-clear misfires on partial programmatic vals() writes during load. The
 * two sanctioned owners: populate() clears in its finally; the SPA page pattern's
 * on_render wiring passes a flag that is false on the seeded render. A forgotten
 * clear is a permanently overlaid form - loud and fixed in minutes; a wrong auto-clear
 * is a briefly-blank editable form - the silent kind of bug this contract exists to
 * kill.
 *
 * ── Errors ───────────────────────────────────────────────────────────────────────────
 *
 * All error rendering goes through Form_Utils - one renderer for component forms and
 * bare-markup pages alike. Validation failures pin messages under their fields and
 * ALWAYS render the top alert; the form scrolls its feedback into view. When the form
 * contains Rsx_Tabs, tab badges update and the first erroring tab activates.
 *
 * ── Dirty protection ─────────────────────────────────────────────────────────────────
 *
 * The user's keystrokes always win. Every user 'input' marks that name dirty; applying
 * data (the $data seed, vals(object)) skips dirty names, so a revalidation re-render
 * updates untouched fields and leaves in-progress edits alone. Across component
 * re-creation (an SPA action re-rendering on fresh data) dirty values are parked on
 * the live SPA action - keyed by controller|method|record-id - and re-applied to the
 * successor instance. Parking is silent, cleared on successful submit, and dies with
 * the action on navigation. To programmatically overwrite a dirty field, address the
 * input directly: form.input(name).val(v).
 */
class Rsx_Form extends Component {
    on_create() {
        this.state = {
            values: {}, // Seed values {name: value} - applied in on_ready()
            tabs: null, // Rsx_Tabs component when the form contains one
        };

        this._dirty = {}; // {name: value} - fields the user has touched
        this._submitting = false;
        this._loading = false;

        /**
         * Optional payload hook, installed by the host (Modal.form's before_submit
         * option, or a page action). Receives the serialized vals; may return an
         * adjusted object, or throw a {field: message} object rendered as validation.
         */
        this.before_submit = null;

        // Parse the $data seed (object, or JSON string when passed through Blade)
        let data = this.args.data;

        if (typeof data === 'string') {
            try {
                const decoded = $('<textarea>').html(data).text();
                data = json_decode(decoded);
            } catch (e) {
                console.error('Rsx_Form: failed to parse $data JSON string', e);
                data = {};
            }
        }

        if (data && typeof data === 'object') {
            this.state.values = data;
        }

        /**
         * The parking key, computed ONCE and never again.
         *
         * It must not be re-derived later: the record id comes from the seed values,
         * and an edit form that starts empty and is filled by populate() would park
         * under one key and clear under another - leaving the stale slot to re-apply
         * itself to the next instance forever.
         */
        this._parking_key = '__rsxform_' + this.args.controller + '|' + this.args.method
            + '|' + ((this.state.values && this.state.values.id) ? this.state.values.id : 'new');

        // Reclaim any values parked by a predecessor instance (see class docblock).
        const parked = this._parking();
        if (parked) {
            Object.assign(this._dirty, parked);
        }
    }

    on_render() {
        const that = this;

        // Tabs integration (error badges + auto-switch), when the form contains one.
        const tabs_el = this.$.find('.Rsx_Tabs').first();
        if (tabs_el.length) {
            this.state.tabs = tabs_el.component();
        }

        // Auto-wire in-form submit buttons to the one pipeline.
        this.$.find('button[type="submit"]').each(function () {
            $(this).on('click', function (e) {
                e.preventDefault();
                that.submit();
            });
        });

        // Renders rebuild the DOM: whatever the loading state says, redraw it.
        this._sync_loading_overlay();
    }

    /**
     * Apply seed values and wire dirty tracking.
     *
     * MUST run in on_ready(), not on_render(): every input is typically wrapped in a
     * Form_Field, so at on_render() time the input components are not guaranteed to
     * exist and a val() written to them would not stick. on_ready()'s contract is
     * "all children are ready".
     */
    on_ready() {
        const that = this;

        // Seed - dirty names win (vals() skips them), including values reclaimed from
        // a predecessor instance.
        this.vals(this.state.values);
        if (Object.keys(this._dirty).length) {
            this._apply_dirty();
        }

        // Dirty tracking: every user interaction marks its field and parks the value.
        this.$.shallowFind('.Form_Input_Abstract').each(function () {
            const $input = $(this);
            const component = $input.component();
            const name = $input.attr('data-name');
            if (!component || !name || !('on' in component)) {
                return;
            }
            component.on('input', function (comp, value) {
                that._dirty[name] = value;
                const parking = that._parking(true);
                if (parking) {
                    parking[name] = value;
                }
                that.trigger('input', name, value);
            });
        });
    }

    /**
     * The form's error container: the <Form_Errors /> the form's content placed where
     * the layout wants it. Placement belongs to the FORM AUTHOR - the structural
     * containers (sections, grids) give the alert their own spacing, which no
     * engine-chosen position could. A form without one is an authoring error and
     * fails loud at the moment feedback is needed, never by swallowing the message.
     */
    _$errors() {
        const $container = this.$.find('.Form_Errors').first();

        if (!$container.length) {
            throw new Error(
                'Rsx_Form: no <Form_Errors /> in this form. Every <Rsx_Form> places ' +
                'exactly one <Form_Errors /> where the layout wants its failure feedback.'
            );
        }

        return $container;
    }

    // =========================================================================
    // Values
    // =========================================================================

    /**
     * Serialize (no argument) or apply (object argument) form values.
     *
     * Serializing collects every descendant input's val() - shallow, so inputs inside
     * a composite input (a Repeater) belong to that composite - plus native hidden
     * inputs. Applying sets each named input present in the object, SKIPPING dirty
     * names: data never clobbers the user (see class docblock).
     *
     * @param {Object} [values] - Values to apply. Omit to serialize.
     * @returns {Object|null} The serialized values, or null when applying.
     */
    vals(values) {
        if (values) {
            const that = this;
            this.$.shallowFind('.Form_Input_Abstract').each(function () {
                const $input = $(this);
                const name = $input.attr('data-name');
                if (name && name in values && !(name in that._dirty)) {
                    $input.component().val(values[name]);
                }
            });

            return null;
        }

        const data = {};

        this.$.shallowFind('.Form_Input_Abstract').each(function () {
            const $input = $(this);
            const name = $input.attr('data-name');
            if (name) {
                data[name] = $input.component().val();
            }
        });

        this.$.find('input[type="hidden"][name]').each(function () {
            const $native = $(this);
            const name = $native.attr('name');
            if (name) {
                data[name] = $native.val();
            }
        });

        return data;
    }

    /**
     * The input component with this $name, or null. The sanctioned way to reach one
     * input for wiring ('form.input("due_date").on("val", ...)') or conditional logic.
     *
     * @param {string} name
     * @returns {Component|null}
     */
    input(name) {
        const $input = this.$.shallowFind(`.Form_Input_Abstract[data-name="${name}"]`).first();
        return $input.exists() ? $input.component() : null;
    }

    /** Apply reclaimed dirty values directly (bypasses the dirty skip - they ARE the dirty values). */
    _apply_dirty() {
        for (const name in this._dirty) {
            const component = this.input(name);
            if (component) {
                component.val(this._dirty[name]);
            }
        }
    }

    // =========================================================================
    // Submission
    // =========================================================================

    /**
     * Submit the form. The one pipeline - see the class docblock for the stages.
     *
     * @returns {Promise<Object|false>} The server result on success; false on any failure.
     */
    async submit() {
        if (this._submitting) {
            return false;
        }

        // A loading form must not serialize: half-populated fields would submit as
        // VALUES (blank is a value - see the class docblock) and validly clear data
        // the fetch had not reached yet. The overlay blocks clicks; this guard blocks
        // the Enter key and programmatic callers.
        if (this._loading) {
            return false;
        }

        if (!this.args.controller || !this.args.method) {
            throw new Error(
                'Rsx_Form: $controller and $method are required on the <Rsx_Form> tag - ' +
                'the form declaration is the only place the endpoint is named.'
            );
        }

        this._clear_errors();

        // Client validation pass - before any network traffic.
        const client_errors = this._run_client_validation();
        if (client_errors) {
            await this.render_error({ code: Ajax.ERROR_VALIDATION, metadata: client_errors });
            this.trigger('submit_error', client_errors);
            return false;
        }

        let values = this.vals();

        // Host payload hook. A thrown {field: message} object renders as validation;
        // returning FALSE aborts the submit silently - the seam for interaction
        // guards (an "are you sure?" confirm the user declined is not an error).
        if (typeof this.before_submit === 'function') {
            try {
                const adjusted = await this.before_submit(values, this);
                if (adjusted === false) {
                    return false;
                }
                if (adjusted && typeof adjusted === 'object') {
                    values = adjusted;
                }
            } catch (hook_error) {
                // The hook may throw three shapes: a {field: message} map (rendered as
                // validation), an Error, or a bare value. Type, not existence.
                const metadata = (typeof hook_error === 'object' && hook_error !== null && !(hook_error instanceof Error))
                    ? hook_error
                    : { _message: String(hook_error instanceof Error ? hook_error.message : hook_error) };
                await this.render_error({ code: Ajax.ERROR_VALIDATION, metadata });
                this.trigger('submit_error', metadata);
                return false;
            }
        }

        this._submitting = true;
        const $submit_btns = this.$.find('button[type="submit"]');
        this._set_submitting($submit_btns, true);

        try {
            const result = await Ajax.call(`/_ajax/${this.args.controller}/${this.args.method}`, values);

            // Success: the user's edits are now the record - nothing left to protect.
            this._dirty = {};
            this._clear_parking();

            this.trigger('submitted', result);

            if (result && result.redirect) {
                // Navigating away - the spinner deliberately stays until the page goes.
                window.location.href = result.redirect;
                return result;
            }

            this._set_submitting($submit_btns, false);
            return result;
        } catch (error) {
            this._set_submitting($submit_btns, false);
            await this.render_error(error);
            this.trigger('submit_error', error);
            return false;
        } finally {
            this._submitting = false;
        }
    }

    /**
     * The _validate() pass - the ARCHITECTURAL exception, not a validation layer.
     *
     * Validation lives on the server, once (see the class docblock). This pass exists
     * solely for the rare input whose invalid state cannot even be EXPRESSED to the
     * server; when one fires, its failure renders through the same pipeline as a
     * server error so there is no second styling path. It never checks requiredness,
     * emptiness, formats, lengths or any rule the server can see for itself.
     *
     * {name: message} on any failure, else null.
     */
    _run_client_validation() {
        const errors = {};
        let failed = false;

        this.$.shallowFind('.Form_Input_Abstract').each(function () {
            const $input = $(this);
            const name = $input.attr('data-name');
            if (!name) {
                return;
            }
            const component = $input.component();
            if (!component || typeof component._validate !== 'function') {
                return;
            }
            const message = component._validate(component.val());
            if (typeof message === 'string' && message !== '') {
                errors[name] = message;
                failed = true;
            }
        });

        return failed ? errors : null;
    }

    /**
     * The submitting visual: Button_Utils' animated-dots treatment, and the buttons
     * are inert until the round-trip completes. Button_Utils ships with the framework
     * (applications override its presentation, never remove it), so it is called
     * directly - core classes are never existence-checked.
     */
    _set_submitting($btns, submitting) {
        if (submitting) {
            Button_Utils.set_submitting($btns);
        } else {
            Button_Utils.clear_submitting($btns);
        }
    }

    // =========================================================================
    // Loading overlay
    // =========================================================================

    /**
     * Populate an edit form from an async source - THE standard procedure for a form
     * whose data arrives after the form renders (edit modals; any fetch-after-render
     * host). Shows the loading overlay, awaits the data, applies it through vals(),
     * and hides the overlay in a finally - the overlay cannot leak.
     *
     * The canonical caller is the edit-modal body component: it starts the fetch in
     * its own on_create() (stashing the promise on the instance), arms the overlay in
     * on_render(), and hands the promise to populate() from a guarded on_ready():
     *
     *     // in the modal body component
     *     on_create() { this._fetch = Settings_Users_Controller.get_user_for_edit({ id: this.args.id }); }
     *     on_ready()  { this.get_form().populate(this._fetch); }
     *
     * NOT needed on a standard SPA edit page: the action's on_load() completes before
     * render, so the form never renders blank there.
     *
     * @param {Promise<Object>|Object} data - The record's form values (or a promise of them).
     * @returns {Promise<Object>} The applied values.
     */
    async populate(data) {
        this.set_loading(true);

        try {
            const values = await data;
            this.state.values = values && typeof values === 'object' ? values : {};
            this.vals(this.state.values);
            return values;
        } finally {
            this.set_loading(false);
        }
    }

    /**
     * The primitive under populate(): gray the form, block interaction, center the
     * registered spinner (a 150px host), and refuse submit() until loading ends.
     * Callers with an irregular load sequence drive this directly; everyone else uses
     * populate(), which cannot forget to hide it.
     *
     * @param {boolean} loading
     */
    set_loading(loading) {
        this._loading = !!loading;
        this._sync_loading_overlay();
    }

    /**
     * Make the DOM match the loading STATE. Called from set_loading() for an immediate
     * effect (the component has always rendered at least once by the time anyone can
     * call it) and from on_render() - renders REBUILD the DOM, so an overlay drawn
     * imperatively would silently die on every re-render; state redrawn each render is
     * the jqhtml way. The DOM itself is the record of whether an overlay exists - no
     * instance bookkeeping to go stale.
     */
    _sync_loading_overlay() {
        const $existing = this.$.children('.Rsx_Form__loading');

        if (this._loading) {
            if (!$existing.length) {
                const $overlay = $(
                    '<div class="Rsx_Form__loading">' +
                        '<div class="Rsx_Form__loading-spinner"></div>' +
                    '</div>'
                );
                this.$.append($overlay);
                $overlay.find('.Rsx_Form__loading-spinner').component(Rsx.get_default_spinner());

                console_debug('FORMS', 'loading overlay created for '
                    + this.args.controller + '.' + this.args.method);
            }
            return;
        }

        if ($existing.length) {
            console_debug('FORMS', 'loading overlay removed for '
                + this.args.controller + '.' + this.args.method);
            $existing.remove();
        }
    }

    // =========================================================================
    // Errors
    // =========================================================================

    /**
     * Render a submit failure. One renderer for everything: validation errors pin to
     * their fields and always raise the top alert (Form_Utils owns that contract);
     * every other error renders as a single alert in the error container. Both paths
     * scroll the feedback into view and update tab badges when tabs are present.
     *
     * @param {Object} error - The Ajax error ({code, metadata, message}) or a
     *                         client-built equivalent.
     */
    async render_error(error) {
        this._clear_errors();

        if (error && error.code === Ajax.ERROR_VALIDATION && error.metadata) {
            const metadata = error.metadata;
            const message = (metadata && typeof metadata === 'object' && metadata._message) || null;

            await Form_Utils.apply_form_errors(this.$, metadata, { message });

            if (this.state.tabs) {
                this.state.tabs.handle_validation_errors(metadata);
            }

            return;
        }

        // Non-validation failures (not_found, unauthorized, generic, network).
        Rsx.render_error(error, this._$errors());
        Form_Utils.scroll_to_errors(this.$);
    }

    /** Clear every rendered error: inline marks, messages, the alert, tab badges. */
    _clear_errors() {
        Form_Utils.reset_form_errors(this.$);
        this._$errors().empty();
        if (this.state.tabs) {
            this.state.tabs.clear_error_badges();
        }
    }

    // =========================================================================
    // Dirty parking (cross-instance survival - see class docblock)
    // =========================================================================

    /**
     * The parking slot for this form's dirty values on the live SPA action, or null
     * outside an SPA context. Keyed by endpoint + record identity (this._parking_key,
     * frozen in on_create) so two forms on one page never share a slot; create forms
     * key on 'new'.
     *
     * @param {boolean} [create] - Allocate the slot when absent.
     */
    _parking(create = false) {
        const action = typeof Spa !== 'undefined' ? Spa.action() : null;
        if (!action) {
            return null;
        }

        if (!action[this._parking_key] && create) {
            action[this._parking_key] = {};
        }

        return action[this._parking_key] || null;
    }

    _clear_parking() {
        const action = typeof Spa !== 'undefined' ? Spa.action() : null;
        if (!action) {
            return;
        }

        delete action[this._parking_key];
    }

    // =========================================================================
    // Debug seeding
    // =========================================================================

    /** Fill fields from their $seeder endpoints (debug tooling). */
    async seed() {
        const promises = [];
        this.$.shallowFind('.Form_Field').each(function () {
            const component = $(this).component();
            if (component && 'seed' in component) {
                promises.push(component.seed());
            }
        });
        await Promise.all(promises);
    }
}
