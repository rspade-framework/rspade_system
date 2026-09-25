<?php

namespace App\RSpade\Core\Database\TextTypes;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;
use App\RSpade\Core\Database\TextTypes\Rsx_Text_Request_Value;

/**
 * The cast that puts a declared text type on a column.
 *
 * Applied automatically from `public static $text_types` - see
 * Rsx_Model_Abstract::__schema_derived_casts(). A column with no declaration never
 * reaches this class and stays an ordinary string.
 *
 * Reads hydrate through from_storage() (trusted, unfiltered). A request value (the Ajax or
 * API envelope, already encoded) routes through from_untrusted_encoded(), so the common
 * `$record->body = $params['body']` is filtered by the type without the endpoint knowing
 * text types exist - which is the point. A BARE STRING is plain text and routes through
 * from_plain_text(): the type encodes it (encode_plain_text()), then sanitizes it. A value
 * of the WRONG type is refused rather than coerced: text types do not convert into one
 * another, and a silent conversion is exactly the class of bug this system exists to make
 * impossible.
 */
#[Instantiatable]
class Rsx_Text_Cast implements CastsAttributes, SerializesCastableAttributes
{
    /**
     * Never cache the assigned OBJECT as the attribute's value.
     *
     * Eloquent's default for a class cast is to remember whatever object was assigned and
     * hand it straight back on the next read, on the assumption that the assigned object
     * IS the cast's domain object. Here it frequently is not: an endpoint assigns a
     * typeless Rsx_Text_Request_Value, and the whole point of assignment is that the
     * column then types and filters it. With caching on, `$model->column = $request_value`
     * followed by `$model->column->is_empty()` would hand back the WRAPPER - unfiltered,
     * untyped - and assign-then-validate, the pattern every endpoint is told to use,
     * would silently validate the wrong thing.
     *
     * Disabled, every read re-hydrates from the stored string through get(), which is
     * from_storage() - an allocation and nothing more, since reads never filter.
     *
     * @var bool
     */
    public bool $withoutObjectCaching = true;

    /**
     * Hydrate the stored string into its declared type.
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array $attributes
     * @return Rsx_Text_Abstract|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Rsx_Text_Abstract
    {
        if ($value === null) {
            return null;
        }

        $type = $model::text_type_for($key);

        return $type::from_storage((string) $value);
    }

    /**
     * Reduce an assigned value to the string the column stores.
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

        $type = $model::text_type_for($key);

        if ($value instanceof $type) {
            return $value->to_storage();
        }

        // A value straight from a request: typeless until now. THIS is where the type is
        // decided - by the column, never by the client's claim - and where the filter runs,
        // exactly once. The claim is discarded without being read.
        if ($value instanceof Rsx_Text_Request_Value) {
            return $type::from_untrusted_encoded($value->_raw())->to_storage();
        }

        // A DIFFERENT text type. Never coerced: the two encodings mean different things,
        // and quietly reinterpreting one as the other is how rich markup ends up rendered
        // as literal text, or plain text ends up trusted as markup.
        if ($value instanceof Rsx_Text_Abstract) {
            throw new \InvalidArgumentException(
                get_class($model) . "::{$key} is " . class_basename($type) . ', got '
                . class_basename($value) . '. Text types do not convert into one another - use '
                . class_basename($type) . '::from_untrusted_encoded($value->to_storage()) to keep its markup, or '
                . class_basename($type) . '::from_plain_text($value->to_plain_text()) to reinterpret it as plain text.'
            );
        }

        if (is_object($value) || is_array($value)) {
            throw new \InvalidArgumentException(
                get_class($model) . "::{$key} is " . class_basename($type) . ', got '
                . (is_array($value) ? 'array' : get_class($value)) . '.'
            );
        }

        // A bare string from an import, a seed, a script or a plain API param - or an int,
        // float or bool, stringified (false becomes '', which is_empty()). It carries no
        // encoding, so it is PLAIN TEXT: the type escapes it into its encoding and filters
        // the result. Encoded content arrives as a typed value or a request envelope above;
        // code holding an encoded string says so with Type::from_untrusted_encoded($encoded).
        return $type::from_plain_text((string) $value)->to_storage();
    }

    /**
     * How the value appears in toArray()/toJson() - the wire envelope the JavaScript
     * side rehydrates. Declared explicitly through SerializesCastableAttributes so the
     * shape is stated here, rather than depending on how Eloquent happens to treat an
     * object it finds in the attribute bag.
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

        return $value->jsonSerialize();
    }
}
