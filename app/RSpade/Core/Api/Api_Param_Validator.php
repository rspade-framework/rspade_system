<?php

namespace App\RSpade\Core\Api;

use Illuminate\Http\UploadedFile;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;

/**
 * Api_Param_Validator - validate + coerce raw request input against baked #[Api_Param] specs.
 *
 * Standalone and framework-free (pure array in, array out) so it is directly unit-testable.
 * The dispatcher assembles the raw input (route params over GET over JSON/form body) and
 * hands it here with the route's baked 'api_params' list. This class:
 *
 * - rejects any key not declared by an #[Api_Param] (fail loud on typos);
 * - reports each missing required param;
 * - applies declared defaults for absent optional params;
 * - coerces present values to the declared scalar type (string/int/float/bool), reporting
 *   a per-field error when a value cannot be coerced;
 * - validates a type:'file' param, which is NOT coerced: it must arrive as an UploadedFile
 *   (a multipart part the dispatcher merged in) and that upload must have succeeded. The
 *   UploadedFile instance is passed through to the endpoint untouched.
 *
 * A param spec is: ['name'=>string, 'type'=>string, 'required'=>bool, 'default'=>mixed,
 * 'description'=>?string, 'example'=>mixed] (as baked by Api_Endpoint_ManifestSupport).
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
                    $params[$name] = $spec['default'];
                }
                continue;
            }

            $coerced = static::_coerce($spec['type'], $raw[$name]);
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
     * Coerce a single raw value to a declared type. Scalars are coerced; a file is validated
     * and passed through. An optional 'message' overrides the caller's generic field error.
     *
     * @return array{ok: bool, value: mixed, message?: string}
     */
    private static function _coerce(string $type, mixed $value): array
    {
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
                if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
                    return ['ok' => true, 'value' => (int) $value];
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
                // Unreachable: the scan restricts types to the four above.
                shouldnt_happen("Api_Param_Validator: unsupported param type '{$type}'");
        }
    }
}
