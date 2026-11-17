/**
 * Column_Equivalence - the browser twin of the PHP class of the same name: whether two values
 * of a column are the same value, decided by the column's DECLARED kind and its SCHEMA type,
 * never by guessing from the values.
 *
 *     Column_Equivalence.same({kind: 'decimal', nullable: true, scale: 2}, '1.50', 1.5)   // true
 *     Project_Model.same_value('hour_estimate', record.hour_estimate, values.hour_estimate)
 *
 * Model.same_value() (Rsx_Js_Model) builds the descriptor from the page's field-length script
 * (Rsx.__column_equivalence_data), which carries each exported column's descriptor as the PHP
 * model computes it - so the descriptor has ONE builder, on the server.
 *
 * THE DESCRIPTOR: {kind, nullable?, scale?, fsp?, blank_distinct?, text_type?} - the kinds,
 * the rule per kind, BLANK and NEVER LOOSENED are the PHP class docblock, word for word. Every
 * algorithm here is the PHP one step for step (decimal digit-string arithmetic, the integer
 * calendar, the fraction rounding), and one vector file
 * (tests/column_equivalence/resource/column_equivalence_vectors.json) is run by both.
 *
 * WHERE THE BROWSER DIFFERS, by necessity:
 *   - type_ref: the browser holds no type-ref registry. Two class names, or two ids, compare
 *     directly; a class name against an id THROWS (a record payload carries class names only).
 *   - text_type: the type is asked through its JS sanitize_encoded() (the browser's defense-in-
 *     depth filter - a passthrough unless the type declares one), not the server's.
 *   - undefined THROWS: it is no value of any column; pass null for "no value".
 *
 * @Instantiatable
 */
class Column_Equivalence {
    static KINDS = ['money', 'text_type', 'type_ref', 'integer', 'decimal', 'float', 'boolean',
        'date', 'datetime', 'time', 'json', 'string', 'exact'];

    /** See the PHP MAX_EXPONENT: no column of this database stores a wider exponent. */
    static MAX_EXPONENT = 400;

    /**
     * Whether two values of a column described by `descriptor` are the same value.
     *
     * @param {Object} descriptor - {kind, nullable?, scale?, fsp?, blank_distinct?, text_type?}
     * @param {*} a
     * @param {*} b
     * @returns {boolean}
     */
    static same(descriptor, a, b) {
        if (a === undefined || b === undefined) {
            throw new Error('Column_Equivalence.same(): a side is undefined. Pass null for "no value".');
        }

        if (a === b && (a === null || typeof a !== 'object')) {
            return true;
        }

        const d = Column_Equivalence.normalize_descriptor(descriptor);
        const ca = Column_Equivalence._canonical(d, a);
        const cb = Column_Equivalence._canonical(d, b);

        if (!ca.read || !cb.read) {
            return a === b;
        }

        if (d.kind === 'type_ref' && ca.value !== null && cb.value !== null
            && ca.value.slice(0, 3) !== cb.value.slice(0, 3)) {
            throw new Error(
                'Column_Equivalence.same(): a type-ref class name was compared with a type-ref id. The browser holds '
                + 'no type-ref registry - compare class names (what a record payload carries).'
            );
        }

        if (d.kind === 'money') {
            return Rsx_Money.same(ca.value, cb.value);
        }

        return ca.value === cb.value;
    }

    /**
     * A descriptor with every key present. An unknown kind, or a text_type kind whose type is
     * not a known Rsx_Text_Abstract class, throws.
     *
     * @param {Object} parts
     * @returns {Object}
     */
    static normalize_descriptor(parts) {
        if (parts === null || typeof parts !== 'object') {
            throw new Error('Column_Equivalence: a descriptor is an object, given ' + JSON.stringify(parts) + '.');
        }
        if (!Column_Equivalence.KINDS.includes(parts.kind)) {
            throw new Error('Column_Equivalence: unknown kind ' + JSON.stringify(parts.kind) + '.');
        }

        const d = {
            kind: parts.kind,
            nullable: !!parts.nullable,
            scale: parts.scale === undefined || parts.scale === null ? null : Number(parts.scale),
            fsp: parts.fsp === undefined || parts.fsp === null ? null : Number(parts.fsp),
            blank_distinct: !!parts.blank_distinct,
            text_type: null,
        };

        if (d.kind === 'text_type') {
            const type = typeof parts.text_type === 'string' ? Manifest.get_class_by_name(parts.text_type) : parts.text_type;
            if (typeof type !== 'function' || !(type.prototype instanceof Rsx_Text_Abstract)) {
                throw new Error('Column_Equivalence: a text_type descriptor names a known Rsx_Text_Abstract class, got '
                    + JSON.stringify(String(parts.text_type)) + '.');
            }
            d.text_type = type;
        }

        return d;
    }

    // =========================================================================
    // CANONICAL FORMS
    // =========================================================================

    /** {read, value} for one side; value is null for NULL (and blank on a loosened column). */
    static _canonical(d, value) {
        if (value === null) {
            return { read: true, value: null };
        }

        if (value === '' && d.nullable && !d.blank_distinct) {
            return { read: true, value: null };
        }

        let canon;
        switch (d.kind) {
            case 'money': canon = Column_Equivalence._money(value); break;
            case 'text_type': canon = Column_Equivalence._text(d.text_type, value, d.scale); break;
            case 'type_ref': canon = Column_Equivalence._type_ref(value); break;
            case 'integer': canon = Column_Equivalence._integer(value); break;
            case 'decimal': canon = Column_Equivalence._decimal_at_scale(value, d.scale ?? 0); break;
            case 'float': canon = Column_Equivalence._plain_decimal(value); break;
            case 'boolean': canon = Column_Equivalence._boolean(value); break;
            case 'date': canon = Column_Equivalence._date(value); break;
            case 'datetime': canon = Column_Equivalence._datetime(value, d.fsp ?? 0); break;
            case 'time': canon = Column_Equivalence._time(value, d.fsp ?? 0); break;
            case 'json': canon = Column_Equivalence._json(value); break;
            default: canon = Column_Equivalence._text_scalar(value);
        }

        return canon === false ? { read: false, value: null } : { read: true, value: canon };
    }

    static _money(value) {
        if (value instanceof Rsx_Money) {
            return value;
        }

        const is_envelope = value !== null && typeof value === 'object' && !Array.isArray(value);
        if (!is_envelope && typeof value !== 'string' && typeof value !== 'number' && typeof value !== 'bigint') {
            return false;
        }

        try {
            return is_envelope ? Rsx_Money.from_envelope(value) : Rsx_Money.from_decimal(value);
        } catch (e) {
            if (e instanceof Rsx_Money_Exception && (e.is_input_problem() || e.reason === Rsx_Money_Exception.REASON_ARGUMENT)) {
                return false;
            }
            throw e;
        }
    }

    /** A number side is read as its text first: at the column's scale on a DECIMAL column. */
    static _text(type, value, scale) {
        let raw;

        if (typeof value === 'number' || typeof value === 'bigint') {
            value = scale === null
                ? Column_Equivalence._plain_decimal(value)
                : Column_Equivalence._decimal_at_scale(value, scale);

            if (value === false) {
                return false;
            }
        }

        if (value instanceof Rsx_Text_Abstract) {
            if (!(value instanceof type)) {
                throw new Error('Column_Equivalence: a ' + value.constructor.name + ' was compared on a ' + type.name
                    + ' column. Text types do not convert into one another.');
            }
            raw = value.to_storage();
        } else if (value !== null && typeof value === 'object' && Object.hasOwn(value, '__TEXT')) {
            if (typeof value.raw !== 'string') {
                return false;
            }
            raw = value.raw;
        } else if (typeof value === 'string') {
            raw = value;
        } else {
            return false;
        }

        return type.sanitize_encoded(raw);
    }

    static _type_ref(value) {
        if ((typeof value === 'number' && Number.isSafeInteger(value) && value >= 0) || typeof value === 'bigint') {
            return 'id:' + String(value);
        }

        if (typeof value !== 'string' || value === '') {
            return false;
        }

        if (/^[0-9]+$/.test(value)) {
            return 'id:' + String(BigInt(value));
        }

        return 'name:' + value.slice(value.lastIndexOf('\\') + 1);
    }

    static _integer(value) {
        if (value === true || value === false) {
            return value ? '1' : '0';
        }

        const parts = Column_Equivalence._decimal_parts(value);

        if (parts === false || parts.frac !== '') {
            return false;
        }

        return Column_Equivalence._layout(parts, null);
    }

    static _decimal_at_scale(value, scale) {
        const parts = Column_Equivalence._decimal_parts(value);

        if (parts === false) {
            return false;
        }

        return Column_Equivalence._layout(Column_Equivalence._round_parts(parts, scale), scale);
    }

    static _plain_decimal(value) {
        const parts = Column_Equivalence._decimal_parts(value);

        return parts === false ? false : Column_Equivalence._layout(parts, null);
    }

    static _boolean(value) {
        if (value === true || value === 1 || value === '1' || value === 1n) {
            return 1;
        }
        if (value === false || value === 0 || value === '0' || value === 0n) {
            return 0;
        }

        return false;
    }

    /** 'Y-m-d'; a midnight tail ('T' or space, H:i or H:i:s, fractional zeros) is dropped. */
    static _date(value) {
        const m = typeof value === 'string'
            ? /^((\d{4})-(\d{2})-(\d{2}))(?:[Tt ]00:00(?::00(?:\.0+)?)?)?$/.exec(value)
            : null;

        if (m === null) {
            return false;
        }

        return Column_Equivalence._valid_date(Number(m[2]), Number(m[3]), Number(m[4])) ? m[1] : false;
    }

    static _datetime(value, fsp) {
        const pattern = /^(\d{4})-(\d{2})-(\d{2})[Tt ](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?([Zz]|[+-]\d{2}(?::?\d{2})?)?$/;
        const m = typeof value === 'string' ? pattern.exec(value) : null;

        if (m === null) {
            return false;
        }

        const y = Number(m[1]);
        const mo = Number(m[2]);
        const d = Number(m[3]);
        const h = Number(m[4]);
        const mi = Number(m[5]);
        const s = m[6] !== undefined ? Number(m[6]) : 0;
        const frac = m[7] ?? '';
        const zone = m[8] ?? '';

        if (!Column_Equivalence._valid_date(y, mo, d) || h > 23 || mi > 59 || s > 59) {
            return false;
        }

        let offset = 0;
        if (zone !== '' && zone !== 'Z' && zone !== 'z') {
            const sign = zone[0] === '-' ? -1 : 1;
            const digits = zone.slice(1).replace(':', '');
            const oh = Number(digits.slice(0, 2));
            const om = digits.length > 2 ? Number(digits.slice(2, 4)) : 0;
            if (oh > 23 || om > 59) {
                return false;
            }
            offset = sign * (oh * 3600 + om * 60);
        }

        const [carry, fraction] = Column_Equivalence._round_fraction(frac, fsp);
        const seconds = Column_Equivalence._days_from_civil(y, mo, d) * 86400 + h * 3600 + mi * 60 + s - offset + carry;

        const rest = Column_Equivalence._floor_mod(seconds, 86400);
        const days = (seconds - rest) / 86400;
        const [cy, cm, cd] = Column_Equivalence._civil_from_days(days);

        if (cy < 0 || cy > 9999) {
            return false;
        }

        const p2 = (n) => String(n).padStart(2, '0');
        const text = String(cy).padStart(4, '0') + '-' + p2(cm) + '-' + p2(cd) + ' '
            + p2(Math.floor(rest / 3600)) + ':' + p2(Math.floor((rest % 3600) / 60)) + ':' + p2(rest % 60);

        return fsp > 0 ? text + '.' + fraction : text;
    }

    static _time(value, fsp) {
        const m = typeof value === 'string' ? /^(-)?(\d{1,3}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?$/.exec(value) : null;

        if (m === null) {
            return false;
        }

        let negative = m[1] === '-';
        const h = Number(m[2]);
        const mi = Number(m[3]);
        const s = m[4] !== undefined ? Number(m[4]) : 0;

        if (mi > 59 || s > 59) {
            return false;
        }

        const [carry, fraction] = Column_Equivalence._round_fraction(m[5] ?? '', fsp);
        const total = h * 3600 + mi * 60 + s + carry;

        if (total === 0 && fraction.replace(/0/g, '') === '') {
            negative = false;
        }

        const p2 = (n) => String(n).padStart(2, '0');
        const text = (negative ? '-' : '') + p2(Math.floor(total / 3600)) + ':' + p2(Math.floor((total % 3600) / 60)) + ':' + p2(total % 60);

        return fsp > 0 ? text + '.' + fraction : text;
    }

    static _json(value) {
        if (typeof value === 'string') {
            let decoded;
            try {
                decoded = JSON.parse(value);
            } catch (e) {
                // Text that is not JSON: a side the kind cannot read.
                return false;
            }
            value = decoded;
        }

        return Column_Equivalence._json_encode_canonical(value);
    }

    static _json_encode_canonical(value) {
        if (value === null) {
            return 'null';
        }
        if (value === true || value === false) {
            return value ? 'true' : 'false';
        }
        if (typeof value === 'number' || typeof value === 'bigint') {
            const text = Column_Equivalence._plain_decimal(value);
            return text === false ? false : 'n:' + text;
        }
        if (typeof value === 'string') {
            return JSON.stringify(value);
        }

        const items = [];

        if (Array.isArray(value)) {
            for (const item of value) {
                const inner = Column_Equivalence._json_encode_canonical(item);
                if (inner === false) {
                    return false;
                }
                items.push(inner);
            }

            return '[' + items.join(',') + ']';
        }

        const proto = typeof value === 'object' ? Object.getPrototypeOf(value) : undefined;
        if (proto !== Object.prototype && proto !== null) {
            return false;
        }

        for (const key of Object.keys(value).sort()) {
            const inner = Column_Equivalence._json_encode_canonical(value[key]);
            if (inner === false) {
                return false;
            }
            items.push(JSON.stringify(key) + ':' + inner);
        }

        return '{' + items.join(',') + '}';
    }

    static _text_scalar(value) {
        if (typeof value === 'string') {
            return value;
        }

        if (typeof value === 'number' || typeof value === 'bigint') {
            return Column_Equivalence._plain_decimal(value);
        }

        return false;
    }

    // =========================================================================
    // DECIMAL TEXT ARITHMETIC (the PHP class's, step for step)
    // =========================================================================

    static _decimal_parts(value) {
        let text;

        if (typeof value === 'number') {
            if (!Number.isFinite(value)) {
                return false;
            }
            text = String(value);
        } else if (typeof value === 'bigint' || typeof value === 'string') {
            text = String(value);
        } else {
            return false;
        }

        const m = /^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/.exec(text);

        if (m === null) {
            return false;
        }

        let int = m[2];
        let frac = m[3] ?? '';

        if (int === '' && frac === '') {
            return false;
        }

        const exponent = m[4] !== undefined ? Number(m[4]) : 0;

        if (Math.abs(exponent) > Column_Equivalence.MAX_EXPONENT) {
            return false;
        }

        let digits = int + frac;
        let point = int.length + exponent;

        if (point < 0) {
            digits = '0'.repeat(-point) + digits;
            point = 0;
        } else if (point > digits.length) {
            digits = digits + '0'.repeat(point - digits.length);
        }

        int = digits.slice(0, point).replace(/^0+/, '');
        frac = digits.slice(point).replace(/0+$/, '');
        const negative = m[1] === '-' && (int !== '' || frac !== '');

        return { negative, int, frac };
    }

    static _round_parts(parts, scale) {
        if (parts.frac.length <= scale) {
            return parts;
        }

        let kept = parts.int + parts.frac.slice(0, scale);

        if (parts.frac[scale] >= '5') {
            kept = Column_Equivalence._increment_digits(kept === '' ? '0' : kept);
        }

        const int_length = kept.length - scale;
        const int = kept.slice(0, Math.max(0, int_length)).replace(/^0+/, '');
        const frac = kept.slice(Math.max(0, int_length)).replace(/0+$/, '');
        const negative = parts.negative && (int !== '' || frac !== '');

        return { negative, int, frac };
    }

    static _increment_digits(digits) {
        const chars = digits.split('');
        let i = chars.length - 1;

        while (i >= 0 && chars[i] === '9') {
            chars[i] = '0';
            i--;
        }

        if (i < 0) {
            return '1' + chars.join('');
        }

        chars[i] = String(Number(chars[i]) + 1);

        return chars.join('');
    }

    static _layout(parts, scale) {
        const int = parts.int === '' ? '0' : parts.int;
        let frac = parts.frac;

        if (scale !== null) {
            frac = frac.padEnd(scale, '0');
        }

        return (parts.negative ? '-' : '') + int + (frac === '' ? '' : '.' + frac);
    }

    static _round_fraction(frac, fsp) {
        let kept = frac.slice(0, fsp).padEnd(fsp, '0');

        if (frac.length > fsp && frac[fsp] >= '5') {
            if (fsp === 0 || kept.replace(/9/g, '') === '') {
                return [1, '0'.repeat(fsp)];
            }
            kept = Column_Equivalence._increment_digits(kept);
        }

        return [0, kept];
    }

    // =========================================================================
    // CALENDAR ARITHMETIC (proleptic Gregorian, integer only - the PHP class's)
    // =========================================================================

    static _valid_date(y, m, d) {
        if (m < 1 || m > 12 || d < 1) {
            return false;
        }

        const leap = (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0;
        const days = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][m - 1];

        return d <= days;
    }

    static _intdiv(a, b) {
        return Math.trunc(a / b);
    }

    static _days_from_civil(y, m, d) {
        const div = Column_Equivalence._intdiv;
        y -= m <= 2 ? 1 : 0;
        const era = div(y >= 0 ? y : y - 399, 400);
        const yoe = y - era * 400;
        const doy = div(153 * (m + (m > 2 ? -3 : 9)) + 2, 5) + d - 1;
        const doe = yoe * 365 + div(yoe, 4) - div(yoe, 100) + doy;

        return era * 146097 + doe - 719468;
    }

    static _civil_from_days(z) {
        const div = Column_Equivalence._intdiv;
        z += 719468;
        const era = div(z >= 0 ? z : z - 146096, 146097);
        const doe = z - era * 146097;
        const yoe = div(doe - div(doe, 1460) + div(doe, 36524) - div(doe, 146096), 365);
        const y = yoe + era * 400;
        const doy = doe - (365 * yoe + div(yoe, 4) - div(yoe, 100));
        const mp = div(5 * doy + 2, 153);
        const d = doy - div(153 * mp + 2, 5) + 1;
        const m = mp + (mp < 10 ? 3 : -9);

        return [y + (m <= 2 ? 1 : 0), m, d];
    }

    static _floor_mod(a, b) {
        const r = a % b;

        return r < 0 ? r + b : r;
    }
}
