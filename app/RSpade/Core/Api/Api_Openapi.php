<?php

namespace App\RSpade\Core\Api;

use App\RSpade\Core\Api\Api_Catalog;

/**
 * Api_Openapi - projects the manifest's API catalog into an OpenAPI 3.1 document.
 *
 * Served at /apidocs/openapi.json. The manifest remains the single source of truth; this
 * class is a pure projection of Api_Catalog::get_endpoint_list() and adds no facts of its
 * own.
 *
 * WHY A STANDARD FORMAT. Everything downstream of an API description - client generators,
 * Postman/Insomnia, mock servers, contract testers, and the LLM/agent toolchains - already
 * speaks OpenAPI. A bespoke shape has to be explained before it can be used; this one does
 * not, which is the whole reason the previous hand-rolled catalog was retired.
 *
 * TRANSLATION RULES (each is a real decision, not an accident):
 *
 *   :token -> {token}      OpenAPI templates path params in braces.
 *   path params            A param whose name appears as :name in the pattern is in:path,
 *                          and OpenAPI requires those to be required:true.
 *   GET, other params      in:query.
 *   POST, other params     a requestBody object. The dispatcher also accepts them as query
 *                          on POST (see Api_Dispatcher::_collect_raw_input), but the body is
 *                          the documented shape and the one the tester sends, so documenting
 *                          both would describe two ways to do one thing.
 *   superseded endpoints   deprecated:true when a HIGHER version exists for the same
 *                          verb + path_key. This is the standard spelling of "prefer the
 *                          newer one" and replaces the old catalog's latest_in_version map
 *                          plus the prose paragraph that had to explain it.
 *   @api-hidden            omitted entirely - there is nothing to say about an endpoint the
 *                          catalog does not publish.
 *
 * Param types are scalar (string/int/float/bool), which is a strict subset of JSON Schema, so
 * every type maps exactly - plus 'file', which is a multipart part rather than a value: an
 * operation declaring one emits a multipart/form-data requestBody with that property typed
 * string/format:binary, instead of the application/json object body every other POST gets. Responses carry the documented example; there is no
 * response SCHEMA because the framework never had one to project - an example alone is
 * valid OpenAPI and degrades honestly.
 */
class Api_Openapi
{
    /**
     * Scalar #[Api_Param] type -> JSON Schema type.
     */
    private const TYPE_MAP = [
        'string' => 'string',
        'int' => 'integer',
        'float' => 'number',
        'bool' => 'boolean',
        // A multipart file part. OpenAPI spells binary content as a string with format
        // 'binary'; the requestBody media type is what actually makes it an upload.
        'file' => 'string',
    ];

    /**
     * The complete OpenAPI 3.1 document for every published endpoint, all versions.
     *
     * Versions are NOT filtered: /api/v1/x and /api/v2/x are two paths, individually
     * callable, and listing both is what makes the document true. The older one carries
     * deprecated:true.
     *
     * $accessible_targets, when supplied, is the map Api_Tester_Key::accessible_targets_for_user()
     * returns: 'Class::method' => bool. Only the endpoints it admits reach the document, so
     * the caller gets a description of what one identity may actually call rather than of the
     * whole surface. It is a VISIBILITY filter and nothing more - Api_Dispatcher gates every
     * real call regardless of which document a client was generated from.
     */
    public static function document(?array $accessible_targets = null): array
    {
        $endpoints = Api_Catalog::get_endpoint_list(false);

        if ($accessible_targets !== null) {
            $endpoints = array_values(array_filter(
                $endpoints,
                static fn ($ep) => !empty($accessible_targets[$ep['class'] . '::' . $ep['method']])
            ));
        }

        return [
            'openapi' => '3.1.0',
            'info' => static::__info(),
            'servers' => [
                ['url' => rtrim((string) config('app.url', ''), '/')],
            ],
            'security' => [['bearerAuth' => new \stdClass()]],
            'components' => static::__components(),
            'tags' => static::__tags($endpoints),
            'paths' => static::__paths($endpoints),
        ];
    }

    /**
     * Document metadata. The version is the newest catalog version, spelled as the API
     * version rather than an application release number - it is what a consumer selects.
     */
    private static function __info(): array
    {
        $versions = Api_Catalog::get_versions();

        return [
            'title' => config('app.name', 'RSpade') . ' API',
            'version' => 'v' . ($versions[0] ?? 1),
            'description' => 'External REST API. Every request carries an API key as '
                . 'Authorization: Bearer rsk_... - there is no cookie or session auth on this '
                . 'surface. Keys are created in Settings > API Keys. A key resolves to a staff '
                . 'user and that user\'s site, and every endpoint is scoped to that site '
                . 'automatically.',
        ];
    }

    /**
     * Security scheme plus the one shared error shape every failure uses.
     */
    private static function __components(): array
    {
        return [
            'securitySchemes' => [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'description' => 'An API key from Settings > API Keys, sent as '
                        . 'Authorization: Bearer rsk_live_...',
                ],
            ],
            'schemas' => [
                'Error' => [
                    'type' => 'object',
                    'required' => ['error'],
                    'properties' => [
                        'error' => [
                            'type' => 'object',
                            'required' => ['code', 'message'],
                            'properties' => [
                                'code' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                                'fields' => [
                                    'type' => 'object',
                                    'description' => 'Per-field messages; present on validation failures only.',
                                    'additionalProperties' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * One tag per resource, in the same grouping the docs page uses.
     */
    private static function __tags(array $endpoints): array
    {
        $names = [];
        foreach ($endpoints as $ep) {
            $names[static::__tag_for($ep)] = true;
        }

        $names = array_keys($names);
        sort($names);

        return array_map(fn($n) => ['name' => $n], $names);
    }

    /**
     * The paths object: one key per URL, one operation per verb under it.
     */
    private static function __paths(array $endpoints): array
    {
        // Highest version present for each verb + path_key, so a superseded endpoint can be
        // marked deprecated without the consumer needing a rule explained to them.
        $newest = [];
        foreach ($endpoints as $ep) {
            foreach ($ep['methods'] as $verb) {
                $key = strtoupper($verb) . ' ' . $ep['path_key'];
                $newest[$key] = max($newest[$key] ?? 0, (int) $ep['version']);
            }
        }

        $paths = [];
        foreach ($endpoints as $ep) {
            $url = static::__to_template($ep['pattern']);

            foreach ($ep['methods'] as $verb) {
                $key = strtoupper($verb) . ' ' . $ep['path_key'];
                $superseded = (int) $ep['version'] < ($newest[$key] ?? 0);

                $paths[$url][strtolower($verb)] = static::__operation($ep, $verb, $superseded);
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * One operation object.
     */
    private static function __operation(array $ep, string $verb, bool $superseded): array
    {
        $verb = strtoupper($verb);
        $path_names = static::__path_param_names($ep['pattern']);

        $summary = trim((string) $ep['description']);
        $operation = [
            'operationId' => static::__operation_id($ep, $verb),
            'tags' => [static::__tag_for($ep)],
            'responses' => static::__responses($ep),
        ];

        if ($summary !== '') {
            $operation['summary'] = static::__first_line($summary);
            if ($summary !== $operation['summary']) {
                $operation['description'] = $summary;
            }
        }

        if ($superseded) {
            $operation['deprecated'] = true;
        }

        $parameters = [];
        $body_props = [];
        $body_required = [];
        $body_has_file = false;

        foreach ($ep['api_params'] as $param) {
            $in_path = in_array($param['name'], $path_names, true);

            if ($in_path || $verb === 'GET') {
                $parameters[] = static::__parameter($param, $in_path ? 'path' : 'query');
                continue;
            }

            $body_props[$param['name']] = static::__schema($param);
            if (($param['type'] ?? null) === 'file') {
                $body_has_file = true;
            }
            if (!empty($param['required'])) {
                $body_required[] = $param['name'];
            }
        }

        if (!empty($parameters)) {
            $operation['parameters'] = $parameters;
        }

        if (!empty($body_props)) {
            $schema = ['type' => 'object', 'properties' => $body_props];
            if (!empty($body_required)) {
                $schema['required'] = $body_required;
            }

            // One media type, never two: a body carrying a file part IS multipart, and its
            // sibling text params ride the same multipart body. Documenting a JSON
            // alternative would describe a request the dispatcher cannot receive a file from.
            $media_type = $body_has_file ? 'multipart/form-data' : 'application/json';

            $operation['requestBody'] = [
                'required' => !empty($body_required),
                'content' => [$media_type => ['schema' => $schema]],
            ];
        }

        return $operation;
    }

    /**
     * A parameter object. A path param is required:true regardless of its declaration -
     * OpenAPI mandates it, and a URL cannot be built without it either way.
     */
    private static function __parameter(array $param, string $in): array
    {
        $out = [
            'name' => $param['name'],
            'in' => $in,
            'required' => $in === 'path' ? true : (bool) ($param['required'] ?? false),
            'schema' => static::__schema($param),
        ];

        if (!empty($param['description'])) {
            $out['description'] = $param['description'];
        }

        if (isset($param['example']) && $param['example'] !== null) {
            $out['example'] = $param['example'];
        }

        return $out;
    }

    /**
     * JSON Schema for one param. A declared default is carried through; null means none was
     * declared, which is not the same as a default of null.
     */
    private static function __schema(array $param): array
    {
        $schema = ['type' => static::TYPE_MAP[$param['type']] ?? 'string'];

        if (($param['type'] ?? null) === 'file') {
            $schema['format'] = 'binary';
        }

        if (array_key_exists('default', $param) && $param['default'] !== null) {
            $schema['default'] = $param['default'];
        }

        return $schema;
    }

    /**
     * Responses. 200 carries the endpoint's documented example when it parses as JSON; the
     * error family is the same shape everywhere, so it is a $ref rather than four copies.
     */
    private static function __responses(array $ep): array
    {
        $ok = ['description' => 'Success'];

        $example = static::__decode_example($ep['response_example'] ?? null);
        if ($example !== null) {
            $ok['content'] = ['application/json' => ['example' => $example]];
        }

        $error = fn(string $description) => [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
        ];

        return [
            '200' => $ok,
            '401' => $error('Missing, malformed, revoked or expired API key.'),
            '403' => $error('The key\'s user does not pass this endpoint\'s permission gates.'),
            '404' => $error('Unknown endpoint, or the record does not exist in this site.'),
            '422' => $error('Parameter validation failed; error.fields carries per-field messages.'),
        ];
    }

    /**
     * The @api-response docblock example, decoded so it embeds as JSON rather than as a
     * string containing JSON. Unparseable examples are dropped: a broken example in a
     * machine-read document is worse than no example.
     */
    private static function __decode_example($raw)
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * '/api/v1/clients/:id' -> '/api/v1/clients/{id}'
     */
    private static function __to_template(string $pattern): string
    {
        return preg_replace('/:([A-Za-z_][A-Za-z0-9_]*)/', '{$1}', $pattern);
    }

    /**
     * The :token names in a pattern, in order.
     */
    private static function __path_param_names(string $pattern): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $pattern, $m);

        return $m[1] ?? [];
    }

    /**
     * Stable, unique operation id. Version is included because the same class::method name
     * can legitimately exist in two API versions.
     */
    private static function __operation_id(array $ep, string $verb): string
    {
        return strtolower($verb) . '_v' . $ep['version'] . '_' . $ep['class'] . '_' . $ep['method'];
    }

    /**
     * Resource tag, matching the docs page's grouping ('Clients_Api_Controller' -> 'Clients').
     */
    private static function __tag_for(array $ep): string
    {
        $class = $ep['class'];

        foreach (['_Api_Controller', '_Controller'] as $suffix) {
            if (str_ends_with($class, $suffix)) {
                return substr($class, 0, -strlen($suffix));
            }
        }

        return $class;
    }

    /**
     * First line of a multi-line description, for the summary.
     */
    private static function __first_line(string $text): string
    {
        $line = strtok($text, "\n");

        return $line === false ? $text : trim($line);
    }
}
