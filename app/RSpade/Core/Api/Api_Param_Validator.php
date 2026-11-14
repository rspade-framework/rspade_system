<?php

namespace App\RSpade\Core\Api;

use Illuminate\Http\UploadedFile;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\RequestHistory\Request_History;
use App\RSpade\Lib\SignIn\Turnstile\Rsx_Turnstile;

/**
 * Api_Param_Validator - validate + coerce raw request input against baked #[Api_Param] specs.
 *
 * Standalone (array in, array out) so it is directly unit-testable; the one outside read is a
 * money param's column limit (the model's money_limit(), from the live schema).
 * The dispatcher assembles the raw input (route params over GET over JSON/form body) and
 * hands it here with the route's baked 'api_params' list. This class:
 *
 * - rejects any key not declared by an #[Api_Param] (fail loud on typos);
 * - reports each missing required param;
 * - applies declared defaults for absent optional params;
 * - coerces present values to the declared scalar type (string/int/float/bool), reporting
 *   a per-field error when a value cannot be coerced;
 * - passes a string param to the endpoint as the string it is. The external API carries
 *   plain values only: the browser's {__TEXT} / {__MONEY} envelopes are never read here, so a
 *   string that happens to hold one is an ordinary string. A declared text column is written
 *   from it through the door the endpoint chooses (assigned: plain text; through the type's
 *   from_untrusted_encoded(): its encoded form) - rsx:man external_api;
 * - reads a type:'money' param with Rsx_Money::_from_api_param(): a plain decimal string
 *   ("1234.50"), a JSON int of whole units or a JSON float; null or blank is null. The
 *   endpoint receives an Rsx_Money (or null); anything else, an envelope included, is the
 *   field's error with the Rsx_Money_Exception's own message. A money param declared with
 *   column: 'Model_Name.column' is also checked against that column's money_limit(): an
 *   amount beyond it is the field's error "This amount is larger than this field can hold.",
 *   the refusal Model::money_from_submitted() gives, so the endpoint receives an amount the
 *   column holds;
 * - validates a type:'file' param, which is NOT coerced: it must arrive as an UploadedFile
 *   (a multipart part the dispatcher merged in) and that upload must have succeeded. The
 *   UploadedFile instance is passed through to the endpoint untouched.
 *
 * A param spec is: ['name'=>string, 'type'=>string, 'required'=>bool, 'default'=>mixed,
 * 'description'=>?string, 'example'=>mixed, 'column'=>?string] (as baked by
 * Api_Endpoint_ManifestSupport; 'column' only on a money param).
 */
class Api_Param_Validator
{
    /**
     * Validate raw input against the declared param specs.
     *
     * @param array $specs Baked #[Api_Param] specs for the route.
     * @param array $raw   Assembled raw input (already merged route > GET > body).
     * @return array{valid: bool, params: array, fields: array<string,string>}
     *         On success: valid=true, params=validated+coerced values.
     *         On failure: valid=false, fields=per-field error messages.
     */
    public static function validate(array $specs, array $raw): array
    {
        $declared = [];
        foreach ($specs as $spec) {
            $declared[$spec['name']] = $spec;
        }

        $fields = [];
        $params = [];

        // Reject undeclared keys.
        foreach ($raw as $key => $value) {
            // The framework-reserved verification field is never a declared param: an
            // endpoint reads it via Rsx_Turnstile::validate($request), off the Request.
            // It rides in the body of any form carrying the widget, so it must not read
            // as a typo'd parameter - and it is not copied into $params below either
            // (those are built from the declared specs alone).
            if ($key === Rsx_Turnstile::FIELD) {
                continue;
            }

            // The request history's system parameter (Request_History) is accepted on every
            // endpoint and never copied into $params: the dispatcher reads it off the raw
            // input. Only its shape is checked here, with the declared params.
            if ($key === Request_History::REF_PARAM) {
                $problem = Request_History::ref_problem($value);

                if ($problem !== null) {
                    $fields[$key] = ucfirst($problem);
                }

                continue;
            }

            if (!isset($declared[$key])) {
                $fields[$key] = 'Unknown parameter';
            }
        }

        // Validate each declared param.
        foreach ($declared as $name => $spec) {
            $present = array_key_exists($name, $raw);

            if (!$present) {
                if (!empty($spec['required'])) {
                    $fields[$name] = 'This field is required';
                    continue;
                }
                // Absent optional param: apply default only when one was declared.
                if (array_key_exists('default', $spec) && $spec['default'] !== null) {
                    // A money default is a literal in the attribute ('0.00'); the endpoint
                    // receives it as the Rsx_Money a submitted value would be. A default that
                    // is not an amount throws: the declaration is wrong, not the request.
                    // A default beyond the column's limit throws the same way.
                    $params[$name] = $spec['type'] === 'money'
                        ? static::_money_for_column($spec, Rsx_Money::_from_api_param($spec['default']))
                        : $spec['default'];
                }
                continue;
            }

            $coerced = static::_coerce($spec, $raw[$name]);
            if (!$coerced['ok']) {
                $fields[$name] = $coerced['message'] ?? ('Expected ' . $spec['type']);
                continue;
            }

            $params[$name] = $coerced['value'];
        }

        if (!empty($fields)) {
            return ['valid' => false, 'params' => [], 'fields' => $fields];
        }

        return ['valid' => true, 'params' => $params, 'fields' => []];
    }

    /**
     * A money param's amount, refused with Rsx_Money_Exception::column_range() when the param
     * names a column (column: 'Model_Name.column') and the amount is beyond that column's
     * money_limit(). Null, and a param with no column, pass as they are.
     *
     * @param array $spec
     * @param Rsx_Money|null $amount
     * @return Rsx_Money|null
     */
    private static function _money_for_column(array $spec, ?Rsx_Money $amount): ?Rsx_Money
    {
        $column = $spec['column'] ?? null;

        if ($amount === null || $column === null) {
            return $amount;
        }

        [$model, $model_column] = explode('.', $column, 2);
        $record = Manifest::php_model_record($model);

        if ($record === null) {
            shouldnt_happen("Api_Param_Validator: the money param '{$spec['name']}' names {$column}, and {$model} is not a model in the manifest's registry");
        }

        $record['fqcn']::_refuse_beyond_money_limit($model_column, $amount);

        return $amount;
    }

    /**
     * Coerce a single raw value to a declared type. Scalars are coerced; a file is validated
     * and passed through. An optional 'message' overrides the caller's generic field error.
     *
     * @return array{ok: bool, value: mixed, message?: string}
     */
    private static function _coerce(array $spec, mixed $value): array
    {
        $type = $spec['type'];

        // A file param is validated, never coerced: the UploadedFile reaches the endpoint as
        // it arrived. Handled before the is_array() guard below so an array of files reports
        // the file-specific message rather than the generic scalar one.
        if ($type === 'file') {
            if (!($value instanceof UploadedFile)) {
                return [
                    'ok' => false,
                    'value' => null,
                    'message' => 'Expected a single uploaded file part (multipart/form-data)',
                ];
            }

            if (!$value->isValid()) {
                return [
                    'ok' => false,
                    'value' => null,
                    'message' => 'File upload failed: ' . $value->getErrorMessage(),
                ];
            }

            return ['ok' => true, 'value' => $value];
        }

        // An amount of money: never coerced through a float. Read before the compound-value
        // guard below so that an array - an envelope included - is refused with the money
        // message written for the caller (This is not an amount of money.), like any value
        // that is not an amount.
        if ($type === 'money') {
            try {
                return ['ok' => true, 'value' => static::_money_for_column($spec, Rsx_Money::_from_api_param($value))];
            } catch (Rsx_Money_Exception $e) {
                return ['ok' => false, 'value' => null, 'message' => $e->getMessage()];
            }
        }

        // Compound values never satisfy a scalar param. An UploadedFile is named explicitly
        // because SplFileInfo::__toString() would otherwise let a multipart file part coerce
        // silently into a string param as its temp PATH.
        if (is_array($value) || $value instanceof UploadedFile) {
            return ['ok' => false, 'value' => null];
        }

        switch ($type) {
            case 'string':
                if (is_bool($value)) {
                    return ['ok' => false, 'value' => null];
                }
                return ['ok' => true, 'value' => (string) $value];

            case 'int':
                if (is_int($value)) {
                    return ['ok' => true, 'value' => $value];
                }
                // \z, not $: $ also matches before a trailing newline. A value outside
                // PHP's integer range is refused rather than saturated to PHP_INT_MAX.
                if (is_string($value) && preg_match('/\A-?\d+\z/', $value)) {
                    $digits = ltrim(ltrim($value, '-'), '0');
                    $canonical = $digits === '' ? '0' : (str_starts_with($value, '-') ? '-' : '') . $digits;
                    if ((string) (int) $canonical !== $canonical) {
                        return ['ok' => false, 'value' => null, 'message' => 'Integer out of range'];
                    }

                    return ['ok' => true, 'value' => (int) $canonical];
                }
                return ['ok' => false, 'value' => null];

            case 'float':
                if (is_int($value) || is_float($value)) {
                    return ['ok' => true, 'value' => (float) $value];
                }
                if (is_string($value) && is_numeric($value)) {
                    return ['ok' => true, 'value' => (float) $value];
                }
                return ['ok' => false, 'value' => null];

            case 'bool':
                if (is_bool($value)) {
                    return ['ok' => true, 'value' => $value];
                }
                $truthy = ['1', 'true', 'yes', 'on'];
                $falsy = ['0', 'false', 'no', 'off'];
                if (is_int($value)) {
                    $value = (string) $value;
                }
                if (is_string($value)) {
                    $lower = strtolower($value);
                    if (in_array($lower, $truthy, true)) {
                        return ['ok' => true, 'value' => true];
                    }
                    if (in_array($lower, $falsy, true)) {
                        return ['ok' => true, 'value' => false];
                    }
                }
                return ['ok' => false, 'value' => null];

            default:
                // Unreachable: the scan restricts types to the ones handled above.
                shouldnt_happen("Api_Param_Validator: unsupported param type '{$type}'");
        }
    }
}
