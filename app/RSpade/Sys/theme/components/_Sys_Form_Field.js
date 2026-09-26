/**
 * _Sys_Form_Field
 *
 * See _Sys_Form_Field.jqhtml. Purely presentational; the one behaviour is the
 * accessibility wiring from the label to the input element, and the loud refusal
 * of a field that wraps no named input.
 */
class _Sys_Form_Field extends Component {
    on_ready() {
        const $input_component = this.$.find('.Form_Input_Abstract').first();

        if (!$input_component.exists()) {
            shouldnt_happen('_Sys_Form_Field has no Form_Input_Abstract child. Every field wraps exactly one panel input.');
        }

        if (!$input_component.attr('data-name')) {
            shouldnt_happen('_Sys_Form_Field child input has no data-name attribute. Pass $name to the input: <_Sys_Text_Input $name="field" />');
        }

        const $label = this.$sid('label');
        const $element = $input_component.find('input, select, textarea').first();

        if ($label.exists() && $element.exists()) {
            if (!$element.attr('id')) {
                $element.attr('id', '_sys_field_' + this._cid);
            }

            $label.attr('for', $element.attr('id'));
        }
    }
}
