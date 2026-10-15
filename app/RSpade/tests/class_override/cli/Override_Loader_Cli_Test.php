<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ClassOverride\Cli;

use App\RSpade\Core\Overrides\Class_Override_Loader;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

// No @ARTISAN-SPAWN-01-EXCEPTION is needed: what these tests spawn is `php <script>`, a plain
// PHP script that requires the framework's vendor/autoload.php, not an artisan command line.

/**
 * The class override loader as a WHOLE PROCESS sees it: a fresh child that requires the real
 * system/vendor/autoload.php - whose composer `files` entry registers the override loader - and
 * nothing else. That is the only way to observe the composer half of the mechanism, because the
 * committed classmap maps every framework class to its framework file and the test process's
 * own loader was configured long before the test ran.
 *
 * The map comes from a SCRATCH build root (the internal --_rsx-build-root flag, set in the
 * child's flag global before composer loads), and the overrides are probe classes under tmp/,
 * so neither the real build tree nor the framework tree is touched. The framework classes they
 * override are real ones composer maps: App\RSpade\Core\Qr\Rsx_Qr_Code (a copy-and-replace
 * override) and App\RSpade\Core\Models\Region_Model (a split model, overridden by extending
 * Region_Model_Abstract).
 */
class Override_Loader_Cli_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    const QR = 'App\\RSpade\\Core\\Qr\\Rsx_Qr_Code';
    const REGION = 'App\\RSpade\\Core\\Models\\Region_Model';
    const PROBE_NAMESPACE = 'Rsx\\Override_Loader_Cli_Probe';

    /** Write the probe overrides and a build root whose map names them. Returns the scratch dir. */
    private static function __stage(): string
    {
        $dir = Rsx_Project_Paths::tmp_path('class-override-cli-test/' . getmypid() . '_' . bin2hex(random_bytes(4)));
        mkdir($dir . '/probes', 0777, true);

        file_put_contents($dir . '/probes/rsx_qr_code.php', "<?php\nnamespace " . self::PROBE_NAMESPACE . ";\n\n"
            . "class Rsx_Qr_Code\n{\n    const PROBE = 'override';\n}\n");
        file_put_contents($dir . '/probes/region_model.php', "<?php\nnamespace " . self::PROBE_NAMESPACE . ";\n\n"
            . "class Region_Model extends \\App\\RSpade\\Core\\Models\\Region_Model_Abstract\n{\n    const PROBE = 'override';\n}\n");

        $map = [
            self::QR => [
                'by_fqcn' => self::PROBE_NAMESPACE . '\\Rsx_Qr_Code',
                'by_file' => $dir . '/probes/rsx_qr_code.php',
                'file' => 'app/RSpade/Core/Qr/Rsx_Qr_Code.php',
            ],
            self::REGION => [
                'by_fqcn' => self::PROBE_NAMESPACE . '\\Region_Model',
                'by_file' => $dir . '/probes/region_model.php',
                'file' => 'app/RSpade/Core/Models/Region_Model.php',
            ],
        ];

        Class_Override_Loader::write_artifact(Rsx_Project_Paths::class_overrides_file_under($dir . '/build'), $map);

        return $dir;
    }

    /**
     * Write a child script: point the build root at the scratch dir, require the framework's
     * composer autoloader, run $body, print one JSON object.
     */
    private static function __script(string $dir, string $name, string $body): string
    {
        $path = $dir . '/' . $name . '.php';
        file_put_contents($path, '<?php' . "\n"
            . '$GLOBALS[\'__rsx_internal_flags\'] = [' . var_export('--_rsx-build-root=' . $dir . '/build', true) . '];' . "\n"
            . 'require ' . var_export(base_path('vendor/autoload.php'), true) . ';' . "\n"
            . '$composer = null;' . "\n"
            . 'foreach (\\Composer\\Autoload\\ClassLoader::getRegisteredLoaders() as $vendor_dir => $loader) {' . "\n"
            . '    if (realpath($vendor_dir) === ' . var_export(realpath(base_path('vendor')), true) . ') { $composer = $loader; }' . "\n"
            . '}' . "\n"
            . '$shell_included = function (string $relative): bool {' . "\n"
            . '    return in_array(realpath(' . var_export(base_path(), true) . ' . "/" . $relative), array_map("realpath", get_included_files()), true);' . "\n"
            . '};' . "\n"
            . $body . "\n");

        return $path;
    }

    /** Run a child from outside the project and return [rc, output]. */
    private static function __run(string $script): array
    {
        $out = [];
        $rc = 0;
        exec_safe('cd /tmp && php ' . escapeshellarg($script) . ' 2>&1', $out, $rc);

        return [$rc, implode("\n", $out)];
    }

    private static function __json(string $output): array
    {
        $lines = array_values(array_filter(preg_split('/\R/', trim($output)), static fn ($l) => trim($l) !== ''));
        $json = json_decode((string) end($lines), true);

        static::__assert_true(is_array($json), 'the child must print one JSON object: ' . $output);

        return $json;
    }

    private static function __rmtree(string $dir): void
    {
        if (is_dir($dir)) {
            rmdir_recursive($dir);
        }
    }

    /** The resolution body both classmap modes share. */
    private static function __resolution_body(bool $authoritative): string
    {
        return ($authoritative ? '$composer->setClassMapAuthoritative(true);' . "\n" : '')
            . 'echo json_encode([' . "\n"
            . '    "authoritative" => $composer->isClassMapAuthoritative(),' . "\n"
            . '    "first_loader" => is_array(spl_autoload_functions()[0]) ? implode("::", spl_autoload_functions()[0]) : "closure",' . "\n"
            . '    "composer_entry_is_tripwire" => $composer->findFile(' . var_export(self::QR, true) . ') === \\App\\RSpade\\Core\\Overrides\\Class_Override_Loader::tripwire_file(),' . "\n"
            . '    "qr" => (new ReflectionClass(' . var_export(self::QR, true) . '))->getName(),' . "\n"
            . '    "qr_probe" => constant(' . var_export(self::QR . '::PROBE', true) . '),' . "\n"
            . '    "region" => (new ReflectionClass(' . var_export(self::REGION, true) . '))->getName(),' . "\n"
            . '    "region_is_model" => is_subclass_of(' . var_export(self::REGION, true) . ', "App\\\\RSpade\\\\Core\\\\Models\\\\Region_Model_Abstract"),' . "\n"
            . '    "qr_shell_included" => $shell_included("app/RSpade/Core/Qr/Rsx_Qr_Code.php"),' . "\n"
            . '    "region_shell_included" => $shell_included("app/RSpade/Core/Models/Region_Model.php"),' . "\n"
            . ']) . "\n";';
    }

    private static function __assert_resolved_to_probes(array $json, string $context): void
    {
        static::__assert_equals(Class_Override_Loader::class . '::load', $json['first_loader'], "{$context}: the override loader is first on the stack");
        static::__assert_true($json['composer_entry_is_tripwire'], "{$context}: composer's entry for an overridden name is the trip-wire");
        static::__assert_equals(self::PROBE_NAMESPACE . '\\Rsx_Qr_Code', $json['qr'], "{$context}: the framework FQCN resolves to the copy-and-replace probe");
        static::__assert_equals('override', $json['qr_probe'], "{$context}: the probe's constant is read through the framework name");
        static::__assert_equals(self::PROBE_NAMESPACE . '\\Region_Model', $json['region'], "{$context}: the split model's framework FQCN resolves to the probe");
        static::__assert_true($json['region_is_model'], "{$context}: the probe extends the framework base");
        static::__assert_false($json['qr_shell_included'], "{$context}: the shadowed framework file was never included");
        static::__assert_false($json['region_shell_included'], "{$context}: the shadowed framework shell was never included");
    }

    // -------------------------------------------------------------------------

    /**
     * The committed, optimised classmap maps both framework classes to their framework files -
     * composer would load them if it were asked. The override loader answers first.
     */
    public static function test_an_optimised_classmap_resolves_the_framework_name_to_the_override()
    {
        $dir = static::__stage();

        try {
            [$rc, $output] = static::__run(static::__script($dir, 'optimised', static::__resolution_body(false)));
            static::__assert_equals(0, $rc, 'the child must succeed: ' . $output);

            $json = static::__json($output);
            static::__assert_false($json['authoritative'], 'the committed loader is not authoritative');
            static::__assert_resolved_to_probes($json, 'optimised classmap');
        } finally {
            static::__rmtree($dir);
        }
    }

    /** A sealed production build dumps an AUTHORITATIVE classmap; the override still wins. */
    public static function test_an_authoritative_classmap_resolves_the_framework_name_to_the_override()
    {
        $dir = static::__stage();

        try {
            [$rc, $output] = static::__run(static::__script($dir, 'authoritative', static::__resolution_body(true)));
            static::__assert_equals(0, $rc, 'the child must succeed: ' . $output);

            $json = static::__json($output);
            static::__assert_true($json['authoritative'], 'the loader was made authoritative');
            static::__assert_resolved_to_probes($json, 'authoritative classmap');
        } finally {
            static::__rmtree($dir);
        }
    }

    /** With the override loader out of the way, composer answers with the trip-wire: loud, never the framework class. */
    public static function test_composer_asked_first_hits_the_tripwire()
    {
        $dir = static::__stage();

        try {
            $body = 'spl_autoload_unregister([\\App\\RSpade\\Core\\Overrides\\Class_Override_Loader::class, "load"]);' . "\n"
                . 'try { class_exists(' . var_export(self::QR, true) . '); $message = "NO THROW"; } catch (\\LogicException $e) { $message = $e->getMessage(); }' . "\n"
                . 'echo json_encode(["message" => $message, "declared" => class_exists(' . var_export(self::QR, true) . ', false), "shell" => $shell_included("app/RSpade/Core/Qr/Rsx_Qr_Code.php")]) . "\n";';

            [$rc, $output] = static::__run(static::__script($dir, 'tripwire', $body));
            static::__assert_equals(0, $rc, 'the child must succeed: ' . $output);

            $json = static::__json($output);
            static::__assert_contains(self::QR . ' is overridden by ' . self::PROBE_NAMESPACE . '\\Rsx_Qr_Code', $json['message'], 'the trip-wire names both classes');
            static::__assert_false($json['declared'], 'nothing was declared under the framework name');
            static::__assert_false($json['shell'], 'composer never included the framework file');
        } finally {
            static::__rmtree($dir);
        }
    }

    /** No map file (a fresh checkout with no build yet) is an empty map: the framework class loads as itself. */
    public static function test_a_missing_map_is_an_empty_map()
    {
        $dir = Rsx_Project_Paths::tmp_path('class-override-cli-test/' . getmypid() . '_empty_' . bin2hex(random_bytes(4)));
        mkdir($dir, 0777, true);

        try {
            $body = 'echo json_encode(["map" => \\App\\RSpade\\Core\\Overrides\\Class_Override_Loader::map(), "qr" => (new ReflectionClass(' . var_export(self::QR, true) . '))->getName()]) . "\n";';

            [$rc, $output] = static::__run(static::__script($dir, 'empty', $body));
            static::__assert_equals(0, $rc, 'the child must succeed: ' . $output);

            $json = static::__json($output);
            static::__assert_equals([], $json['map'], 'no map file, no overrides');
            static::__assert_equals(self::QR, $json['qr'], 'the framework class loads as itself');
        } finally {
            static::__rmtree($dir);
        }
    }

    /**
     * A framework base whose signature names its own overridable class, redeclared by the
     * override with its own name: PHP must compare the two types while the override is not yet a
     * class, so no alias can exist and the process dies.
     *
     * Loaded the way the RSX autoloader loads it - by the override's OWN name - declaring it asks
     * for the framework name, the override loader is reached, and its message names the shape and
     * the fix. Asked for by the FRAMEWORK name, PHP does not re-enter an autoload already in
     * progress for that name, so PHP's own "is not available" is what reports it.
     */
    public static function test_a_self_referential_signature_fails_loud()
    {
        $fixture_namespace = 'App\\RSpade\\Tests\\Override_Loader_Cli_Fixture';
        $dir = static::__stage();

        try {
            file_put_contents($dir . '/probes/Probe_Model_Abstract.php', "<?php\nnamespace {$fixture_namespace};\n\n"
                . "abstract class Probe_Model_Abstract\n{\n    public static function make(): Probe_Model { throw new \\LogicException('never called'); }\n}\n");
            file_put_contents($dir . '/probes/probe_model.php', "<?php\nnamespace " . self::PROBE_NAMESPACE . ";\n\n"
                . "class Probe_Model extends \\{$fixture_namespace}\\Probe_Model_Abstract\n{\n    public static function make(): Probe_Model { throw new \\LogicException('never called'); }\n}\n");

            static::__stage_map_entry($dir, $fixture_namespace . '\\Probe_Model', self::PROBE_NAMESPACE . '\\Probe_Model', $dir . '/probes/probe_model.php', $dir . '/framework/Probe_Model.php');

            $require_base = 'require ' . var_export($dir . '/probes/Probe_Model_Abstract.php', true) . ';' . "\n";

            // By the override's own name, through the include seam the RSX autoloader uses.
            $by_own_name = $require_base
                . '\\App\\RSpade\\Core\\Autoloader::include_declaration(' . var_export($dir . '/probes/probe_model.php', true) . ', ' . var_export(self::PROBE_NAMESPACE . '\\Probe_Model', true) . ');' . "\n"
                . 'echo "REACHED\n";';

            [$rc, $output] = static::__run(static::__script($dir, 'selfref_own_name', $by_own_name));

            static::__assert_not_equals(0, $rc, 'the process cannot continue: ' . $output);
            static::__assert_false(str_contains($output, 'REACHED'), 'nothing after the declaration ran');
            static::__assert_contains('cannot be resolved while ' . self::PROBE_NAMESPACE . '\\Probe_Model is still being declared', $output, 'the failure names the shape');
            static::__assert_contains('`_Abstract` base', $output, 'the failure says what a framework base names instead');

            // By the framework name.
            $by_framework_name = $require_base
                . 'class_exists(' . var_export($fixture_namespace . '\\Probe_Model', true) . ');' . "\n"
                . 'echo "REACHED\n";';

            [$rc, $output] = static::__run(static::__script($dir, 'selfref_framework_name', $by_framework_name));

            static::__assert_not_equals(0, $rc, 'the process cannot continue: ' . $output);
            static::__assert_false(str_contains($output, 'REACHED'), 'nothing after the declaration ran');
            static::__assert_contains('class ' . $fixture_namespace . '\\Probe_Model is not available', $output, 'PHP names the framework class it could not compare against');
        } finally {
            static::__rmtree($dir);
        }
    }

    /** Add one entry to the staged map. */
    private static function __stage_map_entry(string $dir, string $framework_fqcn, string $by_fqcn, string $by_file, string $file): void
    {
        $path = Rsx_Project_Paths::class_overrides_file_under($dir . '/build');
        $map = Class_Override_Loader::read_artifact($path);
        $map[$framework_fqcn] = ['by_fqcn' => $by_fqcn, 'by_file' => $by_file, 'file' => $file];
        Class_Override_Loader::write_artifact($path, $map);
    }
}
