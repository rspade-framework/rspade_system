<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\AuthGates\Php;

use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Database\Model_Fetch_Lineage;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Portal\Portal_Spa_ManifestSupport;
use App\RSpade\Core\SPA\Spa_ManifestSupport;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * CLOSED BY DEFAULT AT BUILD TIME: every dispatchable row the manifest records has a
 * surface in the auth index, and nothing dispatchable is declared in a shape the index
 * cannot see.
 *
 * The findings under test (all raised through Auth_ManifestSupport::validate()'s one
 * batched exception, or thrown by the SPA row modules):
 *
 *   - NO SURFACE              a route / SPA / API row or Ajax / fetch member naming no surface
 *   - NOT STATIC              a surface attribute on a public instance method
 *   - UNMARKED FETCH OVERRIDE a model redeclaring a fetch surface without its attribute
 *   - an @spa / @portal_spa target that is not the #[SPA] / #[Portal_Route] bootstrap
 *
 * plus the lineage rule they rest on: Model_Fetch_Lineage stops at the NEAREST declaration.
 *
 * Driven over synthetic manifest data; the live-index cases assert the real tree is clean.
 *
 * Behavior of record: php artisan rsx:man auth_gates (CLOSED BY DEFAULT).
 */
class Auth_Build_Coverage_Test extends Rsx_Test_Abstract
{
    // =========================================================================
    // NO SURFACE
    // =========================================================================

    /**
     * A route row naming an unindexed surface, and an SPA row whose JS action is unindexed,
     * are each a NO SURFACE finding; rows naming indexed surfaces are not.
     */
    public static function test_rows_without_a_surface_are_findings()
    {
        $data = [
            'routes' => [
                '/cov/ok' => ['type' => 'standard', 'file' => 'fixture/ok.php', 'surface' => 'Cov_Ok::index'],
                '/cov/missing' => ['type' => 'standard', 'file' => 'fixture/missing.php', 'surface' => 'Cov_Missing::index'],
                '/cov/spa' => [
                    'type' => 'spa',
                    'file' => 'fixture/boot.php',
                    'surface' => 'Cov_Ok::index',
                    'target' => 'Cov_Missing_Action',
                ],
            ],
            'portal_routes' => [
                '/cov/portal' => ['type' => 'portal', 'file' => 'fixture/portal.php', 'surface' => 'Cov_Portal_Missing::index'],
            ],
            'api_endpoints' => [
                '/api/v1/cov' => ['class' => 'Cov_Api_Missing', 'method' => 'list', 'file' => 'fixture/api.php'],
            ],
            'attribute_index' => [
                'Ajax_Endpoint' => [['file' => 'fixture/ajax.php', 'class' => 'Cov_Ajax_Missing', 'member' => 'save']],
                'Ajax_Endpoint_Model_Fetch' => [['file' => 'fixture/model.php', 'class' => 'Cov_Ok', 'member' => 'index']],
            ],
        ];

        $violations = Auth_ManifestSupport::row_surface_violations($data, ['Cov_Ok::index' => static::__surface()]);

        $targets = array_column($violations, 'target');
        sort($targets);

        static::__assert_equals(
            ['Cov_Ajax_Missing::save', 'Cov_Api_Missing::list', 'Cov_Missing::index', 'Cov_Missing_Action', 'Cov_Portal_Missing::index'],
            $targets
        );

        foreach ($violations as $violation) {
            static::__assert_equals('NO SURFACE', $violation['type']);
        }
    }

    /**
     * The finding reaches the batched build failure with its remediation text.
     */
    public static function test_no_surface_finding_fails_the_build_with_remediation()
    {
        $violations = Auth_ManifestSupport::row_surface_violations(
            ['routes' => ['/cov/missing' => ['type' => 'standard', 'file' => 'fixture/missing.php', 'surface' => 'Cov_Missing::index']]],
            []
        );

        $e = static::__assert_throws(
            \RuntimeException::class,
            fn () => Auth_ManifestSupport::validate([], [], $violations),
            'NO SURFACE: Cov_Missing::index'
        );

        static::__assert_contains('PUBLIC STATIC method', $e->getMessage());
        static::__assert_contains("'/cov/missing'", $e->getMessage());
    }

    /**
     * Every row of the live manifest names an indexed surface.
     */
    public static function test_the_live_manifest_rows_all_name_a_surface()
    {
        $data = Manifest::get_full_manifest()['data'];

        static::__assert_equals(
            [],
            Auth_ManifestSupport::row_surface_violations($data, $data['auth']['surfaces'] ?? [])
        );
    }

    // =========================================================================
    // NOT STATIC
    // =========================================================================

    /**
     * A surface attribute on a public instance method is a finding naming the attribute.
     * #[Ajax_Endpoint_Model_Fetch] on an instance method is a fetchable relationship, not
     * a finding.
     */
    public static function test_surface_attribute_on_an_instance_method_is_a_finding()
    {
        $index = Auth_ManifestSupport::build_index([
            'fixture/cov_instance.php' => [
                'extension' => 'php',
                'class' => 'Cov_Instance_Fixture',
                'fqcn' => 'Fixture\\Cov_Instance_Fixture',
                'attributes' => ['Auth' => [['public']]],
                'public_static_methods' => [],
                'public_instance_methods' => [
                    'page' => ['attributes' => ['Route' => [['/cov-instance']]]],
                    'endpoint' => ['attributes' => ['Ajax_Endpoint' => [[]]]],
                    'related' => ['attributes' => ['Ajax_Endpoint_Model_Fetch' => [[]]]],
                ],
            ],
        ]);

        $found = [];
        foreach ($index['violations'] as $violation) {
            if ($violation['type'] === 'NOT STATIC') {
                $found[$violation['target']] = $violation['detail'];
            }
        }

        ksort($found);

        static::__assert_equals(['Cov_Instance_Fixture::endpoint', 'Cov_Instance_Fixture::page'], array_keys($found));
        static::__assert_contains('#[Route]', $found['Cov_Instance_Fixture::page']);
        static::__assert_false(isset($index['surfaces']['Cov_Instance_Fixture::page']));
    }

    // =========================================================================
    // THE LINEAGE: NEAREST DECLARATION WINS
    // =========================================================================

    /**
     * A redeclaration WITHOUT the attribute ends the climb: the member is not a fetch
     * surface, whatever an ancestor says. With no redeclaration the ancestor's answers.
     */
    public static function test_lineage_stops_at_the_nearest_declaration()
    {
        $records = static::__model_lineage(false);

        static::__assert_null(Model_Fetch_Lineage::declaration_in(
            $records, 'Cov_Child_Model', 'fetch', 'Ajax_Endpoint_Model_Fetch', 'public_static_methods'
        ));
        static::__assert_null(Model_Fetch_Lineage::declaration_in(
            $records, 'Cov_Child_Model', 'owner', 'Ajax_Endpoint_Model_Fetch', 'public_instance_methods'
        ));

        $inherited = Model_Fetch_Lineage::declaration_in(
            $records, 'Cov_Child_Model', 'items', 'Ajax_Endpoint_Model_Fetch', 'public_instance_methods'
        );
        static::__assert_equals('Cov_Base_Model', $inherited['class']);
    }

    /**
     * An unmarked redeclaration of a marked fetch() or relationship is a build finding,
     * and no inherited surface is recorded for it.
     */
    public static function test_unmarked_fetch_override_is_a_finding()
    {
        $index = Auth_ManifestSupport::build_index(static::__lineage_files(false));

        $found = [];
        foreach ($index['violations'] as $violation) {
            if ($violation['type'] === 'UNMARKED FETCH OVERRIDE') {
                $found[] = $violation['target'];
            }
        }

        sort($found);

        static::__assert_equals(['Cov_Child_Model::fetch', 'Cov_Child_Model::owner'], $found);
        static::__assert_false(isset($index['surfaces']['Cov_Child_Model::fetch']));
        static::__assert_false(isset($index['surfaces']['Cov_Child_Model::owner']));

        // The member the child does NOT redeclare is still inherited, gated.
        static::__assert_equals(['is_logged_in'], $index['surfaces']['Cov_Child_Model::items']['auth']);
    }

    /**
     * A redeclaration that carries the attribute is the child's own surface - no finding.
     */
    public static function test_marked_fetch_override_is_not_a_finding()
    {
        $index = Auth_ManifestSupport::build_index(static::__lineage_files(true));

        foreach ($index['violations'] as $violation) {
            static::__assert_false(
                $violation['type'] === 'UNMARKED FETCH OVERRIDE',
                'a marked override is not a finding: ' . $violation['target']
            );
        }

        static::__assert_equals(['is_logged_in'], $index['surfaces']['Cov_Child_Model::fetch']['auth']);
        static::__assert_equals(['is_logged_in'], $index['surfaces']['Cov_Child_Model::owner']['auth']);
    }

    // =========================================================================
    // @spa / @portal_spa TARGETS
    // =========================================================================

    /**
     * An @spa target without #[SPA] fails the build naming both files; with #[SPA] the
     * row is recorded.
     */
    public static function test_spa_target_must_carry_spa()
    {
        $data = static::__spa_manifest('spa', []);

        $e = static::__assert_throws(
            \RuntimeException::class,
            fn () => Spa_ManifestSupport::rebuild($data),
            "names @spa('Cov_Spa_Controller::index')"
        );
        static::__assert_contains('is not an #[SPA] bootstrap', $e->getMessage());

        $data = static::__spa_manifest('spa', ['SPA' => [[]]]);
        Spa_ManifestSupport::rebuild($data);

        static::__assert_equals('Cov_Spa_Controller::index', $data['data']['routes']['/cov-spa']['surface']);
    }

    /**
     * An @portal_spa target without #[Portal_Route] fails the build; with it the row is recorded.
     */
    public static function test_portal_spa_target_must_carry_portal_route()
    {
        $data = static::__spa_manifest('portal_spa', []);

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Portal_Spa_ManifestSupport::rebuild($data),
            'is not a #[Portal_Route] bootstrap'
        );

        $data = static::__spa_manifest('portal_spa', ['Portal_Route' => [['/*']]]);
        Portal_Spa_ManifestSupport::rebuild($data);

        static::__assert_equals('Cov_Spa_Controller::index', $data['data']['portal_routes']['/cov-spa']['surface']);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * One synthetic, gated surface entry.
     */
    private static function __surface(): array
    {
        return ['kinds' => ['route'], 'realm' => 'staff', 'auth' => ['public'], 'file' => 'fixture/ok.php', 'member' => 'x'];
    }

    /**
     * Base model marks fetch(), owner() and items(); the child redeclares fetch() and
     * owner(), marked or not.
     *
     * @return array<string, array> file => metadata, in build_index()'s input shape
     */
    private static function __lineage_files(bool $child_marks_overrides): array
    {
        $marked = ['Ajax_Endpoint_Model_Fetch' => [[]], 'Auth' => [['is_logged_in']]];
        $child_attributes = $child_marks_overrides ? $marked : [];

        return [
            'fixture/cov_base_model.php' => [
                'extension' => 'php',
                'class' => 'Cov_Base_Model',
                'fqcn' => 'Fixture\\Cov_Base_Model',
                'extends' => 'Rsx_Model_Abstract',
                'abstract' => true,
                'public_static_methods' => ['fetch' => ['attributes' => $marked]],
                'public_instance_methods' => [
                    'owner' => ['attributes' => $marked],
                    'items' => ['attributes' => $marked],
                ],
            ],
            'fixture/cov_child_model.php' => [
                'extension' => 'php',
                'class' => 'Cov_Child_Model',
                'fqcn' => 'Fixture\\Cov_Child_Model',
                'extends' => 'Cov_Base_Model',
                'abstract' => false,
                'public_static_methods' => ['fetch' => ['attributes' => $child_attributes]],
                'public_instance_methods' => ['owner' => ['attributes' => $child_attributes]],
            ],
            'fixture/rsx_model_abstract.php' => [
                'extension' => 'php',
                'class' => 'Rsx_Model_Abstract',
                'fqcn' => 'Fixture\\Rsx_Model_Abstract',
                'abstract' => true,
            ],
        ];
    }

    /**
     * The same lineage as class => record, the shape Model_Fetch_Lineage::declaration_in() reads.
     *
     * @return array<string, array>
     */
    private static function __model_lineage(bool $child_marks_overrides): array
    {
        $records = [];

        foreach (static::__lineage_files($child_marks_overrides) as $file => $metadata) {
            $records[$metadata['class']] = $metadata + ['file' => $file];
        }

        return $records;
    }

    /**
     * A minimal manifest holding one Spa_Action whose @spa / @portal_spa decorator names
     * Cov_Spa_Controller::index, carrying the given method attributes.
     */
    private static function __spa_manifest(string $decorator, array $bootstrap_attributes): array
    {
        return [
            'data' => [
                'routes' => [],
                'portal_routes' => [],
                'js_classes' => ['Cov_Spa_Action' => ['file' => 'fixture/cov_spa_action.js']],
                'js_subclass_index' => ['Spa_Action' => ['Cov_Spa_Action']],
                'php_classes' => [
                    'Cov_Spa_Controller' => ['file' => 'fixture/cov_spa_controller.php', 'fqcn' => 'Fixture\\Cov_Spa_Controller'],
                ],
                'files' => [
                    'fixture/cov_spa_action.js' => [
                        'decorators' => [
                            ['route', ['/cov-spa']],
                            [$decorator, ['Cov_Spa_Controller::index']],
                        ],
                    ],
                    'fixture/cov_spa_controller.php' => [
                        'attributes' => ['Auth' => [['public']]],
                        'public_static_methods' => ['index' => ['attributes' => $bootstrap_attributes]],
                    ],
                ],
            ],
        ];
    }
}
