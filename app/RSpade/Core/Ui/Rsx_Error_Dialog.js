/**
 * Rsx_Error_Dialog - see Rsx_Error_Dialog.jqhtml for the contract.
 */
class Rsx_Error_Dialog extends Component {
    /**
     * Show an Ajax Error as the transport rejected it: its message, plus the PHP origin
     * (file, line, trace) when the server sent one - only ever to a developer caller.
     *
     * @param {Error} error
     * @returns {Promise<void>} Settles when the dialog is closed
     */
    static show_error(error) {
        const message = error && error.message ? error.message : 'An unexpected error occurred. Please try again.';
        let detail = null;

        if (error && error.code === Ajax.ERROR_PHP_EXCEPTION && error.metadata && error.metadata.file) {
            const lines = [error.metadata.file + ':' + error.metadata.line];

            for (const frame of error.metadata.backtrace || []) {
                const where = (frame.class ? frame.class + (frame.type || '::') : '') + frame.function + '()';
                lines.push('  ' + frame.file + ':' + frame.line + '  ' + where);
            }

            detail = lines.join('\n');
        }

        return Rsx_Error_Dialog.show({ title: 'Error', message: message, detail: detail });
    }

    /**
     * Show the dialog over the page.
     *
     * @param {{title: string, message: string, detail: (string|null|undefined)}} options
     * @returns {Promise<void>} Settles when the dialog is closed
     */
    static show(options) {
        return new Promise((resolve) => {
            $('<div>').appendTo(document.body).component('Rsx_Error_Dialog', {
                title: options.title,
                message: options.message,
                detail: options.detail || null,
                on_close: resolve,
            });
        });
    }

    on_create() {
        this.state.closed = false;

        // This instance's own document event namespace: several dialogs may be open at once.
        this.state.event_namespace = 'rsx_error_dialog_' + Rsx.uid();
    }

    on_render() {
        const that = this;

        this.$.attr({ role: 'alertdialog', 'aria-modal': 'true' });
        this.$sid('close').off('click.rsx_error_dialog').on('click.rsx_error_dialog', function () {
            that.close();
        });
    }

    on_ready() {
        const that = this;

        $(document).on('keydown.' + this.state.event_namespace, function (event) {
            if (event.key === 'Escape') {
                that.close();
            }
        });

        this.$sid('close').trigger('focus');
    }

    on_stop() {
        $(document).off('keydown.' + this.state.event_namespace);
    }

    /**
     * Close the dialog and settle show()'s promise.
     */
    close() {
        if (this.state.closed) {
            return;
        }
        this.state.closed = true;

        this.$.remove();
        this.args.on_close();
    }
}
