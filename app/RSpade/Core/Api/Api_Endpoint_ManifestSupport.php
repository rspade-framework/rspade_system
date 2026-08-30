<?php

namespace App\RSpade\Core\Api;

use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Support module for building the API routes index from #[Api_Endpoint] attributes.
 *
 * API routes are stored in the main routes manifest (same table as #[Route]) with
 * type 'api', and duplicated into a dedicated api_endpoints catalog for documentation.
 * The dispatcher routes them by exact pattern match; the base controller type and the
 * dispatcher own Bearer authentication.
 *
 * All declaration constraints are enforced here at scan time and fail loud with a
 * RuntimeException naming {fqcn}::{method} and the source file. A declaration that
 * violates any rule breaks the manifest build until fixed.
 *
 * Usage:
 * ```php
 * #[Api_Endpoint('/api/v1/contacts/:id', methods: ['GET'])]
 * #[Api_Param('id', type: 'int', required: true, description: 'Contact ID')]
 * public static function get(Request $request, array $params = []) { }
 * ```
 *
 * Enforced constraints:
 * - Pattern MUST match #^/api/v[0-9]+/# (a segment after the version is required).
 * - methods MUST be a non-empty subset of {GET, POST}.
 * - Declaring class MUST extend Rsx_Api_Controller_Abstract.
 * - Every :param token in the pattern MUST have a matching #[Api_Param].
 * - #[Api_Param] names are unique per method; required:true with a default is a contradiction.
 * - #[Api_Param] type is one of {string, int, float, bool, file} (default 'string').
 * - type 'file' is POST-only, may never be a :route token, and may never carry a default -
 *   there is no multipart body on a GET, a URL segment is not a file, and there is no such
 *   thing as a default upload.
 * - The method MUST NOT also carry #[FPC], #[Route], #[SPA], or #[Ajax_Endpoint].
 * - API-GET-PURE-01: a GET-only handler's body MUST NOT contain a write call
 *   (->save( / ->delete( / ->update( / ::create( / ->raw_bulk( / DB::statement|insert|
 *   update|delete( / Task::dispatch(). 'Grant GET /api/v1/**' is the only way to express
 *   "read-only", and it is trustworthy only while GET cannot mutate. Escape hatch:
 *   '@API-GET-PURE-01-EXCEPTION <rationale>' in the method docblock, rationale required.
 *
 * Docblock parsing:
 * - description: the first PARAGRAPH (consecutive non-tag lines).
 * - @api-hidden: excludes the endpoint from documentation.
 * - @api-response: an example response JSON block, stored verbatim.
 */
class Api_Endpoint_ManifestSupport extends ManifestSupport_Abstract
{
    // FQCN of the base every API controller must extend.
    private const BASE_CONTROLLER_FQCN = 'App\\RSpade\\Core\\Api\\Rsx_Api_Controller_Abstract';

    // Allowed HTTP verbs and #[Api_Param] types for v1.
    private const ALLOWED_METHODS = ['GET', 'POST'];
    private const ALLOWED_PARAM_TYPES = ['string', 'int', 'float', 'bool', 'file'];

    // Attributes forbidden on the same method as #[Api_Endpoint].
    private const CONFLICTING_ATTRIBUTES = ['FPC', 'Route', 'SPA', 'Ajax_Endpoint'];

    /**
     * API-GET-PURE-01: call shapes that write, searched for in a GET-only handler's body
     * with every comment, string literal and space already removed. Deliberately a small
     * literal list rather than a general effect analysis - it catches the realistic cases,
     * names exactly what it found, and has an explicit escape for the rest.
     */
    private const GET_MUTATION_TOKENS = [
        '->save(',
        '->delete(',
        '->update(',
        '::create(',
        '->raw_bulk(',
        'DB::statement(',
        'DB::insert(',
        'DB::update(',
        'DB::delete(',
        'Task::dispatch(',
    ];

    // The docblock tag that waives API-GET-PURE-01 for one handler.
    private const GET_PURE_EXCEPTION_TAG = '@API-GET-PURE-01-EXCEPTION';

    public static function get_name(): string
    {
        return 'API Endpoints';
    }

    public static function process(array &$manifest_data): void
    {
        if (!isset($manifest_data['data']['api_endpoints'])) {
            $manifest_data['data']['api_endpoints'] = [];
        }

        $files = $manifest_data['data']['files'];

        // Index every file's metadata by FQCN so the extends chain can be walked here,
        // without calling Manifest:: query APIs (the manifest is not finalized mid-build).
        $fqcn_index = [];
        foreach ($files as $metadata) {
            if (isset($metadata['fqcn'])) {
                $fqcn_index[$metadata['fqcn']] = $metadata;
            }
        }

        foreach ($files as $file => $metadata) {
            if (!isset($metadata['public_static_methods'])) {
                continue;
            }

            foreach ($metadata['public_static_methods'] as $method_name => $method_data) {
                if (!isset($method_data['attributes'])) {
                    continue;
                }

                foreach ($method_data['attributes'] as $attr_name => $attr_instances) {
                    if (!str_ends_with($attr_name, '\\Api_Endpoint') && $attr_name !== 'Api_Endpoint') {
                        continue;
                    }

                    $fqcn = $metadata['fqcn'] ?? $metadata['class'] ?? '(unknown)';
                    $location = "{$fqcn}::{$method_name} in {$file}";

                    // The declaring class must extend the API base controller.
                    if (!static::_extends_base_controller($metadata, $fqcn_index)) {
                        throw new \RuntimeException(
                            "Invalid #[Api_Endpoint]: {$location}\n" .
                            "  Declaring class must extend " . self::BASE_CONTROLLER_FQCN . "."
                        );
                    }

                    // No other route/dispatch attribute may share the method.
                    foreach (self::CONFLICTING_ATTRIBUTES as $conflict) {
                        if (static::_method_has_attribute($method_data, $conflict)) {
                            throw new \RuntimeException(
                                "Invalid #[Api_Endpoint]: {$location}\n" .
                                "  A method carrying #[Api_Endpoint] must not also carry #[{$conflict}]."
                            );
                        }
                    }

                    foreach ($attr_instances as $route_args) {
                        $pattern = $route_args[0] ?? ($route_args['pattern'] ?? null);
                        $methods = $route_args[1] ?? ($route_args['methods'] ?? ['GET']);
                        $name = $route_args[2] ?? ($route_args['name'] ?? null);

                        if (!$pattern) {
                            throw new \RuntimeException(
                                "Invalid #[Api_Endpoint]: {$location}\n" .
                                "  A route pattern is required."
                            );
                        }

                        if ($pattern[0] !== '/') {
                            $pattern = '/' . $pattern;
                        }

                        // Pattern must start with /api/vN/ and carry a segment after the version.
                        if (!preg_match('#^/api/v[0-9]+/#', $pattern)) {
                            throw new \RuntimeException(
                                "Invalid #[Api_Endpoint] pattern '{$pattern}': {$location}\n" .
                                "  Pattern must match /api/vN/<segment> (e.g. /api/v1/contacts)."
                            );
                        }

                        // Verbs must be a non-empty subset of {GET, POST}.
                        $methods = array_map('strtoupper', (array) $methods);
                        if (empty($methods)) {
                            throw new \RuntimeException(
                                "Invalid #[Api_Endpoint] methods for '{$pattern}': {$location}\n" .
                                "  At least one HTTP verb is required."
                            );
                        }
                        foreach ($methods as $verb) {
                            if (!in_array($verb, self::ALLOWED_METHODS, true)) {
                                throw new \RuntimeException(
                                    "Invalid #[Api_Endpoint] method '{$verb}' for '{$pattern}': {$location}\n" .
                                    "  Only GET and POST are permitted."
                                );
                            }
                        }

                        // Normalize and validate #[Api_Param] declarations for this method.
                        $api_params = static::_parse_api_params($method_data, $pattern, $location, $methods);

                        // Read the docblock directly from source (the manifest does not store docblocks).
                        $docblock = static::_read_method_docblock(base_path($file), $method_name);

                        // API-GET-PURE-01 - a GET endpoint may not mutate.
                        if ($methods === ['GET']) {
                            static::_assert_get_handler_is_pure(base_path($file), $method_name, $docblock, $location);
                        }

                        // Duplicate route detection (pattern must be unique across all route types).
                        if (isset($manifest_data['data']['routes'][$pattern])) {
                            $existing = $manifest_data['data']['routes'][$pattern];
                            throw new \RuntimeException(
                                "Duplicate route definition: {$pattern}\n" .
                                "  Already defined: {$existing['class']}::{$existing['method']} in {$existing['file']}\n" .
                                "  Conflicting: {$location}"
                            );
                        }

                        $description = static::_parse_description($docblock);
                        $is_hidden = str_contains($docblock, '@api-hidden');
                        $response_example = static::_parse_response_example($docblock);

                        $version = static::parse_version($pattern);
                        $path_key = static::path_key($pattern);

                        // Declarative auth gates: class-level #[Auth] then the
                        // method's own (additive). #[Auth] is deliberately NOT in
                        // CONFLICTING_ATTRIBUTES - it is required company, not a
                        // competing dispatch attribute.
                        $auth_gates = Auth_ManifestSupport::merge_gate_lists(
                            $metadata['attributes'] ?? null,
                            $method_data['attributes'] ?? null,
                            $location
                        );

                        // Route entry consumed by the dispatcher and the runtime param validator.
                        $route_data = [
                            'methods' => $methods,
                            'type' => 'api',
                            'class' => $metadata['fqcn'] ?? $metadata['class'],
                            'method' => $method_name,
                            'name' => $name,
                            'file' => $file,
                            'require' => [],
                            'pattern' => $pattern,
                            'api_params' => $api_params,
                            'version' => $version,
                            'path_key' => $path_key,
                            'response_example' => $response_example,
                            'auth' => $auth_gates,
                        ];

                        $manifest_data['data']['routes'][$pattern] = $route_data;

                        // Store by target for URL generation.
                        $target = ($metadata['class'] ?? '') . '::' . $method_name;
                        if (!isset($manifest_data['data']['routes_by_target'][$target])) {
                            $manifest_data['data']['routes_by_target'][$target] = [];
                        }
                        $manifest_data['data']['routes_by_target'][$target][] = $route_data;

                        // Catalog entry consumed by Api_Catalog for documentation.
                        $manifest_data['data']['api_endpoints'][$pattern] = [
                            'pattern' => $pattern,
                            'methods' => $methods,
                            'class' => $metadata['class'] ?? null,
                            'fqcn' => $metadata['fqcn'] ?? null,
                            'method' => $method_name,
                            'description' => $description,
                            'api_params' => $api_params,
                            'version' => $version,
                            'path_key' => $path_key,
                            'response_example' => $response_example,
                            'hidden' => $is_hidden,
                            'file' => $file,
                        ];
                    }
                }
            }
        }

        ksort($manifest_data['data']['api_endpoints']);
    }

    /**
     * Parse the integer version from an API pattern (e.g. '/api/v2/x' -> 2).
     *
     * Twin of the local regex in this module; reused by Api_Catalog at runtime.
     */
    public static function parse_version(string $pattern): int
    {
        if (preg_match('#^/api/v([0-9]+)#', $pattern, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * Strip the leading /api/vN from a pattern to produce the verb-agnostic path key
     * (e.g. '/api/v1/contacts/:id' -> '/contacts/:id').
     */
    public static function path_key(string $pattern): string
    {
        return preg_replace('#^/api/v[0-9]+#', '', $pattern);
    }

    /**
     * Walk the extends chain via extends_fqcn to determine whether the declaring class
     * extends the API base controller. Abstract intermediates are permitted.
     */
    private static function _extends_base_controller(array $metadata, array $fqcn_index): bool
    {
        $current = $metadata['extends_fqcn'] ?? null;
        $guard = 0;

        while ($current && $guard++ < 50) {
            if ($current === self::BASE_CONTROLLER_FQCN) {
                return true;
            }
            $parent = $fqcn_index[$current] ?? null;
            if (!$parent) {
                return false;
            }
            $current = $parent['extends_fqcn'] ?? null;
        }

        return false;
    }

    /**
     * Determine whether a method carries an attribute by short name (matches either the
     * bare name or a namespaced attribute ending in \Name).
     */
    private static function _method_has_attribute(array $method_data, string $short_name): bool
    {
        if (!isset($method_data['attributes'])) {
            return false;
        }

        foreach ($method_data['attributes'] as $attr_name => $instances) {
            if ($attr_name === $short_name || str_ends_with($attr_name, '\\' . $short_name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse and validate #[Api_Param] declarations for a method into normalized specs.
     *
     * @return array List of [name, type, required, default, description, example].
     */
    private static function _parse_api_params(array $method_data, string $pattern, string $location, array $methods): array
    {
        $raw_instances = [];
        foreach (($method_data['attributes'] ?? []) as $attr_name => $instances) {
            if ($attr_name === 'Api_Param' || str_ends_with($attr_name, '\\Api_Param')) {
                $raw_instances = $instances;
                break;
            }
        }

        $params = [];
        $seen_names = [];

        // Constructor argument order for positional use: (name, type, required, default,
        // description, example). Unknown keys fail loud - a typo like 'requred' would
        // otherwise silently weaken validation.
        $positional_order = ['name', 'type', 'required', 'default', 'description', 'example'];

        foreach ($raw_instances as $raw_args) {
            $args = [];
            foreach ($raw_args as $key => $value) {
                if (is_int($key)) {
                    if (!isset($positional_order[$key])) {
                        throw new \RuntimeException(
                            "Invalid #[Api_Param] for '{$pattern}': {$location}\n" .
                            "  Too many positional arguments (max " . count($positional_order) . ")."
                        );
                    }
                    $key = $positional_order[$key];
                } elseif (!in_array($key, $positional_order, true)) {
                    throw new \RuntimeException(
                        "Unknown #[Api_Param] argument '{$key}' for '{$pattern}': {$location}\n" .
                        "  Allowed: " . implode(', ', $positional_order) . "."
                    );
                }
                if (array_key_exists($key, $args)) {
                    throw new \RuntimeException(
                        "Duplicate #[Api_Param] argument '{$key}' for '{$pattern}': {$location}"
                    );
                }
                $args[$key] = $value;
            }

            $name = $args['name'] ?? null;

            if (!is_string($name) || $name === '') {
                throw new \RuntimeException(
                    "Invalid #[Api_Param] for '{$pattern}': {$location}\n" .
                    "  Each #[Api_Param] requires a non-empty name."
                );
            }

            if (isset($seen_names[$name])) {
                throw new \RuntimeException(
                    "Duplicate #[Api_Param] name '{$name}' for '{$pattern}': {$location}\n" .
                    "  Param names must be unique per method."
                );
            }
            $seen_names[$name] = true;

            $type = $args['type'] ?? 'string';
            if (!in_array($type, self::ALLOWED_PARAM_TYPES, true)) {
                throw new \RuntimeException(
                    "Invalid #[Api_Param] type '{$type}' for '{$name}' on '{$pattern}': {$location}\n" .
                    "  Type must be one of: " . implode(', ', self::ALLOWED_PARAM_TYPES) . "."
                );
            }

            $required = (bool) ($args['required'] ?? false);
            $has_default = array_key_exists('default', $args);

            // A file param is a multipart part, not a value: it cannot ride a GET, cannot be a
            // URL segment, and cannot have a default. Each of these is rejected at scan time
            // rather than producing an endpoint that is undocumentable and uncallable.
            if ($type === 'file') {
                if (in_array('GET', $methods, true)) {
                    throw new \RuntimeException(
                        "Invalid #[Api_Param] '{$name}' for '{$pattern}': {$location}\n" .
                        "  type 'file' is POST-only; this endpoint declares GET."
                    );
                }

                if (preg_match('/(^|\/):' . preg_quote($name, '/') . '(\/|$|\?)/', $pattern)) {
                    throw new \RuntimeException(
                        "Invalid #[Api_Param] '{$name}' for '{$pattern}': {$location}\n" .
                        "  type 'file' cannot be a :route token."
                    );
                }

                if ($has_default) {
                    throw new \RuntimeException(
                        "Invalid #[Api_Param] '{$name}' for '{$pattern}': {$location}\n" .
                        "  type 'file' cannot carry a default."
                    );
                }
            }

            if ($required && $has_default) {
                throw new \RuntimeException(
                    "Contradictory #[Api_Param] '{$name}' for '{$pattern}': {$location}\n" .
                    "  A param cannot be required:true and also carry a default."
                );
            }

            $params[] = [
                'name' => $name,
                'type' => $type,
                'required' => $required,
                'default' => $has_default ? $args['default'] : null,
                'description' => $args['description'] ?? null,
                'example' => $args['example'] ?? null,
            ];
        }

        // Every :param token in the pattern must have a matching declared param.
        if (preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $pattern, $token_matches)) {
            foreach ($token_matches[1] as $token) {
                if (!isset($seen_names[$token])) {
                    throw new \RuntimeException(
                        "Missing #[Api_Param] for route token ':{$token}' on '{$pattern}': {$location}\n" .
                        "  Every :param in the pattern requires a matching #[Api_Param]."
                    );
                }
            }
        }

        return $params;
    }

    /**
     * Parse the first PARAGRAPH of a docblock as the description: consecutive non-tag
     * lines joined with a space, stopping at a blank line or the first @tag.
     */
    private static function _parse_description(string $docblock): string
    {
        $lines = explode("\n", $docblock);
        $paragraph = [];
        $started = false;

        foreach ($lines as $line) {
            $line = static::_strip_docblock_line($line);

            if ($line === '/**' || $line === '*/' || $line === '') {
                if ($started) {
                    break;
                }
                continue;
            }

            if (str_starts_with($line, '@')) {
                if ($started) {
                    break;
                }
                continue;
            }

            $paragraph[] = $line;
            $started = true;
        }

        return implode(' ', $paragraph);
    }

    /**
     * Parse the @api-response block: everything after the tag line until the next @tag
     * or the docblock end, stored verbatim. Returns null when the tag is absent.
     */
    private static function _parse_response_example(string $docblock): ?string
    {
        $lines = explode("\n", $docblock);
        $collecting = false;
        $captured = [];

        foreach ($lines as $line) {
            $stripped = static::_strip_docblock_line($line);

            if (!$collecting) {
                if ($stripped === '@api-response' || str_starts_with($stripped, '@api-response ') || str_starts_with($stripped, '@api-response' . "\t")) {
                    $collecting = true;
                    $inline = trim(substr($stripped, strlen('@api-response')));
                    if ($inline !== '') {
                        $captured[] = $inline;
                    }
                }
                continue;
            }

            // The docblock terminator is tested on the RAW line: _strip_docblock_line()
            // eats the leading '*' of '*/' and returns a bare '/', which never equalled
            // the '*/' this once compared against - so the closing delimiter was captured
            // as a line of the example and EVERY extracted example ended in a stray '/',
            // making it malformed JSON for anything that tried to parse it.
            if (trim($line) === '*/' || str_starts_with($stripped, '@')) {
                break;
            }

            $captured[] = $stripped;
        }

        if (!$collecting) {
            return null;
        }

        $result = trim(implode("\n", $captured));

        return $result === '' ? null : $result;
    }

    /**
     * Strip a single docblock line to its content: leading whitespace, an optional
     * leading '*', and one following space.
     */
    private static function _strip_docblock_line(string $line): string
    {
        $line = ltrim($line);
        if (str_starts_with($line, '*')) {
            $line = substr($line, 1);
            if (str_starts_with($line, ' ')) {
                $line = substr($line, 1);
            }
        }

        return rtrim($line);
    }

    /**
     * API-GET-PURE-01 - refuse a GET-only handler that writes.
     *
     * "Read-only" is expressible in the scope language only as a GET grant, so the whole
     * value of `Grant GET /api/v1/**` rests on GET being side-effect free. That guarantee has
     * to exist BEFORE keys depend on it: retrofitting it later means auditing every GET
     * handler by hand and hoping.
     *
     * The body is read from source and tokenized, so comments and string literals cannot
     * trigger the rule and spacing cannot evade it ("$m -> save ()" is caught). Only the
     * handler's OWN body is inspected - a private helper it calls is not followed, which is
     * the honest limit of a literal-token rule and the reason the escape hatch exists.
     *
     * The escape is '@API-GET-PURE-01-EXCEPTION <rationale>' in the method docblock. The
     * rationale is REQUIRED: a bare tag records that somebody wanted the rule off, which is
     * not the same as somebody having a reason.
     */
    private static function _assert_get_handler_is_pure(string $file_path, string $method_name, string $docblock, string $location): void
    {
        $exception_rationale = static::_parse_get_pure_exception($docblock);

        if ($exception_rationale === '') {
            throw new \RuntimeException(
                "Invalid #[Api_Endpoint] GET handler: {$location}\n" .
                "  API-GET-PURE-01: " . self::GET_PURE_EXCEPTION_TAG . " requires a rationale.\n" .
                "  Write the reason the write is legitimate on the same line as the tag."
            );
        }

        if ($exception_rationale !== null) {
            return;
        }

        $body = static::_read_method_body($file_path, $method_name);
        if ($body === '') {
            return;
        }

        foreach (self::GET_MUTATION_TOKENS as $token) {
            if (!str_contains($body, $token)) {
                continue;
            }

            throw new \RuntimeException(
                "Invalid #[Api_Endpoint] GET handler: {$location}\n" .
                "  API-GET-PURE-01: a GET endpoint must be side-effect free, and this one " .
                "calls '{$token}'.\n" .
                "  Move the write to a POST endpoint, or - if the write is legitimate " .
                "bookkeeping - declare\n" .
                "  '" . self::GET_PURE_EXCEPTION_TAG . " <rationale>' in the method docblock."
            );
        }
    }

    /**
     * The rationale from an @API-GET-PURE-01-EXCEPTION docblock tag.
     *
     * Returns null when the tag is absent, '' when it is present with nothing after it, and
     * the rationale text otherwise. The three answers are distinct because a bare tag must
     * fail rather than waive.
     */
    private static function _parse_get_pure_exception(string $docblock): ?string
    {
        foreach (explode("\n", $docblock) as $line) {
            $stripped = static::_strip_docblock_line($line);

            if (!str_starts_with($stripped, self::GET_PURE_EXCEPTION_TAG)) {
                continue;
            }

            return trim(substr($stripped, strlen(self::GET_PURE_EXCEPTION_TAG)));
        }

        return null;
    }

    /**
     * The body of one method as compacted code text: comments dropped, every string literal
     * replaced with a placeholder, and all whitespace removed - so a literal search for
     * '->save(' cannot be fooled by formatting, and cannot fire on prose or on data.
     *
     * Returns '' when the file or the method cannot be read (a synthetic manifest entry
     * pointing at no file, exactly as the docblock reader behaves).
     */
    private static function _read_method_body(string $file_path, string $method_name): string
    {
        if (!file_exists($file_path)) {
            return '';
        }

        $tokens = @token_get_all(file_get_contents($file_path));
        if (!is_array($tokens)) {
            return '';
        }

        $count = count($tokens);

        // Locate "function <method_name>".
        $start = null;
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                $token = $tokens[$j];
                if (is_array($token) && ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                    continue;
                }
                if (is_array($token) && $token[0] === T_STRING && $token[1] === $method_name) {
                    $start = $j;
                }
                break;
            }

            if ($start !== null) {
                break;
            }
        }

        if ($start === null) {
            return '';
        }

        // Walk to the body's opening brace, then brace-match to its close. An abstract or
        // interface declaration ends at ';' and has no body.
        $depth = 0;
        $body = '';
        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($depth === 0) {
                if ($text === ';') {
                    return '';
                }
                if ($text === '{') {
                    $depth = 1;
                }
                continue;
            }

            if ($text === '{') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }

            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                if ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE
                    || $token[0] === T_INLINE_HTML) {
                    $body .= "'_'";
                    continue;
                }
            }

            $body .= $text;
        }

        return preg_replace('/\s+/', '', $body);
    }

    /**
     * Read the docblock for a specific method from a PHP source file.
     */
    private static function _read_method_docblock(string $file_path, string $method_name): string
    {
        if (!file_exists($file_path)) {
            return '';
        }

        $source = file_get_contents($file_path);
        $lines = explode("\n", $source);

        // Find the line declaring "function {method_name}".
        $method_line = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/function\s+' . preg_quote($method_name, '/') . '\s*\(/', $line)) {
                $method_line = $i;
                break;
            }
        }

        if ($method_line === null) {
            return '';
        }

        // Walk backwards over attribute lines to the docblock end (*/). Attributes may
        // wrap across lines, so continuation lines (ending ')]' or ',') are skipped too;
        // the region between the previous method's closing brace and this method holds
        // only this method's docblock and attributes, so the heuristic cannot overrun.
        $docblock_end = null;
        for ($i = $method_line - 1; $i >= 0; $i--) {
            $trimmed = trim($lines[$i]);
            if ($trimmed === '' || str_starts_with($trimmed, '#[')
                || str_ends_with($trimmed, ')]') || str_ends_with($trimmed, ',')) {
                continue;
            }
            if (str_ends_with($trimmed, '*/')) {
                $docblock_end = $i;
                break;
            }
            break;
        }

        if ($docblock_end === null) {
            return '';
        }

        // Walk backwards to the docblock start (/**).
        $docblock_start = null;
        for ($i = $docblock_end; $i >= 0; $i--) {
            if (str_contains($lines[$i], '/**')) {
                $docblock_start = $i;
                break;
            }
        }

        if ($docblock_start === null) {
            return '';
        }

        return implode("\n", array_slice($lines, $docblock_start, $docblock_end - $docblock_start + 1));
    }
}
