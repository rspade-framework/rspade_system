/**
 * Password_Input - one password field. See Password_Input.jqhtml.
 */
class Password_Input extends Form_Input_Abstract {
    on_render() {
        const that = this;
        const $field = this.$sid('field');

        // Rebound on every render: a render rebuilds the field.
        $field.off('.password_input').on('input.password_input', function () {
            that._notify_input(that._get_value());
        });

        this._mark_ready();
    }

    _get_value() {
        return str(this.$sid('field').val());
    }

    _set_value(value) {
        this.$sid('field').val(value === null || value === undefined ? '' : str(value));
    }

    _focus() {
        this.$sid('field').trigger('focus');
    }
}
