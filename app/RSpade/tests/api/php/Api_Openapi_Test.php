<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use App\RSpade\Core\Api\Api_Catalog;
use App\RSpade\Core\Api\Api_Openapi;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Api_Openapi - the OpenAPI 3.1 projection of the manifest catalog.
 *
 * Reads the FINALIZED real manifest (the template app's v1 endpoints), so these assert
 * the TRANSLATION DECISIONS rather than exact counts: :token becomes {token}, a path
 * param is in:path and required, GET params are in:query while POST params become a
 * requestBody, and the document carries the bearer scheme plus the shared error schema.
 * The catalog cannot be mocked without rebuilding the manifest.
 */
class Api_Openapi_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Every operation object in the document, flattened.
     */
    private static function __operations(array $doc): array
    {
        $out = [];
        foreach ($doc['paths'] as $url => $verbs) {
            foreach ($verbs as $verb => $op) {
                $out[] = ['url' => $url, 'verb' => $verb, 'op' => $op];
            }
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Document envelope
    // -------------------------------------------------------------------------

    public static function test_document_declares_openapi_31()
    {
        $doc = Api_Openapi::document();

        static::__assert_equals('3.1.0', $doc['openapi']);
    }

    public static function test_document_has_the_required_top_level_objects()
    {
        $doc = Api_Openapi::document();

        foreach (['openapi', 'info', 'servers', 'security', 'components', 'tags', 'paths'] as $key) {
            static::__assert_array_has_key($key, $doc);
        }
        static::__assert_array_has_key('title', $doc['info']);
        static::__assert_array_has_key('version', $doc['info']);
    }

    public static function test_bearer_security_scheme_is_declared_and_applied()
    {
        $doc = Api_Openapi::document();
        $scheme = $doc['components']['securitySchemes']['bearerAuth'];

        static::__assert_equals('http', $scheme['type']);
        static::__assert_equals('bearer', $scheme['scheme']);
        // Applied document-wide - every endpoint needs a key, without exception.
        static::__assert_array_has_key('bearerAuth', $doc['security'][0]);
    }

    public static function test_shared_error_schema_is_the_dispatcher_shape()
    {
        $doc = Api_Openapi::document();
        $props = $doc['components']['schemas']['Error']['properties']['error']['properties'];

        static::__assert_array_has_key('code', $props);
        static::__assert_array_has_key('message', $props);
        static::__assert_array_has_key('fields', $props);
    }

    // -------------------------------------------------------------------------
    // Path translation
    // -------------------------------------------------------------------------

    public static function test_paths_are_not_empty()
    {
        $doc = Api_Openapi::document();

        static::__assert_not_empty($doc['paths']);
    }

    public static function test_no_path_retains_the_colon_token_spelling()
    {
        $doc = Api_Openapi::document();

        foreach (array_keys($doc['paths']) as $url) {
            static::__assert_false(str_contains($url, ':'), "path templated, not raw: {$url}");
        }
    }

    public static function test_a_colon_token_pattern_becomes_a_brace_template()
    {
        $doc = Api_Openapi::document();

        // The framework ships /api/v1/files/:key; find the projected form of a
        // :token pattern (any endpoint that declares one).
        $found = false;
        foreach (array_keys($doc['paths']) as $url) {
            if (str_contains($url, '{') && str_contains($url, '}')) {
                $found = true;
                break;
            }
        }

        static::__assert_true($found, 'at least one path carries a {token} template');
    }

    public static function test_every_path_template_token_has_a_path_parameter()
    {
        $doc = Api_Openapi::document();

        foreach (static::__operations($doc) as $entry) {
            preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $entry['url'], $m);
            foreach ($m[1] as $token) {
                $declared = false;
                foreach ($entry['op']['parameters'] ?? [] as $param) {
                    if ($param['name'] === $token && $param['in'] === 'path') {
                        $declared = true;
                        break;
                    }
                }
                static::__assert_true($declared, "{$entry['url']} declares path param {$token}");
            }
        }
    }

    public static function test_path_parameters_are_always_required()
    {
        $doc = Api_Openapi::document();

        foreach (static::__operations($doc) as $entry) {
            foreach ($entry['op']['parameters'] ?? [] as $param) {
                if ($param['in'] === 'path') {
                    static::__assert_true($param['required'], "{$entry['url']} path param is required");
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Parameter placement
    // -------------------------------------------------------------------------

    public static function test_get_operations_never_declare_a_request_body()
    {
        $doc = Api_Openapi::document();

        foreach (static::__operations($doc) as $entry) {
            if ($entry['verb'] === 'get') {
                static::__assert_false(
                    isset($entry['op']['requestBody']),
                    "{$entry['url']} GET carries no requestBody"
                );
            }
        }
    }

    public static function test_get_non_path_params_are_query_params()
    {
        $doc = Api_Openapi::document();

        foreach (static::__operations($doc) as $entry) {
            if ($entry['verb'] !== 'get') {
                continue;
            }
            foreach ($entry['op']['parameters'] ?? [] as $param) {
                static::__assert_true(
                    in_array($param['in'], ['path', 'query'], true),
                    "{$entry['url']} GET param is path or query"
                );
            }
        }
    }

    public static function test_post_non_path_params_become_a_json_request_body()
    {
        $doc = Api_Openapi::document();

        $checked = 0;
        foreach (static::__operations($doc) as $entry) {
            if ($entry['verb'] !== 'post' || !isset($entry['op']['requestBody'])) {
                continue;
            }

            // A body carrying a file part is multipart, not JSON - covered by its own test
            // below. Every other POST body is the JSON object this one asserts.
            $content = $entry['op']['requestBody']['content'];
            if (isset($content['multipart/form-data'])) {
                continue;
            }

            $schema = $content['application/json']['schema'];
            static::__assert_equals('object', $schema['type']);
            static::__assert_not_empty($schema['properties']);
            $checked++;
        }

        static::__assert_greater_than(0, $checked, 'the template app ships at least one POST with a body');
    }

    /**
     * A file param is a multipart part, not a value. The operation must therefore declare
     * ONE media type - multipart/form-data, never a JSON alternative the dispatcher could
     * not receive a file from - and the part itself is spelled string/format:binary.
     *
     * The two sides are counted against each other, so neither a catalog file param that
     * projects as JSON nor a multipart body nothing declared can pass unnoticed.
     */
    public static function test_a_file_param_makes_the_body_multipart_with_a_binary_part()
    {
        $doc = Api_Openapi::document();

        // What the catalog declares: endpoint pattern => the names of its file params.
        $declared = [];
        foreach (Api_Catalog::get_endpoint_list() as $ep) {
            $names = array_column(
                array_filter($ep['api_params'] ?? [], fn ($p) => ($p['type'] ?? null) === 'file'),
                'name'
            );
            if ($names) {
                $declared[$ep['pattern']] = $names;
            }
        }

        static::__assert_not_empty($declared, 'the framework ships at least one file-param endpoint');

        $multipart_operations = 0;
        foreach (static::__operations($doc) as $entry) {
            $content = $entry['op']['requestBody']['content'] ?? null;
            if ($content === null || !isset($content['multipart/form-data'])) {
                continue;
            }

            $multipart_operations++;

            static::__assert_equals('post', $entry['verb'], 'only a POST carries a multipart body');
            static::__assert_equals(1, count($content), 'a multipart body declares exactly one media type');

            $schema = $content['multipart/form-data']['schema'];
            static::__assert_equals('object', $schema['type']);

            // The :token form of this URL, to look the endpoint's declaration back up.
            $pattern = str_replace(['{', '}'], [':', ''], $entry['url']);
            static::__assert_array_has_key($pattern, $declared);

            foreach ($declared[$pattern] as $name) {
                $property = $schema['properties'][$name];
                static::__assert_equals('string', $property['type']);
                static::__assert_equals('binary', $property['format']);
            }
        }

        static::__assert_equals(
            count($declared),
            $multipart_operations,
            'every file-param endpoint projects as exactly one multipart operation'
        );
    }

    public static function test_scalar_types_map_to_json_schema_types()
    {
        $doc = Api_Openapi::document();
        $allowed = ['string', 'integer', 'number', 'boolean'];

        foreach (static::__operations($doc) as $entry) {
            foreach ($entry['op']['parameters'] ?? [] as $param) {
                static::__assert_true(
                    in_array($param['schema']['type'], $allowed, true),
                    "{$entry['url']} param {$param['name']} has a JSON Schema type"
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Operations
    // -------------------------------------------------------------------------

    public static function test_operation_ids_are_unique()
    {
        $doc = Api_Openapi::document();

        $ids = [];
        foreach (static::__operations($doc) as $entry) {
            $id = $entry['op']['operationId'];
            static::__assert_false(isset($ids[$id]), "operationId {$id} is unique");
            $ids[$id] = true;
        }
    }

    public static function test_every_operation_documents_the_error_family()
    {
        $doc = Api_Openapi::document();

        foreach (static::__operations($doc) as $entry) {
            foreach (['200', '401', '403', '404', '422'] as $status) {
                static::__assert_array_has_key($status, $entry['op']['responses']);
            }
        }
    }

    public static function test_response_examples_embed_as_json_not_as_a_string()
    {
        $doc = Api_Openapi::document();

        $checked = 0;
        foreach (static::__operations($doc) as $entry) {
            $ok = $entry['op']['responses']['200'];
            if (!isset($ok['content'])) {
                continue;
            }
            $example = $ok['content']['application/json']['example'];
            static::__assert_true(is_array($example), "{$entry['url']} example is decoded JSON");
            $checked++;
        }

        static::__assert_greater_than(0, $checked, 'the template app documents at least one response example');
    }

    public static function test_endpoints_with_no_newer_version_are_not_deprecated()
    {
        $doc = Api_Openapi::document();

        // The template app ships exactly one version, so nothing can be superseded.
        if (count(Api_Catalog::get_versions()) > 1) {
            return;
        }

        foreach (static::__operations($doc) as $entry) {
            static::__assert_false(
                isset($entry['op']['deprecated']),
                "{$entry['url']} is not deprecated when it is the only version"
            );
        }
    }

    public static function test_every_operation_carries_a_tag()
    {
        $doc = Api_Openapi::document();
        $declared = array_column($doc['tags'], 'name');

        foreach (static::__operations($doc) as $entry) {
            static::__assert_not_empty($entry['op']['tags']);
            static::__assert_true(
                in_array($entry['op']['tags'][0], $declared, true),
                "{$entry['url']} tag is declared at the document level"
            );
        }
    }

    public static function test_hidden_endpoints_are_absent()
    {
        $doc = Api_Openapi::document();

        $published = 0;
        foreach (Api_Catalog::get_endpoint_list(false) as $ep) {
            $published += count($ep['methods']);
        }

        static::__assert_equals($published, count(static::__operations($doc)));
    }
}
