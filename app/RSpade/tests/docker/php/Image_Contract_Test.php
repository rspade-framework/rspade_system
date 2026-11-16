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
 * The image contract: the checkout's requirement (resource/docker/image_contract.conf)
 * against the record an image carries (/opt/rspade/image.conf). Driven against scratch
 * files through the PHP class's seams and the bash library's path arguments, so the two
 * readers are fed the same fixtures and must print the same sentence.
 */
class Image_Contract_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const LIB = 'bin/lib/rsx_image_contract.sh';
    private const CONF = 'app/RSpade/resource/docker/image_contract.conf';

    private static string $dir = '';

    public static function setup()
    {
        static::$dir = sys_get_temp_dir() . '/image_contract_test_' . getmypid();
        @mkdir(static::$dir, 0700, true);

        static::__write('requirement.conf', "# header\ncontract=2\nbreaking_change=some_image_change_01_01\n");
        static::__write('marker', "# This is an RSpade application container.\ntarget=prod\n");
        static::__write('lockd-run.sh', "#!/bin/bash\n");

        Rsx_Image_Contract::$requirement_path_for_tests = static::$dir . '/requirement.conf';
        Rsx_Image_Contract::$record_path_for_tests = static::$dir . '/image.conf';
        Rsx_Image_Contract::$marker_path_for_tests = static::$dir . '/marker';
        Rsx_Image_Contract::$lockd_script_for_tests = static::$dir . '/lockd-run.sh';
    }

    public static function teardown()
    {
        Rsx_Image_Contract::$requirement_path_for_tests = null;
        Rsx_Image_Contract::$record_path_for_tests = null;
        Rsx_Image_Contract::$marker_path_for_tests = null;
        Rsx_Image_Contract::$lockd_script_for_tests = null;
        rmdir_recursive(static::$dir);
    }

    private static function __write(string $name, string $content): void
    {
        file_put_contents(static::$dir . '/' . $name, $content);
    }

    /**
     * The bash library's answer for the current scratch files: [stdout, stderr, return code].
     */
    private static function __bash_problem(): array
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
                static::$dir . '/lockd-run.sh',
            ])),
            $output,
            $rc
        );

        return [trim(implode("\n", $output)), trim((string) @file_get_contents(static::$dir . '/stderr')), $rc];
    }

    public static function test_an_older_record_is_named_with_the_remedy_by_both_readers()
    {
        static::__write('image.conf', "contract=1\nbreaking_change=old_01_01\nbuilt_from=0123456789ab\n");

        $expected = "This container's image (built from 0123456789ab, image contract 1) is older than this"
            . ' checkout needs (image contract 2): rebuild the image from this system/'
            . ' (bash system/app/RSpade/resource/docker/build.sh prod) and recreate the container;'
            . ' see breaking change some_image_change_01_01'
            . ' (php artisan rsx:framework:breaking_changes:show some_image_change_01_01).';

        static::__assert_equals($expected, Rsx_Image_Contract::problem(), 'PHP names the remedy');

        [$stdout, $stderr, $rc] = static::__bash_problem();
        static::__assert_equals(0, $rc, 'bash answers "older"');
        static::__assert_equals($expected, $stdout, 'bash prints the identical sentence');
        static::__assert_equals('', $stderr, 'nothing on stderr');

        $row = Rsx_Image_Contract::container_image();
        static::__assert_equals('FAIL', $row['status'], 'the health row FAILs');
        static::__assert_equals($expected, $row['detail'], 'the row\'s detail is the sentence');
        static::__assert_contains('build.sh prod', $row['remediation'], 'the remediation names the target');
    }

    public static function test_an_unrecorded_revision_reads_as_such()
    {
        static::__write('image.conf', "contract=1\nbuilt_from=unknown\n");

        $php = Rsx_Image_Contract::problem();
        static::__assert_contains('(built from an unrecorded revision, image contract 1)', (string) $php, 'PHP');
        static::__assert_equals($php, static::__bash_problem()[0], 'bash prints the identical sentence');
    }

    public static function test_an_equal_or_newer_image_is_fine()
    {
        foreach (['2', '3'] as $contract) {
            static::__write('image.conf', "contract={$contract}\nbuilt_from=0123456789ab\n");

            static::__assert_null(Rsx_Image_Contract::problem(), "PHP: contract {$contract} is fine");
            [$stdout, , $rc] = static::__bash_problem();
            static::__assert_equals(1, $rc, "bash: contract {$contract} is fine");
            static::__assert_equals('', $stdout, "bash: contract {$contract} prints nothing");
        }

        $row = Rsx_Image_Contract::container_image();
        static::__assert_equals('OK', $row['status'], 'the row is OK');
        static::__assert_equals('image contract 3 (built from 0123456789ab); this checkout needs 2.', $row['detail'], 'the OK detail');
    }

    public static function test_no_record_is_contract_1_with_the_lockd_script_and_0_without()
    {
        @unlink(static::$dir . '/image.conf');
        static::__write('requirement.conf', "contract=1\nbreaking_change=some_image_change_01_01\n");

        static::__assert_equals(1, Rsx_Image_Contract::provided(), 'the lockd script present: contract 1');
        static::__assert_null(Rsx_Image_Contract::problem(), 'contract 1 meets a requirement of 1');
        static::__assert_equals(1, static::__bash_problem()[2], 'bash agrees');

        static::__write('requirement.conf', "contract=2\nbreaking_change=some_image_change_01_01\n");
        $php = Rsx_Image_Contract::problem();
        static::__assert_contains('(built before images recorded their contract, image contract 1)', (string) $php, 'older than 2');
        static::__assert_equals($php, static::__bash_problem()[0], 'bash prints the identical sentence');

        @unlink(static::$dir . '/lockd-run.sh');
        static::__write('requirement.conf', "contract=1\nbreaking_change=some_image_change_01_01\n");
        static::__assert_equals(0, Rsx_Image_Contract::provided(), 'no lockd script: contract 0');
        $php = Rsx_Image_Contract::problem();
        static::__assert_contains('(built before images recorded their contract, image contract 0)', (string) $php, 'older than 1');
        static::__assert_equals($php, static::__bash_problem()[0], 'bash prints the identical sentence');
    }

    public static function test_a_malformed_record_fails_loud_naming_the_file()
    {
        $record = static::$dir . '/image.conf';

        foreach (["built_from=abc\n" => '', "contract=two\n" => 'two', "contract=-1\n" => '-1'] as $content => $value) {
            static::__write('image.conf', $content);

            static::__assert_throws(
                RuntimeException::class,
                fn () => Rsx_Image_Contract::problem(),
                "{$record} is unreadable: contract={$value}"
            );

            $row = Rsx_Image_Contract::container_image();
            static::__assert_equals('FAIL', $row['status'], "the row FAILs for contract={$value}");
            static::__assert_equals("{$record} is unreadable: contract={$value}", $row['detail'], 'the row names the file');

            [$stdout, $stderr, $rc] = static::__bash_problem();
            static::__assert_equals(2, $rc, "bash returns 2 for contract={$value}");
            static::__assert_equals('', $stdout, 'bash prints no sentence');
            static::__assert_equals("[ERROR] {$record} is unreadable: contract={$value}", $stderr, 'bash names the file');
        }
    }

    public static function test_outside_the_container_nothing_applies()
    {
        static::__write('image.conf', "contract=0\n");
        @unlink(static::$dir . '/marker');

        static::__assert_null(Rsx_Image_Contract::problem(), 'PHP: no marker, no problem');
        static::__assert_equals(1, static::__bash_problem()[2], 'bash: no marker, fine');
        static::__assert_equals('INFO', Rsx_Image_Contract::container_image()['status'], 'the row is INFO');
    }

    public static function test_the_shipped_requirement_names_an_existing_breaking_change()
    {
        $raw = (string) file_get_contents(base_path(self::CONF));

        static::__assert_true(str_ends_with($raw, "\n"), 'the file ends in a newline (the image appends built_from)');
        static::__assert_equals(1, preg_match('/^contract=(\d+)$/m', $raw), 'contract= is a whole number');
        static::__assert_equals(1, preg_match('/^breaking_change=([a-z0-9_]+)$/m', $raw, $match), 'breaking_change= is named');
        static::__assert_true(
            is_file(base_path("app/RSpade/breaking_changes/{$match[1]}.txt")),
            "breaking_changes/{$match[1]}.txt exists"
        );
    }

    public static function test_the_dev_and_prod_stages_record_the_contract_in_their_last_layer()
    {
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
                "{$stage} copies the contract into the image"
            );
            static::__assert_true(
                str_contains($last, 'built_from=') && str_contains($last, '/opt/rspade/image.conf'),
                "{$stage}'s last instruction writes the record"
            );
        }
    }
}
