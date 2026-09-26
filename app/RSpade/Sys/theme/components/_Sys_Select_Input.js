/**
 * _Sys_Select_Input
 *
 * See _Sys_Select_Input.jqhtml. A plain native select - the panel is a developer
 * tool and needs no search widget, so there is no async library and the input is
 * ready the moment it renders.
 */
class _Sys_Select_Input extends Form_Input_Abstract {
    /**
     * [{value, label}] from either accepted $options shape.
     *
     * @param {Array} options
     * @returns {Array<{value: string, label: string}>}
     */
    static normalize_options(options) {
        if (!Array.isArray(options)) {
            throw new Error('_Sys_Select_Input requires $options as an array.');
        }

        return options.map((opt) => (
            is_object(opt)
                ? { value: str(opt.value), label: str(opt.label ?? opt.value) }
                : { value: str(opt), label: str(opt) }
        ));
    }

    _get_value() {
        // A value matching no option leaves the select with no selection, which jQuery
        // reads as null; the form serializes that as blank, like the placeholder.
        return this.$sid('input').val() ?? '';
    }

    _set_value(value) {
        this.$sid('input').val(value === null || value === undefined ? '' : str(value));
    }

    on_render() {
        const that = this;

        this._mark_ready();

        this.$sid('input').on('change', function () {
            that._notify_input(that._get_value());
        });
    }
}
