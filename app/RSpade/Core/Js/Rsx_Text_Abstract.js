/**
 * Rsx_Text_Abstract - the browser half of a declared TEXT column type.
 *
 * The PHP class of the SAME NAME owns the encoding: what the value means, how it is
 * filtered on write, how it reads as plain text, how it renders into a server-generated
 * document. None of that is repeated here, because implementing one algorithm twice in
 * two languages produces two sources of truth and no way to tell which drifted.
 *
 * What the browser owns is presentation, and it is pure registration:
 *
 *     class Rich_Text extends Rsx_Text_Abstract {
 *         static PRINTER = 'Rich_Text_Display';
 *         static EDITOR  = 'Wysiwyg_Input';
 *         static EDITOR_ARGS = { rows: 8 };
 *     }
 *
 * PRINTER is the jqhtml component that renders the value on a live page; EDITOR is the
 * input component that edits it. A type may also declare `static filter_set(raw)` to
 * clean a value on the way out of its editor - defense in depth beside the server's
 * authoritative filter, never a replacement for it.
 *
 * ── Why there is no to_text() ────────────────────────────────────────────────────────
 *
 * Asking what a block of rich text "is as a string" is a question the browser often
 * cannot answer: resolving an entity tag to a person's name needs the database. Where a
 * type offers to_text() at all - it is an optional convention, not a requirement - it
 * lives on the server, which can answer synchronously, and the wire carries only the raw
 * form.
 *
 * toString() therefore THROWS rather than being left undefined - undefined would yield
 * '[object Object]' in a template literal or an attribute, which is a silent wrong render
 * and precisely the failure this system exists to remove. A different rendition on a live
 * page is an argument to the PRINTER component, not a string conversion.
 *
 * @Instantiatable
 */
class Rsx_Text_Abstract {
    /**
     * @param {string} raw - the value exactly as the column stores it
     * @param {boolean|null} server_empty - the server's emptiness answer, when this value
     *                                      came off the wire. See is_empty().
     */
    constructor(raw, server_empty = null) {
        this._raw = raw;
        this._server_empty = server_empty;
    }

    /**
     * Hydrate from a {__TEXT, raw, empty} envelope.
     *
     * @param {Object} envelope
     * @returns {Rsx_Text_Abstract|null}
     */
    static from_wire(envelope) {
        if (envelope.raw === null || envelope.raw === undefined) {
            return null;
        }

        return new this(String(envelope.raw), envelope.empty ?? null);
    }

    /**
     * Hydrate from a bare storage string.
     *
     * @param {string|null} raw
     * @returns {Rsx_Text_Abstract|null}
     */
    static from_storage(raw) {
        if (raw === null || raw === undefined) {
            return null;
        }

        return new this(String(raw));
    }

    /**
     * Whether the value carries no content.
     *
     * A value from the server answers with the SERVER's verdict, which is authoritative:
     * only the server can reduce a type whose plain rendition needs a database lookup. A
     * value built locally (straight out of an editor) has no server answer yet and falls
     * back to _local_is_empty(), which a type overrides when it can decide for itself.
     *
     * @returns {boolean}
     */
    is_empty() {
        if (this._server_empty !== null) {
            return this._server_empty;
        }

        return this._local_is_empty();
    }

    /**
     * A type's own emptiness test, for a value that has not been to the server.
     *
     * The default is a whitespace check on the raw form - correct for any encoding whose
     * storage form IS its text. A type whose encoding wraps its content (HTML, a tagged
     * notation) overrides this; a type that genuinely cannot decide locally may return
     * false and let the server settle it on the next round trip.
     *
     * @returns {boolean}
     */
    _local_is_empty() {
        return this._raw.trim() === '';
    }

    /**
     * The value as the column stores it. This is what an editor loads and what an editor
     * hands back - editing always works on the raw form.
     *
     * @returns {string}
     */
    to_storage() {
        return this._raw;
    }

    /**
     * The client-side write filter, applied by an editor before constructing a value.
     * Defense in depth: the server filters authoritatively on every write regardless.
     *
     * @param {string} raw
     * @returns {string}
     */
    static filter_set(raw) {
        return raw;
    }

    /**
     * Construct from an editor's raw output, through the client-side filter.
     *
     * @param {string} raw
     * @returns {Rsx_Text_Abstract}
     */
    static from_editor(raw) {
        return new this(this.filter_set(String(raw ?? '')));
    }

    /**
     * The wire envelope, browser to server.
     *
     * `empty` travels because the server holds the value TYPELESS until it reaches a
     * column, and cannot compute encoding-aware emptiness itself at the boundary - this
     * class can, with the real type at hand. `__TEXT` travels as a consistency check the
     * server may perform and an echo-back hint; it is never used to choose a type there.
     *
     * @returns {Object}
     */
    toJSON() {
        return { __TEXT: this.constructor.name, raw: this._raw, empty: this.is_empty() };
    }

    /**
     * Refuses string coercion, loudly. See the class docblock.
     */
    toString() {
        // Deliberately does not read PRINTER: an undeclared PRINTER throws on its own,
        // and that throw firing from inside this message would mask the real mistake.
        shouldnt_happen(
            `${this.constructor.name} cannot be converted to a string in the browser. ` +
            `Render it with its PRINTER component, or read its raw form with to_storage() ` +
            `if you are feeding an editor.`
        );
    }

    /**
     * The jqhtml component that renders this type on a live page.
     * Declared by every concrete type: `static PRINTER = 'Rich_Text_Display';`
     */
    static PRINTER = null;

    /**
     * The input component that edits this type.
     * Declared by every concrete type: `static EDITOR = 'Wysiwyg_Input';`
     */
    static EDITOR = null;

    /**
     * Arguments the EDITOR component is invoked with by default. A call site may add to
     * or override these per invocation.
     */
    static EDITOR_ARGS = {};

    /**
     * Register the framework's value printer with jqhtml.
     *
     * ONE printer covers every text type: it recognises "is this one of ours" and then
     * delegates to the type itself. jqhtml's chain exists so unrelated consumers can
     * coexist - an application with value objects of its own registers beside this one and
     * neither needs to know about the other - but the framework occupies exactly one slot
     * and dispatches internally from there.
     *
     * Declining with `undefined` is what makes that coexistence work: a value this
     * framework does not own falls through to the next printer, and only when EVERY
     * printer has declined does jqhtml throw.
     */
    static _on_framework_modules_define() {
        jqhtml.add_object_printer(function (value) {
            if (!(value instanceof Rsx_Text_Abstract)) {
                return undefined;
            }

            return value.constructor.print(value);
        });
    }

    /**
     * How this type renders at an interpolation site.
     *
     * The default mounts the type's PRINTER component and hands it the value - which is
     * what a type whose rendition is more than plain text needs, since a returned STRING is
     * escaped by `<%= %>` exactly as a string literal would be and therefore cannot carry
     * markup of its own.
     *
     * A type whose display genuinely is plain text may override this to return that string
     * instead, and skip mounting a component per value - which matters in a list, where a
     * component per cell is a component per row.
     *
     * @param {Rsx_Text_Abstract} value
     * @returns {Object|string}
     */
    static print(value) {
        return { component: { name: this.printer_component(), args: { value: value } } };
    }

    /**
     * PRINTER, checked.
     *
     * The check lives in a METHOD and not in a getter on PRINTER itself, which is where it
     * naturally wants to be. Jqhtml_Integration._on_framework_modules_define() walks
     * Object.getOwnPropertyNames() of every registered class and READS each value to tag
     * static methods with a cache id - so a static getter that throws is not a declaration
     * check, it is a boot failure for the entire application.
     *
     * @returns {string}
     */
    static printer_component() {
        if (!this.PRINTER) {
            shouldnt_happen(`${this.name} must declare: static PRINTER = '<Component_Name>';`);
        }

        return this.PRINTER;
    }

    /**
     * EDITOR, checked. See printer_component() for why this is a method.
     *
     * @returns {string}
     */
    static editor_component() {
        if (!this.EDITOR) {
            shouldnt_happen(`${this.name} must declare: static EDITOR = '<Input_Component_Name>';`);
        }

        return this.EDITOR;
    }
}
