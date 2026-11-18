<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\PHP\Rsx_Namespace;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE AJAX CONTROLLER JS STUB (Controller_Stub_ManifestSupport), over a child build.
 *
 * `My_Controller.save({...})` exists in the browser because the build writes one stub per
 * controller that declares #[Ajax_Endpoint] methods: a class of the controller's own name with
 * one static method per endpoint, each carrying its `/_ajax/<Controller>/<method>` path. A
 * stale stub is a client-side "X is not a function", so pinned here:
 *
 *   - only #[Ajax_Endpoint] methods are stubbed - a route action or a plain static is not;
 *   - the controller's file record names its stub, and the stub is itself indexed;
 *   - a controller with no endpoint gets no stub;
 *   - an endpoint that loses its attribute leaves the stub on the next build, and a
 *     controller that loses its last one loses its stub file and record;
 *   - an endpoint is never inherited: extending a controller that declares one fails the
 *     build (ABSTRACT-ATTR-01), and a controller over an abstract base of plain methods is
 *     stubbed with its own endpoints only.
 *
 * Every build is a child against scratch build and tmp roots (the house fixture-build seam,
 * Manifest_Fixture_Build_Test); the fixture tree sits under a `resource/` directory, so only
 * the children scan it.
 */
class Controller_Stub_Generation_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static string $tree = '';

    private static string $build_root = '';

    private static function __make_tree(): void
    {
        static::$tree = 'app/RSpade/temp/resource/controller_stub' . getmypid();
        static::$build_root = Rsx_Project_Paths::tmp_path('controller-stub-test/' . getmypid() . '/build');

        static::__remove_tree();

        ensure_directory(base_path(static::$tree));
        ensure_directory(static::$build_root);
        ensure_directory(static::$build_root . '-tmp');
    }

    private static function __remove_tree(): void
    {
        if (static::$tree !== '' && is_dir(base_path(static::$tree))) {
            rmdir_recursive(base_path(static::$tree));
        }

        if (static::$build_root !== '' && is_dir(dirname(static::$build_root))) {
            rmdir_recursive(dirname(static::$build_root));
        }
    }

    /**
     * Write a controller into the fixture tree. $methods is name => attribute lines
     * ('' for none); $extends names the parent. A concrete controller carries a class-level
     * #[Auth('public')]; an abstract one carries no attribute at all (ABSTRACT-ATTR-01).
     */
    private static function __write_controller(string $file, string $class, array $methods, string $extends = 'Rsx_Controller_Abstract', bool $abstract = false): string
    {
        $relative = static::$tree . '/' . $file;
        $namespace = Rsx_Namespace::from_path($relative);

        $body = '';

        foreach ($methods as $name => $attributes) {
            $body .= ($attributes === '' ? '' : "    {$attributes}\n")
                . "    public static function {$name}(Request \$request, array \$params = [])\n    {\n        return ['ran' => '{$name}'];\n    }\n\n";
        }

        $uses = "use Illuminate\\Http\\Request;\n";
        if ($extends === 'Rsx_Controller_Abstract') {
            $uses .= "use App\\RSpade\\Core\\Controller\\Rsx_Controller_Abstract;\n";
        }

        file_put_contents(
            base_path($relative),
            "<?php\n\nnamespace {$namespace};\n\n{$uses}\n" . ($abstract ? "abstract class" : "#[Auth('public')]\nclass")
                . " {$class} extends {$extends}\n{\n" . rtrim($body) . "\n}\n"
        );

        return $relative;
    }

    /** The probe controller: its endpoints, a route action and plain statics. */
    private static function __write_probe(array $endpoints, array $plain = []): string
    {
        $methods = [];

        foreach ($endpoints as $endpoint) {
            $methods[$endpoint] = '#[Ajax_Endpoint]';
        }

        foreach ($plain as $name) {
            $methods[$name] = '';
        }

        $methods['page_action'] = "#[Route('/_test/controller-stub/page')]";
        $methods['plain_helper'] = '';

        return static::__write_controller('csg_probe_controller.php', 'Csg_Probe_Controller', $methods);
    }

    /** Build in a child; it must succeed. Returns the union of both index halves. */
    private static function __build(): array
    {
        $output = [];

        $exit = Rsx_Artisan::run('rsx:manifest:build', [
            '--_rsx-build-root=' . static::$build_root,
            '--_rsx-tmp-root=' . static::$build_root . '-tmp',
            '--_manifest-extra-scan-roots=' . static::$tree,
        ], $output);

        static::__assert_equals(0, $exit, "the fixture build failed:\n" . implode("\n", $output));

        $manifest = include static::$build_root . '/manifest_index.php';
        $cold_path = static::$build_root . '/manifest_files.php';

        if (file_exists($cold_path)) {
            $manifest['data']['files'] = $manifest['data']['files'] + (include $cold_path);
        }

        return $manifest;
    }

    private static function __stub_path(string $controller): string
    {
        return static::$build_root . '-tmp/' . Rsx_Project_Paths::STUBS_CONTROLLER . '/' . $controller . '.js';
    }

    private static function __stub(string $controller): ?string
    {
        $path = static::__stub_path($controller);

        return file_exists($path) ? file_get_contents($path) : null;
    }

    private static function __assert_stubs_exactly(string $controller, array $endpoints): void
    {
        $stub = static::__stub($controller);

        static::__assert_not_null($stub, "{$controller} has a stub");
        static::__assert_contains("class {$controller} {", $stub, 'the stub class is named after the controller');

        preg_match_all('/^    static (\w+)\(params = \{\}\) \{$/m', $stub, $matches);
        static::__assert_equals($endpoints, $matches[1], "{$controller}'s stub methods are exactly its endpoints");

        foreach ($endpoints as $endpoint) {
            static::__assert_contains(
                "{$controller}.{$endpoint}.path = '/_ajax/{$controller}/{$endpoint}';",
                $stub,
                "{$endpoint} carries its endpoint path"
            );
        }
    }

    public static function test_only_ajax_endpoints_are_stubbed_under_the_controller_name()
    {
        static::__make_tree();

        try {
            $relative = static::__write_probe(['save_record', 'load_record']);

            $manifest = static::__build();

            static::__assert_stubs_exactly('Csg_Probe_Controller', ['save_record', 'load_record']);

            $stub = static::__stub('Csg_Probe_Controller');
            static::__assert_false(str_contains($stub, 'page_action'), 'a route action is not stubbed');
            static::__assert_false(str_contains($stub, 'plain_helper'), 'a plain static is not stubbed');

            $key = Rsx_Project_Paths::stub_key(Rsx_Project_Paths::STUBS_CONTROLLER, 'Csg_Probe_Controller.js');

            static::__assert_equals($key, $manifest['data']['files'][$relative]['js_stub'] ?? null, "the controller's record names its stub");
            static::__assert_true((bool) ($manifest['data']['files'][$key]['is_stub'] ?? false), 'the stub is indexed as a stub');
            static::__assert_equals($relative, $manifest['data']['files'][$key]['source_controller'] ?? null, 'naming its source controller');
        } finally {
            static::__remove_tree();
        }
    }

    public static function test_a_controller_with_no_endpoint_gets_no_stub()
    {
        static::__make_tree();

        try {
            $relative = static::__write_controller('csg_plain_controller.php', 'Csg_Plain_Controller', [
                'page_action' => "#[Route('/_test/controller-stub/plain')]",
            ]);

            $manifest = static::__build();

            static::__assert_null(static::__stub('Csg_Plain_Controller'), 'no stub file');
            static::__assert_false(isset($manifest['data']['files'][$relative]['js_stub']), 'no js_stub on the record');
        } finally {
            static::__remove_tree();
        }
    }

    public static function test_a_removed_endpoint_leaves_the_stub_and_the_last_one_takes_the_stub_away()
    {
        static::__make_tree();

        try {
            static::__write_probe(['save_record', 'load_record']);
            static::__build();
            static::__assert_stubs_exactly('Csg_Probe_Controller', ['save_record', 'load_record']);

            // load_record loses its attribute: still a method, no longer an endpoint.
            $controller = static::__write_probe(['save_record'], ['load_record']);

            static::__build();
            static::__assert_stubs_exactly('Csg_Probe_Controller', ['save_record']);

            // The last endpoint goes.
            static::__write_probe([]);
            $manifest = static::__build();

            $key = Rsx_Project_Paths::stub_key(Rsx_Project_Paths::STUBS_CONTROLLER, 'Csg_Probe_Controller.js');

            static::__assert_false(file_exists(static::__stub_path('Csg_Probe_Controller')), 'the stub file is removed');
            static::__assert_false(isset($manifest['data']['files'][$key]), 'and its index record');
            static::__assert_false(isset($manifest['data']['files'][$controller]['js_stub']), 'and the controller no longer names one');
        } finally {
            static::__remove_tree();
        }
    }

    /**
     * A controller carrying registry attributes is final (ABSTRACT-ATTR-01), so an endpoint is
     * never inherited from a controller: the build refuses the subclass. Shared code lives in an
     * abstract base as plain methods, and the stub holds only the class's own endpoints.
     */
    public static function test_endpoints_are_never_inherited_and_a_stub_holds_only_its_own()
    {
        static::__make_tree();

        try {
            static::__write_controller('csg_parent_controller.php', 'Csg_Parent_Controller', [
                'inherited_call' => '#[Ajax_Endpoint]',
            ]);
            static::__write_controller('csg_child_controller.php', 'Csg_Child_Controller', [
                'child_call' => '#[Ajax_Endpoint]',
            ], 'Csg_Parent_Controller');

            $output = [];
            $exit = Rsx_Artisan::run('rsx:manifest:build', [
                '--_rsx-build-root=' . static::$build_root,
                '--_rsx-tmp-root=' . static::$build_root . '-tmp',
                '--_manifest-extra-scan-roots=' . static::$tree,
            ], $output);

            static::__assert_not_equals(0, $exit, 'extending a controller that declares endpoints fails the build');
            static::__assert_contains('ABSTRACT-ATTR-01', (string) preg_replace('/\s+/', '', implode("\n", $output)), 'naming the rule');

            unlink(base_path(static::$tree . '/csg_parent_controller.php'));
            unlink(base_path(static::$tree . '/csg_child_controller.php'));

            static::__write_controller('csg_base_controller_abstract.php', 'Csg_Base_Controller_Abstract', [
                'shared_helper' => '',
            ], 'Rsx_Controller_Abstract', true);
            static::__write_controller('csg_derived_controller.php', 'Csg_Derived_Controller', [
                'own_call' => '#[Ajax_Endpoint]',
            ], 'Csg_Base_Controller_Abstract');

            static::__build();

            static::__assert_stubs_exactly('Csg_Derived_Controller', ['own_call']);
            static::__assert_null(static::__stub('Csg_Base_Controller_Abstract'), 'an abstract base declaring no endpoint gets no stub');
        } finally {
            static::__remove_tree();
        }
    }
}
