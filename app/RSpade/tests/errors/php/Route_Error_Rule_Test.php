<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Errors\Php;

use App\RSpade\Core\Dispatch\Route_ManifestSupport;
use App\RSpade\Core\Portal\Portal_Route_ManifestSupport;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * ROUTE-ERROR-01: scan-time enforcement of the reserved /error/ prefix, in both
 * realms.
 *
 * The support modules are fed a SYNTHETIC manifest_data array (never the real
 * manifest) so every rejection can be provoked in isolation. A well-formed
 * declaration must bake its row; a malformed one must throw a RuntimeException
 * naming the rule and the offending method. Pure array work, so no database.
 *
 * Behavior of record: php artisan rsx:man error_pages.
 */
class Route_Error_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** The rule id every violation message must carry. */
    private const RULE = 'ROUTE-ERROR-01';

    // =========================================================================
    // STAFF ROUTES
    // =========================================================================

    /**
     * A pattern that is neither a status nor 'generic' is not a page the funnel
     * ever looks up, so it would silently never render.
     */
    public static function test_a_pattern_that_is_not_a_status_or_generic_is_refused()
    {
        static::__assert_staff_throws(
            static::__manifest([['Route' => [['/error/oops']], 'Auth' => [['public']]]])
        );
    }

    /**
     * A status outside 400-599 is not an error status.
     */
    public static function test_a_status_outside_the_error_range_is_refused()
    {
        static::__assert_staff_throws(
            static::__manifest([['Route' => [['/error/200']], 'Auth' => [['public']]]])
        );
    }

    /**
     * An error page renders one status and has no URL to read, so a :param is a
     * declaration the funnel could never satisfy.
     */
    public static function test_a_param_in_the_pattern_is_refused()
    {
        static::__assert_staff_throws(
            static::__manifest([['Route' => [['/error/404/:id']], 'Auth' => [['public']]]])
        );
    }

    /**
     * An error page is rendered by GET.
     */
    public static function test_a_non_get_method_is_refused()
    {
        static::__assert_staff_throws(
            static::__manifest([['Route' => [['/error/404', ['POST']]], 'Auth' => [['public']]]])
        );
    }

    /**
     * A gated error page would deny the caller who was just denied.
     */
    public static function test_a_page_without_a_public_gate_is_refused()
    {
        static::__assert_staff_throws(
            static::__manifest([['Route' => [['/error/404']], 'Auth' => [['is_logged_in']]]])
        );
    }

    /**
     * The well-formed pair bakes its rows.
     */
    public static function test_a_well_formed_pair_passes()
    {
        $manifest = static::__manifest([
            ['Route' => [['/error/404']], 'Auth' => [['public']]],
            ['Route' => [['/error/generic']], 'Auth' => [['public']]],
        ]);

        Route_ManifestSupport::process($manifest, array_keys($manifest['data']['files']), []);

        static::__assert_true(
            isset($manifest['data']['routes']['/error/404']),
            'the exact page must be recorded'
        );
        static::__assert_true(
            isset($manifest['data']['routes']['/error/generic']),
            'the generic page must be recorded'
        );
    }

    /**
     * The gate may be declared on the class instead of the method - gates are
     * merged class-first, and the rule reads the merged list.
     */
    public static function test_a_class_level_public_gate_satisfies_the_rule()
    {
        $manifest = static::__manifest(
            [['Route' => [['/error/404']]]],
            ['Auth' => [['public']]]
        );

        Route_ManifestSupport::process($manifest, array_keys($manifest['data']['files']), []);

        static::__assert_true(isset($manifest['data']['routes']['/error/404']), 'the page must be recorded');
    }

    // =========================================================================
    // PORTAL ROUTES
    // =========================================================================

    /**
     * The portal declares its own pages, under the same rule - one
     * implementation, so the realms cannot drift.
     */
    public static function test_the_portal_refuses_a_malformed_pattern()
    {
        $manifest = static::__manifest([['Portal_Route' => [['/error/oops']], 'Auth' => [['public']]]]);

        static::__assert_throws(
            \RuntimeException::class,
            function () use ($manifest) {
                $data = $manifest;
                Portal_Route_ManifestSupport::process($data, array_keys($data['data']['files']), []);
            },
            self::RULE
        );
    }

    /**
     * A well-formed portal page bakes its row.
     */
    public static function test_the_portal_accepts_a_well_formed_page()
    {
        $manifest = static::__manifest([['Portal_Route' => [['/error/generic']], 'Auth' => [['public']]]]);

        Portal_Route_ManifestSupport::process($manifest, array_keys($manifest['data']['files']), []);

        static::__assert_true(
            isset($manifest['data']['portal_routes']['/error/generic']),
            'the portal generic page must be recorded'
        );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Build a synthetic manifest_data with one controller file carrying one
     * method per entry in $methods (keyed attributes as the scanner stores them).
     *
     * @param array $methods One attributes bucket per method
     * @param array|null $class_attributes The declaring class's own attributes
     * @return array
     */
    private static function __manifest(array $methods, ?array $class_attributes = null): array
    {
        $public_static_methods = [];
        foreach ($methods as $index => $attributes) {
            $public_static_methods['page_' . $index] = ['attributes' => $attributes];
        }

        return [
            'data' => [
                'files' => [
                    'fake/synthetic_errors_controller.php' => [
                        'fqcn' => 'App\RSpade\Tests\Errors\Php\Synthetic_Errors_Controller',
                        'class' => 'Synthetic_Errors_Controller',
                        'attributes' => $class_attributes,
                        'public_static_methods' => $public_static_methods,
                    ],
                ],
                'routes' => [],
                'portal_routes' => [],
            ],
        ];
    }

    /**
     * Assert the staff module refuses this manifest with a ROUTE-ERROR-01.
     */
    private static function __assert_staff_throws(array $manifest): void
    {
        static::__assert_throws(
            \RuntimeException::class,
            function () use ($manifest) {
                $data = $manifest;
                Route_ManifestSupport::process($data, array_keys($data['data']['files']), []);
            },
            self::RULE
        );
    }
}
