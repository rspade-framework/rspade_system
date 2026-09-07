<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use App\RSpade\Core\Api\Api_Endpoint_ManifestSupport;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Scan-time enforcement in Api_Endpoint_ManifestSupport::process().
 *
 * The support module is fed a SYNTHETIC manifest_data array (never the real manifest)
 * so every rejection rule can be provoked in isolation. A valid declaration must bake
 * the route (type 'api', version, path_key, api_params) into routes + api_endpoints;
 * every malformed declaration must throw a RuntimeException naming the offending method.
 *
 * The file key points at a nonexistent path, so the module's docblock reader returns ''
 * (no fixture file needed). Pure array work, so no database.
 */
class Api_Scan_Validation_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // The base every API controller must extend (single-backslash FQCN).
    private const BASE = 'App\RSpade\Core\Api\Rsx_Api_Controller_Abstract';

    // Real source the API-GET-PURE-01 body reader reads, relative to base_path().
    private const FIXTURE_FILE = 'app/RSpade/tests/api/php/Api_Get_Pure_Fixture.php';

    /**
     * Build a synthetic manifest_data with one controller file carrying one method whose
     * attributes are $attrs. $extends is the declaring class's extends_fqcn; $seed_routes
     * pre-populates the routes table (for duplicate-pattern provocation).
     */
    private static function __manifest(array $attrs, ?string $extends = self::BASE, array $seed_routes = []): array
    {
        return [
            'data' => [
                'files' => [
                    'fake/foo_api_controller.php' => [
                        'fqcn' => 'Rsx\App\Api\V1\Foo_Api_Controller',
                        'class' => 'Foo_Api_Controller',
                        'extends_fqcn' => $extends,
                        'public_static_methods' => [
                            'list' => ['attributes' => $attrs],
                        ],
                    ],
                ],
                'routes' => $seed_routes,
                'routes_by_target' => [],
                'api_endpoints' => [],
            ],
        ];
    }

    /**
     * Assert that process() throws a RuntimeException whose message contains $needle.
     */
    private static function __assert_scan_throws(array $manifest, string $needle)
    {
        static::__assert_throws(
            \RuntimeException::class,
            function () use ($manifest) {
                Api_Endpoint_ManifestSupport::process($manifest);
            },
            $needle
        );
    }

    // -------------------------------------------------------------------------
    // Happy path - a valid declaration bakes into routes + api_endpoints
    // -------------------------------------------------------------------------

    public static function test_valid_declaration_bakes_route_and_catalog()
    {
        $manifest = static::__manifest([
            'Api_Endpoint' => [[0 => '/api/v1/contacts', 1 => ['GET']]],
        ]);

        Api_Endpoint_ManifestSupport::process($manifest);

        $route = $manifest['data']['routes']['/api/v1/contacts'];
        static::__assert_equals('api', $route['type']);
        static::__assert_equals(1, $route['version']);
        static::__assert_equals('/contacts', $route['path_key']);
        static::__assert_equals(['GET'], $route['methods']);
        static::__assert_array_has_key('api_params', $route);

        static::__assert_array_has_key('/api/v1/contacts', $manifest['data']['api_endpoints']);
        $catalog = $manifest['data']['api_endpoints']['/api/v1/contacts'];
        static::__assert_equals(1, $catalog['version']);
        static::__assert_equals('/contacts', $catalog['path_key']);
    }

    public static function test_valid_declaration_bakes_declared_params()
    {
        $manifest = static::__manifest([
            'Api_Endpoint' => [[0 => '/api/v1/contacts/:id', 1 => ['GET']]],
            'Api_Param' => [['id', 'int', true]],
        ]);

        Api_Endpoint_ManifestSupport::process($manifest);

        $params = $manifest['data']['routes']['/api/v1/contacts/:id']['api_params'];
        static::__assert_count(1, $params);
        static::__assert_equals('id', $params[0]['name']);
        static::__assert_equals('int', $params[0]['type']);
        static::__assert_true($params[0]['required']);
    }

    // -------------------------------------------------------------------------
    // Pattern + verb rejections
    // -------------------------------------------------------------------------

    public static function test_bad_prefix_throws()
    {
        static::__assert_scan_throws(
            static::__manifest(['Api_Endpoint' => [[0 => '/foo/v1/contacts', 1 => ['GET']]]]),
            'Pattern must match'
        );
    }

    public static function test_version_without_segment_throws()
    {
        static::__assert_scan_throws(
            static::__manifest(['Api_Endpoint' => [[0 => '/api/v1', 1 => ['GET']]]]),
            'Pattern must match'
        );
    }

    public static function test_put_verb_throws()
    {
        static::__assert_scan_throws(
            static::__manifest(['Api_Endpoint' => [[0 => '/api/v1/contacts', 1 => ['PUT']]]]),
            'Only GET and POST'
        );
    }

    public static function test_empty_methods_throws()
    {
        static::__assert_scan_throws(
            static::__manifest(['Api_Endpoint' => [[0 => '/api/v1/contacts', 1 => []]]]),
            'At least one HTTP verb'
        );
    }

    // -------------------------------------------------------------------------
    // #[Api_Param] rejections
    // -------------------------------------------------------------------------

    public static function test_missing_param_for_route_token_throws()
    {
        static::__assert_scan_throws(
            static::__manifest(['Api_Endpoint' => [[0 => '/api/v1/contacts/:id', 1 => ['GET']]]]),
            "Missing #[Api_Param] for route token ':id'"
        );
    }

    public static function test_duplicate_param_name_throws()
    {
        static::__assert_scan_throws(
            static::__manifest([
                'Api_Endpoint' => [[0 => '/api/v1/contacts', 1 => ['GET']]],
                'Api_Param' => [['dup', 'int'], ['dup', 'string']],
            ]),
            "Duplicate #[Api_Param] name 'dup'"
        );
    }

    public static function test_required_with_default_throws()
    {
        static::__assert_scan_throws(
            static::__manifest([
                'Api_Endpoint' => [[0 => '/api/v1/contacts', 1 => ['GET']]],
                'Api_Param' => [['p', 'int', true, 5]],
            ]),
            'cannot be required:true and also carry a default'
        );
    }

    public static function test_bad_param_type_throws()
    {
        static::__assert_scan_throws(
            static::__manifest([
                'Api_Endpoint' => [[0 => '/api/v1/contacts', 1 => ['GET']]],
                'Api_Param' => [['p', 'array']],
            ]),
            'Type must be one of'
        );
    }

    public static function test_unknown_named_param_arg_throws()
    {
        static::__assert_scan_throws(
            static::__manifest([
                'Api_Endpoint' => [[0 => '/api/v1/contacts', 1 => ['GET']]],
                'Api_Param' => [['name' => 'p', 'requred' => true]],
            ]),
            "Unknown #[Api_Param] argument 'requred'"
        );
    }

    public static function test_too_many_positional_param_args_throws()
    {
        static::__assert_scan_throws(
            static::__manifest([
                'Api_Endpoint' => [[0 => '/api/v1/contacts', 1 => ['GET']]],
                'Api_Param' => [['p', 'int', false, 1, 'desc', 42, 'overflow']],
            ]),
            'Too many positional arguments'
        );
    }

    // -------------------------------------------------------------------------
    // Structural rejections
    // -------------------------------------------------------------------------

    public static function test_conflicting_route_attribute_throws()
    {
        static::__assert_scan_throws(
            static::__manifest([
                'Api_Endpoint' => [[0 => '/api/v1/contacts', 1 => ['GET']]],
                'Route' => [['/some/web/path']],
            ]),
            'must not also carry #[Route]'
        );
    }

    public static function test_class_not_extending_base_throws()
    {
        static::__assert_scan_throws(
            static::__manifest(
                ['Api_Endpoint' => [[0 => '/api/v1/contacts', 1 => ['GET']]]],
                'App\Some\Other_Base'
            ),
            'must extend'
        );
    }

    public static function test_duplicate_route_pattern_throws()
    {
        $seed = [
            '/api/v1/contacts' => [
                'class' => 'Existing_Controller',
                'method' => 'other',
                'file' => 'existing.php',
            ],
        ];

        static::__assert_scan_throws(
            static::__manifest(
                ['Api_Endpoint' => [[0 => '/api/v1/contacts', 1 => ['GET']]]],
                self::BASE,
                $seed
            ),
            'Duplicate route definition'
        );
    }

    // -------------------------------------------------------------------------
    // API-GET-PURE-01 - a GET handler may not mutate
    // -------------------------------------------------------------------------

    /**
     * A synthetic manifest whose file key points at the REAL fixture source, so the rule's
     * body reader has something to read. $method_name selects which fixture method the
     * declaration is pinned to.
     */
    private static function __fixture_manifest(string $method_name, array $methods = ['GET']): array
    {
        return [
            'data' => [
                'files' => [
                    self::FIXTURE_FILE => [
                        'fqcn' => 'App\\RSpade\\Tests\\Api\\Php\\Api_Get_Pure_Fixture',
                        'class' => 'Api_Get_Pure_Fixture',
                        'extends_fqcn' => self::BASE,
                        'public_static_methods' => [
                            $method_name => [
                                'attributes' => [
                                    'Api_Endpoint' => [[0 => '/api/v1/fixture', 1 => $methods]],
                                ],
                            ],
                        ],
                    ],
                ],
                'routes' => [],
                'routes_by_target' => [],
                'api_endpoints' => [],
            ],
        ];
    }

    public static function test_get_handler_that_writes_throws()
    {
        static::__assert_scan_throws(
            static::__fixture_manifest('mutating_get'),
            'API-GET-PURE-01'
        );
    }

    public static function test_get_handler_that_writes_names_the_call_it_found()
    {
        static::__assert_scan_throws(
            static::__fixture_manifest('mutating_get'),
            "calls '->save('"
        );
    }

    public static function test_get_handler_with_a_rationalised_exception_passes()
    {
        $manifest = static::__fixture_manifest('excepted_get');

        Api_Endpoint_ManifestSupport::process($manifest);

        static::__assert_array_has_key('/api/v1/fixture', $manifest['data']['routes']);
    }

    public static function test_get_handler_with_a_bare_exception_tag_throws()
    {
        static::__assert_scan_throws(
            static::__fixture_manifest('bare_tag_get'),
            'requires a rationale'
        );
    }

    public static function test_pure_get_handler_passes_despite_write_words_in_prose()
    {
        $manifest = static::__fixture_manifest('pure_get');

        Api_Endpoint_ManifestSupport::process($manifest);

        static::__assert_array_has_key('/api/v1/fixture', $manifest['data']['routes']);
    }

    public static function test_post_handler_that_writes_is_untouched_by_the_rule()
    {
        $manifest = static::__fixture_manifest('mutating_get', ['POST']);

        Api_Endpoint_ManifestSupport::process($manifest);

        static::__assert_array_has_key('/api/v1/fixture', $manifest['data']['routes']);
    }
}
