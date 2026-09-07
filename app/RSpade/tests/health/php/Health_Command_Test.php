<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Health\Php;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * End-to-end tests for the rsx:health command: plain run exits 0 on this (healthy) box,
 * and --json emits a parseable payload whose 'ok' flag and exit code both track the
 * absence of FAIL rows. The always-present checks (MySQL, PHP, storage) must appear.
 *
 * The command only runs read-only probes, so the default per-test transaction is fine.
 */
class Health_Command_Test extends Rsx_Test_Abstract
{
    public static function test_plain_run_exits_zero()
    {
        $exit = Artisan::call('rsx:health');

        static::__assert_equals(0, $exit, 'rsx:health should exit 0 on a healthy box');
    }

    public static function test_json_output_parses_and_is_consistent()
    {
        $exit = Artisan::call('rsx:health', ['--json' => true]);
        $output = Artisan::output();

        $decoded = json_decode($output, true);
        static::__assert_true(is_array($decoded), 'rsx:health --json must emit parseable JSON');
        static::__assert_true(isset($decoded['generated_at']), 'json payload carries generated_at');
        static::__assert_true(array_key_exists('ok', $decoded), 'json payload carries ok');
        static::__assert_true(
            is_array($decoded['checks'] ?? null) && !empty($decoded['checks']),
            'json payload carries a non-empty checks list'
        );

        // Labels present on every box.
        $labels = array_column($decoded['checks'], 'label');
        foreach (['MySQL Connectivity', 'PHP Version', 'storage/'] as $expected) {
            static::__assert_true(in_array($expected, $labels, true), "checks include '{$expected}'");
        }

        // 'ok' is true iff no FAIL row; exit code is 0 iff ok. Every row has a valid status.
        $has_fail = false;
        foreach ($decoded['checks'] as $check) {
            static::__assert_true(
                in_array($check['status'], ['OK', 'WARN', 'FAIL', 'INFO'], true),
                'every check has a valid status'
            );
            if ($check['status'] === 'FAIL') {
                $has_fail = true;
            }
        }

        static::__assert_equals(!$has_fail, $decoded['ok'], "'ok' reflects the absence of FAIL rows");
        static::__assert_equals($has_fail ? 1 : 0, $exit, 'exit code tracks ok');
    }
}
