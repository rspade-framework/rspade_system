<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Docker\Php;

use RuntimeException;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Operations\Health\Rsx_Image_Contract;

/**
 * The minimum image version: the checkout's min_image_version
 * (resource/docker/image_contract.conf) against the image_version an image records
 * (/opt/rspade/image.conf). Driven against scratch files through the PHP class's seams, the
 * pre-boot gate's path parameters and the bash library's path arguments, so the readers are
 * fed the same fixtures and must print the same sentence.
 *
 * The framework-developer skip is driven through IS_FRAMEWORK_DEVELOPER in a child's
 * environment (which beats .env) for bash and the gate, and through the class's seam in
 * process.
 */
class Image_Contract_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const LIB = 'bin/lib/rsx_image_contract.sh';
    private const BOOTSTRAP = 'bootstrap/rsx_image_contract.php';
    private const CONF = 'app/RSpade/resource/docker/image_contract.conf';

    private static string $dir = '';

    public static function setup()
    {
        static::$dir = sys_get_temp_dir() . '/image_contract_test_' . getmypid();
        @mkdir(static::$dir, 0700, true);

        Rsx_Image_Contract::$requirement_path_for_tests = static::$dir . '/requirement.conf';
        Rsx_Image_Contract::$record_path_for_tests = static::$dir . '/image.conf';
        Rsx_Image_Contract::$marker_path_for_tests = static::$dir . '/marker';
        Rsx_Image_Contract::$framework_developer_for_tests = false;
    }

    public static function teardown()
    {
        Rsx_Image_Contract::$requirement_path_for_tests = null;
        Rsx_Image_Contract::$record_path_for_tests = null;
        Rsx_Image_Contract::$marker_path_for_tests = null;
        Rsx_Image_Contract::$framework_developer_for_tests = null;
        rmdir_recursive(static::$dir);
    }

    /**
     * The scratch files every test starts from (setup() runs once per class): a requirement of
     * 2026.11.19, a marker naming prod, an env file saying false, no record, and the class
     * seam reading "not the framework authors' box".
     */
    private static function __fixture(): void
    {
        static::__write('requirement.conf', "# header\nmin_image_version=2026.11.19\n");
        static::__write('marker', "# This is an RSpade application container.\ntarget=prod\n");
        static::__write('env', "IS_FRAMEWORK_DEVELOPER=false\n");
        @unlink(static::$dir . '/image.conf');
        Rsx_Image_Contract::$framework_developer_for_tests = false;
    }

    private static function __write(string $name, string $content): void
    {
        file_put_contents(static::$dir . '/' . $name, $content);
    }

    /** A record carrying $version (null: an image_version line is absent). */
    private static function __record(?string $version): void
    {
        static::__write('image.conf', "min_image_version=2026.01.01\nbuilt_from=0123456789ab\n"
            . ($version === null ? '' : "image_version={$version}\n"));
    }

    /**
     * The bash library's answer for the current scratch files: [stdout, stderr, return code].
     * $developer is the IS_FRAMEWORK_DEVELOPER value in the child's environment ('' = unset,
     * so the scratch env file decides).
     */
    private static function __bash_problem(string $developer = 'false'): array
    {
        $output = [];
        $rc = 0;
        exec_safe(
            'bash -c ' . escapeshellarg(
                '. ' . escapeshellarg(base_path(self::LIB)) . '; rsx_image_contract_problem "$@" 2>'
                    . escapeshellarg(static::$dir . '/stderr')
            ) . ' _ ' . implode(' ', array_map('escapeshellarg', [
                static::$dir . '/requirement.conf',
                static::$dir . '/image.conf',
                static::$dir . '/marker',
                static::$dir . '/env',
            ])),
            $output,
            $rc,
            ['IS_FRAMEWORK_DEVELOPER' => $developer]
        );

        return [trim(implode("\n", $output)), trim((string) @file_get_contents(static::$dir . '/stderr')), $rc];
    }

    /** The pre-boot gate run in a child CLI process over the scratch files: [stderr, exit code]. */
    private static function __gate(string $developer = 'false'): array
    {
        $code = 'require ' . var_export(base_path(self::BOOTSTRAP), true) . ';'
            . ' rsx_image_contract_gate(' . implode(', ', array_map(fn ($p) => var_export($p, true), [
                static::$dir . '/marker',
                static::$dir . '/image.conf',
                static::$dir . '/requirement.conf',
            ])) . '); echo "passed";';

        $output = [];
        $rc = 0;
        exec_safe(
            'php -r ' . escapeshellarg($code) . ' 2>' . escapeshellarg(static::$dir . '/gate_stderr'),
            $output,
            $rc,
            ['IS_FRAMEWORK_DEVELOPER' => $developer]
        );

        $stderr = trim((string) @file_get_contents(static::$dir . '/gate_stderr'));

        return [$rc === 0 ? trim(implode("\n", $output)) : $stderr, $rc];
    }

    private static function __sentence(string $origin, string $target = 'prod'): string
    {
        return "This container's image ({$origin}) is older than this checkout needs (2026.11.19 or newer):"
            . " docker pull ghcr.io/rspade-framework/rspade-server-{$target}:latest, then recreate the container from it.";
    }

    /** Every reader says $expected (null: fine) for the current scratch files. */
    private static function __assert_all_readers(?string $expected, string $case): void
    {
        static::__assert_equals($expected, Rsx_Image_Contract::problem(), "PHP: {$case}");

        [$stdout, $stderr, $rc] = static::__bash_problem();
        static::__assert_equals($expected === null ? 1 : 0, $rc, "bash return code: {$case}");
        static::__assert_equals((string) $expected, $stdout, "bash sentence: {$case}");
        static::__assert_equals('', $stderr, "bash stderr: {$case}");

        [$gate_said, $gate_rc] = static::__gate();
        static::__assert_equals($expected === null ? 0 : 1, $gate_rc, "gate exit code: {$case}");
        static::__assert_equals($expected === null ? 'passed' : "[ERROR] {$expected}", $gate_said, "gate output: {$case}");
    }

    public static function test_an_older_image_is_refused_naming_the_image_to_pull()
    {
        static::__fixture();

        foreach (['2026.11.18', '2026.10.30', '2025.12.31', '2026.11.18.9'] as $version) {
            static::__record($version);
            static::__assert_all_readers(static::__sentence("version {$version}"), "older {$version}");
        }

        $row = Rsx_Image_Contract::container_image();
        static::__assert_equals('FAIL', $row['status'], 'the health row FAILs');
        static::__assert_equals(static::__sentence('version 2026.11.18.9'), $row['detail'], 'the row\'s detail is the sentence');
        static::__assert_contains('docker pull ghcr.io/rspade-framework/rspade-server-prod:latest', $row['remediation'], 'the remediation names the image');
    }

    public static function test_an_equal_or_newer_image_passes()
    {
        static::__fixture();

        foreach (['2026.11.19', '2026.11.19.2', '2026.11.20', '2027.01.01'] as $version) {
            static::__record($version);
            static::__assert_all_readers(null, "version {$version}");
        }

        $row = Rsx_Image_Contract::container_image();
        static::__assert_equals('OK', $row['status'], 'the row is OK');
        static::__assert_equals('image 2027.01.01 (built from 0123456789ab); this checkout needs 2026.11.19 or newer.', $row['detail'], 'the OK detail');
    }

    public static function test_the_n_suffix_compares_numerically_and_a_missing_one_is_zero()
    {
        static::__fixture();

        static::__write('requirement.conf', "min_image_version=2026.11.19.2\n");

        static::__record('2026.11.19');
        static::__assert_contains('(version 2026.11.19)', (string) Rsx_Image_Contract::problem(), 'no suffix is .0, older than .2');
        static::__assert_equals(0, static::__bash_problem()[2], 'bash agrees');

        static::__record('2026.11.19.10');
        static::__assert_null(Rsx_Image_Contract::problem(), '.10 is newer than .2 (numeric, not text)');
        static::__assert_equals(1, static::__bash_problem()[2], 'bash agrees');

        static::__write('requirement.conf', "min_image_version=2026.09.08\n");
        static::__record('2026.09.09');
        static::__assert_null(Rsx_Image_Contract::problem(), '08 and 09 are decimal');
        static::__assert_equals(1, static::__bash_problem()[2], 'bash agrees');
    }

    public static function test_a_local_build_always_passes()
    {
        static::__fixture();

        static::__record('local');
        static::__assert_all_readers(null, 'image_version=local');
    }

    public static function test_no_record_or_no_dated_version_fails()
    {
        static::__fixture();

        @unlink(static::$dir . '/image.conf');
        static::__assert_all_readers(static::__sentence('no recorded version'), 'no record');

        static::__record(null);
        static::__assert_all_readers(static::__sentence('no recorded version'), 'no image_version line');

        static::__record('');
        static::__assert_all_readers(static::__sentence('no recorded version'), 'an empty image_version');

        static::__record('2026.11');
        static::__assert_all_readers(static::__sentence('version 2026.11, not a dated version'), 'an undated image_version');

        $row = Rsx_Image_Contract::container_image();
        static::__assert_equals('FAIL', $row['status'], 'the row FAILs for an undated version');
    }

    public static function test_the_target_comes_from_the_marker()
    {
        static::__fixture();

        static::__record('2026.01.01');

        static::__write('marker', "target=dev\n");
        static::__assert_all_readers(static::__sentence('version 2026.01.01', 'dev'), 'target=dev');

        static::__write('marker', "# no target\n");
        static::__assert_all_readers(static::__sentence('version 2026.01.01', '<dev|prod>'), 'no target');
    }

    public static function test_a_malformed_requirement_fails_loud_naming_the_file()
    {
        static::__fixture();

        $requirement = static::$dir . '/requirement.conf';
        static::__record('2026.11.19');

        foreach (["contract=1\n" => '', "min_image_version=next\n" => 'next', "min_image_version=2026.1.1\n" => '2026.1.1'] as $content => $value) {
            static::__write('requirement.conf', $content);
            $expected = "{$requirement} is unreadable: min_image_version={$value}";

            static::__assert_throws(RuntimeException::class, fn () => Rsx_Image_Contract::problem(), $expected);

            $row = Rsx_Image_Contract::container_image();
            static::__assert_equals('FAIL', $row['status'], "the row FAILs for min_image_version={$value}");
            static::__assert_equals($expected, $row['detail'], 'the row names the file');

            [$stdout, $stderr, $rc] = static::__bash_problem();
            static::__assert_equals(2, $rc, "bash returns 2 for min_image_version={$value}");
            static::__assert_equals('', $stdout, 'bash prints no sentence');
            static::__assert_equals("[ERROR] {$expected}", $stderr, 'bash names the file');

            static::__assert_equals(["[ERROR] {$expected}", 1], static::__gate(), 'the gate refuses naming the file');
        }
    }

    public static function test_outside_the_container_nothing_is_checked()
    {
        static::__fixture();

        @unlink(static::$dir . '/image.conf');
        @unlink(static::$dir . '/marker');

        static::__assert_all_readers(null, 'no marker');
        static::__assert_equals('INFO', Rsx_Image_Contract::container_image()['status'], 'the row is INFO');
    }

    public static function test_the_framework_authors_box_skips_the_check()
    {
        static::__fixture();

        @unlink(static::$dir . '/image.conf');

        foreach (['true', '1', 'Yes', 'ON'] as $value) {
            static::__assert_equals(1, static::__bash_problem($value)[2], "bash skips for IS_FRAMEWORK_DEVELOPER={$value}");
            static::__assert_equals(['passed', 0], static::__gate($value), "the gate skips for IS_FRAMEWORK_DEVELOPER={$value}");
        }

        // The environment unset: the env file decides.
        static::__write('env', "IS_FRAMEWORK_DEVELOPER=true\n");
        static::__assert_equals(1, static::__bash_problem('')[2], 'bash reads the env file');
        static::__write('env', "IS_FRAMEWORK_DEVELOPER=false\n");
        static::__assert_equals(0, static::__bash_problem('')[2], 'false in the env file checks');

        Rsx_Image_Contract::$framework_developer_for_tests = true;
        static::__assert_null(Rsx_Image_Contract::problem(), 'PHP skips');
        $row = Rsx_Image_Contract::container_image();
        static::__assert_equals('INFO', $row['status'], 'the row is INFO');
        static::__assert_contains('IS_FRAMEWORK_DEVELOPER', $row['detail'], 'the row says why');
    }

    public static function test_the_preboot_exempts_only_the_boot_free_commands_and_rsx_health()
    {
        static::__fixture();

        require_once base_path(self::BOOTSTRAP);

        foreach (['rsx:framework:pull', 'rsx:maintenance:enable', 'rsx:maintenance:disable', 'rsx:git', 'rsx:health'] as $command) {
            static::__assert_true(rsx_image_contract_exempt($command), "{$command} is exempt");
        }
        foreach (['list', 'migrate', 'rsx:build', 'rsx:framework:post_update', 'rsx:test', 'rsx:health:x', ''] as $command) {
            static::__assert_false(rsx_image_contract_exempt($command), "'{$command}' is refused");
        }
        static::__assert_false(rsx_image_contract_exempt(null), 'an external script is refused');

        // Every boot-free command rsx_preboot() intercepts is exempt, and the gate is asked
        // after the container gate, for artisan and scripts alike.
        $preboot = (string) file_get_contents(base_path('bootstrap/rsx_preboot.php'));
        static::__assert_equals(1, preg_match("/\\\$boot_free = .*?in_array\\(\\\$_SERVER\\['argv'\\]\\[1\\], \\[(.*?)\\], true\\);/s", $preboot, $match), 'the preboot lists its boot-free commands');
        preg_match_all("/'([a-z:]+)'/", $match[1], $boot_free);
        static::__assert_count(4, $boot_free[1], 'four boot-free commands');
        foreach ($boot_free[1] as $command) {
            static::__assert_true(rsx_image_contract_exempt($command), "boot-free {$command} is exempt");
        }

        $gate = strpos($preboot, 'rsx_image_contract_gate();');
        static::__assert_true($gate !== false, 'the preboot runs the gate');
        static::__assert_true(strpos($preboot, "require __DIR__ . '/rsx_container_gate.php';") < $gate, 'after the container gate');
        static::__assert_true(
            str_contains($preboot, "rsx_image_contract_exempt(\$script_mode ? null : (\$_SERVER['argv'][1] ?? null))"),
            'a script is never exempt'
        );

        $index = (string) file_get_contents(base_path('public/index.php'));
        $web_gate = strpos($index, 'rsx_image_contract_gate();');
        static::__assert_true($web_gate !== false, 'every web request runs the gate');
        static::__assert_true(strpos($index, '$__rsx_migrating_flag') < $web_gate, 'after the maintenance and migration gates');
        static::__assert_true($web_gate < strpos($index, "require __DIR__.'/../vendor/autoload.php';"), 'before the autoloader');
    }

    public static function test_the_shipped_requirement_is_a_dated_version()
    {
        static::__fixture();

        $raw = (string) file_get_contents(base_path(self::CONF));

        static::__assert_true(str_ends_with($raw, "\n"), 'the file ends in a newline (the image appends its record)');
        static::__assert_equals(1, preg_match('/^min_image_version=(\d{4}\.\d{2}\.\d{2}(\.\d+)?)$/m', $raw), 'min_image_version= is a dated version');
        static::__assert_equals(0, preg_match('/^(contract|image_version|built_from)=/m', $raw), 'no key the image record writes or the contract counter used');
    }

    public static function test_the_dev_and_prod_stages_record_the_version_in_their_last_layer()
    {
        static::__fixture();

        $dockerfile = (string) file_get_contents(base_path('app/RSpade/resource/docker/Dockerfile'));
        $stages = preg_split('/^FROM .* AS (\w+)$/m', $dockerfile, -1, PREG_SPLIT_DELIM_CAPTURE);

        $bodies = [];
        for ($i = 1; $i < count($stages); $i += 2) {
            $bodies[$stages[$i]] = $stages[$i + 1];
        }

        static::__assert_false(str_contains($bodies['base'], 'image_contract.conf'), 'base records nothing');
        static::__assert_false(str_contains($bodies['docconvert'], 'image_contract.conf'), 'docconvert records nothing');

        foreach (['dev', 'prod'] as $stage) {
            $instructions = array_values(array_filter(
                preg_split('/\n(?=[A-Z]+ )/', trim($bodies[$stage])),
                fn ($chunk) => !str_starts_with(ltrim($chunk), '#')
            ));
            $last = (string) end($instructions);

            static::__assert_true(
                str_contains($bodies[$stage], 'COPY image_contract.conf /opt/rspade/image.conf'),
                "{$stage} copies the file into the image"
            );
            static::__assert_true(
                str_contains($bodies[$stage], 'ARG RSPADE_IMAGE_VERSION=local'),
                "{$stage} records local unless a version is passed"
            );
            static::__assert_true(
                str_contains($last, 'built_from=') && str_contains($last, 'image_version=') && str_contains($last, '/opt/rspade/image.conf'),
                "{$stage}'s last instruction writes the record"
            );
        }
    }
}
