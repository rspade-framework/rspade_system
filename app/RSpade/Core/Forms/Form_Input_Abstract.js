/**
 * Form_Input_Abstract - the base class every form input extends.
 *
 * An input is a component that owns ONE named value. This class owns the whole value
 * contract so a concrete input only describes how its value maps to its DOM:
 *
 *   class My_Input extends Form_Input_Abstract {
 *       _get_value()      { return this.$sid('input').val(); }          // REQUIRED
 *       _set_value(value) { this.$sid('input').val(value ?? ''); }      // REQUIRED
 *       _validate(value)  { return null; }                              // optional
 *
 *       on_render() {
 *           this._mark_ready();   // at the earliest moment _set_value() would stick
 *
 *           const that = this;
 *           this.$sid('input').on('input', function () {
 *               that._notify_input(that._get_value());
 *           });
 *       }
 *   }
 *
 * NEVER override val(). It is where buffering and events live; different read/write
 * behaviour is expressed through _get_value()/_set_value().
 *
 * ── Buffering: settable before ready, always ─────────────────────────────────────────
 *
 * val(v) may be called at ANY point in the component's life - before the option list
 * has been fetched, before the underlying library has instantiated, before the DOM
 * exists. The value is buffered and applied at _mark_ready(). The pending slot is
 * tracked with a BOOLEAN flag, so null, 0, false and '' are all faithfully buffered -
 * null is a legitimate value (most nullable columns), not a sentinel.
 *
 * While a value is pending, val() as a getter returns it, so a form serialized before
 * every input finished initializing still reports what was set.
 *
 * ── Lifecycle: editable as soon as possible ──────────────────────────────────────────
 *
 * Initialize in on_render() whenever the input's DOM is self-contained; use on_ready()
 * only when initialization genuinely depends on child components or an async library.
 * Call _mark_ready() at the earliest moment a programmatic _set_value() would stick -
 * for a plain text input that is on_render(); for a TomSelect-backed input it is after
 * the library instantiates; for a server-fed select it is after the options arrive.
 *
 * ── Events ───────────────────────────────────────────────────────────────────────────
 *
 *   'input'  the USER changed the value through the UI. Fired only via
 *            _notify_input(value) - concrete classes call the helper on user
 *            interaction and never hand-trigger the pair.
 *   'val'    the value changed, by any path (user or programmatic val(v)).
 *
 * _mark_ready() applying a buffered value fires 'val' (the value did change). With
 * nothing buffered it fires NOTHING - readiness is not a value change, and listeners
 * wired to "on change" must not fire on load.
 *
 * ── _validate(): the architectural exception, NOT a validation layer ─────────────────
 *
 * Validation lives on the server, once - a client check that duplicates a server rule
 * masks the absence of the server rule, and the gap surfaces only when a script hits
 * the endpoint directly (see Rsx_Form's docblock for the full reasoning). _validate()
 * exists solely for a constraint whose invalid state cannot even be EXPRESSED to the
 * server: a pick-at-most-2 multiselect, a structured value that cannot serialize when
 * malformed. It returns null (valid) or a message string, rendered through the same
 * pipeline as a server error. Never use it for required/format/length rules - and
 * prefer interaction design (the widget refusing the invalid state) even where it is
 * legitimate.
 *
 * ── Naming ───────────────────────────────────────────────────────────────────────────
 *
 * $name is required on every input that participates in a form. It is stamped as
 * data-name on the component root; that attribute is how Rsx_Form discovers inputs and
 * how the error renderer targets them. data-name is a live contract attribute, not a
 * debug attribute.
 *
 * @Instantiatable
 */
class Form_Input_Abstract extends Component {
    /**
     * Initialize buffering state and stamp data-name.
     * Concrete classes that override on_create() must call super.on_create().
     */
    on_create() {
        this._pending_value = undefined;
        this._has_pending = false;
        this._is_ready = false;

        if (this.args.name) {
            this.$.attr('data-name', this.args.name);
        }
    }

    /**
     * Get or set the value.
     *
     * @param {*} [value] - When provided, sets the value (buffered until ready).
     *                      When omitted, returns the current value.
     * @returns {*} The current value when called as a getter.
     */
    val(value) {
        if (arguments.length === 0) {
            // Getter: a pending value IS the value until it lands.
            if (this._has_pending) {
                return this._pending_value;
            }
            return this._get_value();
        }

        // Setter
        if (this._is_ready) {
            this._set_value(value);
            this.trigger('val', value);
        } else {
            this._pending_value = value;
            this._has_pending = true;
        }
    }

    /**
     * User-interaction notifier. Concrete classes call this - and only this - when the
     * user changes the value through the UI. Fires 'input' then 'val', in that order.
     *
     * @param {*} value - The value after the interaction.
     */
    _notify_input(value) {
        this.trigger('input', value);
        this.trigger('val', value);
    }

    /**
     * Mark the input ready and apply any buffered value.
     *
     * Call at the earliest moment the component can accept a value. For inputs with
     * async initialization (TomSelect, Quill, a server-fetched option list), call
     * AFTER that initialization completes.
     */
    _mark_ready() {
        this._is_ready = true;

        if (this._has_pending) {
            const value = this._pending_value;
            this._pending_value = undefined;
            this._has_pending = false;
            this._set_value(value);
            this.trigger('val', value);
        }
        // Nothing pending: no event. Readiness is not a value change.
    }

    /**
     * The architectural exception (see the class docblock - never a duplicate of a
     * server rule). Return null when valid, or a user-facing message string. The
     * default input has no rule, and almost every input should keep it that way.
     *
     * @param {*} value - The value about to be submitted.
     * @returns {string|null}
     */
    _validate(value) {
        return null;
    }

    /**
     * Read the current value from the DOM/library. REQUIRED.
     * @returns {*}
     */
    _get_value() {
        throw new Error(`${this.constructor.name} must implement _get_value()`);
    }

    /**
     * Write a value to the DOM/library. REQUIRED.
     * @param {*} value
     */
    _set_value(value) {
        throw new Error(`${this.constructor.name} must implement _set_value()`);
    }
}
