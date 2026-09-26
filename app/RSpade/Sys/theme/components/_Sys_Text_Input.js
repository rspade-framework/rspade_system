/**
 * _Sys_Text_Input
 *
 * See _Sys_Text_Input.jqhtml. The form-input contract: the base class owns val(),
 * buffering and events; this class maps the value to one <input>.
 */
class _Sys_Text_Input extends Form_Input_Abstract {
    on_create() {
        super.on_create();

        // The same rule the template's Text_Input states: a length limit is a
        // decision, so it is declared at every use - -1 is how "unlimited" is said.
        if (this.args.max_length === undefined) {
            throw new Error(
                `_Sys_Text_Input with $name="${this.args.name}" requires $max_length ` +
                '(Model.field_length(\'column\'), a number, or -1 for unlimited).'
            );
        }
    }

    _get_value() {
        return this.$sid('input').val();
    }

    _set_value(value) {
        this.$sid('input').val(value ?? '');
    }

    on_render() {
        const that = this;

        this._mark_ready();

        this.$sid('input').on('input', function () {
            that._notify_input(that._get_value());
        });
    }
}
