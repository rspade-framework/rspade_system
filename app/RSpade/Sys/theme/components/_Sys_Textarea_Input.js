/**
 * _Sys_Textarea_Input
 *
 * See _Sys_Textarea_Input.jqhtml. Same contract as _Sys_Text_Input, over a
 * <textarea>.
 */
class _Sys_Textarea_Input extends Form_Input_Abstract {
    on_create() {
        super.on_create();

        if (this.args.max_length === undefined) {
            throw new Error(
                `_Sys_Textarea_Input with $name="${this.args.name}" requires $max_length ` +
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
