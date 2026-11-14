<?php

namespace App\RSpade\Core\Database\Money;

use App\RSpade\Core\Database\Money\Rsx_Money_Core;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;

/**
 * An amount of money: an immutable count of cents in the signed 64-bit range, with exactly
 * two decimal places (rsx:man money).
 *
 * The JavaScript class of the same name implements every algorithm here step for step, so
 * both languages give the identical result - or the identical refusal - for the same call.
 * No float takes part in a calculation: every quantity is an integer checked by
 * Rsx_Money_Core, and a step whose true result leaves the range throws `range`.
 *
 * Ways in: from_decimal() (a decimal string, whole units as an int, or a float without
 * residue), from_cents(), from_text() (what a person typed, read through the display
 * configuration), from_submitted() (a form or API field; blank is null), from_aggregate()
 * (an SQL total, the one door that rounds), from_storage() (the column value),
 * from_envelope() (the {"__MONEY": "1.00"} wire form), zero().
 *
 * Ways out: to_decimal() (the canonical "1234.56"), to_cents_string(), to_envelope() (also
 * what json_encode() produces), format() and to_input_text(). There is no float or int
 * accessor, and converting the object to a string throws.
 *
 * Every refusal is an Rsx_Money_Exception; its reason names what was wrong.
 */
#[Instantiatable]
final class Rsx_Money implements \JsonSerializable
{
    /** Any remainder moves away from zero. */
    public const ROUND_UP = 'up';

    /** Any remainder is dropped (toward zero). */
    public const ROUND_DOWN = 'down';

    /** Nearest; an exact half goes away from zero. */
    public const ROUND_HALF_UP = 'half_up';

    /** Nearest; an exact half goes to the even neighbour. */
    public const ROUND_HALF_EVEN = 'half_even';

    /** Any remainder throws `rounding`. */
    public const ROUND_EXACT = 'exact';

    /** The one key of the wire envelope {"__MONEY": "1234.56"}. */
    public const ENVELOPE_KEY = '__MONEY';

    /** The framework's display configuration (rsx.money.display). */
    public const DISPLAY_DEFAULTS = [
        'symbol' => '$',
        'symbol_position' => 'before',
        'symbol_spacing' => false,
        'decimal_separator' => '.',
        'group_separator' => ',',
    ];

    private const MODES = [
        self::ROUND_UP,
        self::ROUND_DOWN,
        self::ROUND_HALF_UP,
        self::ROUND_HALF_EVEN,
        self::ROUND_EXACT,
    ];

    private const GROUP_SEPARATORS = [',', '.', "'", ' ', "\u{00A0}", "\u{202F}", ''];

    /** The whitespace a person's input may be wrapped in (from_text()). */
    private const WS_CLASS = '[\x{0009}\x{000A}\x{000B}\x{000C}\x{000D}\x{0020}\x{00A0}\x{2009}\x{202F}]';

    /** A plain decimal: sign, whole digits, fraction digits; no grouping, no exponent. */
    private const DECIMAL_PATTERN = '/^(-?)(?:([0-9]+)(?:\.([0-9]*))?|\.([0-9]+))$/D';

    /** The canonical form. */
    private const CANONICAL_PATTERN = '/^-?(?:0|[1-9][0-9]*)\.[0-9]{2}$/D';

    private const ERROR_PRIMITIVE = 'Rsx_Money cannot be converted to a string: use to_decimal() for the exact value or format() for display.';

    /** The configured display, validated once per process. */
    private static ?array $configured_display = null;

    /** A display installed by _use_display(), or null. */
    private static ?array $display_override = null;

    private readonly int $cents;
    private readonly string $decimal;

    private function __construct(int $cents, string $decimal)
    {
        $this->cents = $cents;
        $this->decimal = $decimal;
    }

    // =========================================================================
    // WAYS IN
    // =========================================================================

    /**
     * A decimal string ("1234.5", "1.500"), an int of WHOLE units (5 is 5.00), or a float
     * whose shortest form has at most 15 significant digits.
     *
     * @param string|int|float $x
     * @return Rsx_Money
     */
    public static function from_decimal(mixed $x): Rsx_Money
    {
        if (is_int($x)) {
            return self::_new(Rsx_Money_Core::mul($x, 100));
        }
        if (is_float($x)) {
            return self::_dec(self::_float_door($x, true, false));
        }
        if (is_string($x)) {
            return self::_dec($x);
        }

        throw Rsx_Money_Exception::argument('from_decimal', 'expected a decimal string, an int or a float, got ' . get_debug_type($x));
    }

    /**
     * A count of cents: an int, or a string of one ("-5", "0").
     *
     * @param int|string $x
     * @return Rsx_Money
     */
    public static function from_cents(mixed $x): Rsx_Money
    {
        if (is_int($x)) {
            return self::_new($x);
        }
        if (is_string($x) && preg_match('/^(?:0|-?[1-9][0-9]*)$/D', $x) === 1) {
            return self::_new(Rsx_Money_Core::int($x));
        }

        throw Rsx_Money_Exception::argument('from_cents', 'expected an int or an integer string, got ' . get_debug_type($x));
    }

    /**
     * What a person typed, read through the display configuration: "$1,234.56", "(500.00)",
     * "-$99.99", "1.500". More than two significant decimals is refused, never rounded.
     *
     * @param string $s
     * @return Rsx_Money
     */
    public static function from_text(mixed $s): Rsx_Money
    {
        // 1
        if (!is_string($s)) {
            throw Rsx_Money_Exception::argument('from_text', 'expected a string, got ' . get_debug_type($s));
        }

        // 2-3
        $s = self::_trim_ws($s);
        if ($s === '') {
            throw Rsx_Money_Exception::format();
        }

        // 4
        $paren = false;
        if (strlen($s) >= 2 && $s[0] === '(' && $s[strlen($s) - 1] === ')') {
            $paren = true;
            $s = substr($s, 1, -1);
        }

        // 5
        $display = self::display();
        $g = $display['group_separator'];
        $dm = preg_quote($display['decimal_separator'], '/');
        $whole = $g === '' ? '([0-9]+)' : '([0-9]+|[1-9][0-9]{0,2}(?:' . preg_quote($g, '/') . '[0-9]{3})+)';
        $num = '(?:' . $whole . '(?:' . $dm . '([0-9]*))?|' . $dm . '([0-9]+))';
        $spc = $display['symbol_spacing'] ? '[\x{0020}\x{00A0}]?' : '';
        $sym = preg_quote($display['symbol'], '/');

        if ($display['symbol'] === '') {
            $pattern = '/^(-?)' . $num . '$/uD';
            $sign_groups = [1];
            $first = 2;
        } elseif ($display['symbol_position'] === 'before') {
            $pattern = '/^(-?)(?:' . $sym . $spc . ')?(-?)' . $num . '$/uD';
            $sign_groups = [1, 2];
            $first = 3;
        } else {
            $pattern = '/^(-?)' . $num . '(?:' . $spc . $sym . ')?$/uD';
            $sign_groups = [1];
            $first = 2;
        }

        // 6
        $matched = preg_match($pattern, $s, $m, PREG_UNMATCHED_AS_NULL);
        if ($matched !== 1) {
            throw Rsx_Money_Exception::format();
        }

        // 7
        $signs = 0;
        foreach ($sign_groups as $group) {
            if ($m[$group] !== null && $m[$group] !== '') {
                $signs++;
            }
        }
        if ($signs > 1) {
            throw Rsx_Money_Exception::format();
        }
        if ($paren && $signs > 0) {
            throw Rsx_Money_Exception::format();
        }

        // 8
        if ($m[$first] !== null) {
            $whole_digits = $g === '' ? $m[$first] : str_replace($g, '', $m[$first]);
            $frac = $m[$first + 1] ?? '';
        } else {
            $whole_digits = '';
            $frac = $m[$first + 2] ?? '';
        }

        // 9
        $sign = ($paren || $signs === 1) ? '-' : '';

        return self::_from_parts($sign, $whole_digits, $frac);
    }

    /**
     * A form or API field. Null and blank are null (never zero); a value is returned as is;
     * a string is read as typed text; an int is whole units; a float goes through the float
     * door; an envelope is decoded. Every refusal is an input problem (format, scale, range).
     *
     * @param mixed $x
     * @return Rsx_Money|null
     */
    public static function from_submitted(mixed $x): ?Rsx_Money
    {
        // 1
        if ($x === null) {
            return null;
        }

        // 2
        if ($x instanceof Rsx_Money) {
            return $x;
        }

        // 3
        if (is_string($x)) {
            if (self::_trim_ws($x) === '') {
                return null;
            }

            return self::from_text($x);
        }

        // 4-5
        if (is_int($x) || is_float($x)) {
            return self::from_decimal($x);
        }

        // 6
        if (is_array($x) && array_key_exists(self::ENVELOPE_KEY, $x)) {
            try {
                return self::from_envelope($x);
            } catch (Rsx_Money_Exception $e) {
                if ($e->reason === Rsx_Money_Exception::REASON_ARGUMENT) {
                    throw Rsx_Money_Exception::format();
                }
                throw $e;
            }
        }

        // 7
        throw Rsx_Money_Exception::format();
    }

    /**
     * An SQL total: null (SUM over no rows) is 0.00, an int is whole units, and a decimal
     * string or a float - floating-point residue included - is rounded half-up to two places
     * by integer arithmetic. The one door that rounds; never for a figure a person entered.
     *
     * @param string|int|float|null $x
     * @return Rsx_Money
     */
    public static function from_aggregate(mixed $x): Rsx_Money
    {
        // 1
        if ($x === null) {
            return self::_new(0);
        }

        // 2
        if (is_int($x)) {
            return self::from_decimal($x);
        }

        // 3
        if (is_float($x)) {
            $s = self::_float_door($x, false, false);
        } elseif (is_string($x)) {
            $s = $x;
        } else {
            throw Rsx_Money_Exception::argument('from_aggregate', 'expected null, a decimal string, an int or a float, got ' . get_debug_type($x));
        }

        // 4
        if (preg_match(self::DECIMAL_PATTERN, $s, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            throw Rsx_Money_Exception::format();
        }

        // 5
        [$sign, $whole, $frac] = self::_decimal_parts($m);
        $frac = rtrim($frac, '0');

        // 6
        if (strlen($frac) <= 2) {
            return self::_from_parts($sign, $whole, $frac);
        }

        // 7
        $n = Rsx_Money_Core::int($sign . ($whole === '' ? '0' : $whole) . substr($frac, 0, 3));

        return self::_new(self::_round($n, 10, self::ROUND_HALF_UP));
    }

    /**
     * The canonical string a DECIMAL(p,2) column returns. Grammar and bound are both checked:
     * a stored value is never trusted to be in range.
     *
     * @param string $s
     * @return Rsx_Money
     */
    public static function from_storage(mixed $s): Rsx_Money
    {
        if (!is_string($s) || preg_match(self::CANONICAL_PATTERN, $s) !== 1 || $s === '-0.00') {
            throw Rsx_Money_Exception::argument('from_storage', 'expected the canonical two-place decimal string, got ' . self::_describe($s));
        }

        return self::_new(Rsx_Money_Core::int(str_replace('.', '', $s)));
    }

    /**
     * The wire envelope: an array whose only key is __MONEY, holding the canonical string.
     *
     * @param array $e
     * @return Rsx_Money
     */
    public static function from_envelope(mixed $e): Rsx_Money
    {
        if (!is_array($e) || array_keys($e) !== [self::ENVELOPE_KEY]) {
            throw Rsx_Money_Exception::argument('from_envelope', 'expected an array whose only key is ' . self::ENVELOPE_KEY . ', got ' . self::_describe($e));
        }

        return self::from_storage($e[self::ENVELOPE_KEY]);
    }

    /**
     * 0.00
     *
     * @return Rsx_Money
     */
    public static function zero(): Rsx_Money
    {
        return self::_new(0);
    }

    // =========================================================================
    // WAYS OUT
    // =========================================================================

    /**
     * The canonical string: "1234.56", "-0.01", "0.00".
     *
     * @return string
     */
    public function to_decimal(): string
    {
        return $this->decimal;
    }

    /**
     * The cents as an integer string: "123456", "-5", "0".
     *
     * @return string
     */
    public function to_cents_string(): string
    {
        return (string) $this->cents;
    }

    /**
     * The wire envelope ['__MONEY' => '1234.56'].
     *
     * @return array
     */
    public function to_envelope(): array
    {
        return [self::ENVELOPE_KEY => $this->decimal];
    }

    /**
     * json_encode() writes the envelope.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->to_envelope();
    }

    /**
     * The amount for display, through the display configuration: "$1,234.56", "-$1,234.56".
     *
     * Options (any subset): negative 'sign' (default) | 'parentheses'; symbol (bool, default
     * true); grouped (bool, default true).
     *
     * @param array|null $options
     * @return string
     */
    public function format(mixed $options = null): string
    {
        $o = self::_format_options($options);
        $display = self::display();

        // 1
        $d = $this->decimal;
        $neg = $d[0] === '-';
        if ($neg) {
            $d = substr($d, 1);
        }
        [$whole, $frac] = explode('.', $d);

        // 2
        $g = $display['group_separator'];
        if ($o['grouped'] && $g !== '') {
            $grouped = '';
            $len = strlen($whole);
            for ($i = 0; $i < $len; $i++) {
                $distance = $len - $i;
                if ($i > 0 && $distance % 3 === 0) {
                    $grouped .= $g;
                }
                $grouped .= $whole[$i];
            }
            $whole = $grouped;
        }

        // 3
        $number = $whole . $display['decimal_separator'] . $frac;

        // 4
        if ($o['symbol'] && $display['symbol'] !== '') {
            $sp = $display['symbol_spacing'] ? "\u{00A0}" : '';
            $body = $display['symbol_position'] === 'before'
                ? $display['symbol'] . $sp . $number
                : $number . $sp . $display['symbol'];
        } else {
            $body = $number;
        }

        // 5
        if ($neg) {
            return $o['negative'] === 'parentheses' ? '(' . $body . ')' : '-' . $body;
        }

        return $body;
    }

    /**
     * What an edit box shows: no symbol, no grouping ("-1234.56", "1234,56").
     *
     * @return string
     */
    public function to_input_text(): string
    {
        return $this->format(['symbol' => false, 'grouped' => false]);
    }

    /**
     * Refused: a money value is never silently a string.
     *
     * @return string
     */
    public function __toString(): string
    {
        throw new \LogicException(self::ERROR_PRIMITIVE);
    }

    // =========================================================================
    // QUESTIONS
    // =========================================================================

    /**
     * Whether the two amounts are equal; null is never equal.
     *
     * @param Rsx_Money|null $other
     * @return bool
     */
    public function equals(mixed $other): bool
    {
        if ($other === null) {
            return false;
        }
        $b = self::_money($other, 'equals');

        return $this->cents === $b->cents;
    }

    /**
     * The null-safe pair form: both null is the same, one null differs.
     *
     * @param Rsx_Money|null $a
     * @param Rsx_Money|null $b
     * @return bool
     */
    public static function same(mixed $a, mixed $b): bool
    {
        if ($a !== null) {
            self::_money($a, 'same');
        }
        if ($b !== null) {
            self::_money($b, 'same');
        }
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }

        return $a->cents === $b->cents;
    }

    /**
     * -1, 0 or 1.
     *
     * @param Rsx_Money $other
     * @return int
     */
    public function compare(mixed $other): int
    {
        $b = self::_money($other, 'compare');

        return Rsx_Money_Core::compare($this->cents, $b->cents);
    }

    /**
     * @param Rsx_Money $other
     * @return bool
     */
    public function less_than(mixed $other): bool
    {
        return $this->cents < self::_money($other, 'less_than')->cents;
    }

    /**
     * @param Rsx_Money $other
     * @return bool
     */
    public function less_than_or_equal(mixed $other): bool
    {
        return $this->cents <= self::_money($other, 'less_than_or_equal')->cents;
    }

    /**
     * @param Rsx_Money $other
     * @return bool
     */
    public function greater_than(mixed $other): bool
    {
        return $this->cents > self::_money($other, 'greater_than')->cents;
    }

    /**
     * @param Rsx_Money $other
     * @return bool
     */
    public function greater_than_or_equal(mixed $other): bool
    {
        return $this->cents >= self::_money($other, 'greater_than_or_equal')->cents;
    }

    /** @return bool */
    public function is_zero(): bool
    {
        return $this->cents === 0;
    }

    /** @return bool */
    public function is_negative(): bool
    {
        return $this->cents < 0;
    }

    /** @return bool */
    public function is_positive(): bool
    {
        return $this->cents > 0;
    }

    /**
     * low <= this <= high, inclusive.
     *
     * @param Rsx_Money $low
     * @param Rsx_Money $high
     * @return bool
     */
    public function is_between(mixed $low, mixed $high): bool
    {
        $lo = self::_money($low, 'is_between');
        $hi = self::_money($high, 'is_between');
        if ($lo->cents > $hi->cents) {
            throw Rsx_Money_Exception::argument('is_between', 'low is greater than high');
        }

        return $lo->cents <= $this->cents && $this->cents <= $hi->cents;
    }

    // =========================================================================
    // ARITHMETIC
    // =========================================================================

    /**
     * @param Rsx_Money $other
     * @return Rsx_Money
     */
    public function add(mixed $other): Rsx_Money
    {
        $b = self::_money($other, 'add');

        return self::_new(Rsx_Money_Core::add($this->cents, $b->cents));
    }

    /**
     * @param Rsx_Money $other
     * @return Rsx_Money
     */
    public function subtract(mixed $other): Rsx_Money
    {
        $b = self::_money($other, 'subtract');

        return self::_new(Rsx_Money_Core::sub($this->cents, $b->cents));
    }

    /** @return Rsx_Money */
    public function negate(): Rsx_Money
    {
        return self::_new(Rsx_Money_Core::neg($this->cents));
    }

    /** @return Rsx_Money */
    public function abs(): Rsx_Money
    {
        return self::_new(Rsx_Money_Core::abs($this->cents));
    }

    /**
     * This amount times a decimal factor, rounded once.
     *
     * @param int|string|float $factor
     * @param string $rounding One of the ROUND_* constants
     * @return Rsx_Money
     */
    public function multiply(mixed $factor, mixed $rounding): Rsx_Money
    {
        $mode = self::_mode($rounding, 'multiply');
        [$u, $s] = self::_factor($factor, 'multiply');
        $d = Rsx_Money_Core::pow10($s);
        $n = Rsx_Money_Core::mul($this->cents, $u);

        return self::_new(self::_round($n, $d, $mode));
    }

    /**
     * This amount times numerator / denominator - one calculation, one rounding.
     *
     * @param int|string $numerator
     * @param int|string $denominator
     * @param string $rounding One of the ROUND_* constants
     * @return Rsx_Money
     */
    public function multiply_fraction(mixed $numerator, mixed $denominator, mixed $rounding): Rsx_Money
    {
        $mode = self::_mode($rounding, 'multiply_fraction');
        $big_n = self::_intarg($numerator, 'multiply_fraction');
        $big_d = self::_intarg($denominator, 'multiply_fraction');
        if ($big_d === 0) {
            throw Rsx_Money_Exception::division();
        }
        $n = Rsx_Money_Core::mul($this->cents, $big_n);
        if ($big_d < 0) {
            $n = Rsx_Money_Core::neg($n);
            $big_d = Rsx_Money_Core::neg($big_d);
        }

        return self::_new(self::_round($n, $big_d, $mode));
    }

    /**
     * `rate` percent of this amount: percent('7.25', $mode) is 7.25%.
     *
     * @param int|string|float $rate
     * @param string $rounding One of the ROUND_* constants
     * @return Rsx_Money
     */
    public function percent(mixed $rate, mixed $rounding): Rsx_Money
    {
        $mode = self::_mode($rounding, 'percent');
        [$u, $s] = self::_factor($rate, 'percent');
        $k = Rsx_Money_Core::add($s, 2);
        $d = Rsx_Money_Core::pow10($k);
        $n = Rsx_Money_Core::mul($this->cents, $u);

        return self::_new(self::_round($n, $d, $mode));
    }

    /**
     * Integer division of the cents: quotient * divisor + remainder is this amount, and the
     * remainder carries this amount's sign.
     *
     * @param int|string $divisor
     * @return array{quotient: Rsx_Money, remainder: Rsx_Money}
     */
    public function divide(mixed $divisor): array
    {
        $n = self::_intarg($divisor, 'divide');
        if ($n === 0) {
            throw Rsx_Money_Exception::division();
        }
        $q = Rsx_Money_Core::quo($this->cents, $n);
        $r = Rsx_Money_Core::rem($this->cents, $n);

        return ['quotient' => self::_new($q), 'remainder' => self::_new($r)];
    }

    /**
     * This amount divided by a decimal divisor, rounded once.
     *
     * @param int|string|float $divisor
     * @param string $rounding One of the ROUND_* constants
     * @return Rsx_Money
     */
    public function divide_rounded(mixed $divisor, mixed $rounding): Rsx_Money
    {
        $mode = self::_mode($rounding, 'divide_rounded');
        [$u, $s] = self::_factor($divisor, 'divide_rounded');
        if ($u === 0) {
            throw Rsx_Money_Exception::division();
        }
        $p = Rsx_Money_Core::pow10($s);
        $n = Rsx_Money_Core::mul($this->cents, $p);
        if ($u < 0) {
            $n = Rsx_Money_Core::neg($n);
            $u = Rsx_Money_Core::neg($u);
        }

        return self::_new(self::_round($n, $u, $mode));
    }

    /**
     * The nearest multiple of `unit` (1.00, 100.00, 0.05) under the mode.
     *
     * @param Rsx_Money $unit
     * @param string $rounding One of the ROUND_* constants
     * @return Rsx_Money
     */
    public function round_to(mixed $unit, mixed $rounding): Rsx_Money
    {
        $mode = self::_mode($rounding, 'round_to');
        $uc = self::_money($unit, 'round_to')->cents;
        if ($uc <= 0) {
            throw Rsx_Money_Exception::argument('round_to', 'the unit must be positive');
        }
        $q = self::_round($this->cents, $uc, $mode);

        return self::_new(Rsx_Money_Core::mul($q, $uc));
    }

    /**
     * low when below it, high when above it, else this value.
     *
     * @param Rsx_Money $low
     * @param Rsx_Money $high
     * @return Rsx_Money
     */
    public function clamp(mixed $low, mixed $high): Rsx_Money
    {
        $lo = self::_money($low, 'clamp');
        $hi = self::_money($high, 'clamp');
        if ($lo->cents > $hi->cents) {
            throw Rsx_Money_Exception::argument('clamp', 'low is greater than high');
        }
        if ($this->cents < $lo->cents) {
            return $lo;
        }
        if ($this->cents > $hi->cents) {
            return $hi;
        }

        return $this;
    }

    /**
     * Split this amount by integer ratios - largest remainder, stable order; the parts sum
     * to the whole and a zero ratio receives nothing.
     *
     * @param array<int, int|string> $ratios
     * @return array<int, Rsx_Money>
     */
    public function allocate(mixed $ratios): array
    {
        $a = $this->cents;

        // 1
        self::_list($ratios, 'allocate');
        if (count($ratios) === 0) {
            throw Rsx_Money_Exception::argument('allocate', 'the ratios are empty');
        }

        // 2
        $r = [];
        foreach ($ratios as $element) {
            $ri = self::_intarg($element, 'allocate');
            if ($ri < 0) {
                throw Rsx_Money_Exception::argument('allocate', 'a ratio is negative');
            }
            $r[] = $ri;
        }

        // 3
        $total = 0;
        foreach ($r as $ri) {
            $total = Rsx_Money_Core::add($total, $ri);
        }

        // 4
        if ($total === 0) {
            throw Rsx_Money_Exception::argument('allocate', 'the ratios sum to zero');
        }

        // 5
        $parts = [];
        $rems = [];
        foreach ($r as $i => $ri) {
            $p = Rsx_Money_Core::mul($a, $ri);
            $parts[$i] = Rsx_Money_Core::quo($p, $total);
            $rems[$i] = Rsx_Money_Core::rem($p, $total);
        }

        // 6
        $dist = 0;
        foreach ($parts as $part) {
            $dist = Rsx_Money_Core::add($dist, $part);
        }

        // 7
        $left = Rsx_Money_Core::sub($a, $dist);

        // 8
        $unit = $a < 0 ? -1 : 1;
        $count = Rsx_Money_Core::abs($left);

        // 9
        $order = array_keys($r);
        $magnitudes = [];
        foreach ($rems as $i => $rem) {
            $magnitudes[$i] = Rsx_Money_Core::abs($rem);
        }
        usort($order, function (int $x, int $y) use ($magnitudes): int {
            if ($magnitudes[$x] !== $magnitudes[$y]) {
                return $magnitudes[$y] <=> $magnitudes[$x];
            }

            return $x <=> $y;
        });

        // 10
        for ($j = 0; $j < $count; $j++) {
            $i = $order[$j];
            $parts[$i] = Rsx_Money_Core::add($parts[$i], $unit);
        }

        // 11
        $result = [];
        foreach ($parts as $part) {
            $result[] = self::_new($part);
        }

        return $result;
    }

    // =========================================================================
    // LISTS (a null element is refused: the caller filters first)
    // =========================================================================

    /**
     * The total; an empty list is 0.00.
     *
     * @param array<int, Rsx_Money> $list
     * @return Rsx_Money
     */
    public static function sum(mixed $list): Rsx_Money
    {
        self::_list($list, 'sum');

        return self::_new(self::_sum_cents($list, 'sum'));
    }

    /**
     * The first element holding the smallest amount.
     *
     * @param array<int, Rsx_Money> $list
     * @return Rsx_Money
     */
    public static function min(mixed $list): Rsx_Money
    {
        return self::_extreme($list, 'min', -1);
    }

    /**
     * The first element holding the largest amount.
     *
     * @param array<int, Rsx_Money> $list
     * @return Rsx_Money
     */
    public static function max(mixed $list): Rsx_Money
    {
        return self::_extreme($list, 'max', 1);
    }

    /**
     * The mean, rounded once.
     *
     * @param array<int, Rsx_Money> $list
     * @param string $rounding One of the ROUND_* constants
     * @return Rsx_Money
     */
    public static function average(mixed $list, mixed $rounding): Rsx_Money
    {
        $mode = self::_mode($rounding, 'average');
        self::_list($list, 'average');
        if (count($list) === 0) {
            throw Rsx_Money_Exception::argument('average', 'the list is empty');
        }
        $t = self::_sum_cents($list, 'average');

        return self::_new(self::_round($t, count($list), $mode));
    }

    /**
     * The total after each element, in order.
     *
     * @param array<int, Rsx_Money> $list
     * @return array<int, Rsx_Money>
     */
    public static function running_totals(mixed $list): array
    {
        self::_list($list, 'running_totals');
        $t = 0;
        $result = [];
        foreach ($list as $x) {
            $t = Rsx_Money_Core::add($t, self::_money($x, 'running_totals')->cents);
            $result[] = self::_new($t);
        }

        return $result;
    }

    // =========================================================================
    // PROPORTIONS (decimal strings, never money)
    // =========================================================================

    /**
     * this / other as a decimal string with `places` places: 50.00 to 200.00 is "0.25".
     *
     * @param Rsx_Money $other
     * @param int|string $places
     * @param string $rounding One of the ROUND_* constants
     * @return string
     */
    public function ratio_to(mixed $other, mixed $places, mixed $rounding): string
    {
        $mode = self::_mode($rounding, 'ratio_to');
        $b = self::_money($other, 'ratio_to')->cents;
        $p = self::_intarg($places, 'ratio_to');
        if ($p < 0) {
            throw Rsx_Money_Exception::argument('ratio_to', 'places is negative');
        }
        if ($b === 0) {
            throw Rsx_Money_Exception::division();
        }
        $m = Rsx_Money_Core::pow10($p);
        $n = Rsx_Money_Core::mul($this->cents, $m);
        $d = $b;
        if ($d < 0) {
            $n = Rsx_Money_Core::neg($n);
            $d = Rsx_Money_Core::neg($d);
        }
        $q = self::_round($n, $d, $mode);

        return self::_layout($q, $p);
    }

    /**
     * (other - this) / this x 100 as a decimal string, `this` signed: 100.00 to 107.25 is
     * "7.25".
     *
     * @param Rsx_Money $other
     * @param int|string $places
     * @param string $rounding One of the ROUND_* constants
     * @return string
     */
    public function percent_change_to(mixed $other, mixed $places, mixed $rounding): string
    {
        $mode = self::_mode($rounding, 'percent_change_to');
        $b = self::_money($other, 'percent_change_to')->cents;
        $p = self::_intarg($places, 'percent_change_to');
        if ($p < 0) {
            throw Rsx_Money_Exception::argument('percent_change_to', 'places is negative');
        }
        $a = $this->cents;
        if ($a === 0) {
            throw Rsx_Money_Exception::division();
        }
        $diff = Rsx_Money_Core::sub($b, $a);
        $k = Rsx_Money_Core::add($p, 2);
        $m = Rsx_Money_Core::pow10($k);
        $n = Rsx_Money_Core::mul($diff, $m);
        $d = $a;
        if ($d < 0) {
            $n = Rsx_Money_Core::neg($n);
            $d = Rsx_Money_Core::neg($d);
        }
        $q = self::_round($n, $d, $mode);

        return self::_layout($q, $p);
    }

    // =========================================================================
    // REQUEST BOUNDARIES
    // =========================================================================

    /**
     * Turn every {__MONEY} envelope in a decoded Ajax request body into an Rsx_Money,
     * recursively, leaving every array without a __MONEY key untouched. Runs at the Ajax
     * boundary BEFORE the text-envelope wrapping, so a money value is never read as text.
     *
     * Only toJSON() writes an envelope, so a malformed one (another key beside __MONEY, a
     * value that is not the canonical two-place string) is a client bug, not a person's typo:
     * it throws Rsx_Money_Exception (`argument`) and fails the request.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function _wrap_request_envelopes(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_key_exists(self::ENVELOPE_KEY, $value)) {
            return self::from_envelope($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::_wrap_request_envelopes($item);
        }

        return $value;
    }

    /**
     * One external-API param declared #[Api_Param(type: 'money')]: null or blank is null; an
     * array carrying __MONEY (the envelope as a JSON object in a JSON body) is the envelope; a
     * string beginning with `{` that decodes to a JSON object carrying __MONEY is the
     * envelope; any other string is a plain decimal ("1234.50"); a JSON int is whole units; a
     * JSON float goes through the float door (residue refused). Any other value - another
     * array, a bool, an object - is refused. Every refusal is an Rsx_Money_Exception, which
     * the validator reports as the field's error.
     *
     * @param mixed $raw
     * @return Rsx_Money|null
     */
    public static function _wrap_api_param(mixed $raw): ?Rsx_Money
    {
        if ($raw === null) {
            return null;
        }

        if (is_string($raw)) {
            if (self::_trim_ws($raw) === '') {
                return null;
            }

            if (str_starts_with($raw, '{')) {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && array_key_exists(self::ENVELOPE_KEY, $decoded)) {
                    return self::from_envelope($decoded);
                }
            }

            return self::from_decimal($raw);
        }

        if (is_int($raw) || is_float($raw)) {
            return self::from_decimal($raw);
        }

        if (is_array($raw) && array_key_exists(self::ENVELOPE_KEY, $raw)) {
            return self::from_envelope($raw);
        }

        throw Rsx_Money_Exception::format();
    }

    // =========================================================================
    // DISPLAY CONFIGURATION
    // =========================================================================

    /**
     * The active display (rsx.money.display), validated. The browser receives the same
     * array as window.rsxapp.money_display.
     *
     * @return array{symbol: string, symbol_position: string, symbol_spacing: bool, decimal_separator: string, group_separator: string}
     */
    public static function display(): array
    {
        if (self::$display_override !== null) {
            return self::$display_override;
        }
        if (self::$configured_display === null) {
            $configured = config('rsx.money.display', []);
            if (!is_array($configured)) {
                throw new \RuntimeException('rsx.money.display must be an array of display keys; got ' . self::_describe($configured) . '.');
            }
            self::$configured_display = self::_validate_display(array_merge(self::DISPLAY_DEFAULTS, $configured));
        }

        return self::$configured_display;
    }

    /**
     * Test seam: run under `$display` merged over the DEFAULTS (validated), or restore the
     * configured display with null.
     *
     * @param array|null $display
     * @return void
     */
    public static function _use_display(?array $display): void
    {
        self::$display_override = $display === null
            ? null
            : self::_validate_display(array_merge(self::DISPLAY_DEFAULTS, $display));
    }

    /**
     * @param array $display
     * @return array
     */
    private static function _validate_display(array $display): array
    {
        foreach ($display as $key => $value) {
            if (!array_key_exists($key, self::DISPLAY_DEFAULTS)) {
                self::_display_violation((string) $key, 'absent (it is not a display key)', $value);
            }
        }

        $symbol = $display['symbol'];
        if (!is_string($symbol) || preg_match('/[0-9\-+().,\'\x{0009}-\x{000D}\x{0020}\x{00A0}\x{2009}\x{202F}]/u', $symbol) !== 0) {
            self::_display_violation('symbol', 'a string with no digit, sign, parenthesis, point, comma, apostrophe or whitespace', $symbol);
        }
        if ($display['symbol_position'] !== 'before' && $display['symbol_position'] !== 'after') {
            self::_display_violation('symbol_position', "'before' or 'after'", $display['symbol_position']);
        }
        if (!is_bool($display['symbol_spacing'])) {
            self::_display_violation('symbol_spacing', 'true or false', $display['symbol_spacing']);
        }
        if ($display['decimal_separator'] !== '.' && $display['decimal_separator'] !== ',') {
            self::_display_violation('decimal_separator', "'.' or ','", $display['decimal_separator']);
        }
        if (!in_array($display['group_separator'], self::GROUP_SEPARATORS, true)
            || $display['group_separator'] === $display['decimal_separator']) {
            self::_display_violation(
                'group_separator',
                "one of ',' '.' \"'\" ' ' U+00A0 U+202F or '' (no grouping), different from decimal_separator",
                $display['group_separator']
            );
        }

        return [
            'symbol' => $symbol,
            'symbol_position' => $display['symbol_position'],
            'symbol_spacing' => $display['symbol_spacing'],
            'decimal_separator' => $display['decimal_separator'],
            'group_separator' => $display['group_separator'],
        ];
    }

    /**
     * @param string $key
     * @param string $rule
     * @param mixed $value
     * @return never
     */
    private static function _display_violation(string $key, string $rule, mixed $value): never
    {
        throw new \RuntimeException("rsx.money.display.{$key} must be {$rule}; got " . self::_describe($value) . '.');
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /**
     * NEW(c): a value of `c` cents.
     *
     * @param int $c
     * @return Rsx_Money
     */
    private static function _new(int $c): Rsx_Money
    {
        return new self($c, self::_layout($c, 2));
    }

    /**
     * LAYOUT(q, p): the integer written with a point p digits from the right.
     *
     * @param int $q
     * @param int $p
     * @return string
     */
    private static function _layout(int $q, int $p): string
    {
        $s = (string) $q;
        $neg = $s[0] === '-';
        $digits = $neg ? substr($s, 1) : $s;
        if ($p > 0) {
            $digits = str_pad($digits, $p + 1, '0', STR_PAD_LEFT);
            $digits = substr($digits, 0, -$p) . '.' . substr($digits, -$p);
        }

        return ($neg ? '-' : '') . $digits;
    }

    /**
     * ROUND(n, d, mode): reduce n by a positive denominator under the mode.
     *
     * @param int $n
     * @param int $d
     * @param string $mode
     * @return int
     */
    private static function _round(int $n, int $d, string $mode): int
    {
        // 1
        if ($d === 1) {
            return $n;
        }

        // 2
        $q = Rsx_Money_Core::quo($n, $d);
        $r = Rsx_Money_Core::rem($n, $d);

        // 3
        if ($r === 0) {
            return $q;
        }

        // 4
        if ($mode === self::ROUND_EXACT) {
            throw Rsx_Money_Exception::rounding();
        }

        // 5
        $ra = Rsx_Money_Core::abs($r);
        $other = Rsx_Money_Core::sub($d, $ra);

        // 6
        switch ($mode) {
            case self::ROUND_UP:
                $away = true;
                break;
            case self::ROUND_DOWN:
                $away = false;
                break;
            case self::ROUND_HALF_UP:
                $away = $ra >= $other;
                break;
            case self::ROUND_HALF_EVEN:
                if ($ra > $other) {
                    $away = true;
                } elseif ($ra < $other) {
                    $away = false;
                } else {
                    $away = Rsx_Money_Core::rem($q, 2) !== 0;
                }
                break;
            default:
                throw new \LogicException('Rsx_Money: an unchecked rounding mode reached ROUND: ' . $mode);
        }

        // 7
        if ($away) {
            $q = $n < 0 ? Rsx_Money_Core::sub($q, 1) : Rsx_Money_Core::add($q, 1);
        }

        // 8
        return $q;
    }

    /**
     * DEC(str): the decimal-string door.
     *
     * @param string $str
     * @return Rsx_Money
     */
    private static function _dec(string $str): Rsx_Money
    {
        // 1
        if (preg_match(self::DECIMAL_PATTERN, $str, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            throw Rsx_Money_Exception::format();
        }

        // 2-5
        [$sign, $whole, $frac] = self::_decimal_parts($m);

        return self::_from_parts($sign, $whole, $frac);
    }

    /**
     * Sign, whole and fraction of a DECIMAL_PATTERN match.
     *
     * @param array $m
     * @return array{0: string, 1: string, 2: string}
     */
    private static function _decimal_parts(array $m): array
    {
        if ($m[2] !== null) {
            return [$m[1], $m[2], $m[3] ?? ''];
        }

        return [$m[1], '', $m[4]];
    }

    /**
     * DEC steps 2-5: strip trailing zeros, refuse a third place, pad, read the cents.
     *
     * @param string $sign '' or '-'
     * @param string $whole Digits, possibly empty
     * @param string $frac Digits, possibly empty
     * @return Rsx_Money
     */
    private static function _from_parts(string $sign, string $whole, string $frac): Rsx_Money
    {
        $frac = rtrim($frac, '0');
        if (strlen($frac) > 2) {
            throw Rsx_Money_Exception::scale();
        }
        $frac = str_pad($frac, 2, '0');

        return self::_new(Rsx_Money_Core::int($sign . ($whole === '' ? '0' : $whole) . $frac));
    }

    /**
     * FLOAT(f, residue_check): the float's shortest round-trip decimal, laid out exactly as
     * ECMAScript Number::toString() lays it out, independently of the precision and
     * serialize_precision ini settings.
     *
     * @param float $f
     * @param bool $residue_check False only for from_aggregate()
     * @param bool $factor_position True where a NaN, an infinity or an exponent form is the
     *                              calling code's mistake (`argument`) rather than input (`format`)
     * @return string
     */
    private static function _float_door(float $f, bool $residue_check, bool $factor_position): string
    {
        // 1
        if (is_nan($f) || is_infinite($f)) {
            throw $factor_position
                ? Rsx_Money_Exception::argument('factor', 'a NaN or an infinity is not a factor')
                : Rsx_Money_Exception::format();
        }

        // 2
        $s = self::_float_to_string($f);

        // 3
        if (str_contains($s, 'e')) {
            throw $factor_position
                ? Rsx_Money_Exception::argument('factor', 'a float in exponent form is not a factor')
                : Rsx_Money_Exception::format();
        }

        // 4
        $sig = strlen(rtrim(ltrim(str_replace(['-', '.'], '', $s), '0'), '0'));

        // 5
        if ($residue_check && $sig > 15) {
            throw Rsx_Money_Exception::residue();
        }

        // 6
        return $s;
    }

    /**
     * ECMAScript Number::toString() of a finite float.
     *
     * @param float $f
     * @return string
     */
    private static function _float_to_string(float $f): string
    {
        if ($f == 0.0) {
            return '0';
        }

        $saved = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $r = var_export($f, true);
        } finally {
            ini_set('serialize_precision', $saved);
        }

        $neg = $r[0] === '-';
        if ($neg) {
            $r = substr($r, 1);
        }

        $e_at = strpos($r, 'E');
        if ($e_at === false) {
            $mantissa = $r;
            $exponent = 0;
        } else {
            $mantissa = substr($r, 0, $e_at);
            $exponent = (int) substr($r, $e_at + 1);
        }

        $point = strpos($mantissa, '.');
        $int_len = $point === false ? strlen($mantissa) : $point;
        $digits = str_replace('.', '', $mantissa);
        $n = $int_len + $exponent;

        $stripped = ltrim($digits, '0');
        $n -= strlen($digits) - strlen($stripped);
        $digits = rtrim($stripped, '0');
        $k = strlen($digits);

        // value = 0.d1d2...dk x 10^n
        if ($k <= $n && $n <= 21) {
            $s = $digits . str_repeat('0', $n - $k);
        } elseif (0 < $n && $n <= 21) {
            $s = substr($digits, 0, $n) . '.' . substr($digits, $n);
        } elseif (-6 < $n && $n <= 0) {
            $s = '0.' . str_repeat('0', -$n) . $digits;
        } else {
            $e = $n - 1;
            $s = $digits[0] . ($k > 1 ? '.' . substr($digits, 1) : '') . 'e' . ($e < 0 ? '-' : '+') . abs($e);
        }

        return ($neg ? '-' : '') . $s;
    }

    /**
     * MODE(m)
     *
     * @param mixed $m
     * @param string $method
     * @return string
     */
    private static function _mode(mixed $m, string $method): string
    {
        if (!is_string($m) || !in_array($m, self::MODES, true)) {
            throw Rsx_Money_Exception::argument($method, 'the rounding mode must be one of the Rsx_Money::ROUND_* constants, got ' . self::_describe($m));
        }

        return $m;
    }

    /**
     * MONEY(x)
     *
     * @param mixed $x
     * @param string $method
     * @return Rsx_Money
     */
    private static function _money(mixed $x, string $method): Rsx_Money
    {
        if (!($x instanceof Rsx_Money)) {
            throw Rsx_Money_Exception::argument($method, 'expected an Rsx_Money, got ' . get_debug_type($x));
        }

        return $x;
    }

    /**
     * LIST(x)
     *
     * @param mixed $x
     * @param string $method
     * @return void
     */
    private static function _list(mixed $x, string $method): void
    {
        if (!is_array($x) || !array_is_list($x)) {
            throw Rsx_Money_Exception::argument($method, 'expected a list, got ' . get_debug_type($x));
        }
    }

    /**
     * INTARG(x): an int, or a string of one.
     *
     * @param mixed $x
     * @param string $method
     * @return int
     */
    private static function _intarg(mixed $x, string $method): int
    {
        if (is_int($x)) {
            return $x;
        }
        if (is_string($x) && preg_match('/^-?[0-9]+$/D', $x) === 1) {
            return Rsx_Money_Core::int($x);
        }

        throw Rsx_Money_Exception::argument($method, 'expected an integer, got ' . self::_describe($x));
    }

    /**
     * FACTOR(x): a decimal factor as [units, places].
     *
     * @param mixed $x
     * @param string $method
     * @return array{0: int, 1: int}
     */
    private static function _factor(mixed $x, string $method): array
    {
        // 1
        if (is_int($x)) {
            return [$x, 0];
        }

        // 2
        if (is_float($x)) {
            $str = self::_float_door($x, true, true);
        } elseif (is_string($x)) {
            $str = $x;
        } else {
            throw Rsx_Money_Exception::argument($method, 'expected an int, a decimal string or a float factor, got ' . get_debug_type($x));
        }

        // 3
        if (preg_match(self::DECIMAL_PATTERN, $str, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            throw Rsx_Money_Exception::argument($method, 'the factor is not a plain decimal: ' . self::_describe($str));
        }

        // 4
        [$sign, $whole, $frac] = self::_decimal_parts($m);
        $frac = rtrim($frac, '0');

        // 5
        $s = strlen($frac);
        $u = Rsx_Money_Core::int($sign . ($whole === '' ? '0' : $whole) . $frac);

        return [$u, $s];
    }

    /**
     * The sum steps over a list already checked by LIST.
     *
     * @param array $list
     * @param string $method
     * @return int
     */
    private static function _sum_cents(array $list, string $method): int
    {
        $t = 0;
        foreach ($list as $x) {
            $t = Rsx_Money_Core::add($t, self::_money($x, $method)->cents);
        }

        return $t;
    }

    /**
     * min / max: the FIRST element holding the extreme.
     *
     * @param mixed $list
     * @param string $method
     * @param int $direction -1 for the smallest, 1 for the largest
     * @return Rsx_Money
     */
    private static function _extreme(mixed $list, string $method, int $direction): Rsx_Money
    {
        self::_list($list, $method);
        if (count($list) === 0) {
            throw Rsx_Money_Exception::argument($method, 'the list is empty');
        }

        $best = null;
        foreach ($list as $x) {
            $m = self::_money($x, $method);
            if ($best === null || Rsx_Money_Core::compare($m->cents, $best->cents) === $direction) {
                $best = $m;
            }
        }

        return $best;
    }

    /**
     * Normalize format() options.
     *
     * @param mixed $options
     * @return array{negative: string, symbol: bool, grouped: bool}
     */
    private static function _format_options(mixed $options): array
    {
        $o = ['negative' => 'sign', 'symbol' => true, 'grouped' => true];
        if ($options === null) {
            return $o;
        }
        // An empty array is no options: PHP cannot tell [] from an empty object.
        if (!is_array($options) || ($options !== [] && array_is_list($options))) {
            throw Rsx_Money_Exception::argument('format', 'options must be an associative array, got ' . self::_describe($options));
        }
        foreach ($options as $key => $value) {
            switch ($key) {
                case 'negative':
                    if ($value !== 'sign' && $value !== 'parentheses') {
                        throw Rsx_Money_Exception::argument('format', "negative must be 'sign' or 'parentheses', got " . self::_describe($value));
                    }
                    break;
                case 'symbol':
                case 'grouped':
                    if (!is_bool($value)) {
                        throw Rsx_Money_Exception::argument('format', "{$key} must be a bool, got " . self::_describe($value));
                    }
                    break;
                default:
                    throw Rsx_Money_Exception::argument('format', 'unknown option ' . self::_describe($key));
            }
            $o[$key] = $value;
        }

        return $o;
    }

    /**
     * Remove leading and trailing runs of the whitespace set; invalid UTF-8 is `format`.
     *
     * @param string $s
     * @return string
     */
    private static function _trim_ws(string $s): string
    {
        $r = preg_replace('/^' . self::WS_CLASS . '+|' . self::WS_CLASS . '+$/uD', '', $s);
        if ($r === null) {
            throw Rsx_Money_Exception::format();
        }

        return $r;
    }

    /**
     * A value written for a developer's message.
     *
     * @param mixed $value
     * @return string
     */
    private static function _describe(mixed $value): string
    {
        if ($value instanceof Rsx_Money) {
            return 'Rsx_Money(' . $value->decimal . ')';
        }
        if (is_object($value)) {
            return get_debug_type($value);
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? get_debug_type($value) : $json;
    }
}
