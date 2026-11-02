<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Companions\Php;

use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Companion\Companion_Descriptor_ManifestSupport;
use App\RSpade\Core\Companion\Companion_ManifestSupport;
use App\RSpade\Core\Companion\Companion_Service_Abstract;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Companions\Php\CompanionFixture\Companion_Fixture_Companion_Service;

/**
 * The manifest's half of the realm. Companion_ManifestSupport::assemble() is pure over its
 * input, so each FATAL is provoked with synthetic rows. Handler bodies are read from real
 * source: this class's fixture endpoints.
 */
class Companion_Manifest_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const ENDPOINTS_FILE = 'app/RSpade/tests/companions/php/Companion_Fixture_Endpoints.php';
    private const ENDPOINTS_FQCN = 'App\RSpade\Tests\Companions\Php\Companion_Fixture_Endpoints';

    // =========================================================================
    // DECLARATIONS
    // =========================================================================

    public static function test_the_declaration_reads_off_the_class()
    {
        $row = Companion_ManifestSupport::read_declaration(
            Companion_Fixture_Companion_Service::class,
            'app/RSpade/tests/companions/php/companion_fixture/Companion_Fixture_Companion_Service.php'
        );

        static::__assert_equals('companion_fixture', $row['name']);
        static::__assert_equals('Companion_Fixture_Companion_Service', $row['class']);
        static::__assert_equals('system/app/RSpade/tests/companions/php/companion_fixture', $row['directory']);
        static::__assert_equals(['node', 'resource/main.js'], $row['command']);
        static::__assert_equals(Companion_Service_Abstract::RESTART_ALWAYS, $row['restart_policy']);
        static::__assert_equals(Companion_Service_Abstract::CODE_CHANGE_RESTART_IF_AFFECTED, $row['on_code_change']);
        static::__assert_true($row['single_instance']);
    }

    public static function test_a_companion_is_a_service_so_tasks_work_on_it()
    {
        static::__assert_true(is_subclass_of(Companion_Fixture_Companion_Service::class, \App\RSpade\Core\Service\Rsx_Service_Abstract::class));
    }

    public static function test_the_name_is_validated()
    {
        foreach (['_daemon', 'Upper', '9lives', 'has-dash', ''] as $name) {
            static::__assert_throws_containing(
                fn () => Companion_ManifestSupport::read_declaration(static::__fixture_named($name), 'x/y.php'),
                'name() answered'
            );
        }
    }

    public static function test_endpoints_come_from_the_manifest()
    {
        static::__assert_equals(
            ['coded', 'fails', 'nothing', 'ping', 'run_as_dispatcher', 'write_record'],
            Companion_Fixture_Companion_Service::endpoints()
        );
    }

    // =========================================================================
    // THE FATALS
    // =========================================================================

    public static function test_an_endpoint_naming_no_companion_is_fatal()
    {
        static::__assert_fatal('no Companion_Service_Abstract subclass declares', static::__attribute_row('ping', 'no_such_companion'));
    }

    public static function test_an_attribute_with_no_name_is_fatal()
    {
        static::__assert_fatal("takes the companion's name", ['file' => self::ENDPOINTS_FILE, 'member' => 'ping', 'instances' => [[]]]);
    }

    public static function test_a_duplicate_endpoint_is_fatal()
    {
        static::__assert_throws_containing(
            fn () => static::__assemble([static::__attribute_row('ping'), static::__attribute_row('ping')]),
            "already has an endpoint 'ping'"
        );
    }

    public static function test_two_companions_with_one_name_are_fatal()
    {
        static::__assert_throws_containing(
            fn () => Companion_ManifestSupport::assemble([static::__declaration(), static::__declaration()], [], []),
            "two companions declare the name"
        );
    }

    public static function test_another_surface_attribute_on_the_method_is_fatal()
    {
        foreach (['Route', 'SPA', 'Portal_Route', 'Ajax_Endpoint', 'Ajax_Endpoint_Model_Fetch', 'Api_Endpoint', 'FPC'] as $attribute) {
            static::__assert_throws_containing(
                fn () => static::__assemble([static::__attribute_row('ping')], [$attribute => [[]]]),
                "must not also carry #[{$attribute}]"
            );
        }
    }

    public static function test_auth_on_the_method_is_fatal()
    {
        static::__assert_throws_containing(
            fn () => static::__assemble([static::__attribute_row('ping')], ['Auth' => [['is_logged_in']]]),
            'must not also carry #[Auth]'
        );
    }

    public static function test_a_class_level_auth_is_fatal()
    {
        static::__assert_throws_containing(
            fn () => static::__assemble([static::__attribute_row('ping')], [], ['Auth' => [['is_logged_in']]]),
            'its class declares #[Auth]'
        );
    }

    public static function test_an_instance_method_is_fatal()
    {
        static::__assert_throws_containing(
            fn () => Companion_ManifestSupport::assemble([static::__declaration()], [static::__attribute_row('ping')], [
                self::ENDPOINTS_FILE => ['fqcn' => self::ENDPOINTS_FQCN, 'class' => 'Companion_Fixture_Endpoints', 'public_static_methods' => []],
            ]),
            'PUBLIC STATIC'
        );
    }

    public static function test_the_sdk_probe_name_is_reserved()
    {
        $probe = Companion_Service_Abstract::SDK_PROBE_ENDPOINT;

        static::__assert_throws_containing(
            fn () => Companion_ManifestSupport::assemble([static::__declaration()], [static::__attribute_row($probe)], [
                self::ENDPOINTS_FILE => [
                    'fqcn' => self::ENDPOINTS_FQCN,
                    'class' => 'Companion_Fixture_Endpoints',
                    'public_static_methods' => [$probe => ['attributes' => ['Companion_Endpoint' => [['companion_fixture']]]]],
                ],
            ]),
            'reserved by the companion SDK'
        );

        $sdk = (string) file_get_contents(base_path('bin/rsx-companiond/sdk/rsx-companion-sdk.js'));
        static::__assert_true(str_contains($sdk, "const PROBE_ENDPOINT = '{$probe}';"), 'the SDK probes the name the manifest reserves');
    }

    public static function test_a_route_under_the_reserved_path_is_fatal()
    {
        static::__assert_throws_containing(
            fn () => Companion_ManifestSupport::assert_routes_not_reserved(['/_companion/x']),
            'Reserved route pattern'
        );

        Companion_ManifestSupport::assert_routes_not_reserved(['/_companions', '/companion/x']);
    }

    // =========================================================================
    // AUTH
    // =========================================================================

    public static function test_the_sentinel_resolves_in_the_companion_realm_alone()
    {
        $surface = fn (string $realm) => [
            'x::y' => ['kinds' => ['route'], 'realm' => $realm, 'auth' => [Auth_ManifestSupport::COMPANION_GATE], 'file' => 'f', 'member' => 'x::y'],
        ];

        Auth_ManifestSupport::validate($surface('companion'), ['staff' => [], 'portal' => []]);

        foreach (['staff', 'portal'] as $realm) {
            static::__assert_throws_containing(
                fn () => Auth_ManifestSupport::validate($surface($realm), ['staff' => [], 'portal' => []]),
                'companion_signature'
            );
        }
    }

    public static function test_a_staff_check_does_not_resolve_on_a_companion_surface()
    {
        $surfaces = ['x::y' => ['kinds' => ['companion'], 'realm' => 'companion', 'auth' => ['is_logged_in'], 'file' => 'f', 'member' => 'x::y']];

        static::__assert_throws_containing(
            fn () => Auth_ManifestSupport::validate($surfaces, ['staff' => ['is_logged_in' => []], 'portal' => []]),
            'is_logged_in'
        );
    }

    public static function test_a_companion_kind_mixed_with_another_is_refused_by_auth_too()
    {
        $surfaces = ['x::y' => ['kinds' => ['ajax', 'companion'], 'realm' => 'any', 'auth' => ['companion_signature'], 'file' => 'f', 'member' => 'x::y']];

        static::__assert_throws_containing(
            fn () => Auth_ManifestSupport::validate($surfaces, ['staff' => [], 'portal' => []]),
            'COMPANION CONFLICT'
        );
    }

    public static function test_every_endpoint_has_a_surface()
    {
        $data = ['companions' => Manifest::get_companions()];
        $surfaces = \App\RSpade\Core\Auth\Auth_Gates::get_surfaces();

        static::__assert_empty(Auth_ManifestSupport::row_surface_violations($data, $surfaces));

        $without = $surfaces;
        unset($without['Companion_Fixture_Endpoints::ping']);

        static::__assert_not_empty(Auth_ManifestSupport::row_surface_violations($data, $without));
    }

    // =========================================================================
    // THE CONTRACT KEY AND THE SOURCE HASH
    // =========================================================================

    public static function test_the_contract_key_is_deterministic_and_follows_the_endpoints()
    {
        $one = static::__assemble([static::__attribute_row('ping')])['companion_fixture']['endpoints_hash'];
        $again = static::__assemble([static::__attribute_row('ping')])['companion_fixture']['endpoints_hash'];
        $other = static::__assemble([static::__attribute_row('fails')])['companion_fixture']['endpoints_hash'];

        static::__assert_equals($one, $again);
        static::__assert_false($one === $other, 'another endpoint is another contract');
    }

    public static function test_the_source_hash_reads_content_and_relative_paths_only()
    {
        $root = sys_get_temp_dir() . '/companion_hash_' . bin2hex(random_bytes(6));
        mkdir($root . '/a/resource/node_modules/x', 0775, true);
        file_put_contents($root . '/a/resource/main.js', 'one');
        file_put_contents($root . '/a/resource/node_modules/x/y.js', 'ignored');
        file_put_contents($root . '/a/.hidden', 'ignored');

        $first = Companion_ManifestSupport::source_hash($root . '/a');

        touch($root . '/a/resource/main.js', 1000000);
        rename($root . '/a', $root . '/b');
        file_put_contents($root . '/b/resource/node_modules/x/y.js', 'changed');
        file_put_contents($root . '/b/.hidden', 'changed');

        static::__assert_equals($first, Companion_ManifestSupport::source_hash($root . '/b'), 'not the location, an mtime, node_modules or a dotfile');

        file_put_contents($root . '/b/resource/main.js', 'two');

        static::__assert_false($first === Companion_ManifestSupport::source_hash($root . '/b'));

        exec('rm -rf ' . escapeshellarg($root));
    }

    // =========================================================================
    // THE DESCRIPTOR AND THE CLIENT
    // =========================================================================

    public static function test_the_descriptor_carries_what_the_daemon_needs()
    {
        $companion = Manifest::get_companions()['companion_fixture'];
        $descriptor = json_decode(Companion_Descriptor_ManifestSupport::descriptor_json($companion), true);

        static::__assert_equals(Companion_Descriptor_ManifestSupport::DESCRIPTOR_SCHEMA, $descriptor['schema']);
        static::__assert_equals($companion['endpoints_hash'], $descriptor['endpoints_hash']);
        static::__assert_equals($companion['source_hash'], $descriptor['source_hash']);
        static::__assert_equals(array_keys($companion['endpoints']), $descriptor['endpoints']);
        static::__assert_true(str_contains(Companion_Descriptor_ManifestSupport::descriptor_json($companion), '"environment": {}'), 'an empty environment is an object');
        static::__assert_false(str_contains(json_encode($descriptor), env('APP_KEY')));
    }

    public static function test_the_client_names_exactly_the_endpoints()
    {
        $client = Companion_Descriptor_ManifestSupport::client_source(Manifest::get_companions()['companion_fixture']);

        static::__assert_true(str_contains($client, "ping: (params, options) => call('ping', params, options),"));
        static::__assert_false(str_contains($client, 'only_mine'));
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private static function __declaration(): array
    {
        return ['name' => 'companion_fixture', 'fqcn' => 'X', 'file' => 'x.php'];
    }

    private static function __attribute_row(string $method, string $companion = 'companion_fixture'): array
    {
        return ['file' => self::ENDPOINTS_FILE, 'member' => $method, 'instances' => [[$companion]]];
    }

    private static function __assemble(array $rows, array $method_attributes = [], array $class_attributes = []): array
    {
        $methods = [];
        foreach (['ping', 'fails'] as $method) {
            $methods[$method] = ['attributes' => array_merge(['Companion_Endpoint' => [['companion_fixture']]], $method_attributes)];
        }

        return Companion_ManifestSupport::assemble([static::__declaration()], $rows, [
            self::ENDPOINTS_FILE => [
                'fqcn' => self::ENDPOINTS_FQCN,
                'class' => 'Companion_Fixture_Endpoints',
                'attributes' => $class_attributes,
                'public_static_methods' => $methods,
            ],
        ]);
    }

    private static function __assert_fatal(string $message, array $row): void
    {
        static::__assert_throws_containing(fn () => static::__assemble([$row]), $message);
    }

    private static function __assert_throws_containing(callable $fn, string $message): void
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            static::__assert_true(str_contains($e->getMessage(), $message), "expected '{$message}', got: " . $e->getMessage());

            return;
        }

        static::__fail("Nothing threw; expected '{$message}'");
    }

    /**
     * A throwaway subclass answering $name.
     */
    private static function __fixture_named(string $name): string
    {
        $class = 'Companion_Named_Fixture_' . bin2hex(random_bytes(6));

        eval('class ' . $class . ' extends \\' . Companion_Service_Abstract::class . ' {
            public static function name(): string { return ' . var_export($name, true) . '; }
            public static function description(): string { return "x"; }
            public static function command(): array { return ["x"]; }
        }');

        return $class;
    }
}
