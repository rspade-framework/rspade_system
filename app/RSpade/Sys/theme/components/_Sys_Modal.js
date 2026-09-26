/**
 * _Sys_Modal - the framework application's dialogs (the /_sys panel and the API console).
 *
 * FRAMEWORK-OWNED ON PURPOSE. The template app's Modal is application code, and the panel
 * may not borrow it (CONV-BUNDLE-04): an app is free to restyle it, replace it or ship none.
 * The API mirrors Modal's semantics so one mental model covers both:
 *
 *     await _Sys_Modal.alert('Saved.');                          // 1 arg = body
 *     await _Sys_Modal.alert('Saved', 'The flags are stored.');  // 2+ args = TITLE first
 *
 *     if (await _Sys_Modal.confirm('Disable site?', 'Its 4 members are signed out.', 'Disable')) { ... }
 *
 *     const answer = await _Sys_Modal.show({
 *         title: 'Run now?',
 *         body: 'The schedule is not moved.',
 *         buttons: [
 *             {label: 'Cancel', value: false},
 *             {label: 'Run', value: true, class: 'btn-primary', default: true},
 *         ],
 *     });
 *
 *     const result = await _Sys_Modal.form({
 *         title: 'Kill task',
 *         component: '_Sys_Kill_Task_Form',       // any component containing an <Rsx_Form>
 *         component_args: {task_id: 12},
 *         submit_label: 'Kill',
 *         on_success: (result, form) => Flash_Alert.success('Killed.'),
 *     });
 *
 * SEMANTICS (the same as Modal's):
 *   - Dismissing - the X, Escape, a backdrop click, a Cancel button - resolves FALSE.
 *   - A button resolves with its `value`, or with its `callback`'s return. Only a LITERAL
 *     false returned by a callback keeps the dialog open; null (or anything else) closes it.
 *   - ENTER ACCEPTS: it presses the button marked `default: true` - except inside a
 *     textarea, a select or a contenteditable, and on a focused button or link, where Enter
 *     already means something. In a form dialog the default button is Submit, so Enter
 *     submits through the dialog and never through the form's own submit event.
 *   - form() drives the hosted form's submit(): a failure keeps the dialog open with the
 *     errors already rendered by the form; success runs on_success, then closes resolving
 *     with the server result (true for an empty success). Cancel resolves false.
 *   - A body string is plain TEXT (escaped, newlines kept); pass a jQuery element for markup.
 *   - An SPA navigation closes every open dialog, resolving false.
 *
 * @Instantiatable
 */
class _Sys_Modal extends Component {
    /** Open instances, oldest first. Enter only ever reaches the newest. */
    static _open = [];

    static on_app_modules_init() {
        Rsx.on('spa_dispatch_start', () => _Sys_Modal.close_all());
    }

    /**
     * An acknowledgement: one OK button, resolves true (false when dismissed).
     *
     * @param {string} title_or_body The body alone, or the title when body follows
     * @param {string|null} [body]
     * @param {string} [button_label='OK']
     * @returns {Promise<boolean>}
     */
    static async alert(title_or_body, body = null, button_label = 'OK') {
        const parts = _Sys_Modal._title_and_body(title_or_body, body);

        return _Sys_Modal.show({
            title: parts.title,
            body: parts.body,
            buttons: [
                { label: button_label, value: true, class: 'btn-primary', default: true },
            ],
        });
    }

    /**
     * A yes/no question: resolves true on confirm, false on cancel or dismissal.
     *
     * @param {string} title_or_body The body alone, or the title when body follows
     * @param {string|null} [body]
     * @param {string} [confirm_label='Confirm']
     * @param {string} [cancel_label='Cancel']
     * @returns {Promise<boolean>}
     */
    static async confirm(title_or_body, body = null, confirm_label = 'Confirm', cancel_label = 'Cancel') {
        const parts = _Sys_Modal._title_and_body(title_or_body, body);

        return _Sys_Modal.show({
            title: parts.title,
            body: parts.body,
            buttons: [
                { label: cancel_label, value: false, class: 'btn-secondary' },
                { label: confirm_label, value: true, class: 'btn-primary', default: true },
            ],
        });
    }

    /**
     * A dialog with arbitrary buttons and no endpoint.
     *
     * @param {object} options
     * @param {string} [options.title]
     * @param {string|jQuery} [options.body] Text (escaped) or an element (appended)
     * @param {Array} [options.buttons] [{label, value, class, default, callback}]
     * @param {number} [options.max_width=500] Dialog width cap, px
     * @returns {Promise<*>} The pressed button's value/callback result; false on dismissal
     */
    static show(options) {
        return new Promise((resolve) => {
            const $host = $('<div>').appendTo(document.body);

            $host.component('_Sys_Modal', {
                title: options.title || '',
                body: options.body === undefined || options.body === null ? '' : options.body,
                buttons: options.buttons || [],
                max_width: options.max_width || 500,
                on_mount: options.on_mount || null,
                on_closed: resolve,
            });
        });
    }

    /**
     * A dialog hosting a component that contains an <Rsx_Form>, submitted by the dialog.
     *
     * @param {object} options
     * @param {string} options.title
     * @param {string} options.component Component name; it must contain an <Rsx_Form>
     * @param {object} [options.component_args]
     * @param {string} [options.submit_label='Submit']
     * @param {string} [options.cancel_label='Cancel']
     * @param {Function} [options.on_success] (result, form) - awaited before the dialog closes
     * @param {number} [options.max_width=640]
     * @returns {Promise<*>} The server result (true for an empty success); false on cancel
     */
    static form(options) {
        if (!options.component) {
            throw new Error('_Sys_Modal.form() requires a component.');
        }

        const $container = $('<div class="_Sys_Modal__form">');
        let hosted = null;

        return _Sys_Modal.show({
            title: options.title || '',
            body: $container,
            max_width: options.max_width || 640,
            on_mount: () => {
                $container.component(options.component, options.component_args || {});
                hosted = $container.component();
            },
            buttons: [
                { label: options.cancel_label || 'Cancel', value: false, class: 'btn-secondary' },
                {
                    label: options.submit_label || 'Submit',
                    class: 'btn-primary',
                    default: true,
                    callback: async () => {
                        const $form = hosted.$.hasClass('Rsx_Form') ? hosted.$ : hosted.$.find('.Rsx_Form').first();

                        if (!$form.exists()) {
                            shouldnt_happen(
                                `_Sys_Modal.form({component: '${options.component}'}) found no <Rsx_Form> inside that ` +
                                'component. A dialog with no form belongs on _Sys_Modal.show({buttons}).'
                            );
                        }

                        const form = $form.component();
                        const result = await form.submit();

                        // Validation or transport failure: the form has rendered it. Stay open.
                        if (result === false) {
                            return false;
                        }

                        if (options.on_success) {
                            await options.on_success(result, form);
                        }

                        return result ?? true;
                    },
                },
            ],
        });
    }

    /** Close every open dialog, each resolving false. */
    static close_all() {
        for (const dialog of _Sys_Modal._open.slice()) {
            dialog.close(false);
        }
    }

    /** The newest open dialog, or null. */
    static top() {
        return _Sys_Modal._open.length ? _Sys_Modal._open[_Sys_Modal._open.length - 1] : null;
    }

    /** Modal's overload: one argument is the body; two or more put the title first. */
    static _title_and_body(title_or_body, body) {
        if (body === null || body === undefined) {
            return { title: '', body: title_or_body };
        }

        return { title: title_or_body, body: body };
    }

    // ------------------------------------------------------------------ instance

    on_create() {
        this.state = { result: false, accepting: false, closing: false };
        this._is_shown = false;
        this._trigger_element = document.activeElement;
    }

    on_render() {
        const that = this;

        if (!is_string(this.args.body)) {
            this.$sid('body').empty().append(this.args.body);
        }

        this.$.off('click._sys_modal').on('click._sys_modal', '.modal-footer [data-index]', function () {
            that._press(int($(this).attr('data-index')));
        });
    }

    on_ready() {
        const that = this;

        this._bs = new bootstrap.Modal(this.$[0], { backdrop: true, keyboard: true, focus: true });

        // A dismissal Bootstrap performs itself (X, Escape, backdrop) leaves state.result
        // false, so the promise resolves false; marking it closing stops Enter mid-fade.
        this.$.on('hide.bs.modal', () => { that.state.closing = true; });
        this.$.on('shown.bs.modal', () => that._on_shown());
        this.$.on('hidden.bs.modal', () => that._on_hidden());

        // ENTER ACCEPTS THE DIALOG. Captured at the document so it works wherever focus sits
        // inside the dialog; only the newest open dialog answers.
        this._key_listener = (e) => that._on_key(e);
        document.addEventListener('keydown', this._key_listener, true);

        _Sys_Modal._open.push(this);

        if (this.args.on_mount) {
            this.args.on_mount(this);
        }

        this._bs.show();
    }

    /**
     * Close, resolving the dialog's promise with `result` once Bootstrap has finished
     * hiding it. A second close while one is in flight is ignored: Escape during the click
     * that already answered must not turn a yes into a no.
     */
    close(result) {
        if (this.state.closing) {
            return;
        }

        this.state.closing = true;
        this.state.result = result;

        // Bootstrap ignores hide() while the show transition is still running, so an answer
        // given during the fade-in is applied the moment the dialog finishes showing.
        if (this._is_shown) {
            this._bs.hide();
        }
    }

    async _on_shown() {
        this._is_shown = true;

        if (this.state.closing) {
            this._bs.hide();
            return;
        }

        const $default = this.$.find('[data-sys-modal-default]').first();
        const $form = this.$sid('body').children('._Sys_Modal__form');

        if (!$form.exists()) {
            if ($default.exists()) {
                $default.trigger('focus');
            }

            return;
        }

        // A form dialog: put the cursor in the first field once the form has rendered.
        const hosted = $form.component();

        if (hosted) {
            await hosted.ready();
            $form.find('input:not([type=hidden]), select, textarea').filter(':visible').first().trigger('focus');
        }
    }

    _on_hidden() {
        document.removeEventListener('keydown', this._key_listener, true);
        _Sys_Modal._open = _Sys_Modal._open.filter((dialog) => dialog !== this);

        this._bs.dispose();

        const result = this.state.result;
        const on_closed = this.args.on_closed;
        const trigger = this._trigger_element;

        this.stop();
        this.$.remove();

        if (trigger && document.body.contains(trigger)) {
            trigger.focus();
        }

        on_closed(result);
    }

    _on_key(e) {
        if (e.key !== 'Enter' || _Sys_Modal.top() !== this || this.state.closing) {
            return;
        }

        // Mid-composition Enter commits an IME candidate; it is not an answer.
        if (e.isComposing || e.keyCode === 229) {
            return;
        }

        if ($(e.target).is('textarea, select, button, a, [contenteditable], [contenteditable] *')) {
            return;
        }

        const $default = this.$.find('[data-sys-modal-default]').first();

        if (!$default.exists() || $default.prop('disabled')) {
            return;
        }

        // Stops the browser's implicit form submission, so the dialog's button is the one
        // submission path, and stops key repeat from pressing an async button twice.
        e.preventDefault();

        if (this.state.accepting) {
            return;
        }

        $default.trigger('click');
    }

    async _press(index) {
        const button = (this.args.buttons || [])[index];

        if (!button || this.state.accepting || this.state.closing) {
            return;
        }

        if (!button.callback) {
            this.close(button.value);
            return;
        }

        const $buttons = this.$.find('.modal-footer [data-index]');

        this.state.accepting = true;
        $buttons.prop('disabled', true);

        let result;

        try {
            result = await button.callback();
        } finally {
            this.state.accepting = false;
            $buttons.prop('disabled', false);

            // Disabling the focused button dropped focus to <body>, outside the dialog,
            // where Escape and Enter no longer reach it. Hand it back.
            if (!this.$[0].contains(document.activeElement)) {
                $buttons.eq(index).trigger('focus');
            }
        }

        // Only a literal false from a callback keeps the dialog open.
        if (result === false) {
            return;
        }

        this.close(result);
    }
}
