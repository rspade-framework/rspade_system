<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database\Models;

use LogicException;
use RuntimeException;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;
use App\RSpade\Core\Database\TextTypes\Rsx_Text_Request_Value;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Manifest\Manifest;

/**
 * COLUMN EQUIVALENCE - the ONE answer to "do these two values of this column hold the same
 * value?", decided by the column's DECLARED kind ($columns) and its SCHEMA type, never by
 * guessing from the values.
 *
 * Every "did it change" question in the framework asks here: the model's dirty check
 * (Rsx_Model_Abstract::originalIsEquivalent(), so isDirty() / getChanges(), the audit stamp,
 * the revision diff, after_update() and the realtime frame), the revision writer and the
 * history reader (Revision::meaningful_changes()). Application code asks through
 * Model::same_value($column, $a, $b); the browser has the identical rule in
 * Column_Equivalence.js (Model.same_value() on every generated stub), and one vector file
 * (tests/column_equivalence/resource/column_equivalence_vectors.json) is run by both.
 *
 * THE DESCRIPTOR. A column is described by a small array, the same shape in both languages:
 *
 *   kind            money | text_type | type_ref | integer | decimal | float | boolean |
 *                   date | datetime | time | json | string | exact
 *   nullable        bool - the column accepts NULL
 *   scale           int - a DECIMAL column's digits after the point (decimal, and a
 *                   text_type column whose schema type is DECIMAL)
 *   fsp             int - fractional-second digits (datetime and time only)
 *   blank_distinct  bool - declared ['blank_distinct' => true]: '' is a value, never NULL
 *   text_type       the declared Rsx_Text_Abstract class (text_type only; PHP: the class,
 *                   the browser: its simple name)
 *
 * The declared kind wins over the schema (money, then text_type, then type_ref); every
 * other column takes its kind from the schema type: an integer of any width (an enum and a
 * foreign key included) is `integer`, TINYINT(1) `boolean`, DECIMAL `decimal`, FLOAT and
 * DOUBLE `float`, VARCHAR / CHAR / TEXT / ENUM `string`, DATETIME and TIMESTAMP `datetime`,
 * and a type with no rule of its own (a blob, a SET, a YEAR) `exact`.
 *
 * WHEN TWO VALUES ARE THE SAME. Both sides are first read into the kind's canonical form:
 *
 *   money      Rsx_Money::same() - an Rsx_Money, the envelope, a decimal string or an int
 *   text_type  the TYPE is asked: each side through its own sanitize_encoded(), compared as
 *              stored (a string side is the stored/encoded form, an instance its to_storage(),
 *              a submitted Rsx_Text_Request_Value read through from_request()). A number side
 *              is read as its text first: on a DECIMAL column laid out at the column's scale
 *              by the decimal kind's digit-string arithmetic (60 and 60.0 are '60.0000' on a
 *              DECIMAL(12,4)), elsewhere its plain decimal text - never a float's printing
 *   type_ref   both sides resolved to the registry id (a class name and its id are the same)
 *   integer    equal as integers ("23", 23, 23.0; true / false are 1 / 0)
 *   decimal    equal as exact decimals at the column's scale, rounded as MySQL stores them
 *              (half away from zero) - "1.50", 1.5, "1.5" - by digit-string arithmetic, never
 *              a float epsilon
 *   float      equal as exact decimals (FLOAT / DOUBLE have no declared scale)
 *   boolean    {true, 1, '1'} against {false, 0, '0'}
 *   date       the same calendar date (YYYY-MM-DD); a date followed by a MIDNIGHT time
 *              ('2026-01-02 00:00:00', 'T' or space, H:i or H:i:s, fractional zeros) is that
 *              date - what a driver or an importer writes for one. A non-midnight time, or
 *              any zone suffix (a zoned value names an instant, never a calendar date), is
 *              unreadable
 *   datetime   the same instant at the column's fractional precision: ISO, zoned, '.000' and
 *              second-only forms of one instant are equal; a zoneless value is UTC (what the
 *              column stores); a fraction beyond the precision rounds as MySQL rounds it
 *   time       the same [-]H:i:s at the column's fractional precision (H:i reads as H:i:00)
 *   json       decoded: object keys in any order, list order significant, numbers by value
 *   string     exact (an int or float side reads as its decimal text)
 *   exact      exact, as text
 *
 * BLANK. '' is NULL on a NULLABLE column of any kind, unless it is declared blank_distinct.
 * On a NOT NULL column '' is read by the kind like any other value (a string's '' is a value;
 * an integer's '' reads as nothing and is different from every number).
 *
 * NEVER LOOSENED: 0 / 0.00 / false are never NULL (on a numeric, money or boolean column);
 * '' and NULL differ on a blank_distinct column; a side the kind cannot read (a word on an
 * integer column, an array on a string column, a malformed date) is the same as the other
 * side only when the two are IDENTICAL (===). never_export columns compare normally - only a
 * revision's RECORDING of them is redacted.
 *
 * STORAGE FORM. storage_form() is the value as the column holds it ("1.00" for 1 on a
 * DECIMAL(8,2), 1 for true on a boolean, 'Y-m-d H:i:s.fff' for an ISO instant on a
 * DATETIME(3)) - what a revision records. A value the kind cannot read is returned unchanged.
 */
class Column_Equivalence
{
    public const KIND_MONEY = 'money';
    public const KIND_TEXT_TYPE = 'text_type';
    public const KIND_TYPE_REF = 'type_ref';
    public const KIND_INTEGER = 'integer';
    public const KIND_DECIMAL = 'decimal';
    public const KIND_FLOAT = 'float';
    public const KIND_BOOLEAN = 'boolean';
    public const KIND_DATE = 'date';
    public const KIND_DATETIME = 'datetime';
    public const KIND_TIME = 'time';
    public const KIND_JSON = 'json';
    public const KIND_STRING = 'string';
    public const KIND_EXACT = 'exact';

    /** Every kind, in the order the class docblock lists them. */
    public const KINDS = [
        self::KIND_MONEY, self::KIND_TEXT_TYPE, self::KIND_TYPE_REF, self::KIND_INTEGER,
        self::KIND_DECIMAL, self::KIND_FLOAT, self::KIND_BOOLEAN, self::KIND_DATE,
        self::KIND_DATETIME, self::KIND_TIME, self::KIND_JSON, self::KIND_STRING, self::KIND_EXACT,
    ];

    /** Rsx_Schema's simplified type => the kind a column of it takes (unlisted: exact). */
    private const SCHEMA_KINDS = [
        'integer' => self::KIND_INTEGER,
        'boolean' => self::KIND_BOOLEAN,
        'decimal' => self::KIND_DECIMAL,
        'float' => self::KIND_FLOAT,
        'double' => self::KIND_FLOAT,
        'string' => self::KIND_STRING,
        'text' => self::KIND_STRING,
        'enum' => self::KIND_STRING,
        'date' => self::KIND_DATE,
        'datetime' => self::KIND_DATETIME,
        'time' => self::KIND_TIME,
        'json' => self::KIND_JSON,
    ];

    /**
     * The largest decimal exponent a numeric side may carry. A DOUBLE spans 1e-308..1e308, so
     * a wider exponent is not a number any column of this database stores; refusing it keeps
     * a hostile '1e999999999' from being expanded into a billion-digit string.
     */
    private const MAX_EXPONENT = 400;

    /**
     * Canonical text-type storage by type and raw form. A dirty check asks the same pair
     * several times in one save (isDirty(), the audit stamp, the revision diff), and a
     * sanitizer (HTMLPurifier) is the costly step. Emptied whenever it reaches MEMO_SIZE, so
     * a long-running worker never grows it without end.
     *
     * @var array<string, string>
     */
    private static array $_text_memo = [];

    private const MEMO_SIZE = 256;

    // =========================================================================
    // DESCRIPTORS
    // =========================================================================

    /**
     * Every column's descriptor for a model, or null when the model's table does not exist in
     * the connected database. Memoized for as long as the schema is (Rsx_Schema::derived()).
     *
     * @param string $model_class FQCN or simple name of a model
     * @return array<string, array>|null column => descriptor
     */
    public static function descriptors(string $model_class): ?array
    {
        $fqcn = static::__model_fqcn($model_class);
        $cache_key = 'column_equivalence|' . $fqcn;
        $cached = Rsx_Schema::derived($cache_key);
        if ($cached !== null) {
            return $cached['columns'];
        }

        $shapes = Rsx_Schema::model_columns(Manifest::_normalize_class_name($fqcn));
        $descriptors = null;

        if ($shapes !== null) {
            $descriptors = [];
            foreach ($shapes as $column => $shape) {
                $descriptors[$column] = static::__describe($fqcn, $column, $shape);
            }
        }

        Rsx_Schema::remember_derived($cache_key, ['columns' => $descriptors]);

        return $descriptors;
    }

    /**
     * One column's descriptor. Throws when the model has no such column, or its table does not
     * exist - a caller naming a column holds a fact about the model, so an absence is a broken
     * assumption (as field_length() treats it).
     *
     * @param string $model_class FQCN or simple name of a model
     * @param string $column
     * @return array
     */
    public static function descriptor(string $model_class, string $column): array
    {
        $descriptors = static::descriptors($model_class);

        if ($descriptors === null) {
            throw new RuntimeException(
                "Column_Equivalence: the table of {$model_class} does not exist in the connected database. "
                . 'Run `php artisan migrate`.'
            );
        }

        if (!array_key_exists($column, $descriptors)) {
            throw new RuntimeException("Column_Equivalence: {$model_class} has no column '{$column}'.");
        }

        return $descriptors[$column];
    }

    /**
     * One column's descriptor, or null when the table does not exist or has no such column -
     * for the dirty check, which also sees attributes that are not columns (a selected alias).
     *
     * @param string $model_class
     * @param string $column
     * @return array|null
     */
    public static function descriptor_or_null(string $model_class, string $column): ?array
    {
        return static::descriptors($model_class)[$column] ?? null;
    }

    /**
     * A descriptor as the browser receives it (Field_Length_Script): default-valued keys left
     * out, and a text type named by its simple name.
     *
     * @param array $descriptor
     * @return array
     */
    public static function to_browser(array $descriptor): array
    {
        $out = ['kind' => $descriptor['kind']];

        if ($descriptor['nullable']) {
            $out['nullable'] = true;
        }
        if ($descriptor['scale'] !== null) {
            $out['scale'] = $descriptor['scale'];
        }
        if ($descriptor['fsp'] !== null) {
            $out['fsp'] = $descriptor['fsp'];
        }
        if ($descriptor['blank_distinct']) {
            $out['blank_distinct'] = true;
        }
        if ($descriptor['text_type'] !== null) {
            $out['text_type'] = class_basename($descriptor['text_type']);
        }

        return $out;
    }

    /**
     * A descriptor from its parts, every key present - the form same() and storage_form() take.
     * Missing keys take their defaults; an unknown kind, or a text_type kind with no type,
     * throws.
     *
     * @param array $parts
     * @return array
     */
    public static function normalize_descriptor(array $parts): array
    {
        $kind = $parts['kind'] ?? null;

        if (!in_array($kind, self::KINDS, true)) {
            throw new LogicException('Column_Equivalence: unknown kind ' . var_export($kind, true) . '.');
        }

        $descriptor = [
            'kind' => $kind,
            'nullable' => (bool) ($parts['nullable'] ?? false),
            'scale' => isset($parts['scale']) ? (int) $parts['scale'] : null,
            'fsp' => isset($parts['fsp']) ? (int) $parts['fsp'] : null,
            'blank_distinct' => (bool) ($parts['blank_distinct'] ?? false),
            'text_type' => $parts['text_type'] ?? null,
        ];

        if ($kind === self::KIND_TEXT_TYPE
            && (!is_string($descriptor['text_type']) || !is_subclass_of($descriptor['text_type'], Rsx_Text_Abstract::class))) {
            throw new LogicException('Column_Equivalence: a text_type descriptor names its Rsx_Text_Abstract class.');
        }

        return $descriptor;
    }

    // =========================================================================
    // THE RULE
    // =========================================================================

    /**
     * Whether two values of a column described by $descriptor are the same value.
     *
     * @param array $descriptor See the class docblock (normalize_descriptor() fills defaults)
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    public static function same(array $descriptor, mixed $a, mixed $b): bool
    {
        if ($a === $b && !is_object($a)) {
            return true;
        }

        $descriptor = static::normalize_descriptor($descriptor);

        [$read_a, $canon_a] = static::__canonical($descriptor, $a);
        [$read_b, $canon_b] = static::__canonical($descriptor, $b);

        if (!$read_a || !$read_b) {
            return $a === $b;
        }

        if ($descriptor['kind'] === self::KIND_MONEY) {
            return Rsx_Money::same($canon_a, $canon_b);
        }

        return $canon_a === $canon_b;
    }

    /**
     * The value as the column holds it: what a revision records. A value the kind cannot read
     * is returned unchanged; a non-scalar one (an array on a JSON column) is JSON-encoded.
     *
     * @param array $descriptor
     * @param mixed $value
     * @return mixed
     */
    public static function storage_form(array $descriptor, mixed $value): mixed
    {
        $descriptor = static::normalize_descriptor($descriptor);
        $kind = $descriptor['kind'];

        if ($value === null) {
            return null;
        }

        if ($value === '' && in_array($kind, [self::KIND_STRING, self::KIND_EXACT, self::KIND_TEXT_TYPE, self::KIND_JSON], true)) {
            return '';
        }

        switch ($kind) {
            case self::KIND_TEXT_TYPE:
                if ($value instanceof Rsx_Text_Abstract) {
                    return $value->to_storage();
                }

                return is_scalar($value) ? $value : json_encode($value);

            case self::KIND_JSON:
                return is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            case self::KIND_TYPE_REF:
                [$read, $canon] = static::__canonical($descriptor, $value);
                if ($read && $canon !== null && str_starts_with($canon, 'id:')) {
                    return (int) substr($canon, 3);
                }

                return $value;

            case self::KIND_INTEGER:
                [$read, $canon] = static::__canonical($descriptor, $value);
                if (!$read) {
                    return $value;
                }
                if ($canon === null) {
                    return null;
                }
                $as_int = filter_var($canon, FILTER_VALIDATE_INT);

                return $as_int === false ? $canon : $as_int;

            case self::KIND_MONEY:
                [$read, $canon] = static::__canonical($descriptor, $value);

                return $read ? $canon?->to_decimal() : $value;

            default:
                [$read, $canon] = static::__canonical($descriptor, $value);
                if (!$read) {
                    return is_scalar($value) ? $value : json_encode($value);
                }

                return $canon;
        }
    }

    // =========================================================================
    // CANONICAL FORMS
    // =========================================================================

    /**
     * [readable, canonical] for one side. Canonical is null for NULL (and for '' on a nullable
     * column that is not blank_distinct).
     *
     * @param array $d A normalized descriptor
     * @param mixed $value
     * @return array{0: bool, 1: mixed}
     */
    private static function __canonical(array $d, mixed $value): array
    {
        if ($value === null) {
            return [true, null];
        }

        if ($value === '' && $d['nullable'] && !$d['blank_distinct']) {
            return [true, null];
        }

        $canon = match ($d['kind']) {
            self::KIND_MONEY => static::__money($value),
            self::KIND_TEXT_TYPE => static::__text($d['text_type'], $value, $d['scale']),
            self::KIND_TYPE_REF => static::__type_ref($value),
            self::KIND_INTEGER => static::__integer($value),
            self::KIND_DECIMAL => static::__decimal_at_scale($value, $d['scale']),
            self::KIND_FLOAT => static::__plain_decimal($value),
            self::KIND_BOOLEAN => static::__boolean($value),
            self::KIND_DATE => static::__date($value),
            self::KIND_DATETIME => static::__datetime($value, $d['fsp'] ?? 0),
            self::KIND_TIME => static::__time($value, $d['fsp'] ?? 0),
            self::KIND_JSON => static::__json($value),
            self::KIND_STRING, self::KIND_EXACT => static::__text_scalar($value),
        };

        return $canon === false ? [false, null] : [true, $canon];
    }

    /** @return Rsx_Money|false */
    private static function __money(mixed $value): Rsx_Money|false
    {
        if ($value instanceof Rsx_Money) {
            return $value;
        }

        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_array($value)) {
            return false;
        }

        try {
            return is_array($value) ? Rsx_Money::from_envelope($value) : Rsx_Money::from_decimal($value);
        } catch (Rsx_Money_Exception $e) {
            if ($e->is_input_problem() || $e->reason === Rsx_Money_Exception::REASON_ARGUMENT) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * A text-type side's canonical storage: the type's own sanitizer over its encoded form. A
     * number side (an int or a float - a stored diff written before the column carried its
     * type, a JSON-decoded payload) is read as its text first: at the column's scale when the
     * schema type is DECIMAL (what the column stores for it), its plain decimal text otherwise.
     *
     * @param class-string<Rsx_Text_Abstract> $type
     * @param int|null $scale The DECIMAL column's scale, null for a column of any other type
     * @return string|false
     */
    private static function __text(string $type, mixed $value, ?int $scale): string|false
    {
        if (is_int($value) || is_float($value)) {
            $value = $scale === null ? static::__plain_decimal($value) : static::__decimal_at_scale($value, $scale);

            if ($value === false) {
                return false;
            }
        }

        if ($value instanceof Rsx_Text_Abstract) {
            if (!$value instanceof $type) {
                throw new LogicException(
                    'Column_Equivalence: a ' . class_basename($value) . ' was compared on a ' . class_basename($type)
                    . ' column. Text types do not convert into one another.'
                );
            }
            $raw = $value->to_storage();
        } elseif ($value instanceof Rsx_Text_Request_Value) {
            $raw = $type::from_request($value)->to_storage();
        } elseif (is_string($value)) {
            $raw = $value;
        } else {
            return false;
        }

        $key = $type . "\0" . $raw;

        if (!array_key_exists($key, self::$_text_memo)) {
            if (count(self::$_text_memo) >= self::MEMO_SIZE) {
                self::$_text_memo = [];
            }
            self::$_text_memo[$key] = $type::sanitize_encoded($raw);
        }

        return self::$_text_memo[$key];
    }

    /** 'id:N' for a side the registry knows, 'name:X' for a class name it has never seen. */
    private static function __type_ref(mixed $value): string|false
    {
        if (is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1)) {
            return 'id:' . (int) $value;
        }

        if (!is_string($value) || $value === '') {
            return false;
        }

        $name = class_basename($value);
        $id = Type_Ref_Registry::find_id_by_class_name($name);

        return $id === null ? 'name:' . $name : 'id:' . $id;
    }

    /** The canonical integer text ('-12', '0'), or false. */
    private static function __integer(mixed $value): string|false
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $parts = static::__decimal_parts($value);

        if ($parts === false || $parts['frac'] !== '') {
            return false;
        }

        return static::__layout($parts, null);
    }

    /** The canonical decimal text at a fixed scale ('1.50'), rounded half away from zero. */
    private static function __decimal_at_scale(mixed $value, ?int $scale): string|false
    {
        $parts = static::__decimal_parts($value);

        if ($parts === false) {
            return false;
        }

        return static::__layout(static::__round_parts($parts, $scale ?? 0), $scale ?? 0);
    }

    /** The canonical decimal text with no fixed scale ('1.5', '100'). */
    private static function __plain_decimal(mixed $value): string|false
    {
        $parts = static::__decimal_parts($value);

        return $parts === false ? false : static::__layout($parts, null);
    }

    private static function __boolean(mixed $value): int|false
    {
        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 1.0 => 1,
            $value === false, $value === 0, $value === '0', $value === 0.0, $value === -0.0 => 0,
            default => false,
        };
    }

    /** 'Y-m-d'; a midnight tail ('T' or space, H:i or H:i:s, fractional zeros) is dropped. */
    private static function __date(mixed $value): string|false
    {
        if (!is_string($value) || preg_match('/^((\d{4})-(\d{2})-(\d{2}))(?:[Tt ]00:00(?::00(?:\.0+)?)?)?$/', $value, $m) !== 1) {
            return false;
        }

        [$y, $mo, $d] = [(int) $m[2], (int) $m[3], (int) $m[4]];

        return static::__valid_date($y, $mo, $d) ? $m[1] : false;
    }

    /** 'Y-m-d H:i:s' (UTC) plus '.' and $fsp digits when $fsp > 0. */
    private static function __datetime(mixed $value, int $fsp): string|false
    {
        $pattern = '/^(\d{4})-(\d{2})-(\d{2})[Tt ](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?([Zz]|[+-]\d{2}(?::?\d{2})?)?$/';

        if (!is_string($value) || preg_match($pattern, $value, $m) !== 1) {
            return false;
        }

        [$y, $mo, $d, $h, $mi] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]];
        $s = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
        $frac = $m[7] ?? '';
        $zone = $m[8] ?? '';

        if (!static::__valid_date($y, $mo, $d) || $h > 23 || $mi > 59 || $s > 59) {
            return false;
        }

        $offset = 0;
        if ($zone !== '' && $zone !== 'Z' && $zone !== 'z') {
            $sign = $zone[0] === '-' ? -1 : 1;
            $digits = str_replace(':', '', substr($zone, 1));
            $oh = (int) substr($digits, 0, 2);
            $om = strlen($digits) > 2 ? (int) substr($digits, 2, 2) : 0;
            if ($oh > 23 || $om > 59) {
                return false;
            }
            $offset = $sign * ($oh * 3600 + $om * 60);
        }

        [$carry, $fraction] = static::__round_fraction($frac, $fsp);
        $seconds = static::__days_from_civil($y, $mo, $d) * 86400 + $h * 3600 + $mi * 60 + $s - $offset + $carry;

        $days = intdiv($seconds - static::__floor_mod($seconds, 86400), 86400);
        $rest = static::__floor_mod($seconds, 86400);
        [$cy, $cm, $cd] = static::__civil_from_days($days);

        if ($cy < 0 || $cy > 9999) {
            return false;
        }

        $text = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $cy, $cm, $cd, intdiv($rest, 3600), intdiv($rest % 3600, 60), $rest % 60);

        return $fsp > 0 ? $text . '.' . $fraction : $text;
    }

    /** '[-]HH:MM:SS' (hours at least two digits) plus '.' and $fsp digits when $fsp > 0. */
    private static function __time(mixed $value, int $fsp): string|false
    {
        if (!is_string($value) || preg_match('/^(-)?(\d{1,3}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?$/', $value, $m) !== 1) {
            return false;
        }

        $negative = $m[1] === '-';
        [$h, $mi] = [(int) $m[2], (int) $m[3]];
        $s = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;

        if ($mi > 59 || $s > 59) {
            return false;
        }

        [$carry, $fraction] = static::__round_fraction($m[5] ?? '', $fsp);
        $total = $h * 3600 + $mi * 60 + $s + $carry;

        if ($total === 0 && trim($fraction, '0') === '') {
            $negative = false;
        }

        $text = ($negative ? '-' : '') . sprintf('%02d:%02d:%02d', intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60);

        return $fsp > 0 ? $text . '.' . $fraction : $text;
    }

    /** A canonical encoding of the decoded document: object keys sorted, numbers by value. */
    private static function __json(mixed $value): string|false
    {
        if (is_string($value)) {
            $decoded = json_decode($value, false, 512);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                return false;
            }
            if ($decoded === null) {
                return 'null';
            }
            $value = $decoded;
        }

        return static::__json_encode_canonical($value);
    }

    private static function __json_encode_canonical(mixed $value): string|false
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            $text = static::__plain_decimal($value);

            return $text === false ? false : 'n:' . $text;
        }
        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $is_object = $value instanceof \stdClass || (is_array($value) && !array_is_list($value));

        if (!$is_object && !is_array($value)) {
            return false;
        }

        $items = [];

        if ($is_object) {
            $map = (array) $value;
            $keys = array_map('strval', array_keys($map));
            sort($keys, SORT_STRING);
            foreach ($keys as $key) {
                $inner = static::__json_encode_canonical($map[$key]);
                if ($inner === false) {
                    return false;
                }
                $items[] = json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ':' . $inner;
            }

            return '{' . implode(',', $items) . '}';
        }

        foreach ($value as $item) {
            $inner = static::__json_encode_canonical($item);
            if ($inner === false) {
                return false;
            }
            $items[] = $inner;
        }

        return '[' . implode(',', $items) . ']';
    }

    /** A string side as is; an int or float as its plain decimal text. */
    private static function __text_scalar(mixed $value): string|false
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return static::__plain_decimal($value);
        }

        return false;
    }

    // =========================================================================
    // DECIMAL TEXT ARITHMETIC (mirrored step for step in Column_Equivalence.js)
    // =========================================================================

    /**
     * A number read into sign, integer digits and fraction digits - leading zeros of the
     * integer part and trailing zeros of the fraction removed, negative zero made positive.
     * Takes an int, a finite float (its shortest round-trip text) or a decimal string with an
     * optional exponent; anything else is false.
     *
     * @return array{negative: bool, int: string, frac: string}|false
     */
    private static function __decimal_parts(mixed $value): array|false
    {
        if (is_int($value)) {
            $text = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                return false;
            }
            $text = json_encode($value);
        } elseif (is_string($value)) {
            $text = $value;
        } else {
            return false;
        }

        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', $text, $m) !== 1) {
            return false;
        }

        $int = $m[2];
        $frac = $m[3] ?? '';

        if ($int === '' && $frac === '') {
            return false;
        }

        $exponent = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;

        if (abs($exponent) > self::MAX_EXPONENT) {
            return false;
        }

        $digits = $int . $frac;
        $point = strlen($int) + $exponent;

        if ($point < 0) {
            $digits = str_repeat('0', -$point) . $digits;
            $point = 0;
        } elseif ($point > strlen($digits)) {
            $digits .= str_repeat('0', $point - strlen($digits));
        }

        $int = ltrim(substr($digits, 0, $point), '0');
        $frac = rtrim(substr($digits, $point), '0');
        $negative = $m[1] === '-' && ($int !== '' || $frac !== '');

        return ['negative' => $negative, 'int' => $int, 'frac' => $frac];
    }

    /**
     * Round to $scale fraction digits, half away from zero (how MySQL stores a DECIMAL).
     *
     * @param array{negative: bool, int: string, frac: string} $parts
     * @return array{negative: bool, int: string, frac: string}
     */
    private static function __round_parts(array $parts, int $scale): array
    {
        if (strlen($parts['frac']) <= $scale) {
            return $parts;
        }

        $kept = $parts['int'] . substr($parts['frac'], 0, $scale);
        $round_up = $parts['frac'][$scale] >= '5';

        if ($round_up) {
            $kept = static::__increment_digits($kept === '' ? '0' : $kept);
        }

        $int_length = strlen($kept) - $scale;
        $int = ltrim(substr($kept, 0, max(0, $int_length)), '0');
        $frac = rtrim(substr($kept, max(0, $int_length)), '0');
        $negative = $parts['negative'] && ($int !== '' || $frac !== '');

        return ['negative' => $negative, 'int' => $int, 'frac' => $frac];
    }

    /** '199' -> '200', '99' -> '100'. */
    private static function __increment_digits(string $digits): string
    {
        $i = strlen($digits) - 1;

        while ($i >= 0 && $digits[$i] === '9') {
            $digits[$i] = '0';
            $i--;
        }

        return $i < 0 ? '1' . $digits : substr_replace($digits, (string) ((int) $digits[$i] + 1), $i, 1);
    }

    /**
     * The canonical text: '-12.5', '0', and with a fixed $scale exactly that many fraction
     * digits ('1.50', '0.00').
     */
    private static function __layout(array $parts, ?int $scale): string
    {
        $int = $parts['int'] === '' ? '0' : $parts['int'];
        $frac = $parts['frac'];

        if ($scale !== null) {
            $frac = str_pad($frac, $scale, '0');
        }

        return ($parts['negative'] ? '-' : '') . $int . ($frac === '' ? '' : '.' . $frac);
    }

    /**
     * A fraction-of-a-second digit string rounded half up to $fsp digits: [carry seconds,
     * the $fsp digits].
     *
     * @return array{0: int, 1: string}
     */
    private static function __round_fraction(string $frac, int $fsp): array
    {
        $kept = str_pad(substr($frac, 0, $fsp), $fsp, '0');

        if (strlen($frac) > $fsp && $frac[$fsp] >= '5') {
            if ($fsp === 0 || trim($kept, '9') === '') {
                return [1, str_repeat('0', $fsp)];
            }
            $kept = static::__increment_digits($kept);
        }

        return [0, $kept];
    }

    // =========================================================================
    // CALENDAR ARITHMETIC (proleptic Gregorian, integer only - mirrored in JS)
    // =========================================================================

    private static function __valid_date(int $y, int $m, int $d): bool
    {
        if ($m < 1 || $m > 12 || $d < 1) {
            return false;
        }

        $leap = ($y % 4 === 0 && $y % 100 !== 0) || $y % 400 === 0;
        $days = [31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$m - 1];

        return $d <= $days;
    }

    /** Days since 1970-01-01 of a civil date. */
    private static function __days_from_civil(int $y, int $m, int $d): int
    {
        $y -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    /** The civil date of a day count since 1970-01-01: [y, m, d]. */
    private static function __civil_from_days(int $z): array
    {
        $z += 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $y = $yoe + $era * 400;
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $d = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $m = $mp + ($mp < 10 ? 3 : -9);

        return [$y + ($m <= 2 ? 1 : 0), $m, $d];
    }

    private static function __floor_mod(int $a, int $b): int
    {
        $r = $a % $b;

        return $r < 0 ? $r + $b : $r;
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /**
     * One column's descriptor, from the model's declaration and the column's schema shape.
     *
     * @param class-string<Rsx_Model_Abstract> $fqcn
     * @param string $column
     * @param array $shape Rsx_Schema's column shape
     * @return array
     */
    private static function __describe(string $fqcn, string $column, array $shape): array
    {
        $kind = self::SCHEMA_KINDS[$shape['type']] ?? self::KIND_EXACT;
        $text_type = null;

        if ($fqcn::is_money_column($column)) {
            $kind = self::KIND_MONEY;
        } elseif ($fqcn::text_type_or_null($column) !== null) {
            $kind = self::KIND_TEXT_TYPE;
            $text_type = $fqcn::text_type_for($column);
        } elseif (in_array($column, $fqcn::type_ref_columns(), true)) {
            $kind = self::KIND_TYPE_REF;
        }

        return [
            'kind' => $kind,
            'nullable' => (bool) $shape['nullable'],
            'scale' => $kind === self::KIND_DECIMAL || ($kind === self::KIND_TEXT_TYPE && $shape['type'] === 'decimal')
                ? (int) $shape['scale']
                : null,
            'fsp' => in_array($kind, [self::KIND_DATETIME, self::KIND_TIME], true) ? (int) ($shape['fsp'] ?? 0) : null,
            'blank_distinct' => in_array($column, $fqcn::blank_distinct_columns(), true),
            'text_type' => $text_type,
        ];
    }

    /**
     * The FQCN of a model named by FQCN or simple name; throws for anything that is not a
     * model.
     *
     * @return class-string<Rsx_Model_Abstract>
     */
    private static function __model_fqcn(string $model_class): string
    {
        $record = Manifest::php_model_record(Manifest::_normalize_class_name($model_class));

        if ($record === null) {
            throw new LogicException("Column_Equivalence: {$model_class} is not a model in the manifest's registry.");
        }

        return $record['fqcn'];
    }
}
