<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Scripting\Cli;

use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

// No @ARTISAN-SPAWN-01-EXCEPTION is needed here. What these tests spawn is `php <script>` -
// a plain PHP script including system/script.php - which is not an artisan command line, so
// the Rsx_Artisan mandate does not reach it. The two artisan invocations below (maintenance
// enable/disable) go through the same helper Maintenance_Gate_Cli_Test uses, and are the
// pre-boot intercepted pair, which Rsx_Artisan could not carry either.

/**
 * system/script.php - the single include that boots the RSpade shell for a PHP script living
 * outside the application.
 *
 * Every case runs a THROWAWAY script in a subprocess, from a working directory outside the
 * project, and asserts on the JSON it prints. That is the only way to observe this entry
 * point: the thing under test is a whole process's boot, and the pre-boot half of it happens
 * before any class exists.
 *
 * The maintenance case raises the REAL flag - there is no other way to test the real gate -
 * always with --no-services (no supervisord unit is touched), always through try/finally, and
 * teardown() clears it unconditionally so an aborted run cannot leave the box in 503.
 */
class Script_Boot_Cli_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** The refusal the script gate emits, written by raw fwrite before anything is autoloaded. */
    const REFUSAL_MARKER = '503 - System is in maintenance mode';
    const REFUSAL_HINT = 'Exit maintenance mode: php artisan rsx:maintenance:disable';
    const REFUSAL_FORCE_HINT = "Set \$RSX_SCRIPT_OPTIONS['force'] = true before the include to run anyway.";
    const REFUSAL_EXIT_CODE = 75;

    const REASON = 'framework test window';

    /** The scratch directory the throwaway scripts are written into. */
    protected static function __scratch_dir(): string
    {
        $dir = Rsx_Project_Paths::tmp_path('scripting-test');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }

    /**
     * The body every probe script shares: boot through the entry point, then print one JSON
     * object describing what the process ended up with.
     */
    protected static function __probe_body(): string
    {
        $entry = base_path('script.php');

        return '<?php' . "\n"
            . '$app = require ' . var_export($entry, true) . ';' . "\n"
            . 'echo json_encode(['
            . "'app' => get_class(\$app),"
            . "'manifest' => (string) \\App\\RSpade\\Core\\Manifest\\Manifest::php_find_class('User_Model'),"
            . "'site_id' => \\App\\RSpade\\Core\\Session\\Session::get_site_id(),"
            . "'script_mode' => \\App\\RSpade\\Core\\Console\\Rsx_Script::is_active(),"
            . "'cwd' => getcwd(),"
            . ']), "\n";' . "\n";
    }

    /** Write $source to <scratch>/$name and return its absolute path. */
    protected static function __write_script(string $name, string $source): string
    {
        $path = static::__scratch_dir() . '/' . $name;
        file_put_contents($path, $source);

        return $path;
    }

    /**
     * Run a script from /tmp - a working directory OUTSIDE the project, which is the whole
     * point of this entry point - and return [rc, output].
     */
    protected static function __run(string $script, string $args = ''): array
    {
        $out = [];
        $rc = 0;
        exec_safe(
            'cd /tmp && php ' . escapeshellarg($script) . ($args === '' ? '' : ' ' . $args) . ' 2>&1',
            $out,
            $rc
        );

        return [$rc, implode("\n", $out)];
    }

    /** The last line of a probe run, decoded. */
    protected static function __decode(string $output): array
    {
        $lines = array_values(array_filter(preg_split('/\R/', trim($output)), static fn ($l) => trim($l) !== ''));
        $json = json_decode((string) end($lines), true);

        static::__assert_true(is_array($json), 'the probe must print one JSON object: ' . $output);

        return $json;
    }

    /** Everything the boot is supposed to have produced. */
    protected static function __assert_full_boot(array $json, string $context): void
    {
        static::__assert_equals(
            get_class(app()),
            $json['app'] ?? null,
            "{$context}: the include returns the application container"
        );
        static::__assert_true(
            !empty($json['manifest']),
            "{$context}: the manifest must be loaded (User_Model resolved to a file)"
        );
        static::__assert_equals(
            Session::get_site_id(),
            $json['site_id'] ?? null,
            "{$context}: the script gets the same site Main::init() declares for this process"
        );
        static::__assert_true(
            $json['site_id'] > 0,
            "{$context}: the declared site must be a real tenant, not 0"
        );
        static::__assert_true(
            $json['script_mode'] === true,
            "{$context}: Rsx_Script::is_active() must be true inside a script"
        );
        static::__assert_equals(
            base_path(),
            $json['cwd'] ?? null,
            "{$context}: the working directory is left at the framework tree"
        );
    }

    /** SCRIPT-01: the plain include boots the whole shell from outside the project. */
    public static function test_a_bare_script_boots_the_whole_shell()
    {
        $script = static::__write_script('probe_bare.php', static::__probe_body());

        [$rc, $output] = static::__run($script);

        static::__assert_equals(0, $rc, 'the probe must exit 0: ' . $output);
        static::__assert_full_boot(static::__decode($output), 'a bare script');
    }

    /**
     * SCRIPT-02: THE BUG THIS ENTRY POINT EXISTS FOR. `help` and `list` are artisan
     * introspection commands that skip the manifest boot entirely. As a SCRIPT's first
     * argument they must mean nothing at all - the same full boot, byte for byte.
     */
    public static function test_the_scripts_own_first_argument_is_never_a_command_name()
    {
        $script = static::__write_script('probe_args.php', static::__probe_body());

        [$bare_rc, $bare] = static::__run($script);
        static::__assert_equals(0, $bare_rc, 'the no-argument run must exit 0: ' . $bare);
        $baseline = static::__decode($bare);

        foreach (['help', 'list', 'rsx:manifest:build', '--version'] as $argument) {
            [$rc, $output] = static::__run($script, escapeshellarg($argument));

            static::__assert_equals(0, $rc, "argument '{$argument}' must exit 0: " . $output);
            $json = static::__decode($output);
            static::__assert_full_boot($json, "argument '{$argument}'");
            static::__assert_equals(
                $baseline,
                $json,
                "argument '{$argument}' must change nothing about the boot"
            );
        }
    }

    /**
     * SCRIPT-03: the script's own PSR-4 autoloader, registered AFTER the include, resolves a
     * class the manifest has never seen. The chain works because the RSpade autoloader
     * returns false for a class it does not know.
     */
    public static function test_the_scripts_own_autoloader_resolves_a_class_the_manifest_never_saw()
    {
        $dir = static::__scratch_dir();
        $src = $dir . '/src';
        if (!is_dir($src)) {
            mkdir($src, 0777, true);
        }

        file_put_contents(
            $src . '/Widget.php',
            '<?php namespace Rsxtest_Script_Tool; class Widget { public static function name(): string { return "widget-ok"; } }' . "\n"
        );

        $entry = base_path('script.php');
        $source = '<?php' . "\n"
            . '$app = require ' . var_export($entry, true) . ';' . "\n"
            . 'spl_autoload_register(function (string $class): void {' . "\n"
            . '    $prefix = "Rsxtest_Script_Tool\\\\";' . "\n"
            . '    if (!str_starts_with($class, $prefix)) { return; }' . "\n"
            . '    $path = ' . var_export($src, true) . ' . "/" . str_replace("\\\\", "/", substr($class, strlen($prefix))) . ".php";' . "\n"
            . '    if (is_file($path)) { require $path; }' . "\n"
            . '});' . "\n"
            . 'echo json_encode(['
            . "'own' => \\Rsxtest_Script_Tool\\Widget::name(),"
            . "'framework' => class_exists('App\\\\RSpade\\\\Core\\\\Console\\\\Rsx_Script'),"
            . "'unknown' => class_exists('Rsxtest_Script_Tool\\\\Nonexistent'),"
            . ']), "\n";' . "\n";

        $script = static::__write_script('probe_autoload.php', $source);

        [$rc, $output] = static::__run($script);

        static::__assert_equals(0, $rc, 'the probe must exit 0: ' . $output);
        $json = static::__decode($output);

        static::__assert_equals('widget-ok', $json['own'] ?? null, "the script's own class must resolve");
        static::__assert_true($json['framework'] === true, 'framework classes must still resolve');
        static::__assert_true(
            $json['unknown'] === false,
            'an unknown class must answer false, not throw - both loaders decline'
        );
    }

    /**
     * SCRIPT-04: maintenance mode refuses the whole process, and $RSX_SCRIPT_OPTIONS['force']
     * is the script's own way to insist.
     */
    public static function test_maintenance_mode_refuses_a_script_unless_it_forces()
    {
        $plain = static::__write_script('probe_maint.php', static::__probe_body());

        $forced = static::__write_script(
            'probe_maint_forced.php',
            '<?php' . "\n"
            . '$RSX_SCRIPT_OPTIONS = ["force" => true];' . "\n"
            . substr(static::__probe_body(), strlen('<?php') + 1)
        );

        try {
            [$enable_rc, $enable_output] = static::__artisan(
                'rsx:maintenance:enable --no-services --reason=' . escapeshellarg(self::REASON)
            );
            static::__assert_equals(0, $enable_rc, 'enable must succeed: ' . $enable_output);

            [$rc, $output] = static::__run($plain);
            static::__assert_contains(self::REFUSAL_MARKER, $output, 'the gate must refuse a script');
            static::__assert_contains(self::REASON, $output, 'the refusal must name the reason');
            static::__assert_contains('external scripts are refused', $output, 'the refusal must say what it refused');
            static::__assert_contains(self::REFUSAL_FORCE_HINT, $output, 'the refusal must name the override');
            static::__assert_contains(self::REFUSAL_HINT, $output, 'the refusal must name the exit command');
            static::__assert_equals(self::REFUSAL_EXIT_CODE, $rc, 'the refusal exits ' . self::REFUSAL_EXIT_CODE);

            [$forced_rc, $forced_output] = static::__run($forced);
            static::__assert_true(
                !str_contains($forced_output, self::REFUSAL_MARKER),
                'a forced script must not be refused: ' . $forced_output
            );
            static::__assert_equals(0, $forced_rc, 'a forced script boots normally: ' . $forced_output);
            static::__assert_full_boot(static::__decode($forced_output), 'a forced script');
        } finally {
            static::__artisan('rsx:maintenance:disable --no-services');
        }
    }

    /** php <artisan> <args>, returning [rc, output]. */
    protected static function __artisan(string $args): array
    {
        $out = [];
        $rc = 0;
        exec_safe('php ' . escapeshellarg(base_path('artisan')) . ' ' . $args . ' 2>&1', $out, $rc);

        return [$rc, implode("\n", $out)];
    }

    public static function teardown()
    {
        // Belt: whatever happened above, the box must not stay in maintenance mode.
        Framework_Maintenance::clear();

        $dir = Rsx_Project_Paths::tmp_path('scripting-test');
        if (is_dir($dir)) {
            foreach (['probe_bare.php', 'probe_args.php', 'probe_autoload.php', 'probe_maint.php', 'probe_maint_forced.php'] as $file) {
                @unlink($dir . '/' . $file);
            }
            @unlink($dir . '/src/Widget.php');
            @rmdir($dir . '/src');
            @rmdir($dir);
        }
    }
}
