<?php

namespace App\RSpade\Core\Database\Money;

/**
 * The one exception every Rsx_Money operation throws (rsx:man money).
 *
 * `$reason` says what went wrong, one of six:
 *
 *     format     the input is not an amount of money (also: a float carrying residue)
 *     scale      more than two decimal places
 *     range      outside the signed 64-bit range of cents, at any step - or, for an amount
 *                read for or assigned to a money column, beyond what that column can hold
 *                (Rsx_Model_Abstract::money_limit())
 *     rounding   a calculation left a fraction of a cent and ROUND_EXACT was asked for
 *     division   a division by zero
 *     argument   the calling CODE is wrong: a missing mode, a null, a wrong type
 *
 * `format`, `scale` and `range` carry a sentence written for a person, so an endpoint may hand
 * getMessage() to response_form_error() unchanged; is_input_problem() answers exactly those
 * three. The JavaScript class of the same name carries the same reasons and messages.
 */
#[Instantiatable]
final class Rsx_Money_Exception extends \InvalidArgumentException
{
    public const REASON_FORMAT = 'format';
    public const REASON_SCALE = 'scale';
    public const REASON_RANGE = 'range';
    public const REASON_ROUNDING = 'rounding';
    public const REASON_DIVISION = 'division';
    public const REASON_ARGUMENT = 'argument';

    public const MESSAGE_FORMAT = 'This is not an amount of money.';
    public const MESSAGE_RESIDUE = 'This number carries floating-point residue; pass it as a decimal string.';
    public const MESSAGE_SCALE = 'An amount of money has at most two decimal places.';
    public const MESSAGE_RANGE = 'This amount is outside the range the system can hold.';
    public const MESSAGE_COLUMN_RANGE = 'This amount is larger than this field can hold.';
    public const MESSAGE_ROUNDING = 'This calculation does not come to a whole number of cents, and rounding was not allowed.';
    public const MESSAGE_DIVISION = 'An amount of money cannot be divided by zero.';

    /** One of the REASON_* values. */
    public readonly string $reason;

    /**
     * @param string $reason One of the REASON_* values
     * @param string $message The MESSAGE_* text for that reason; free text for `argument`
     */
    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    /**
     * Whether the refusal is about the INPUT (format, scale, range) - a field error to show
     * the person - rather than about the calculation or the calling code.
     *
     * @return bool
     */
    public function is_input_problem(): bool
    {
        return $this->reason === self::REASON_FORMAT
            || $this->reason === self::REASON_SCALE
            || $this->reason === self::REASON_RANGE;
    }

    /** @return self */
    public static function format(): self
    {
        return new self(self::REASON_FORMAT, self::MESSAGE_FORMAT);
    }

    /** @return self */
    public static function residue(): self
    {
        return new self(self::REASON_FORMAT, self::MESSAGE_RESIDUE);
    }

    /** @return self */
    public static function scale(): self
    {
        return new self(self::REASON_SCALE, self::MESSAGE_SCALE);
    }

    /** @return self */
    public static function range(): self
    {
        return new self(self::REASON_RANGE, self::MESSAGE_RANGE);
    }

    /**
     * An amount beyond what its money column can hold (Rsx_Model_Abstract::money_limit()).
     *
     * @return self
     */
    public static function column_range(): self
    {
        return new self(self::REASON_RANGE, self::MESSAGE_COLUMN_RANGE);
    }

    /** @return self */
    public static function rounding(): self
    {
        return new self(self::REASON_ROUNDING, self::MESSAGE_ROUNDING);
    }

    /** @return self */
    public static function division(): self
    {
        return new self(self::REASON_DIVISION, self::MESSAGE_DIVISION);
    }

    /**
     * A refusal of the calling code: `Rsx_Money::<method>(): <what was wrong>.`
     *
     * @param string $method
     * @param string $what
     * @return self
     */
    public static function argument(string $method, string $what): self
    {
        return new self(self::REASON_ARGUMENT, "Rsx_Money::{$method}(): {$what}.");
    }
}
