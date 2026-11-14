<?php

namespace App\RSpade\Core\Database\Money;

use App\RSpade\Core\Database\Money\Rsx_Money_Exception;

/**
 * The checked-integer core of Rsx_Money: every quantity inside a money algorithm is a PHP
 * int in the signed 64-bit range, and every primitive here refuses a result outside it with
 * Rsx_Money_Exception (`range`).
 *
 * The check is on the TRUE mathematical result. PHP turns an overflowing int operation into a
 * float, so add / sub / mul test `is_int()` of the result; the three operations PHP does not
 * widen (negating, taking abs of, and dividing PHP_INT_MIN by -1) are tested on their inputs.
 * The JavaScript class of the same name executes the same primitives on BigInt with an
 * explicit bound check, so both languages refuse exactly the same inputs.
 *
 * Internal to Rsx_Money; nothing else calls it.
 */
final class Rsx_Money_Core
{
    /**
     * a + b
     *
     * @param int $a
     * @param int $b
     * @return int
     */
    public static function add(int $a, int $b): int
    {
        $r = $a + $b;
        if (!is_int($r)) {
            throw Rsx_Money_Exception::range();
        }

        return $r;
    }

    /**
     * a - b
     *
     * @param int $a
     * @param int $b
     * @return int
     */
    public static function sub(int $a, int $b): int
    {
        $r = $a - $b;
        if (!is_int($r)) {
            throw Rsx_Money_Exception::range();
        }

        return $r;
    }

    /**
     * a * b
     *
     * @param int $a
     * @param int $b
     * @return int
     */
    public static function mul(int $a, int $b): int
    {
        $r = $a * $b;
        if (!is_int($r)) {
            throw Rsx_Money_Exception::range();
        }

        return $r;
    }

    /**
     * -a
     *
     * @param int $a
     * @return int
     */
    public static function neg(int $a): int
    {
        if ($a === PHP_INT_MIN) {
            throw Rsx_Money_Exception::range();
        }

        return -$a;
    }

    /**
     * |a|
     *
     * @param int $a
     * @return int
     */
    public static function abs(int $a): int
    {
        if ($a === PHP_INT_MIN) {
            throw Rsx_Money_Exception::range();
        }

        return $a < 0 ? -$a : $a;
    }

    /**
     * The quotient truncated toward zero. A zero divisor is the caller's bug.
     *
     * @param int $a
     * @param int $b
     * @return int
     */
    public static function quo(int $a, int $b): int
    {
        if ($b === 0) {
            throw new \LogicException('Rsx_Money_Core::quo(): a zero divisor reached the core.');
        }
        if ($a === PHP_INT_MIN && $b === -1) {
            throw Rsx_Money_Exception::range();
        }

        return intdiv($a, $b);
    }

    /**
     * a - b * quo(a, b): the sign of a; never overflows. A zero divisor is the caller's bug.
     *
     * @param int $a
     * @param int $b
     * @return int
     */
    public static function rem(int $a, int $b): int
    {
        if ($b === 0) {
            throw new \LogicException('Rsx_Money_Core::rem(): a zero divisor reached the core.');
        }
        if ($b === -1) {
            return 0;
        }

        return $a % $b;
    }

    /**
     * 10^k for 0 <= k; k > 18 leaves the range.
     *
     * @param int $k
     * @return int
     */
    public static function pow10(int $k): int
    {
        if ($k < 0) {
            throw new \LogicException('Rsx_Money_Core::pow10(): a negative exponent reached the core.');
        }
        if ($k > 18) {
            throw Rsx_Money_Exception::range();
        }

        $r = 1;
        for ($i = 0; $i < $k; $i++) {
            $r = self::mul($r, 10);
        }

        return $r;
    }

    /**
     * The integer an optionally signed digit string writes. Leading zeros carry nothing;
     * all zeros is 0 with no sign; more than 19 digits, or a value outside the range, is
     * `range`. A string of another shape is the caller's bug.
     *
     * @param string $s Matches ^-?[0-9]+$
     * @return int
     */
    public static function int(string $s): int
    {
        if (preg_match('/^(-?)([0-9]+)$/D', $s, $m) !== 1) {
            throw new \LogicException('Rsx_Money_Core::int(): not a digit string: ' . $s);
        }

        $digits = ltrim($m[2], '0');
        if ($digits === '') {
            return 0;
        }
        if (strlen($digits) > 19) {
            throw Rsx_Money_Exception::range();
        }

        // The cast saturates at the bound, so the round trip catches every overflow.
        $canon = $m[1] . $digits;
        $v = (int) $canon;
        if ((string) $v !== $canon) {
            throw Rsx_Money_Exception::range();
        }

        return $v;
    }

    /**
     * -1, 0 or 1.
     *
     * @param int $a
     * @param int $b
     * @return int
     */
    public static function compare(int $a, int $b): int
    {
        return $a <=> $b;
    }
}
