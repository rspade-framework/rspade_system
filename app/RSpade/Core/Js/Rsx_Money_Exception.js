/**
 * Rsx_Money_Exception - the one exception every Rsx_Money operation throws (rsx:man money).
 *
 * `reason` says what went wrong, one of six:
 *
 *     format     the input is not an amount of money (also: a Number carrying residue)
 *     scale      more than two decimal places
 *     range      outside the signed 64-bit range of cents, at any step - or, for an amount
 *                read for or assigned to a money column, beyond what that column can hold
 *                (the server's Rsx_Model_Abstract::money_limit())
 *     rounding   a calculation left a fraction of a cent and ROUND_EXACT was asked for
 *     division   a division by zero
 *     argument   the calling CODE is wrong: a missing mode, a null, a wrong type
 *
 * `format`, `scale` and `range` carry a sentence written for a person; is_input_problem()
 * answers exactly those three. The PHP class of the same name carries the same reasons and
 * messages.
 *
 * @Instantiatable
 */
class Rsx_Money_Exception extends Error {
    static REASON_FORMAT = 'format';
    static REASON_SCALE = 'scale';
    static REASON_RANGE = 'range';
    static REASON_ROUNDING = 'rounding';
    static REASON_DIVISION = 'division';
    static REASON_ARGUMENT = 'argument';

    static MESSAGE_FORMAT = 'This is not an amount of money.';
    static MESSAGE_RESIDUE = 'This number carries floating-point residue; pass it as a decimal string.';
    static MESSAGE_SCALE = 'An amount of money has at most two decimal places.';
    static MESSAGE_RANGE = 'This amount is outside the range the system can hold.';
    static MESSAGE_COLUMN_RANGE = 'This amount is larger than this field can hold.';
    static MESSAGE_ROUNDING = 'This calculation does not come to a whole number of cents, and rounding was not allowed.';
    static MESSAGE_DIVISION = 'An amount of money cannot be divided by zero.';

    /**
     * @param {string} reason - one of the REASON_* values
     * @param {string} message - the MESSAGE_* text for that reason; free text for `argument`
     */
    constructor(reason, message) {
        super(message);
        this.name = 'Rsx_Money_Exception';
        this.reason = reason;
    }

    /**
     * Whether the refusal is about the INPUT (format, scale, range) - a field error to show
     * the person - rather than about the calculation or the calling code.
     *
     * @returns {boolean}
     */
    is_input_problem() {
        return this.reason === Rsx_Money_Exception.REASON_FORMAT
            || this.reason === Rsx_Money_Exception.REASON_SCALE
            || this.reason === Rsx_Money_Exception.REASON_RANGE;
    }

    /** @returns {Rsx_Money_Exception} */
    static format() {
        return new Rsx_Money_Exception(Rsx_Money_Exception.REASON_FORMAT, Rsx_Money_Exception.MESSAGE_FORMAT);
    }

    /** @returns {Rsx_Money_Exception} */
    static residue() {
        return new Rsx_Money_Exception(Rsx_Money_Exception.REASON_FORMAT, Rsx_Money_Exception.MESSAGE_RESIDUE);
    }

    /** @returns {Rsx_Money_Exception} */
    static scale() {
        return new Rsx_Money_Exception(Rsx_Money_Exception.REASON_SCALE, Rsx_Money_Exception.MESSAGE_SCALE);
    }

    /** @returns {Rsx_Money_Exception} */
    static range() {
        return new Rsx_Money_Exception(Rsx_Money_Exception.REASON_RANGE, Rsx_Money_Exception.MESSAGE_RANGE);
    }

    /** @returns {Rsx_Money_Exception} */
    static column_range() {
        return new Rsx_Money_Exception(Rsx_Money_Exception.REASON_RANGE, Rsx_Money_Exception.MESSAGE_COLUMN_RANGE);
    }

    /** @returns {Rsx_Money_Exception} */
    static rounding() {
        return new Rsx_Money_Exception(Rsx_Money_Exception.REASON_ROUNDING, Rsx_Money_Exception.MESSAGE_ROUNDING);
    }

    /** @returns {Rsx_Money_Exception} */
    static division() {
        return new Rsx_Money_Exception(Rsx_Money_Exception.REASON_DIVISION, Rsx_Money_Exception.MESSAGE_DIVISION);
    }

    /**
     * A refusal of the calling code: `Rsx_Money::<method>(): <what was wrong>.`
     *
     * @param {string} method
     * @param {string} what
     * @returns {Rsx_Money_Exception}
     */
    static argument(method, what) {
        return new Rsx_Money_Exception(Rsx_Money_Exception.REASON_ARGUMENT, `Rsx_Money::${method}(): ${what}.`);
    }
}
