<?php

namespace App\RSpade\Core\Database\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;

/**
 * The cast that makes a money column read as an Rsx_Money (rsx:man money).
 *
 * Applied automatically to every column declared ['money' => true] - see
 * Rsx_Model_Abstract::__schema_derived_casts(). The column is a DECIMAL(p,2) (MODEL-MONEY-01),
 * so the database hands back the canonical two-place string, which from_storage() reads.
 *
 * Assignment accepts an Rsx_Money, a strict decimal string ("29.99", "250000"), an int of
 * whole units, or the {__MONEY} envelope array, and stores the canonical two-place string -
 * exactly what MySQL returns for the column, so an unchanged value is never dirty and a
 * no-op save records no revision. A float is refused: it cannot carry an amount exactly.
 * Typed text ("$1,234") is refused too - an endpoint reads a person's input with
 * Model::money_from_submitted('column', $value) first, which turns a bad entry into a field
 * error. An amount beyond what the column can hold (Model::money_limit()) is refused with
 * Rsx_Money_Exception::column_range() before the database sees it; the schema is consulted
 * on assignment only, never when the attribute is read.
 */
#[Instantiatable]
class Rsx_Money_Cast implements CastsAttributes, SerializesCastableAttributes
{
    /**
     * Never cache the assigned value as the attribute's value: every read re-hydrates from
     * the stored string through get(), so a read always answers an Rsx_Money whatever form
     * was assigned (a decimal string, an int, an envelope).
     *
     * @var bool
     */
    public bool $withoutObjectCaching = true;

    /**
     * Hydrate the stored string. A string that is not canonical (a column that is not
     * DECIMAL(p,2)) throws - MODEL-MONEY-01 reports such a column before it is read.
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array $attributes
     * @return Rsx_Money|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Rsx_Money
    {
        if ($value === null) {
            return null;
        }

        return Rsx_Money::from_storage((string) $value);
    }

    /**
     * Reduce an assigned value to the canonical two-place string the column stores.
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array $attributes
     * @return string|null
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $amount = $this->__amount($model, $key, $value);
        $model::_refuse_beyond_money_limit($key, $amount);

        return $amount->to_decimal();
    }

    /**
     * The Rsx_Money an assigned value carries, or the refusal of a value the column never takes.
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value Not null
     * @return Rsx_Money
     */
    private function __amount(Model $model, string $key, mixed $value): Rsx_Money
    {
        if ($value instanceof Rsx_Money) {
            return $value;
        }

        if (is_string($value) || is_int($value)) {
            return Rsx_Money::from_decimal($value);
        }

        if (is_float($value)) {
            throw new Rsx_Money_Exception(
                Rsx_Money_Exception::REASON_ARGUMENT,
                class_basename($model) . "::{$key} is a money column; a float is never assigned to it. "
                . "Assign Rsx_Money::from_decimal('1.00') or the decimal string."
            );
        }

        if (is_array($value) && array_key_exists(Rsx_Money::ENVELOPE_KEY, $value)) {
            return Rsx_Money::from_envelope($value);
        }

        throw new Rsx_Money_Exception(
            Rsx_Money_Exception::REASON_ARGUMENT,
            class_basename($model) . "::{$key} is a money column; it takes an Rsx_Money, a decimal string, "
            . 'an int of whole units or a {__MONEY} envelope, got ' . get_debug_type($value) . '.'
        );
    }

    /**
     * How the value appears in toArray()/toJson(): the {__MONEY: "1234.56"} envelope the
     * browser rehydrates into its Rsx_Money. An external API body carries the decimal string
     * instead: the API's response pass makes it plain (Api_Dispatcher::plain_body()).
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array $attributes
     * @return array<string, string>|null
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        return $value->to_envelope();
    }
}
