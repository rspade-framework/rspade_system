/**
 * Money_Input - an amount of money. See Money_Input.jqhtml.
 *
 * THE VALUE CONTRACT: val() returns
 *   - an Rsx_Money, when the box holds text Rsx_Money.from_text() reads;
 *   - null, when the box is blank (whitespace only included);
 *   - the typed STRING, untouched, when the text does not parse.
 *
 * The third state is deliberate: answering null for a typo would submit "no value" - a silent
 * change - while the string reaches the endpoint, whose Model::money_from_submitted() runs the
 * same parser and refuses it with its own message, shown by <Form_Errors />. So the input
 * validates nothing itself (no _validate()): every state it can hold is one the server can be
 * told. Typing is never filtered (no rsx_numeric(), which drops '-' and parentheses and
 * truncates extra places).
 *
 * val(v) takes an Rsx_Money or null (static ACCEPTS); a form seed is never ''.
 */
class Money_Input extends Form_Input_Abstract {
    static ACCEPTS = 'Rsx_Money';

    on_render() {
        const that = this;
        const $field = this.$sid('field');

        // Rebound on every render: a render rebuilds the field.
        $field.off('.money_input')
            .on('input.money_input', function () {
                that._notify_input(that._get_value());
            })
            .on('blur.money_input', function () {
                // A display tidy, not an edit: the amount is unchanged, so no 'input' event.
                const value = that._get_value();
                if (value instanceof Rsx_Money) {
                    $field.val(value.to_input_text());
                }
            });

        this._mark_ready();
    }

    _get_value() {
        const text = str(this.$sid('field').val());

        if (Rsx_Money._trim_ws(text) === '') {
            return null;
        }

        try {
            return Rsx_Money.from_text(text);
        } catch (e) {
            if (e instanceof Rsx_Money_Exception) {
                // Not an amount: the typed text is the value, for the server to refuse.
                return text;
            }
            throw e;
        }
    }

    _set_value(value) {
        this.$sid('field').val(value === null || value === undefined ? '' : value.to_input_text());
    }

    _focus() {
        this.$sid('field').trigger('focus');
    }
}
