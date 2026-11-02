/**
 * _Sys_Companion_Detail - see the .jqhtml.
 */
class _Sys_Companion_Detail extends Component {
    /**
     * Show one companion in a dialog. Resolves when the dialog closes.
     *
     * @param {string} name
     * @returns {Promise<boolean>}
     */
    static async open(name) {
        const $body = $('<div>');
        $body.component('_Sys_Companion_Detail', { name: str(name) });

        return _Sys_Modal.show({
            title: 'Companion ' + name,
            body: $body,
            max_width: 1600,
            height: 'min(1075px, 80vh)',
            mobile_height: 'min(1075px, 90vh)',
            buttons: [
                { label: 'Close', value: true, class: 'btn-primary', default: true },
            ],
        });
    }

    on_create() {
        if (!this.args.name) {
            throw new Error('_Sys_Companion_Detail requires $name');
        }
    }
}
