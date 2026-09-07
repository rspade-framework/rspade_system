<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\AuthGates\Php;

use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Auth_ManifestSupport indexing + check-registry construction.
 *
 * Driven by SYNTHETIC manifest metadata: process() is pure over the array it is
 * handed, so a fixture lineage can be described without adding real classes to the
 * application's check vocabulary. (The one exception is the JS action pass, which
 * reads the real manifest; the synthetic targets here are uniquely named so real
 * actions cannot collide with the assertions.)
 */
class Auth_Index_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const STAFF_BASE = 'App\\RSpade\\Core\\Permission\\Permission_Abstract';
    private const PORTAL_BASE = 'App\\RSpade\\Core\\Portal\\Portal_Permission_Abstract';

    // =========================================================================
    // GATE COLLECTION
    // =========================================================================

    /**
     * #[Auth('a','b')] arrives as a single instance with two arguments.
     */
    public static function test_variadic_arguments_are_collected_in_order()
    {
        $auth = static::__build([
            'fixture/route.php' => static::__class_metadata('Idx_Route_Fixture', null, [
                'index' => static::__method(['Route' => [['/idx-route']], 'Auth' => [['alpha', 'beta']]]),
            ]),
        ]);

        static::__assert_equals(['alpha', 'beta'], $auth['surfaces']['Idx_Route_Fixture::index']['auth']);
        static::__assert_equals(['route'], $auth['surfaces']['Idx_Route_Fixture::index']['kinds']);
        static::__assert_equals('staff', $auth['surfaces']['Idx_Route_Fixture::index']['realm']);
    }

    /**
     * Class-level gates come first, the method's own are appended, repeats drop.
     */
    public static function test_class_and_method_gates_merge_additively_with_dedupe()
    {
        $auth = static::__build([
            'fixture/merge.php' => static::__class_metadata(
                'Idx_Merge_Fixture',
                ['Auth' => [['is_logged_in', 'shared']]],
                [
                    'save' => static::__method([
                        'Ajax_Endpoint' => [[]],
                        'Auth' => [['shared', 'can_edit']],
                    ]),
                ]
            ),
        ]);

        static::__assert_equals(
            ['is_logged_in', 'shared', 'can_edit'],
            $auth['surfaces']['Idx_Merge_Fixture::save']['auth']
        );
    }

    /**
     * The contract is ONE #[Auth] per declaration. A repeated attribute is stored as
     * two instances; the reader merges them rather than silently taking one.
     */
    public static function test_repeated_auth_attribute_instances_merge()
    {
        $auth = static::__build([
            'fixture/repeat.php' => static::__class_metadata('Idx_Repeat_Fixture', null, [
                'index' => static::__method([
                    'Route' => [['/idx-repeat']],
                    'Auth' => [['first'], ['second', 'first']],
                ]),
            ]),
        ]);

        static::__assert_equals(['first', 'second'], $auth['surfaces']['Idx_Repeat_Fixture::index']['auth']);
    }

    /**
     * A non-string argument cannot be a check name; it fails the build.
     */
    public static function test_non_string_auth_argument_throws()
    {
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                static::__build([
                    'fixture/bad_arg.php' => static::__class_metadata('Idx_Bad_Arg_Fixture', null, [
                        'index' => static::__method([
                            'Route' => [['/idx-bad-arg']],
                            'Auth' => [[42]],
                        ]),
                    ]),
                ]);
            },
            'must be a non-empty check-name string'
        );
    }

    // =========================================================================
    // SURFACE KINDS AND REALMS
    // =========================================================================

    /**
     * Each surface attribute maps to its kind and realm. #[Ajax_Endpoint] and model
     * relationships are realm-agnostic ('any'): one dispatcher serves both realms.
     */
    public static function test_surface_kinds_and_realms()
    {
        $auth = static::__build([
            'fixture/kinds.php' => static::__class_metadata('Idx_Kinds_Fixture', null, [
                'page' => static::__method(['Route' => [['/idx-kinds']]]),
                'boot' => static::__method(['SPA' => [[]]]),
                'portal_page' => static::__method(['Portal_Route' => [['/idx-portal']]]),
                'endpoint' => static::__method(['Ajax_Endpoint' => [[]]]),
                'rest' => static::__method(['Api_Endpoint' => [['/api/v1/idx']]]),
                'fetch' => static::__method(['Ajax_Endpoint_Model_Fetch' => [[]]]),
                'portal_fetch' => static::__method(['Ajax_Endpoint_Model_Fetch' => [[]]]),
                // The scanner's static map is filtered PUBLIC-or-STATIC, so a public
                // INSTANCE method (a fetchable relationship) is listed here too. It
                // must still be classified as a relationship, not a fetch entry point.
                'related_items' => static::__method(['Ajax_Endpoint_Model_Fetch' => [[]]]),
            ], [
                'related_items' => static::__method(['Ajax_Endpoint_Model_Fetch' => [[]]]),
            ]),
        ]);

        $expected = [
            'Idx_Kinds_Fixture::page' => ['route', 'staff'],
            'Idx_Kinds_Fixture::boot' => ['spa', 'staff'],
            'Idx_Kinds_Fixture::portal_page' => ['portal_route', 'portal'],
            // An #[Ajax_Endpoint] declaring no realm, outside a portal root: the
            // fail-closed staff default (see AJAX REALM in Auth_ManifestSupport).
            'Idx_Kinds_Fixture::endpoint' => ['ajax', 'staff'],
            'Idx_Kinds_Fixture::rest' => ['api', 'staff'],
            'Idx_Kinds_Fixture::fetch' => ['model_fetch', 'staff'],
            'Idx_Kinds_Fixture::portal_fetch' => ['model_fetch', 'portal'],
            'Idx_Kinds_Fixture::related_items' => ['model_relationship', 'any'],
        ];

        foreach ($expected as $target => $spec) {
            static::__assert_array_has_key($target, $auth['surfaces'], "Missing surface {$target}");
            static::__assert_equals([$spec[0]], $auth['surfaces'][$target]['kinds'], "Wrong kind for {$target}");
            static::__assert_equals($spec[1], $auth['surfaces'][$target]['realm'], "Wrong realm for {$target}");
        }
    }

    /**
     * The three ways an #[Ajax_Endpoint] surface gets its realm: an explicit
     * class-level #[Auth_Realm], the portal-root path default, and the staff default.
     * The realm is what the Ajax seam refuses a cross-realm caller on.
     */
    public static function test_ajax_realm_resolution()
    {
        $auth = static::__build([
            'rsx/portal/things/portal_things_controller.php' => static::__class_metadata(
                'Idx_Portal_Ajax_Fixture',
                null,
                ['endpoint' => static::__method(['Ajax_Endpoint' => [[]]])]
            ),
            'rsx/app/things/things_controller.php' => static::__class_metadata(
                'Idx_Staff_Ajax_Fixture',
                null,
                ['endpoint' => static::__method(['Ajax_Endpoint' => [[]]])]
            ),
            'app/RSpade/Core/Things/Shared_Controller.php' => static::__class_metadata(
                'Idx_Shared_Ajax_Fixture',
                ['Auth_Realm' => [['any']]],
                ['endpoint' => static::__method(['Ajax_Endpoint' => [[]]])]
            ),
            // An explicit declaration OVERRIDES the path default, both directions.
            'rsx/portal/odd/odd_controller.php' => static::__class_metadata(
                'Idx_Declared_Staff_Fixture',
                ['Auth_Realm' => [['staff']]],
                ['endpoint' => static::__method(['Ajax_Endpoint' => [[]]])]
            ),
        ]);

        static::__assert_equals('portal', $auth['surfaces']['Idx_Portal_Ajax_Fixture::endpoint']['realm']);
        static::__assert_equals('staff', $auth['surfaces']['Idx_Staff_Ajax_Fixture::endpoint']['realm']);
        static::__assert_equals('any', $auth['surfaces']['Idx_Shared_Ajax_Fixture::endpoint']['realm']);
        static::__assert_equals('staff', $auth['surfaces']['Idx_Declared_Staff_Fixture::endpoint']['realm']);
    }

    // =========================================================================
    // CHECK REGISTRY
    // =========================================================================

    /**
     * Marked methods land in their realm's registry; the realms stay separate.
     */
    public static function test_marked_checks_register_per_realm()
    {
        $auth = static::__build([
            'fixture/staff_perm.php' => static::__permission_class('Idx_Staff_Permission', self::STAFF_BASE, [
                'can_do_staff_thing' => static::__check_method(),
            ]),
            'fixture/portal_perm.php' => static::__permission_class('Idx_Portal_Permission', self::PORTAL_BASE, [
                'can_do_portal_thing' => static::__check_method(),
            ]),
        ]);

        static::__assert_array_has_key('can_do_staff_thing', $auth['checks']['staff']);
        static::__assert_array_has_key('can_do_portal_thing', $auth['checks']['portal']);
        static::__assert_false(isset($auth['checks']['portal']['can_do_staff_thing']));
        static::__assert_false(isset($auth['checks']['staff']['can_do_portal_thing']));

        static::__assert_equals(
            'Fixture\\Idx_Staff_Permission',
            $auth['checks']['staff']['can_do_staff_thing']['class']
        );
    }

    /**
     * When a check is declared twice down one chain, the MOST DERIVED declaration is
     * the one that runs (a static call resolves to it), so it is the one registered.
     */
    public static function test_most_derived_declaration_wins()
    {
        $auth = static::__build([
            'fixture/mid.php' => static::__permission_class('Idx_Mid_Permission', self::STAFF_BASE, [
                'layered_check' => static::__check_method(),
            ]),
            'fixture/leaf.php' => static::__permission_class(
                'Idx_Leaf_Permission',
                'Fixture\\Idx_Mid_Permission',
                ['layered_check' => static::__check_method()]
            ),
        ]);

        static::__assert_equals(
            'Fixture\\Idx_Leaf_Permission',
            $auth['checks']['staff']['layered_check']['class']
        );
    }

    /**
     * Two unrelated branches claiming one name is a genuine collision.
     */
    public static function test_duplicate_check_name_on_unrelated_classes_throws()
    {
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                static::__build([
                    'fixture/dupe_a.php' => static::__permission_class('Idx_Dupe_A_Permission', self::STAFF_BASE, [
                        'colliding_check' => static::__check_method(),
                    ]),
                    'fixture/dupe_b.php' => static::__permission_class('Idx_Dupe_B_Permission', self::STAFF_BASE, [
                        'colliding_check' => static::__check_method(),
                    ]),
                ]);
            },
            "Duplicate auth check name 'colliding_check'"
        );
    }

    /**
     * A check takes no parameters - a predicate needing an argument is record-level.
     */
    public static function test_parameterized_check_throws()
    {
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                static::__build([
                    'fixture/param.php' => static::__permission_class('Idx_Param_Permission', self::STAFF_BASE, [
                        'needs_an_id' => [
                            'attributes' => ['Auth_Check' => [[]]],
                            'parameters' => [['name' => 'id', 'optional' => false, 'type' => 'int']],
                            'return_type' => ['type' => 'bool', 'nullable' => false],
                        ],
                    ]),
                ]);
            },
            'takes NO PARAMETERS'
        );
    }

    /**
     * A missing ': bool' return type fails statically rather than at dispatch.
     */
    public static function test_check_without_bool_return_type_throws()
    {
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                static::__build([
                    'fixture/untyped.php' => static::__permission_class('Idx_Untyped_Permission', self::STAFF_BASE, [
                        'untyped_check' => ['attributes' => ['Auth_Check' => [[]]]],
                    ]),
                ]);
            },
            "must declare a ': bool' return type"
        );
    }

    /**
     * A nullable ': ?bool' is not a bool declaration either.
     */
    public static function test_check_with_nullable_bool_return_type_throws()
    {
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                static::__build([
                    'fixture/nullable.php' => static::__permission_class('Idx_Nullable_Permission', self::STAFF_BASE, [
                        'nullable_check' => [
                            'attributes' => ['Auth_Check' => [[]]],
                            'return_type' => ['type' => 'bool', 'nullable' => true],
                        ],
                    ]),
                ]);
            },
            "must declare a ': bool' return type"
        );
    }

    /**
     * Checks are invoked statically, so a marked instance method can never run.
     */
    public static function test_instance_method_check_throws()
    {
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                static::__build([
                    'fixture/instance.php' => static::__permission_class(
                        'Idx_Instance_Permission',
                        self::STAFF_BASE,
                        // Present in BOTH maps, exactly as the scanner records a public
                        // instance method. It must be rejected as non-static rather than
                        // quietly registered as a check.
                        ['instance_check' => static::__check_method()],
                        ['instance_check' => static::__check_method()]
                    ),
                ]);
            },
            'must be a PUBLIC STATIC method'
        );
    }

    /**
     * An unmarked override would shadow the marked ancestor at runtime, so the
     * marked body would silently never execute. That fails the build.
     */
    public static function test_unmarked_override_of_marked_check_throws()
    {
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                static::__build([
                    'fixture/shadow_base.php' => static::__permission_class('Idx_Shadow_Base_Permission', self::STAFF_BASE, [
                        'shadowed_check' => static::__check_method(),
                    ]),
                    'fixture/shadow_leaf.php' => static::__permission_class(
                        'Idx_Shadow_Leaf_Permission',
                        'Fixture\\Idx_Shadow_Base_Permission',
                        ['shadowed_check' => ['return_type' => ['type' => 'bool', 'nullable' => false]]]
                    ),
                ]);
            },
            "Unmarked override of auth check 'shadowed_check'"
        );
    }

    // =========================================================================
    // SYNTHETIC MANIFEST HELPERS
    // =========================================================================

    /**
     * Run Auth_ManifestSupport over synthetic file metadata and return the index.
     *
     * @param array $files file path => class metadata
     * @return array
     */
    private static function __build(array $files): array
    {
        // build_index(), not process(): a synthetic file set describes a fixture
        // vocabulary, so the closed-by-default validation process() runs afterward
        // would flag every fixture surface. Validation has its own matrix
        // (Auth_Validation_Test).
        return Auth_ManifestSupport::build_index($files);
    }

    /**
     * Metadata for an ordinary (non-Permission) class carrying surfaces.
     */
    private static function __class_metadata(
        string $class,
        ?array $class_attributes,
        array $static_methods,
        array $instance_methods = []
    ): array {
        $metadata = [
            'extension' => 'php',
            'class' => $class,
            'fqcn' => 'Fixture\\' . $class,
            'public_static_methods' => $static_methods,
            'public_instance_methods' => $instance_methods,
        ];

        if ($class_attributes !== null) {
            $metadata['attributes'] = $class_attributes;
        }

        return $metadata;
    }

    /**
     * Metadata for a class inside a Permission realm lineage.
     */
    private static function __permission_class(
        string $class,
        string $extends_fqcn,
        array $static_methods,
        array $instance_methods = []
    ): array {
        return [
            'extension' => 'php',
            'class' => $class,
            'fqcn' => 'Fixture\\' . $class,
            'extends_fqcn' => $extends_fqcn,
            'public_static_methods' => $static_methods,
            'public_instance_methods' => $instance_methods,
        ];
    }

    /**
     * Metadata for one method carrying attributes.
     */
    private static function __method(array $attributes): array
    {
        return ['attributes' => $attributes];
    }

    /**
     * Metadata for a well-formed #[Auth_Check] method.
     */
    private static function __check_method(): array
    {
        return [
            'attributes' => ['Auth_Check' => [[]]],
            'return_type' => ['type' => 'bool', 'nullable' => false],
        ];
    }
}
