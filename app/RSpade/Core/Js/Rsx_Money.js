/**
 * Rsx_Money - an amount of money in the browser: an immutable count of cents in the signed
 * 64-bit range, with exactly two decimal places (rsx:man money).
 *
 * The PHP class of the same name implements every algorithm here step for step, so both
 * languages give the identical result - or the identical refusal - for the same call. No
 * Number takes part in a calculation: every quantity is a BigInt checked by Rsx_Money_Core,
 * created inside a method from the canonical string and discarded. The ONE own property is
 * `_decimal`, the canonical string, so jqhtml's cache, hash() and an input's change detection
 * see a plain string.
 *
 * Ways in: from_decimal(), from_cents(), from_text(), from_submitted(), from_aggregate(),
 * from_storage(), from_envelope(), zero(). The constructor is internal.
 *
 * Ways out: to_decimal(), to_cents_string(), to_envelope() (also what toJSON() and
 * json_encode() produce), format(), to_input_text(). Coercing the value to a primitive
 * (`+m`, `m + 1`, `m < n`, a template literal, str(m), Number(m)) THROWS: compare with
 * compare() and the questions (equals, less_than, ...), print with format().
 *
 * @Instantiatable
 */
class Rsx_Money {
    /** Any remainder moves away from zero. */
    static ROUND_UP = 'up';

    /** Any remainder is dropped (toward zero). */
    static ROUND_DOWN = 'down';

    /** Nearest; an exact half goes away from zero. */
    static ROUND_HALF_UP = 'half_up';

    /** Nearest; an exact half goes to the even neighbour. */
    static ROUND_HALF_EVEN = 'half_even';

    /** Any remainder throws `rounding`. */
    static ROUND_EXACT = 'exact';

    /** The one key of the wire envelope {__MONEY: '1234.56'}. */
    static ENVELOPE_KEY = '__MONEY';

    /** The framework's display configuration (rsx.money.display). */
    static DISPLAY_DEFAULTS = {
        symbol: '$',
        symbol_position: 'before',
        symbol_spacing: false,
        decimal_separator: '.',
        group_separator: ',',
    };

    static _MODES = ['up', 'down', 'half_up', 'half_even', 'exact'];

    static _GROUP_SEPARATORS = [',', '.', "'", ' ', ' ', ' ', ''];

    /** The whitespace a person's input may be wrapped in (from_text()). */
    static _WS_CLASS = '[\\u0009\\u000A\\u000B\\u000C\\u000D\\u0020\\u00A0\\u2009\\u202F]';

    /** A plain decimal: sign, whole digits, fraction digits; no grouping, no exponent. */
    static _DECIMAL_PATTERN = /^(-?)(?:([0-9]+)(?:\.([0-9]*))?|\.([0-9]+))$/;

    /** The canonical form. */
    static _CANONICAL_PATTERN = /^-?(?:0|[1-9][0-9]*)\.[0-9]{2}$/;

    static _ERROR_PRIMITIVE = 'Rsx_Money cannot be converted to a primitive: use to_decimal(), format(), compare() or the questions (equals, less_than, ...).';

    /** The configured display (window.rsxapp.money_display), validated at boot. */
    static _configured_display = null;

    /** A display installed by _use_display(), or null. */
    static _display_override = null;

    /**
     * Internal: build through the static doors, never `new`.
     *
     * @param {string} decimal - the canonical string
     */
    constructor(decimal) {
        this._decimal = decimal;
    }

    // =========================================================================
    // BOOT
    // =========================================================================

    /**
     * The printer (`<%= value %>` prints format()) and the cache class (a value in a cached
     * component's this.data comes back as an Rsx_Money).
     */
    static _on_framework_modules_define() {
        jqhtml.add_object_printer(function (value) {
            return value instanceof Rsx_Money ? value.format() : undefined;
        });

        jqhtml.register_cache_class(Rsx_Money, {
            to_plain: (m) => m.to_decimal(),
            from_plain: (d) => Rsx_Money.from_storage(d),
        });
    }

    /**
     * Read and validate the application's display (rsx.money.display, shipped as
     * window.rsxapp.money_display) with the rules the server applied.
     */
    static _on_framework_core_init() {
        if (window.rsxapp && window.rsxapp.money_display) {
            Rsx_Money._configured_display = Rsx_Money._validate_display(Rsx_Money._merge_display(window.rsxapp.money_display));
        }
    }

    // =========================================================================
    // WAYS IN
    // =========================================================================

    /**
     * A decimal string ('1234.5', '1.500'), a BigInt or safe-integer Number of WHOLE units
     * (5 is 5.00), or another Number whose shortest form has at most 15 significant digits.
     *
     * @param {string|bigint|number} x
     * @returns {Rsx_Money}
     */
    static from_decimal(x) {
        const type = typeof x;
        if (type === 'bigint') {
            return Rsx_Money._new(Rsx_Money_Core.mul(Rsx_Money_Core.check(x), 100n));
        }
        if (type === 'number') {
            if (Number.isSafeInteger(x)) {
                return Rsx_Money._new(Rsx_Money_Core.mul(BigInt(x), 100n));
            }

            return Rsx_Money._dec(Rsx_Money._float_door(x, true, false));
        }
        if (type === 'string') {
            return Rsx_Money._dec(x);
        }

        throw Rsx_Money_Exception.argument('from_decimal', 'expected a decimal string, a BigInt or a Number, got ' + Rsx_Money._describe(x));
    }

    /**
     * A count of cents: a BigInt, a safe-integer Number, or a string of one ('-5', '0').
     *
     * @param {bigint|number|string} x
     * @returns {Rsx_Money}
     */
    static from_cents(x) {
        const type = typeof x;
        if (type === 'bigint') {
            return Rsx_Money._new(Rsx_Money_Core.check(x));
        }
        if (type === 'number' && Number.isSafeInteger(x)) {
            return Rsx_Money._new(BigInt(x));
        }
        if (type === 'string' && /^(?:0|-?[1-9][0-9]*)$/.test(x)) {
            return Rsx_Money._new(Rsx_Money_Core.int(x));
        }

        throw Rsx_Money_Exception.argument('from_cents', 'expected a BigInt, a safe integer or an integer string, got ' + Rsx_Money._describe(x));
    }

    /**
     * What a person typed, read through the display configuration: '$1,234.56', '(500.00)',
     * '-$99.99', '1.500'. More than two significant decimals is refused, never rounded.
     *
     * @param {string} s
     * @returns {Rsx_Money}
     */
    static from_text(s) {
        // 1
        if (!is_string(s)) {
            throw Rsx_Money_Exception.argument('from_text', 'expected a string, got ' + Rsx_Money._describe(s));
        }

        // 2-3
        s = Rsx_Money._trim_ws(s);
        if (s === '') {
            throw Rsx_Money_Exception.format();
        }

        // 4
        let paren = false;
        if (s.length >= 2 && s[0] === '(' && s[s.length - 1] === ')') {
            paren = true;
            s = s.substring(1, s.length - 1);
        }

        // 5
        const display = Rsx_Money.display();
        const g = display.group_separator;
        const dm = Rsx_Money._regex_escape(display.decimal_separator);
        const whole = g === '' ? '([0-9]+)' : '([0-9]+|[1-9][0-9]{0,2}(?:' + Rsx_Money._regex_escape(g) + '[0-9]{3})+)';
        const num = '(?:' + whole + '(?:' + dm + '([0-9]*))?|' + dm + '([0-9]+))';
        const spc = display.symbol_spacing ? '[\\u0020\\u00A0]?' : '';
        const sym = Rsx_Money._regex_escape(display.symbol);

        let pattern;
        let sign_groups;
        let first;
        if (display.symbol === '') {
            pattern = '^(-?)' + num + '$';
            sign_groups = [1];
            first = 2;
        } else if (display.symbol_position === 'before') {
            pattern = '^(-?)(?:' + sym + spc + ')?(-?)' + num + '$';
            sign_groups = [1, 2];
            first = 3;
        } else {
            pattern = '^(-?)' + num + '(?:' + spc + sym + ')?$';
            sign_groups = [1];
            first = 2;
        }

        // 6
        const m = new RegExp(pattern, 'u').exec(s);
        if (!m) {
            throw Rsx_Money_Exception.format();
        }

        // 7
        let signs = 0;
        for (const group of sign_groups) {
            if (m[group] !== undefined && m[group] !== '') {
                signs++;
            }
        }
        if (signs > 1) {
            throw Rsx_Money_Exception.format();
        }
        if (paren && signs > 0) {
            throw Rsx_Money_Exception.format();
        }

        // 8
        let whole_digits;
        let frac;
        if (m[first] !== undefined) {
            whole_digits = g === '' ? m[first] : m[first].split(g).join('');
            frac = m[first + 1] === undefined ? '' : m[first + 1];
        } else {
            whole_digits = '';
            frac = m[first + 2] === undefined ? '' : m[first + 2];
        }

        // 9
        const sign = (paren || signs === 1) ? '-' : '';

        return Rsx_Money._from_parts(sign, whole_digits, frac);
    }

    /**
     * A form or API field. null, undefined and blank are null (never zero); a value is
     * returned as is; a string is read as typed text; a BigInt is whole units; a Number goes
     * through from_decimal(); an envelope is decoded. Every refusal is an input problem
     * (format, scale, range).
     *
     * @param {*} x
     * @returns {Rsx_Money|null}
     */
    static from_submitted(x) {
        // 1
        if (x === null || x === undefined) {
            return null;
        }

        // 2
        if (x instanceof Rsx_Money) {
            return x;
        }

        const type = typeof x;

        // 3
        if (type === 'string') {
            if (Rsx_Money._trim_ws(x) === '') {
                return null;
            }

            return Rsx_Money.from_text(x);
        }

        // 4-5
        if (type === 'bigint' || type === 'number') {
            return Rsx_Money.from_decimal(x);
        }

        // 6
        if (Rsx_Money._is_plain_object(x) && Object.prototype.hasOwnProperty.call(x, Rsx_Money.ENVELOPE_KEY)) {
            try {
                return Rsx_Money.from_envelope(x);
            } catch (e) {
                if (e instanceof Rsx_Money_Exception && e.reason === Rsx_Money_Exception.REASON_ARGUMENT) {
                    throw Rsx_Money_Exception.format();
                }
                throw e;
            }
        }

        // 7
        throw Rsx_Money_Exception.format();
    }

    /**
     * An SQL total: null (SUM over no rows) is 0.00, a BigInt is whole units, and a decimal
     * string or a Number - floating-point residue included - is rounded half-up to two
     * places by integer arithmetic. The one door that rounds; never for a figure a person
     * entered.
     *
     * @param {string|bigint|number|null} x
     * @returns {Rsx_Money}
     */
    static from_aggregate(x) {
        // 1
        if (x === null || x === undefined) {
            return Rsx_Money._new(0n);
        }

        const type = typeof x;

        // 2
        if (type === 'bigint') {
            return Rsx_Money.from_decimal(x);
        }

        // 3
        let s;
        if (type === 'number') {
            s = Rsx_Money._float_door(x, false, false);
        } else if (type === 'string') {
            s = x;
        } else {
            throw Rsx_Money_Exception.argument('from_aggregate', 'expected null, a decimal string, a BigInt or a Number, got ' + Rsx_Money._describe(x));
        }

        // 4
        const m = Rsx_Money._DECIMAL_PATTERN.exec(s);
        if (!m) {
            throw Rsx_Money_Exception.format();
        }

        // 5
        const parts = Rsx_Money._decimal_parts(m);
        const sign = parts[0];
        const whole = parts[1];
        const frac = parts[2].replace(/0+$/, '');

        // 6
        if (frac.length <= 2) {
            return Rsx_Money._from_parts(sign, whole, frac);
        }

        // 7
        const n = Rsx_Money_Core.int(sign + (whole === '' ? '0' : whole) + frac.substring(0, 3));

        return Rsx_Money._new(Rsx_Money._round(n, 10n, Rsx_Money.ROUND_HALF_UP));
    }

    /**
     * The canonical string a DECIMAL(p,2) column returns. Grammar and bound are both checked.
     *
     * @param {string} s
     * @returns {Rsx_Money}
     */
    static from_storage(s) {
        if (!is_string(s) || !Rsx_Money._CANONICAL_PATTERN.test(s) || s === '-0.00') {
            throw Rsx_Money_Exception.argument('from_storage', 'expected the canonical two-place decimal string, got ' + Rsx_Money._describe(s));
        }

        return Rsx_Money._new(Rsx_Money_Core.int(s.replace('.', '')));
    }

    /**
     * The wire envelope: a plain object whose only own key is __MONEY, holding the canonical
     * string. The model rehydrator calls this for every {__MONEY} it meets.
     *
     * @param {Object} e
     * @returns {Rsx_Money}
     */
    static from_envelope(e) {
        if (!Rsx_Money._is_plain_object(e)) {
            throw Rsx_Money_Exception.argument('from_envelope', 'expected a plain object whose only key is __MONEY, got ' + Rsx_Money._describe(e));
        }
        const keys = Object.keys(e);
        if (keys.length !== 1 || keys[0] !== Rsx_Money.ENVELOPE_KEY) {
            throw Rsx_Money_Exception.argument('from_envelope', 'expected a plain object whose only key is __MONEY, got ' + Rsx_Money._describe(e));
        }

        return Rsx_Money.from_storage(e[Rsx_Money.ENVELOPE_KEY]);
    }

    /**
     * 0.00
     *
     * @returns {Rsx_Money}
     */
    static zero() {
        return Rsx_Money._new(0n);
    }

    // =========================================================================
    // WAYS OUT
    // =========================================================================

    /**
     * The canonical string: '1234.56', '-0.01', '0.00'.
     *
     * @returns {string}
     */
    to_decimal() {
        return this._decimal;
    }

    /**
     * The cents as an integer string: '123456', '-5', '0'.
     *
     * @returns {string}
     */
    to_cents_string() {
        return str(this._cents());
    }

    /**
     * The wire envelope {__MONEY: '1234.56'}.
     *
     * @returns {Object}
     */
    to_envelope() {
        return { [Rsx_Money.ENVELOPE_KEY]: this._decimal };
    }

    /**
     * json_encode() and Ajax write the envelope.
     *
     * @returns {Object}
     */
    toJSON() {
        return this.to_envelope();
    }

    /**
     * The amount for display, through the display configuration: '$1,234.56', '-$1,234.56'.
     *
     * Options (any subset): negative 'sign' (default) | 'parentheses'; symbol (boolean,
     * default true); grouped (boolean, default true).
     *
     * @param {Object|null} [options]
     * @returns {string}
     */
    format(options = null) {
        const o = Rsx_Money._format_options(options);
        const display = Rsx_Money.display();

        // 1
        let d = this._decimal;
        const neg = d[0] === '-';
        if (neg) {
            d = d.substring(1);
        }
        const point = d.indexOf('.');
        let whole = d.substring(0, point);
        const frac = d.substring(point + 1);

        // 2
        const g = display.group_separator;
        if (o.grouped && g !== '') {
            let grouped = '';
            const len = whole.length;
            for (let i = 0; i < len; i++) {
                const distance = len - i;
                if (i > 0 && distance % 3 === 0) {
                    grouped += g;
                }
                grouped += whole[i];
            }
            whole = grouped;
        }

        // 3
        const number = whole + display.decimal_separator + frac;

        // 4
        let body;
        if (o.symbol && display.symbol !== '') {
            const sp = display.symbol_spacing ? ' ' : '';
            body = display.symbol_position === 'before'
                ? display.symbol + sp + number
                : number + sp + display.symbol;
        } else {
            body = number;
        }

        // 5
        if (neg) {
            return o.negative === 'parentheses' ? '(' + body + ')' : '-' + body;
        }

        return body;
    }

    /**
     * What an edit box shows: no symbol, no grouping ('-1234.56', '1234,56').
     *
     * @returns {string}
     */
    to_input_text() {
        return this.format({ symbol: false, grouped: false });
    }

    /**
     * Refused: a money value is never silently a primitive. This is the class's only
     * computed-key member.
     */
    [Symbol.toPrimitive](hint) {
        shouldnt_happen(Rsx_Money._ERROR_PRIMITIVE);
    }

    /**
     * Refused, as above: an explicit .toString() does not consult Symbol.toPrimitive.
     */
    toString() {
        shouldnt_happen(Rsx_Money._ERROR_PRIMITIVE);
    }

    // =========================================================================
    // QUESTIONS
    // =========================================================================

    /**
     * Whether the two amounts are equal; null is never equal.
     *
     * @param {Rsx_Money|null} other
     * @returns {boolean}
     */
    equals(other) {
        if (other === null) {
            return false;
        }
        const b = Rsx_Money._money(other, 'equals');

        return this._decimal === b._decimal;
    }

    /**
     * The null-safe pair form: both null is the same, one null differs.
     *
     * @param {Rsx_Money|null} a
     * @param {Rsx_Money|null} b
     * @returns {boolean}
     */
    static same(a, b) {
        if (a !== null) {
            Rsx_Money._money(a, 'same');
        }
        if (b !== null) {
            Rsx_Money._money(b, 'same');
        }
        if (a === null && b === null) {
            return true;
        }
        if (a === null || b === null) {
            return false;
        }

        return a._decimal === b._decimal;
    }

    /**
     * -1, 0 or 1 (a Number).
     *
     * @param {Rsx_Money} other
     * @returns {number}
     */
    compare(other) {
        const b = Rsx_Money._money(other, 'compare');

        return Rsx_Money_Core.compare(this._cents(), b._cents());
    }

    /** @returns {boolean} */
    less_than(other) {
        return this._cents() < Rsx_Money._money(other, 'less_than')._cents();
    }

    /** @returns {boolean} */
    less_than_or_equal(other) {
        return this._cents() <= Rsx_Money._money(other, 'less_than_or_equal')._cents();
    }

    /** @returns {boolean} */
    greater_than(other) {
        return this._cents() > Rsx_Money._money(other, 'greater_than')._cents();
    }

    /** @returns {boolean} */
    greater_than_or_equal(other) {
        return this._cents() >= Rsx_Money._money(other, 'greater_than_or_equal')._cents();
    }

    /** @returns {boolean} */
    is_zero() {
        return this._cents() === 0n;
    }

    /** @returns {boolean} */
    is_negative() {
        return this._cents() < 0n;
    }

    /** @returns {boolean} */
    is_positive() {
        return this._cents() > 0n;
    }

    /**
     * low <= this <= high, inclusive.
     *
     * @param {Rsx_Money} low
     * @param {Rsx_Money} high
     * @returns {boolean}
     */
    is_between(low, high) {
        const lo = Rsx_Money._money(low, 'is_between')._cents();
        const hi = Rsx_Money._money(high, 'is_between')._cents();
        if (lo > hi) {
            throw Rsx_Money_Exception.argument('is_between', 'low is greater than high');
        }
        const a = this._cents();

        return lo <= a && a <= hi;
    }

    // =========================================================================
    // ARITHMETIC
    // =========================================================================

    /** @returns {Rsx_Money} */
    add(other) {
        const b = Rsx_Money._money(other, 'add');

        return Rsx_Money._new(Rsx_Money_Core.add(this._cents(), b._cents()));
    }

    /** @returns {Rsx_Money} */
    subtract(other) {
        const b = Rsx_Money._money(other, 'subtract');

        return Rsx_Money._new(Rsx_Money_Core.sub(this._cents(), b._cents()));
    }

    /** @returns {Rsx_Money} */
    negate() {
        return Rsx_Money._new(Rsx_Money_Core.neg(this._cents()));
    }

    /** @returns {Rsx_Money} */
    abs() {
        return Rsx_Money._new(Rsx_Money_Core.abs(this._cents()));
    }

    /**
     * This amount times a decimal factor, rounded once.
     *
     * @param {bigint|number|string} factor
     * @param {string} rounding - one of the ROUND_* constants
     * @returns {Rsx_Money}
     */
    multiply(factor, rounding) {
        const mode = Rsx_Money._mode(rounding, 'multiply');
        const [u, s] = Rsx_Money._factor(factor, 'multiply');
        const d = Rsx_Money_Core.pow10(s);
        const n = Rsx_Money_Core.mul(this._cents(), u);

        return Rsx_Money._new(Rsx_Money._round(n, d, mode));
    }

    /**
     * This amount times numerator / denominator - one calculation, one rounding.
     *
     * @param {bigint|number|string} numerator
     * @param {bigint|number|string} denominator
     * @param {string} rounding - one of the ROUND_* constants
     * @returns {Rsx_Money}
     */
    multiply_fraction(numerator, denominator, rounding) {
        const mode = Rsx_Money._mode(rounding, 'multiply_fraction');
        const big_n = Rsx_Money._intarg(numerator, 'multiply_fraction');
        let big_d = Rsx_Money._intarg(denominator, 'multiply_fraction');
        if (big_d === 0n) {
            throw Rsx_Money_Exception.division();
        }
        let n = Rsx_Money_Core.mul(this._cents(), big_n);
        if (big_d < 0n) {
            n = Rsx_Money_Core.neg(n);
            big_d = Rsx_Money_Core.neg(big_d);
        }

        return Rsx_Money._new(Rsx_Money._round(n, big_d, mode));
    }

    /**
     * `rate` percent of this amount: percent('7.25', mode) is 7.25%.
     *
     * @param {bigint|number|string} rate
     * @param {string} rounding - one of the ROUND_* constants
     * @returns {Rsx_Money}
     */
    percent(rate, rounding) {
        const mode = Rsx_Money._mode(rounding, 'percent');
        const [u, s] = Rsx_Money._factor(rate, 'percent');
        const k = Rsx_Money_Core.add(s, 2n);
        const d = Rsx_Money_Core.pow10(k);
        const n = Rsx_Money_Core.mul(this._cents(), u);

        return Rsx_Money._new(Rsx_Money._round(n, d, mode));
    }

    /**
     * Integer division of the cents: quotient * divisor + remainder is this amount, and the
     * remainder carries this amount's sign.
     *
     * @param {bigint|number|string} divisor
     * @returns {{quotient: Rsx_Money, remainder: Rsx_Money}}
     */
    divide(divisor) {
        const n = Rsx_Money._intarg(divisor, 'divide');
        if (n === 0n) {
            throw Rsx_Money_Exception.division();
        }
        const a = this._cents();
        const q = Rsx_Money_Core.quo(a, n);
        const r = Rsx_Money_Core.rem(a, n);

        return { quotient: Rsx_Money._new(q), remainder: Rsx_Money._new(r) };
    }

    /**
     * This amount divided by a decimal divisor, rounded once.
     *
     * @param {bigint|number|string} divisor
     * @param {string} rounding - one of the ROUND_* constants
     * @returns {Rsx_Money}
     */
    divide_rounded(divisor, rounding) {
        const mode = Rsx_Money._mode(rounding, 'divide_rounded');
        let [u, s] = Rsx_Money._factor(divisor, 'divide_rounded');
        if (u === 0n) {
            throw Rsx_Money_Exception.division();
        }
        const p = Rsx_Money_Core.pow10(s);
        let n = Rsx_Money_Core.mul(this._cents(), p);
        if (u < 0n) {
            n = Rsx_Money_Core.neg(n);
            u = Rsx_Money_Core.neg(u);
        }

        return Rsx_Money._new(Rsx_Money._round(n, u, mode));
    }

    /**
     * The nearest multiple of `unit` (1.00, 100.00, 0.05) under the mode.
     *
     * @param {Rsx_Money} unit
     * @param {string} rounding - one of the ROUND_* constants
     * @returns {Rsx_Money}
     */
    round_to(unit, rounding) {
        const mode = Rsx_Money._mode(rounding, 'round_to');
        const uc = Rsx_Money._money(unit, 'round_to')._cents();
        if (uc <= 0n) {
            throw Rsx_Money_Exception.argument('round_to', 'the unit must be positive');
        }
        const q = Rsx_Money._round(this._cents(), uc, mode);

        return Rsx_Money._new(Rsx_Money_Core.mul(q, uc));
    }

    /**
     * low when below it, high when above it, else this value.
     *
     * @param {Rsx_Money} low
     * @param {Rsx_Money} high
     * @returns {Rsx_Money}
     */
    clamp(low, high) {
        const lo = Rsx_Money._money(low, 'clamp');
        const hi = Rsx_Money._money(high, 'clamp');
        if (lo._cents() > hi._cents()) {
            throw Rsx_Money_Exception.argument('clamp', 'low is greater than high');
        }
        const a = this._cents();
        if (a < lo._cents()) {
            return lo;
        }
        if (a > hi._cents()) {
            return hi;
        }

        return this;
    }

    /**
     * Split this amount by integer ratios - largest remainder, stable order; the parts sum
     * to the whole and a zero ratio receives nothing.
     *
     * @param {Array<bigint|number|string>} ratios
     * @returns {Array<Rsx_Money>}
     */
    allocate(ratios) {
        const a = this._cents();

        // 1
        Rsx_Money._list(ratios, 'allocate');
        if (ratios.length === 0) {
            throw Rsx_Money_Exception.argument('allocate', 'the ratios are empty');
        }

        // 2
        const r = [];
        for (const element of ratios) {
            const ri = Rsx_Money._intarg(element, 'allocate');
            if (ri < 0n) {
                throw Rsx_Money_Exception.argument('allocate', 'a ratio is negative');
            }
            r.push(ri);
        }

        // 3
        let total = 0n;
        for (const ri of r) {
            total = Rsx_Money_Core.add(total, ri);
        }

        // 4
        if (total === 0n) {
            throw Rsx_Money_Exception.argument('allocate', 'the ratios sum to zero');
        }

        // 5
        const parts = [];
        const rems = [];
        for (const ri of r) {
            const p = Rsx_Money_Core.mul(a, ri);
            parts.push(Rsx_Money_Core.quo(p, total));
            rems.push(Rsx_Money_Core.rem(p, total));
        }

        // 6
        let dist = 0n;
        for (const part of parts) {
            dist = Rsx_Money_Core.add(dist, part);
        }

        // 7
        const left = Rsx_Money_Core.sub(a, dist);

        // 8
        const unit = a < 0n ? -1n : 1n;
        const count = Rsx_Money_Core.abs(left);

        // 9
        const magnitudes = rems.map((rem) => Rsx_Money_Core.abs(rem));
        const order = r.map((ri, i) => i);
        order.sort((x, y) => {
            if (magnitudes[x] !== magnitudes[y]) {
                return magnitudes[y] > magnitudes[x] ? 1 : -1;
            }

            return x - y;
        });

        // 10
        let given = 0n;
        for (const i of order) {
            if (given >= count) {
                break;
            }
            parts[i] = Rsx_Money_Core.add(parts[i], unit);
            given = given + 1n;
        }

        // 11
        return parts.map((part) => Rsx_Money._new(part));
    }

    // =========================================================================
    // LISTS (a null element is refused: the caller filters first)
    // =========================================================================

    /**
     * The total; an empty list is 0.00.
     *
     * @param {Array<Rsx_Money>} list
     * @returns {Rsx_Money}
     */
    static sum(list) {
        Rsx_Money._list(list, 'sum');

        return Rsx_Money._new(Rsx_Money._sum_cents(list, 'sum'));
    }

    /**
     * The first element holding the smallest amount.
     *
     * @param {Array<Rsx_Money>} list
     * @returns {Rsx_Money}
     */
    static min(list) {
        return Rsx_Money._extreme(list, 'min', -1);
    }

    /**
     * The first element holding the largest amount.
     *
     * @param {Array<Rsx_Money>} list
     * @returns {Rsx_Money}
     */
    static max(list) {
        return Rsx_Money._extreme(list, 'max', 1);
    }

    /**
     * The mean, rounded once.
     *
     * @param {Array<Rsx_Money>} list
     * @param {string} rounding - one of the ROUND_* constants
     * @returns {Rsx_Money}
     */
    static average(list, rounding) {
        const mode = Rsx_Money._mode(rounding, 'average');
        Rsx_Money._list(list, 'average');
        if (list.length === 0) {
            throw Rsx_Money_Exception.argument('average', 'the list is empty');
        }
        const t = Rsx_Money._sum_cents(list, 'average');

        return Rsx_Money._new(Rsx_Money._round(t, BigInt(list.length), mode));
    }

    /**
     * The total after each element, in order.
     *
     * @param {Array<Rsx_Money>} list
     * @returns {Array<Rsx_Money>}
     */
    static running_totals(list) {
        Rsx_Money._list(list, 'running_totals');
        let t = 0n;
        const result = [];
        for (const x of list) {
            t = Rsx_Money_Core.add(t, Rsx_Money._money(x, 'running_totals')._cents());
            result.push(Rsx_Money._new(t));
        }

        return result;
    }

    // =========================================================================
    // PROPORTIONS (decimal strings, never money)
    // =========================================================================

    /**
     * this / other as a decimal string with `places` places: 50.00 to 200.00 is '0.25'.
     *
     * @param {Rsx_Money} other
     * @param {bigint|number|string} places
     * @param {string} rounding - one of the ROUND_* constants
     * @returns {string}
     */
    ratio_to(other, places, rounding) {
        const mode = Rsx_Money._mode(rounding, 'ratio_to');
        const b = Rsx_Money._money(other, 'ratio_to')._cents();
        const p = Rsx_Money._intarg(places, 'ratio_to');
        if (p < 0n) {
            throw Rsx_Money_Exception.argument('ratio_to', 'places is negative');
        }
        if (b === 0n) {
            throw Rsx_Money_Exception.division();
        }
        const m = Rsx_Money_Core.pow10(p);
        let n = Rsx_Money_Core.mul(this._cents(), m);
        let d = b;
        if (d < 0n) {
            n = Rsx_Money_Core.neg(n);
            d = Rsx_Money_Core.neg(d);
        }
        const q = Rsx_Money._round(n, d, mode);

        return Rsx_Money._layout(q, p);
    }

    /**
     * (other - this) / this x 100 as a decimal string, `this` signed: 100.00 to 107.25 is
     * '7.25'.
     *
     * @param {Rsx_Money} other
     * @param {bigint|number|string} places
     * @param {string} rounding - one of the ROUND_* constants
     * @returns {string}
     */
    percent_change_to(other, places, rounding) {
        const mode = Rsx_Money._mode(rounding, 'percent_change_to');
        const b = Rsx_Money._money(other, 'percent_change_to')._cents();
        const p = Rsx_Money._intarg(places, 'percent_change_to');
        if (p < 0n) {
            throw Rsx_Money_Exception.argument('percent_change_to', 'places is negative');
        }
        const a = this._cents();
        if (a === 0n) {
            throw Rsx_Money_Exception.division();
        }
        const diff = Rsx_Money_Core.sub(b, a);
        const k = Rsx_Money_Core.add(p, 2n);
        const m = Rsx_Money_Core.pow10(k);
        let n = Rsx_Money_Core.mul(diff, m);
        let d = a;
        if (d < 0n) {
            n = Rsx_Money_Core.neg(n);
            d = Rsx_Money_Core.neg(d);
        }
        const q = Rsx_Money._round(n, d, mode);

        return Rsx_Money._layout(q, p);
    }

    // =========================================================================
    // DISPLAY CONFIGURATION
    // =========================================================================

    /**
     * The active display: the application's rsx.money.display as the server validated and
     * shipped it, or the framework default before boot has read it.
     *
     * @returns {{symbol: string, symbol_position: string, symbol_spacing: boolean, decimal_separator: string, group_separator: string}}
     */
    static display() {
        if (Rsx_Money._display_override !== null) {
            return Rsx_Money._display_override;
        }
        if (Rsx_Money._configured_display !== null) {
            return Rsx_Money._configured_display;
        }

        return Rsx_Money.DISPLAY_DEFAULTS;
    }

    /**
     * Test seam: run under `display` merged over the DEFAULTS (validated), or restore the
     * configured display with null.
     *
     * @param {Object|null} display
     */
    static _use_display(display) {
        Rsx_Money._display_override = display === null
            ? null
            : Rsx_Money._validate_display(Rsx_Money._merge_display(display));
    }

    static _merge_display(display) {
        if (!Rsx_Money._is_plain_object(display)) {
            shouldnt_happen('rsx.money.display must be an object of display keys; got ' + Rsx_Money._describe(display) + '.');
        }

        return Object.assign({}, Rsx_Money.DISPLAY_DEFAULTS, display);
    }

    static _validate_display(display) {
        for (const key of Object.keys(display)) {
            if (!Object.prototype.hasOwnProperty.call(Rsx_Money.DISPLAY_DEFAULTS, key)) {
                Rsx_Money._display_violation(key, 'absent (it is not a display key)', display[key]);
            }
        }

        const symbol = display.symbol;
        if (!is_string(symbol) || /[0-9\-+().,'\u0009-\u000D    ]/u.test(symbol)) {
            Rsx_Money._display_violation('symbol', 'a string with no digit, sign, parenthesis, point, comma, apostrophe or whitespace', symbol);
        }
        if (display.symbol_position !== 'before' && display.symbol_position !== 'after') {
            Rsx_Money._display_violation('symbol_position', "'before' or 'after'", display.symbol_position);
        }
        if (display.symbol_spacing !== true && display.symbol_spacing !== false) {
            Rsx_Money._display_violation('symbol_spacing', 'true or false', display.symbol_spacing);
        }
        if (display.decimal_separator !== '.' && display.decimal_separator !== ',') {
            Rsx_Money._display_violation('decimal_separator', "'.' or ','", display.decimal_separator);
        }
        if (!Rsx_Money._GROUP_SEPARATORS.includes(display.group_separator)
            || display.group_separator === display.decimal_separator) {
            Rsx_Money._display_violation(
                'group_separator',
                "one of ',' '.' \"'\" ' ' U+00A0 U+202F or '' (no grouping), different from decimal_separator",
                display.group_separator
            );
        }

        return {
            symbol: symbol,
            symbol_position: display.symbol_position,
            symbol_spacing: display.symbol_spacing,
            decimal_separator: display.decimal_separator,
            group_separator: display.group_separator,
        };
    }

    static _display_violation(key, rule, value) {
        shouldnt_happen(`rsx.money.display.${key} must be ${rule}; got ${Rsx_Money._describe(value)}.`);
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /**
     * This value's cents, created from the canonical string and discarded by the caller.
     *
     * @returns {bigint}
     */
    _cents() {
        return Rsx_Money_Core.int(this._decimal.replace('.', ''));
    }

    /** NEW(c): a value of `c` cents. */
    static _new(c) {
        return new Rsx_Money(Rsx_Money._layout(c, 2n));
    }

    /**
     * LAYOUT(q, p): the integer written with a point p digits from the right.
     *
     * @param {bigint} q
     * @param {bigint} p - a count of places (at most 18 by the time it is laid out)
     * @returns {string}
     */
    static _layout(q, p) {
        const s = str(q);
        const neg = s[0] === '-';
        let digits = neg ? s.substring(1) : s;
        const places = Number(p);
        if (places > 0) {
            digits = digits.padStart(places + 1, '0');
            digits = digits.substring(0, digits.length - places) + '.' + digits.substring(digits.length - places);
        }

        return (neg ? '-' : '') + digits;
    }

    /**
     * ROUND(n, d, mode): reduce n by a positive denominator under the mode.
     *
     * @param {bigint} n
     * @param {bigint} d
     * @param {string} mode
     * @returns {bigint}
     */
    static _round(n, d, mode) {
        // 1
        if (d === 1n) {
            return n;
        }

        // 2
        let q = Rsx_Money_Core.quo(n, d);
        const r = Rsx_Money_Core.rem(n, d);

        // 3
        if (r === 0n) {
            return q;
        }

        // 4
        if (mode === Rsx_Money.ROUND_EXACT) {
            throw Rsx_Money_Exception.rounding();
        }

        // 5
        const ra = Rsx_Money_Core.abs(r);
        const other = Rsx_Money_Core.sub(d, ra);

        // 6
        let away;
        switch (mode) {
            case Rsx_Money.ROUND_UP:
                away = true;
                break;
            case Rsx_Money.ROUND_DOWN:
                away = false;
                break;
            case Rsx_Money.ROUND_HALF_UP:
                away = ra >= other;
                break;
            case Rsx_Money.ROUND_HALF_EVEN:
                if (ra > other) {
                    away = true;
                } else if (ra < other) {
                    away = false;
                } else {
                    away = Rsx_Money_Core.rem(q, 2n) !== 0n;
                }
                break;
            default:
                shouldnt_happen('Rsx_Money: an unchecked rounding mode reached ROUND: ' + mode);
        }

        // 7
        if (away) {
            q = n < 0n ? Rsx_Money_Core.sub(q, 1n) : Rsx_Money_Core.add(q, 1n);
        }

        // 8
        return q;
    }

    /** DEC(str): the decimal-string door. */
    static _dec(s) {
        // 1
        const m = Rsx_Money._DECIMAL_PATTERN.exec(s);
        if (!m) {
            throw Rsx_Money_Exception.format();
        }

        // 2-5
        const parts = Rsx_Money._decimal_parts(m);

        return Rsx_Money._from_parts(parts[0], parts[1], parts[2]);
    }

    /** Sign, whole and fraction of a _DECIMAL_PATTERN match. */
    static _decimal_parts(m) {
        if (m[2] !== undefined) {
            return [m[1], m[2], m[3] === undefined ? '' : m[3]];
        }

        return [m[1], '', m[4]];
    }

    /** DEC steps 2-5: strip trailing zeros, refuse a third place, pad, read the cents. */
    static _from_parts(sign, whole, frac) {
        frac = frac.replace(/0+$/, '');
        if (frac.length > 2) {
            throw Rsx_Money_Exception.scale();
        }
        frac = frac.padEnd(2, '0');

        return Rsx_Money._new(Rsx_Money_Core.int(sign + (whole === '' ? '0' : whole) + frac));
    }

    /**
     * FLOAT(f, residue_check): the Number's shortest round-trip decimal.
     *
     * @param {number} f
     * @param {boolean} residue_check - false only for from_aggregate()
     * @param {boolean} factor_position - true where a NaN, an infinity or an exponent form is
     *                                    the calling code's mistake (`argument`), not input (`format`)
     * @returns {string}
     */
    static _float_door(f, residue_check, factor_position) {
        // 1
        if (!Number.isFinite(f)) {
            throw factor_position
                ? Rsx_Money_Exception.argument('factor', 'a NaN or an infinity is not a factor')
                : Rsx_Money_Exception.format();
        }

        // 2
        const s = str(f);

        // 3
        if (s.includes('e')) {
            throw factor_position
                ? Rsx_Money_Exception.argument('factor', 'a Number in exponent form is not a factor')
                : Rsx_Money_Exception.format();
        }

        // 4
        const sig = s.replace('-', '').replace('.', '').replace(/^0+/, '').replace(/0+$/, '').length;

        // 5
        if (residue_check && sig > 15) {
            throw Rsx_Money_Exception.residue();
        }

        // 6
        return s;
    }

    /** MODE(m) */
    static _mode(m, method) {
        if (!is_string(m) || !Rsx_Money._MODES.includes(m)) {
            throw Rsx_Money_Exception.argument(method, 'the rounding mode must be one of the Rsx_Money.ROUND_* constants, got ' + Rsx_Money._describe(m));
        }

        return m;
    }

    /** MONEY(x) */
    static _money(x, method) {
        if (!(x instanceof Rsx_Money)) {
            throw Rsx_Money_Exception.argument(method, 'expected an Rsx_Money, got ' + Rsx_Money._describe(x));
        }

        return x;
    }

    /** LIST(x) */
    static _list(x, method) {
        if (!is_array(x)) {
            throw Rsx_Money_Exception.argument(method, 'expected an array, got ' + Rsx_Money._describe(x));
        }
    }

    /** INTARG(x): a BigInt, a safe-integer Number, or a string of an integer. */
    static _intarg(x, method) {
        const type = typeof x;
        if (type === 'bigint') {
            return Rsx_Money_Core.check(x);
        }
        if (type === 'number' && Number.isSafeInteger(x)) {
            return BigInt(x);
        }
        if (type === 'string' && /^-?[0-9]+$/.test(x)) {
            return Rsx_Money_Core.int(x);
        }

        throw Rsx_Money_Exception.argument(method, 'expected an integer, got ' + Rsx_Money._describe(x));
    }

    /** FACTOR(x): a decimal factor as [units, places], both BigInt. */
    static _factor(x, method) {
        const type = typeof x;

        // 1
        if (type === 'bigint') {
            return [Rsx_Money_Core.check(x), 0n];
        }
        if (type === 'number' && Number.isSafeInteger(x)) {
            return [BigInt(x), 0n];
        }

        // 2
        let s;
        if (type === 'number') {
            s = Rsx_Money._float_door(x, true, true);
        } else if (type === 'string') {
            s = x;
        } else {
            throw Rsx_Money_Exception.argument(method, 'expected a BigInt, a Number or a decimal string factor, got ' + Rsx_Money._describe(x));
        }

        // 3
        const m = Rsx_Money._DECIMAL_PATTERN.exec(s);
        if (!m) {
            throw Rsx_Money_Exception.argument(method, 'the factor is not a plain decimal: ' + Rsx_Money._describe(s));
        }

        // 4
        const parts = Rsx_Money._decimal_parts(m);
        const frac = parts[2].replace(/0+$/, '');

        // 5
        const places = BigInt(frac.length);
        const u = Rsx_Money_Core.int(parts[0] + (parts[1] === '' ? '0' : parts[1]) + frac);

        return [u, places];
    }

    /** The sum steps over a list already checked by LIST. */
    static _sum_cents(list, method) {
        let t = 0n;
        for (const x of list) {
            t = Rsx_Money_Core.add(t, Rsx_Money._money(x, method)._cents());
        }

        return t;
    }

    /** min / max: the FIRST element holding the extreme. */
    static _extreme(list, method, direction) {
        Rsx_Money._list(list, method);
        if (list.length === 0) {
            throw Rsx_Money_Exception.argument(method, 'the list is empty');
        }

        let best = null;
        for (const x of list) {
            const m = Rsx_Money._money(x, method);
            if (best === null || Rsx_Money_Core.compare(m._cents(), best._cents()) === direction) {
                best = m;
            }
        }

        return best;
    }

    /** Normalize format() options. */
    static _format_options(options) {
        const o = { negative: 'sign', symbol: true, grouped: true };
        if (options === null || options === undefined) {
            return o;
        }
        if (!Rsx_Money._is_plain_object(options)) {
            throw Rsx_Money_Exception.argument('format', 'options must be a plain object, got ' + Rsx_Money._describe(options));
        }
        for (const key of Object.keys(options)) {
            const value = options[key];
            switch (key) {
                case 'negative':
                    if (value !== 'sign' && value !== 'parentheses') {
                        throw Rsx_Money_Exception.argument('format', "negative must be 'sign' or 'parentheses', got " + Rsx_Money._describe(value));
                    }
                    break;
                case 'symbol':
                case 'grouped':
                    if (value !== true && value !== false) {
                        throw Rsx_Money_Exception.argument('format', key + ' must be a boolean, got ' + Rsx_Money._describe(value));
                    }
                    break;
                default:
                    throw Rsx_Money_Exception.argument('format', 'unknown option ' + Rsx_Money._describe(key));
            }
            o[key] = value;
        }

        return o;
    }

    /** Remove leading and trailing runs of the whitespace set. */
    static _trim_ws(s) {
        return s.replace(new RegExp('^' + Rsx_Money._WS_CLASS + '+|' + Rsx_Money._WS_CLASS + '+$', 'gu'), '');
    }

    /** Escape a display string for a regular expression. */
    static _regex_escape(x) {
        return x.replace(/[.*+?^${}()|[\]\\\/-]/g, '\\$&');
    }

    /** A plain object: {} or Object.create(null), never an array or a class instance. */
    static _is_plain_object(x) {
        if (!is_object(x) || is_array(x)) {
            return false;
        }
        const proto = Object.getPrototypeOf(x);

        return proto === Object.prototype || proto === null;
    }

    /** A value written for a developer's message. */
    static _describe(value) {
        if (value instanceof Rsx_Money) {
            return 'Rsx_Money(' + value._decimal + ')';
        }
        const type = typeof value;
        if (type === 'undefined') {
            return 'undefined';
        }
        if (type === 'bigint') {
            return str(value) + 'n';
        }
        if (type === 'string') {
            return json_encode(value);
        }
        if (is_array(value)) {
            return 'an array';
        }
        if (value !== null && type === 'object') {
            return 'an object';
        }

        return str(value);
    }
}
