/**
 * Api_Confirm_Dialog - the docs console's own confirmation dialog.
 *
 * THE CONSOLE DEPENDS ONLY ON FRAMEWORK FILES. It is framework code mounted inside somebody
 * else's application, so it cannot call that application's Modal: an app may restyle it,
 * replace it wholesale, or ship none at all, and a console that borrowed it would fail on the
 * install that customised the most. Everything this dialog needs - markup, look, behaviour -
 * lives in these three files, beside the components that use it.
 *
 * ONE QUESTION, ONE ANSWER:
 *
 *   const ok = await Api_Confirm_Dialog.ask({
 *       title: 'Create a temporary API key?',
 *       body: 'This creates a real API key on your own user account.',
 *       confirm_label: 'Create Key',
 *       cancel_label: 'Cancel',
 *   });
 *
 * All four are required and the promise always settles to a boolean. There is no third
 * button, no custom body markup and no queue - this is a confirm, not a modal system, and a
 * second kind of question belongs to whichever feature needs it rather than here.
 *
 * @Instantiatable
 */
class Api_Confirm_Dialog extends Component {
    /**
     * Ask, and resolve to what the user answered.
     *
     * Mounted on the console ROOT rather than on <body> or on whatever was clicked. A confirm
     * nested inside the control it is asking about inherits that control's stacking and
     * overflow; hung off <body> it would sit outside .Api_Docs_Page, where the --api-*
     * palette is declared, and would have to restate the whole palette to paint itself.
     *
     * Focus is handed to the dialog on open (on_ready) and handed BACK to whatever had it
     * when the answer is in: a keyboard user who pressed "Temporary Key" with the keyboard is
     * returned to that button, not to the top of the document.
     */
    static async ask(options) {
        const trigger = document.activeElement;
        const $root = $('.Api_Docs_Page');

        if (!$root.exists()) {
            throw new Error('Api_Confirm_Dialog.ask() was called outside the API docs console.');
        }

        const $host = $('<div>').appendTo($root);

        const dialog = $host.component('Api_Confirm_Dialog', {
            title: options.title,
            body: options.body,
            confirm_label: options.confirm_label,
            cancel_label: options.cancel_label,
        }).component();

        const confirmed = await new Promise((resolve) => {
            dialog.on('answered', (comp, data) => resolve(data.confirmed));
        });

        dialog.stop();
        $host.remove();

        trigger?.focus?.();

        return confirmed;
    }

    on_create() {
        const args = this.args;

        // Fail loud: a dialog missing its question or its labels renders as blank furniture
        // the user cannot interpret, and an empty confirm button is a worse outcome than a
        // stack trace pointing at the call site.
        if (!args.title || !args.body || !args.confirm_label || !args.cancel_label) {
            throw new Error('Api_Confirm_Dialog requires title, body, confirm_label and cancel_label.');
        }

        this.state = { answered: false };
    }

    on_ready() {
        const that = this;

        this.$sid('confirm').on('click.acd', () => that._answer(true));
        this.$sid('cancel').on('click.acd', () => that._answer(false));

        // A click on the darkened surround is a cancel, the same as the Cancel button: it is
        // the gesture people already use to dismiss a dialog they did not mean to open.
        this.$sid('backdrop').on('click.acd', () => that._answer(false));

        // Captured at the document, so Escape works wherever focus happens to be - including
        // the console's own inputs, which stop the event on their way up.
        this._key_listener = (e) => that._on_key(e);
        document.addEventListener('keydown', this._key_listener, true);

        this.$sid('confirm').trigger('focus');
    }

    on_stop() {
        document.removeEventListener('keydown', this._key_listener, true);
    }

    /**
     * Escape cancels; Tab cycles between the two buttons.
     *
     * The cycle keeps a keyboard user inside a dialog that is asking them a question, but
     * Escape is always live, so it is a loop the user can leave at any moment rather than a
     * trap - the dialog never becomes the only thing on the page that answers a key.
     */
    _on_key(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            this._answer(false);
            return;
        }

        if (e.key !== 'Tab') {
            return;
        }

        const buttons = [this.$sid('cancel')[0], this.$sid('confirm')[0]];
        const index = buttons.indexOf(document.activeElement);
        const next = e.shiftKey ? index - 1 : index + 1;

        e.preventDefault();
        buttons[(next + buttons.length) % buttons.length].focus();
    }

    /**
     * Settle the question once. A second answer is ignored rather than broadcast: Escape
     * during the click that already confirmed must not turn a yes into a no.
     */
    _answer(confirmed) {
        if (this.state.answered) {
            return;
        }

        this.state.answered = true;
        this.trigger('answered', { confirmed: confirmed });
    }
}
