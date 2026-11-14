/**
 * Rsx_Money_Core - the checked-integer core of Rsx_Money in the browser.
 *
 * Every quantity inside a money algorithm is a BigInt in the signed 64-bit range of cents,
 * and every primitive checks its result: a true result outside [MIN, MAX] throws
 * Rsx_Money_Exception (`range`). The PHP class of the same name executes the same primitives
 * on native ints with PHP's overflow detection, so both languages refuse exactly the same
 * inputs.
 *
 * Digit strings become BigInt only through int() here - never through int(), float() or
 * Number(), which would lose digits past 2^53.
 *
 * Internal to Rsx_Money; nothing else calls it.
 */
class Rsx_Money_Core {
    /** The smallest amount, in cents. */
    static MIN = -9223372036854775808n;

    /** The largest amount, in cents. */
    static MAX = 9223372036854775807n;

    /**
     * x, when MIN <= x <= MAX; otherwise `range`.
     *
     * @param {bigint} x
     * @returns {bigint}
     */
    static check(x) {
        if (x < Rsx_Money_Core.MIN || x > Rsx_Money_Core.MAX) {
            throw Rsx_Money_Exception.range();
        }

        return x;
    }

    /** a + b */
    static add(a, b) {
        return Rsx_Money_Core.check(a + b);
    }

    /** a - b */
    static sub(a, b) {
        return Rsx_Money_Core.check(a - b);
    }

    /** a * b */
    static mul(a, b) {
        return Rsx_Money_Core.check(a * b);
    }

    /** -a */
    static neg(a) {
        return Rsx_Money_Core.check(-a);
    }

    /** |a| */
    static abs(a) {
        return Rsx_Money_Core.check(a < 0n ? -a : a);
    }

    /**
     * The quotient truncated toward zero (BigInt `/` truncates). A zero divisor is the
     * caller's bug.
     */
    static quo(a, b) {
        if (b === 0n) {
            shouldnt_happen('Rsx_Money_Core.quo(): a zero divisor reached the core.');
        }

        return Rsx_Money_Core.check(a / b);
    }

    /** a - b * quo(a, b): the sign of a. A zero divisor is the caller's bug. */
    static rem(a, b) {
        if (b === 0n) {
            shouldnt_happen('Rsx_Money_Core.rem(): a zero divisor reached the core.');
        }

        return Rsx_Money_Core.check(a % b);
    }

    /**
     * 10^k for 0 <= k; k > 18 leaves the range.
     *
     * @param {bigint} k
     * @returns {bigint}
     */
    static pow10(k) {
        if (k < 0n) {
            shouldnt_happen('Rsx_Money_Core.pow10(): a negative exponent reached the core.');
        }
        if (k > 18n) {
            throw Rsx_Money_Exception.range();
        }

        return 10n ** k;
    }

    /**
     * The integer an optionally signed digit string writes. Leading zeros carry nothing; all
     * zeros is 0 with no sign; more than 19 digits, or a value outside the range, is `range`.
     * A string of another shape is the caller's bug.
     *
     * @param {string} s - matches ^-?[0-9]+$
     * @returns {bigint}
     */
    static int(s) {
        const m = /^(-?)([0-9]+)$/.exec(s);
        if (!m) {
            shouldnt_happen('Rsx_Money_Core.int(): not a digit string: ' + s);
        }

        const digits = m[2].replace(/^0+/, '');
        if (digits === '') {
            return 0n;
        }
        if (digits.length > 19) {
            throw Rsx_Money_Exception.range();
        }

        return Rsx_Money_Core.check(BigInt(m[1] + digits));
    }

    /**
     * -1, 0 or 1 (a Number).
     *
     * @param {bigint} a
     * @param {bigint} b
     * @returns {number}
     */
    static compare(a, b) {
        if (a < b) {
            return -1;
        }

        return a > b ? 1 : 0;
    }
}
