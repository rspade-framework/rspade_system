<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dispatch\Php;

use RuntimeException;
use App\RSpade\Core\Dispatch\RouteResolver;
use App\RSpade\Core\Dispatch\Route_ManifestSupport;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Portal\Portal_Route_ManifestSupport;

/**
 * ROUTE-PATH-01 and ROUTE-PARAM-01: the grammar of a route pattern, a manifest-build FATAL.
 *
 * A pattern is a path - a '?' is legal only as the optional-parameter marker (':page?') -
 * and a parameter name is [A-Za-z0-9_] ending at '/', '.', '?' or the end (the documented
 * '/files/:id.:ext' form stays legal), so ':user-id' can no longer
 * compile to the parameter 'user' plus the literal '-id'. The shared assertion is driven
 * directly, and through the #[Route] / #[Portal_Route] modules over a SYNTHETIC
 * manifest_data array to prove both realms call it. Pure array work, no database.
 *
 * Behavior of record: php artisan rsx:man routing.
 */
class Route_Pattern_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_well_formed_patterns_are_accepted()
    {
        $patterns = [
            '/',
            '/users',
            '/users/:id',
            '/users/:user_id/edit',
            '/list/:page?',
            '/posts/:id?/comments',
            '/files/*',
            '/a/:Mixed_Case9/:b2',
            '/api/v1/clients/:id',
            '/files/:id.:ext',
            '/doc/:name.pdf',
        ];

        foreach ($patterns as $pattern) {
            Route_ManifestSupport::assert_route_pattern($pattern, 'probe');
        }

        static::__pass('every well-formed pattern passes');
    }

    public static function test_a_query_part_is_refused()
    {
        foreach (['/search?q=1', '/search/?q', '/users/:id?x=1', '/report?', '/a/:b/c?d'] as $pattern) {
            static::__assert_throws(
                RuntimeException::class,
                fn () => Route_ManifestSupport::assert_route_pattern($pattern, 'probe'),
                'ROUTE-PATH-01'
            );
        }
    }

    public static function test_a_parameter_name_outside_the_alphabet_is_refused()
    {
        foreach (['/users/:user-id', '/x/:', '/x/:/y', '/x/:a%b', '/x/:id-:slug', '/x/:id~v'] as $pattern) {
            static::__assert_throws(
                RuntimeException::class,
                fn () => Route_ManifestSupport::assert_route_pattern($pattern, 'probe'),
                'ROUTE-PARAM-01'
            );
        }
    }

    public static function test_the_refusal_names_the_parameter_and_the_fix()
    {
        try {
            Route_ManifestSupport::assert_route_pattern('/users/:user-id/edit', 'Probe_Controller::edit in probe.php');
            static::__fail('a hyphenated parameter name was accepted');
        } catch (RuntimeException $e) {
            static::__assert_contains("':user-id'", $e->getMessage(), 'names the parameter as written');
            static::__assert_contains("':user_id'", $e->getMessage(), 'shows the renamed parameter');
            static::__assert_contains('[A-Za-z0-9_]', $e->getMessage(), 'states the alphabet');
            static::__assert_contains('Probe_Controller::edit in probe.php', $e->getMessage(), 'names the declaration');
        }
    }

    /**
     * Why the rule exists: before it, the matcher read the longest run of name characters
     * and left the rest as literal text. The assertion now stops such a pattern at the
     * build; this pins the matcher behaviour the refusal guards against.
     */
    public static function test_the_matcher_reads_a_name_as_the_longest_run_of_name_characters()
    {
        $compiled = RouteResolver::compile_pattern('/_test/pattern/:user-id');

        static::__assert_equals(['user'], $compiled['params'], 'the parameter is "user", the rest is literal');
    }

    public static function test_both_realms_refuse_at_manifest_build()
    {
        foreach (['Route' => Route_ManifestSupport::class, 'Portal_Route' => Portal_Route_ManifestSupport::class] as $attribute => $module) {
            foreach (['/_test/pattern/:user-id' => 'ROUTE-PARAM-01', '/_test/pattern?x=1' => 'ROUTE-PATH-01'] as $pattern => $rule) {
                static::__assert_throws(
                    RuntimeException::class,
                    function () use ($attribute, $module, $pattern) {
                        $manifest = static::__manifest($attribute, [$pattern]);
                        $module::process($manifest, array_keys($manifest['data']['files']), []);
                    },
                    $rule
                );
            }

            $manifest = static::__manifest($attribute, ['/_test/pattern/:user_id']);
            $module::process($manifest, array_keys($manifest['data']['files']), []);
            $table = $attribute === 'Route' ? 'routes' : 'portal_routes';
            static::__assert_true(isset($manifest['data'][$table]['/_test/pattern/:user_id']), "{$attribute}: a well-formed pattern is recorded");
        }
    }

    /**
     * A synthetic manifest_data with one controller method carrying one route attribute.
     */
    private static function __manifest(string $attribute, array $args): array
    {
        return [
            'data' => [
                'files' => [
                    'fake/synthetic_pattern_controller.php' => [
                        'fqcn' => 'App\RSpade\Tests\Dispatch\Php\Synthetic_Pattern_Controller',
                        'class' => 'Synthetic_Pattern_Controller',
                        'attributes' => null,
                        'public_static_methods' => [
                            'probe' => ['attributes' => [$attribute => [$args], 'Auth' => [['public']]]],
                        ],
                    ],
                ],
                'routes' => [],
                'portal_routes' => [],
            ],
        ];
    }
}
