/**
 * _Sys_Checkbox_Input
 *
 * See _Sys_Checkbox_Input.jqhtml.
 */
class _Sys_Checkbox_Input extends Form_Input_Abstract {
    on_create() {
        super.on_create();

        this.checked_value = this.args.checked_value ?? '1';
        this.unchecked_value = this.args.unchecked_value ?? '0';
    }

    _get_value() {
        return this.$sid('input').prop('checked') ? this.checked_value : this.unchecked_value;
    }

    _set_value(value) {
        const checked = value === true
            || value === 1
            || str(value) === '1'
            || str(value) === str(this.checked_value);

        this.$sid('input').prop('checked', checked);
    }

    on_render() {
        const that = this;
        const $input = this.$sid('input');

        this._mark_ready();

        // The label toggles the box. The input's id is the one $sid gave it (and the
        // one $sid finds it by), so the label points at that rather than replacing it.
        this.$sid('label').attr('for', $input.attr('id'));

        $input.on('change', function () {
            that._notify_input(that._get_value());
        });
    }
}
