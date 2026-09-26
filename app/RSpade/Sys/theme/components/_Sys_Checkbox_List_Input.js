/**
 * _Sys_Checkbox_List_Input
 *
 * See _Sys_Checkbox_List_Input.jqhtml. A multi-select as plain checkboxes - the panel
 * is a developer tool and needs no search widget, so the input is ready the moment it
 * renders.
 */
class _Sys_Checkbox_List_Input extends Form_Input_Abstract {
    /**
     * [{value, label, hint}] from either accepted $options shape.
     *
     * @param {Array} options
     * @returns {Array<{value: string, label: string, hint: string|null}>}
     */
    static normalize_options(options) {
        if (!Array.isArray(options)) {
            throw new Error('_Sys_Checkbox_List_Input requires $options as an array.');
        }

        return options.map((opt) => (
            is_object(opt)
                ? { value: str(opt.value), label: str(opt.label ?? opt.value), hint: opt.hint ? str(opt.hint) : null }
                : { value: str(opt), label: str(opt), hint: null }
        ));
    }

    _get_value() {
        const values = [];

        this.$.find('._Sys_Checkbox_List_Input__box').each(function () {
            const $box = $(this);
            if ($box.prop('checked')) {
                values.push(str($box.val()));
            }
        });

        return values;
    }

    _set_value(value) {
        const wanted = Array.isArray(value) ? value.map((v) => str(v)) : [];

        this.$.find('._Sys_Checkbox_List_Input__box').each(function () {
            const $box = $(this);
            $box.prop('checked', wanted.includes(str($box.val())));
        });
    }

    on_render() {
        const that = this;

        this._mark_ready();

        this.$.off('change._sys_cbl').on('change._sys_cbl', '._Sys_Checkbox_List_Input__box', function () {
            that._notify_input(that._get_value());
        });

        this.$.off('click._sys_cbl').on('click._sys_cbl', '[data-action="all"], [data-action="none"]', function () {
            const $button = $(this);
            const checked = $button.attr('data-action') === 'all';

            that.$.find('._Sys_Checkbox_List_Input__box:not(:disabled)').prop('checked', checked);
            that._notify_input(that._get_value());
        });
    }
}
