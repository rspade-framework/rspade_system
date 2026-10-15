<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ClassOverride\Php;

use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\Manifest_Overrides;
use App\RSpade\Core\PHP\Rsx_Namespace;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE OVERRIDE MAP, END TO END (Manifest_Overrides; rsx:man class_override).
 *
 * An application overrides a framework file by declaring its name under rsx/. The framework
 * file stays where it is; the manifest records it as SHADOWED and takes it out of the live
 * index. This class pins that lifecycle through real CHILD BUILDS of a fixture tree into a
 * scratch build root (the child-build seam, Core/Manifest/CLAUDE.md), so the developer's own
 * index is never touched:
 *
 *   - adding an override of each of the three kinds (php, js, jqhtml) shadows each
 *     framework file in ONE pass - no build restart - and the map records all three, while
 *     an application stylesheet wrapped in the same class as a framework one shadows nothing;
 *   - an unchanged rebuild is a no-op with the same build key;
 *   - removing the override returns the framework file to the live tree, reflected;
 *   - nothing in the framework fixture tree is ever written, renamed or archived;
 *   - a FATAL refusal fails the child build (two application templates of one name).
 *
 * The remaining refusals are decisions over the file list, asserted in-process against a
 * synthetic one: an override of a boot class, of a class in a subtree the manifest never
 * indexes or in a directory it passes over inside a scanned one, of a class already declared
 * in the building process, and a split base naming its own shell in a signature.
 *
 * THE FIXTURE TREES LIVE UNDER `resource/` DIRECTORIES, which no build descends into: they
 * are scanned only because the child names them as extra roots. A fixture that is DESIGNED
 * to fail a build must never be visible to anybody else's.
 */
class Manifest_Overrides_Test extends Rsx_Test_Abstract
{
    // The subject is the filesystem and child processes.
    protected static $use_database_transactions = false;

    /** The framework-side fixture root, relative to base_path(). */
    private static string $framework_root = '';

    /** The application-side fixture root, relative to base_path(). */
    private static string $application_root = '';

    /** The child's scratch build root. */
    private static string $build_root = '';

    // =====================================================================
    // The fixture
    // =====================================================================

    private static function __roots(): void
    {
        $tag = 'mo_' . getmypid();

        static::$framework_root = 'app/RSpade/temp/resource/' . $tag . '/framework';
        static::$application_root = 'rsx/resource/' . $tag . '/application';
        static::$build_root = Rsx_Project_Paths::tmp_path('manifest-overrides/' . getmypid() . '/build');
    }

    /**
     * The framework files, path => content. Each kind declares its name the way the manifest
     * reads it; the PHP class carries the namespace its path gives it, so the fixer has
     * nothing to rewrite.
     *
     * @return array<string,string>
     */
    private static function __framework_files(): array
    {
        $php = static::$framework_root . '/Mo_Probe_Service.php';

        return [
            $php => "<?php\n\nnamespace " . Rsx_Namespace::from_path($php) . ";\n\nclass Mo_Probe_Service\n{\n"
                . "    public static function label(): string\n    {\n        return 'framework';\n    }\n}\n",
            static::$framework_root . '/Mo_Probe_Widget.js' => "class Mo_Probe_Widget {\n    static label() {\n        return 'framework';\n    }\n}\n",
            static::$framework_root . '/Mo_Probe_Panel.jqhtml' => "<Define:Mo_Probe_Panel>\n    <div class=\"Mo_Probe_Panel__body\">framework</div>\n</Define:Mo_Probe_Panel>\n",
            static::$framework_root . '/Mo_Probe_Panel.scss' => ".Mo_Probe_Panel {\n    &__body {\n        color: #111;\n    }\n}\n",
        ];
    }

    /**
     * The application overrides - one of each kind, named after the application's own file
     * convention, never after the framework's files.
     *
     * @return array<string,string>
     */
    private static function __application_files(): array
    {
        $php = static::$application_root . '/mo_probe_service.php';

        return [
            $php => "<?php\n\nnamespace " . Rsx_Namespace::from_path($php) . ";\n\nclass Mo_Probe_Service\n{\n"
                . "    public static function label(): string\n    {\n        return 'application';\n    }\n}\n",
            static::$application_root . '/mo_probe_widget.js' => "class Mo_Probe_Widget {\n    static label() {\n        return 'application';\n    }\n}\n",
            static::$application_root . '/mo_probe_panel.jqhtml' => "<Define:Mo_Probe_Panel>\n    <div class=\"Mo_Probe_Panel__body\">application</div>\n</Define:Mo_Probe_Panel>\n",
            static::$application_root . '/mo_probe_panel.scss' => ".Mo_Probe_Panel {\n    &__body {\n        color: #222;\n    }\n}\n",
        ];
    }

    /** @param array<string,string> $files */
    private static function __write(array $files): void
    {
        foreach ($files as $path => $content) {
            ensure_directory(dirname(base_path($path)));
            file_put_contents(base_path($path), $content);
        }

        clearstatcache();
    }

    /** @param array<int,string> $paths */
    private static function __delete(array $paths): void
    {
        foreach ($paths as $path) {
            if (file_exists(base_path($path))) {
                unlink(base_path($path));
            }
        }

        clearstatcache();
    }

    private static function __remove_everything(): void
    {
        foreach ([dirname(static::$framework_root), dirname(static::$application_root)] as $directory) {
            if (is_dir(base_path($directory))) {
                rmdir_recursive(base_path($directory));
            }
        }

        if (is_dir(dirname(static::$build_root))) {
            rmdir_recursive(dirname(static::$build_root));
        }
    }

    /**
     * sha1 of every file under the framework fixture root, path => hash. Also the proof that
     * no file appeared there (an archive would be a new key).
     *
     * @return array<string,string>
     */
    private static function __framework_tree_hashes(): array
    {
        $hashes = [];
        $root = base_path(static::$framework_root);

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $hashes[substr($file->getPathname(), strlen($root) + 1)] = sha1_file($file->getPathname());
        }

        ksort($hashes);

        return $hashes;
    }

    // =====================================================================
    // The child build
    // =====================================================================

    /**
     * Build the fixture tree in a child, into the scratch root. Returns [exit code, output].
     *
     * @return array{0: int, 1: string}
     */
    private static function __build(): array
    {
        ensure_directory(static::$build_root);
        ensure_directory(static::$build_root . '-tmp');

        // A scan root must exist even while it holds nothing.
        ensure_directory(base_path(static::$framework_root));
        ensure_directory(base_path(static::$application_root));

        $output = [];

        $exit = Rsx_Artisan::run('rsx:manifest:build', [
            '--_rsx-build-root=' . static::$build_root,
            '--_rsx-tmp-root=' . static::$build_root . '-tmp',
            '--_manifest-extra-scan-roots=' . static::$framework_root . ',' . static::$application_root,
            '--_manifest-report-restarts',
        ], $output);

        return [$exit, implode("\n", $output)];
    }

    /** A build that must succeed, settled in one pass. Returns its output. */
    private static function __build_ok(string $what): string
    {
        [$exit, $output] = static::__build();

        static::__assert_equals(0, $exit, "{$what}: the fixture build failed:\n{$output}");
        static::__assert_contains('MANIFEST_RESTARTS=0', $output, "{$what}: the build settled in one pass, with no restart");

        return $output;
    }

    /**
     * The index the child wrote: the hot half, the cold half, and the override loader's map.
     *
     * @return array{hot: array, cold: array, loader: array, build_key: string}
     */
    private static function __index(): array
    {
        return [
            'hot' => include static::$build_root . '/manifest_index.php',
            'cold' => include static::$build_root . '/manifest_files.php',
            'loader' => include Rsx_Project_Paths::class_overrides_file_under(static::$build_root),
            'build_key' => trim((string) file_get_contents(static::$build_root . '/build_key')),
        ];
    }

    // =====================================================================
    // The lifecycle, through real child builds
    // =====================================================================

    /**
     * Add an override of every kind to a tree already built without them: each framework file
     * is shadowed in one pass, recorded in the map, out of the live index and still indexed -
     * and nothing in the framework tree is written. An unchanged rebuild is then a no-op with
     * the same key. Removing the overrides returns every framework file to the live tree,
     * reflected, again in one pass, again writing nothing.
     */
    public static function test_an_override_of_each_kind_is_shadowed_and_released_without_touching_the_framework_tree()
    {
        static::__roots();
        static::__remove_everything();

        try {
            static::__write(static::__framework_files());
            static::__build_ok('the framework tree alone');

            $framework_before = static::__framework_tree_hashes();
            $application = static::__application_files();

            // ---- ADD the three overrides, and a stylesheet beside the framework's ---------
            static::__write($application);
            static::__build_ok('adding the overrides');

            $index = static::__index();
            $overrides = $index['hot']['data']['overrides'];
            $framework_paths = array_keys(static::__framework_files());
            $application_paths = array_keys($application);
            $framework_stylesheet = $framework_paths[3];

            $expected_kinds = ['php', 'js', 'jqhtml'];
            foreach (array_slice($framework_paths, 0, 3) as $i => $framework_path) {
                static::__assert_true(isset($overrides[$framework_path]), "{$framework_path} is recorded as shadowed");
                static::__assert_equals($expected_kinds[$i], $overrides[$framework_path]['kind'], "{$framework_path} is recorded under its kind");
                static::__assert_equals($application_paths[$i], $overrides[$framework_path]['by'], "{$framework_path} is shadowed by the application file of its kind");
                static::__assert_false(isset($index['hot']['data']['files'][$framework_path]), "{$framework_path} is out of the live index");
                static::__assert_false(isset($index['hot']['data']['shadowed_files']), 'the shadowed records are never in the hot half');
                static::__assert_true(isset($index['cold'][$framework_path]), "{$framework_path}'s record is kept in the cold half");
                static::__assert_true(isset($index['hot']['data']['file_index'][$framework_path]), "{$framework_path} is still indexed for change detection");
            }

            static::__assert_equals(3, count($overrides), 'exactly the three overrides are recorded');
            static::__assert_false(isset($overrides[$framework_stylesheet]), 'the framework stylesheet is never shadowed');
            static::__assert_true(isset($index['hot']['data']['files'][$framework_stylesheet]) || isset($index['cold'][$framework_stylesheet]), 'it stays in the live index');
            static::__assert_true(isset($index['hot']['data']['files'][$application_paths[3]]) || isset($index['cold'][$application_paths[3]]), 'beside the application stylesheet');

            $php_framework = $framework_paths[0];
            $framework_fqcn = Rsx_Namespace::from_path($php_framework) . '\\Mo_Probe_Service';
            $application_fqcn = Rsx_Namespace::from_path($application_paths[0]) . '\\Mo_Probe_Service';

            static::__assert_equals($framework_fqcn, $overrides[$php_framework]['fqcn'], 'the php entry names the framework class');
            static::__assert_equals($application_fqcn, $overrides[$php_framework]['by_fqcn'], 'and the application class');
            static::__assert_equals(
                [$framework_fqcn => ['by_fqcn' => $application_fqcn, 'by_file' => $application_paths[0], 'file' => $php_framework]],
                $index['loader'],
                'the override loader is handed the php override, and only it'
            );
            static::__assert_equals($application_paths[0], $index['hot']['data']['php_classes']['Mo_Probe_Service']['file'], 'the class map resolves the name to the override');
            static::__assert_equals($application_paths[2], $index['hot']['data']['jqhtml']['components']['Mo_Probe_Panel']['file'], 'and the component registry to the override template');

            static::__assert_equals($framework_before, static::__framework_tree_hashes(), 'adding the overrides wrote nothing in the framework tree');

            // ---- UNCHANGED: a no-op ------------------------------------------------------
            $output = static::__build_ok('rebuilding an unchanged tree');
            static::__assert_contains('Rebuilt: no', $output, 'an unchanged tree with overrides does not rebuild');
            static::__assert_equals($index['build_key'], static::__index()['build_key'], 'and keeps its build key');

            // ---- REMOVE the overrides ----------------------------------------------------
            static::__delete($application_paths);
            static::__build_ok('removing the overrides');

            $index = static::__index();

            static::__assert_equals([], $index['hot']['data']['overrides'], 'the map is empty again');
            static::__assert_equals([], $index['loader'], 'and so is the override loader\'s');

            foreach ($framework_paths as $framework_path) {
                $record = $index['hot']['data']['files'][$framework_path] ?? $index['cold'][$framework_path] ?? null;
                static::__assert_true($record !== null, "{$framework_path} is back in the live index");
            }

            $php_record = $index['hot']['data']['files'][$php_framework] ?? $index['cold'][$php_framework];
            static::__assert_true(array_key_exists('abstract', $php_record), 'the framework class was reflected again on its way back');
            static::__assert_true(isset($php_record['public_static_methods']['label']), 'its method map is its own');
            static::__assert_equals($php_framework, $index['hot']['data']['php_classes']['Mo_Probe_Service']['file'], 'the class map resolves the name to the framework class');
            static::__assert_equals($framework_paths[2], $index['hot']['data']['jqhtml']['components']['Mo_Probe_Panel']['file'], 'and the component registry to the framework template');

            static::__assert_equals($framework_before, static::__framework_tree_hashes(), 'removing the overrides wrote nothing in the framework tree either');
        } finally {
            static::__remove_everything();
        }
    }

    /**
     * Two application templates of one component name are refused, and the refusal fails the
     * build - nothing can choose between them, and choosing the first silently is how an
     * application loses the template it edited.
     */
    public static function test_two_application_templates_of_one_name_fail_the_build()
    {
        static::__roots();
        static::__remove_everything();

        try {
            static::__write(static::__framework_files());
            static::__write([
                static::$application_root . '/mo_probe_panel.jqhtml' => "<Define:Mo_Probe_Panel>\n    <div>one</div>\n</Define:Mo_Probe_Panel>\n",
                static::$application_root . '/second/mo_probe_panel.jqhtml' => "<Define:Mo_Probe_Panel>\n    <div>two</div>\n</Define:Mo_Probe_Panel>\n",
            ]);

            [$exit, $output] = static::__build();

            static::__assert_true($exit !== 0, "the build fails:\n{$output}");
            static::__assert_contains("Duplicate jqhtml component 'Mo_Probe_Panel'", $output, 'naming the component');
            static::__assert_contains(static::$application_root . '/second/mo_probe_panel.jqhtml', $output, 'and both files');
        } finally {
            static::__remove_everything();
        }
    }

    // =====================================================================
    // The refusals, as decisions over a synthetic file list
    // =====================================================================

    /**
     * Derive over a synthetic file list with the real manifest state saved and restored.
     *
     * @param array<int,string> $changed
     */
    private static function __derive(array $files, array $changed = []): void
    {
        $saved_data = Manifest::$data;
        $saved_error_log = ini_get('error_log');

        Manifest::$data = ['data' => ['files' => $files]];
        ini_set('error_log', Rsx_Project_Paths::tmp_path('manifest_overrides_log_' . getmypid() . '.txt'));

        try {
            Manifest_Overrides::derive([], $changed);
        } finally {
            ini_set('error_log', $saved_error_log === false ? '' : $saved_error_log);
            Manifest::$data = $saved_data;
            @unlink(Rsx_Project_Paths::tmp_path('manifest_overrides_log_' . getmypid() . '.txt'));
        }
    }

    /** A PHP file record as the derivation reads it. */
    private static function __php(string $path, string $fqcn, ?string $extends = null): array
    {
        $position = strrpos($fqcn, '\\');
        $record = [
            'extension' => 'php',
            'class' => substr($fqcn, $position + 1),
            'namespace' => substr($fqcn, 0, $position),
            'fqcn' => $fqcn,
            'hash' => is_file(base_path($path)) ? sha1_file(base_path($path)) : sha1($path),
        ];

        if ($extends !== null) {
            $record['extends'] = $extends;
        }

        return [$path => $record];
    }

    /**
     * An application file named like a framework class that boots the framework is refused:
     * that class is declared before any override could answer for its name.
     */
    public static function test_an_override_of_a_boot_class_is_refused()
    {
        static::__roots();
        $application = static::$application_root . '/rsx.php';

        try {
            static::__write([$application => "<?php\n"]);

            $error = static::__assert_throws(
                \RuntimeException::class,
                static fn () => static::__derive(array_merge(
                    static::__php('app/RSpade/Core/Rsx.php', 'App\\RSpade\\Core\\Rsx'),
                    static::__php($application, 'Rsx\\Resource\\Probe\\Rsx')
                )),
                "'Rsx' cannot be overridden"
            );

            static::__assert_contains('while the framework boots', $error->getMessage(), 'and it says why');
            static::__assert_contains($application, $error->getMessage(), 'naming the application file');
        } finally {
            static::__remove_everything();
        }
    }

    /**
     * An application class named like a class in a framework subtree the manifest never
     * indexes is refused: it can be neither an override nor a second class of that name.
     */
    public static function test_a_namesake_of_an_unindexed_framework_class_is_refused()
    {
        static::__roots();
        $application = static::$application_root . '/manifest_build_command.php';

        $error = static::__assert_throws(
            \RuntimeException::class,
            static fn () => static::__derive(static::__php($application, 'Rsx\\Resource\\Probe\\Manifest_Build_Command')),
            "'Manifest_Build_Command' cannot be overridden"
        );

        static::__assert_contains('App\\RSpade\\Commands\\Rsx\\Manifest_Build_Command', $error->getMessage(), 'naming the framework class');
        static::__assert_contains('does not index', $error->getMessage(), 'and why it cannot be shadowed');
    }

    /**
     * An application class named like a framework class in a directory discovery passes over
     * INSIDE a scanned framework root (a resource/ directory, here under the scratch tree the
     * test run scans) is refused the same way: that class is never indexed, so it can never be
     * shadowed either.
     */
    public static function test_a_namesake_of_a_class_in_an_excluded_framework_directory_is_refused()
    {
        static::__roots();
        $framework = dirname(static::$framework_root) . '/excluded/Mo_Excluded_Probe.php';
        $application = static::$application_root . '/mo_excluded_probe.php';
        $framework_fqcn = 'App\\RSpade\\Temp\\Resource\\Mo_Excluded\\Mo_Excluded_Probe';

        static::__assert_true(
            in_array('app/RSpade/temp', Manifest::scan_directories(), true),
            'the test run scans the scratch tree, so its resource/ directory is an excluded directory of a scanned framework root'
        );

        try {
            static::__write([
                $framework => "<?php\n\nnamespace App\\RSpade\\Temp\\Resource\\Mo_Excluded;\n\nclass Mo_Excluded_Probe\n{\n}\n",
                $application => "<?php\n",
            ]);

            $error = static::__assert_throws(
                \RuntimeException::class,
                static fn () => static::__derive(static::__php($application, 'Rsx\\Resource\\Probe\\Mo_Excluded_Probe')),
                "'Mo_Excluded_Probe' cannot be overridden"
            );

            static::__assert_contains($framework_fqcn, $error->getMessage(), 'naming the framework class');
            static::__assert_contains('does not index', $error->getMessage(), 'and why it cannot be shadowed');

            // The same file INDEXED by the build (a nested scan root) is no longer unindexed:
            // the pair is then an ordinary override, and the framework file is shadowed.
            static::__derive(array_merge(
                static::__php($framework, $framework_fqcn),
                static::__php($application, 'Rsx\\Resource\\Probe\\Mo_Excluded_Probe')
            ));
            static::__pass('an indexed file in an excluded directory is overridden, not refused');
        } finally {
            static::__remove_everything();
        }
    }

    /**
     * A framework class this process has already declared as itself cannot become an alias of
     * an override in this process, so the build refuses it. (This test class is such a class:
     * it is loaded, and it is not on the boot list.)
     */
    public static function test_an_override_of_a_class_already_declared_in_the_building_process_is_refused()
    {
        static::__roots();
        $application = static::$application_root . '/manifest_overrides_test.php';

        try {
            static::__write([$application => "<?php\n"]);

            $error = static::__assert_throws(
                \RuntimeException::class,
                static fn () => static::__derive(array_merge(
                    static::__php('app/RSpade/tests/class_override/php/Manifest_Overrides_Test.php', self::class),
                    static::__php($application, 'Rsx\\Resource\\Probe\\Manifest_Overrides_Test')
                )),
                "'Manifest_Overrides_Test' cannot be overridden"
            );

            static::__assert_contains('already declared in this process', $error->getMessage(), 'and it says why');
        } finally {
            static::__remove_everything();
        }
    }

    /**
     * A split base that names its own shell in a method signature is refused: an override that
     * redeclares that method could never link, because PHP compares the signatures while the
     * override is still being declared.
     */
    public static function test_a_split_base_naming_its_own_shell_in_a_signature_is_refused()
    {
        static::__roots();
        $base = static::$framework_root . '/Mo_Split_Probe_Abstract.php';
        $shell = static::$framework_root . '/Mo_Split_Probe.php';
        $namespace = Rsx_Namespace::from_path($base);

        try {
            static::__write([
                $base => "<?php\n\nnamespace {$namespace};\n\nabstract class Mo_Split_Probe_Abstract\n{\n"
                    . "    public function untouched(int \$count, ?Mo_Split_Probe_Abstract \$other = null): static\n    {\n        return \$this;\n    }\n\n"
                    . "    // A comment naming Mo_Split_Probe is not a signature.\n"
                    . "    public static function make(#[\\SensitiveParameter] string \$label): Mo_Split_Probe\n    {\n        return new Mo_Split_Probe();\n    }\n}\n",
                $shell => "<?php\n\nnamespace {$namespace};\n\nclass Mo_Split_Probe extends Mo_Split_Probe_Abstract\n{\n}\n",
            ]);

            $files = static fn () => array_merge(
                static::__php($base, $namespace . '\\Mo_Split_Probe_Abstract'),
                static::__php($shell, $namespace . '\\Mo_Split_Probe', 'Mo_Split_Probe_Abstract')
            );

            $error = static::__assert_throws(
                \RuntimeException::class,
                static fn () => static::__derive($files(), [$base]),
                'Mo_Split_Probe_Abstract::make() names its own overridable class Mo_Split_Probe'
            );

            static::__assert_contains($base . ':13', $error->getMessage(), 'naming the line of the return type');

            // The same base spelling the type as the base, or `static`, is accepted.
            file_put_contents(base_path($base), str_replace('): Mo_Split_Probe' . "\n", '): Mo_Split_Probe_Abstract' . "\n", file_get_contents(base_path($base))));
            clearstatcache();

            static::__derive($files(), [$base]);
            static::__pass('a base that names itself is accepted');
        } finally {
            static::__remove_everything();
        }
    }
}
