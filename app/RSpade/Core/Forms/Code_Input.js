/**
 * Code_Input - a numeric code typed one digit per box. See Code_Input.jqhtml.
 *
 * THE INVARIANT everything here keeps: the boxes are filled from the left with no gaps. The
 * value is therefore just the boxes read in order, and "where does the cursor go" has one
 * answer at any moment - the first empty box, or the last box when none is empty. Every
 * handler ends by restoring that invariant (_write_digits) and that cursor (_focus), so no
 * path - typing, pasting, autofill, deleting, clicking - needs rules of its own.
 *
 * The digit filtering is its own rather than $(input).rsx_numeric(): a code is a string of
 * digits and not a number, so its leading zeros have to survive - "0042" is not 42.
 */
class Code_Input extends Form_Input_Abstract {
    on_render() {
        const that = this;
        const $boxes = this._$boxes();

        // Rebound on every render: a render rebuilds the boxes, so the previous handlers went
        // with the previous elements.
        $boxes.off('.code_input');

        // Typing, pasting and autofill all arrive here. Whatever landed in this box is merged
        // into the code at this position and the whole row is rewritten.
        $boxes.on('input.code_input', function () {
            const index = $boxes.index(this);
            const digits = that._digits_of_boxes($boxes);
            const merged = digits.slice(0, index).join('')
                + Code_Input._only_digits(this.value)
                + digits.slice(index + 1).join('');

            that._write_digits(merged);
            that._notify_input(that._get_value());
            that._focus();
        });

        $boxes.on('keydown.code_input', function (e) {
            // Enter belongs to whatever the input sits in - but only for a COMPLETE code.
            // With every box filled the key is left alone, so it takes the same path Enter
            // takes in any text input. Short of that it is swallowed: submitting four digits
            // can only fail.
            if (e.key === 'Enter') {
                if (!that.is_complete()) {
                    e.preventDefault();
                }
                return;
            }

            // Backspace in an EMPTY box deletes the digit before it. (In a box holding a
            // digit the browser deletes it and the input handler above takes over.)
            if (e.key === 'Backspace' && this.value === '') {
                const index = $boxes.index(this);
                if (index > 0) {
                    e.preventDefault();
                    $boxes.eq(index - 1).val('');
                    that._notify_input(that._get_value());
                    that._focus();
                }
            }
        });

        // Focus arriving in ANY box - a click, a tap, the Tab key - is sent to the box the
        // next digit belongs in. The click handler repeats the cursor placement because a
        // mouse press positions the caret itself, after the focus event has already run.
        $boxes.on('focus.code_input click.code_input', function () {
            that._focus_from(this);
        });

        this._mark_ready();
    }

    /** True when every box holds a digit. */
    is_complete() {
        return this._get_value().length === this._$boxes().length;
    }

    _get_value() {
        return this._digits_of_boxes(this._$boxes()).join('');
    }

    _set_value(value) {
        this._write_digits(value === null || value === undefined ? '' : str(value));
    }

    /**
     * Where the next digit goes: the first empty box, or after the digit in the last box
     * when every box is full.
     */
    _focus() {
        this._focus_from(null);
    }

    _$boxes() {
        return this.$.find('.Code_Input__box');
    }

    /** The digit in each box, in order; '' for an empty one. */
    _digits_of_boxes($boxes) {
        return $boxes.map(function () {
            return Code_Input._only_digits(this.value).charAt(0);
        }).get();
    }

    /** Write a code across the boxes from the left, dropping anything that is not a digit and anything past the last box. */
    _write_digits(text) {
        const digits = Code_Input._only_digits(text);

        this._$boxes().each(function (index) {
            this.value = digits.charAt(index);
        });
    }

    /** The box the next digit belongs in. */
    _target_box() {
        const $boxes = this._$boxes();
        const $empty = $boxes.filter(function () {
            return this.value === '';
        });

        return ($empty.length ? $empty.first() : $boxes.last()).get(0);
    }

    /** Move focus to the target box unless `current` already is it, and put the caret after its digit. */
    _focus_from(current) {
        const target = this._target_box();
        if (!target || target.disabled) {
            return;
        }

        if (target !== current || document.activeElement !== target) {
            target.focus();
        }

        const end = target.value.length;
        target.setSelectionRange(end, end);
    }

    static _only_digits(text) {
        return str(text).replace(/\D/g, '');
    }
}
