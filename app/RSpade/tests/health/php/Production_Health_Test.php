<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Health\Php;

use App\RSpade\Core\Health\Health_Check_Runner;
use App\RSpade\Core\Health\Production_Health_Checks;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The rows that only mean something on a SEALED box: the seal, the APP_URL scheme,
 * login auto-fill, the console_debug variant and the mail delivery target.
 *
 * NONE of these states is one this development box is in, and forcing the mode alone
 * would not create them - a forced-production child on an unsealed box fatals at the
 * manifest gate before a single row runs. So every row builder takes what it reports as
 * a parameter and is driven directly, which is the only way these branches are ever
 * seen. What the mode axis itself does is Health_Mode_Axis_Test's subject; what is
 * asserted here is that each of these checks DECLARES the two sealed modes, and that
 * each branch says the right thing.
 *
 * No database access - skip the per-test transaction.
 */
class Production_Health_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** Every check this class declares, by label. */
    private const PROD_LABELS = [
        'Production Seal',
        'APP_URL Scheme',
        'Login Auto-fill',
        'Console Debug',
        'Mail Delivery Target',
        'Hostname',
    ];

    // -------------------------------------------------------------------------
    // The mode declaration
    // -------------------------------------------------------------------------

    public static function test_every_production_check_declares_the_two_sealed_modes()
    {
        $declared = [];

        foreach (Health_Check_Runner::discover() as $check) {
            if ($check['fqcn'] === Production_Health_Checks::class) {
                $declared[$check['label']] = $check['modes'];
            }
        }

        foreach (self::PROD_LABELS as $label) {
            static::__assert_array_has_key($label, $declared, "{$label} is discovered");
            static::__assert_equals(
                ['debug', 'production'],
                $declared[$label],
                "{$label} applies to the two sealed modes and to neither development box"
            );
        }

        static::__assert_equals(
            count(self::PROD_LABELS),
            count($declared),
            'this test knows about every check the class declares'
        );
    }

    // -------------------------------------------------------------------------
    // The seal
    // -------------------------------------------------------------------------

    public static function test_an_intact_seal_is_ok_and_drift_is_a_fail_naming_the_rebuild()
    {
        $ok = Production_Health_Checks::_seal_row(true, []);
        static::__assert_equals('OK', $ok['status'], 'a build matching its seal is OK');

        $drifted = Production_Health_Checks::_seal_row(true, ['Hash mismatch: bundles/app.js']);
        static::__assert_equals('FAIL', $drifted['status'], 'drift from the seal gates the deploy');
        static::__assert_contains('bundles/app.js', $drifted['detail'], 'the finding is carried, not summarized away');
        static::__assert_contains('rsx:build --force', $drifted['remediation'], 'the remediation names the rebuild');
    }

    public static function test_a_missing_seal_is_a_fail_naming_the_build()
    {
        $row = Production_Health_Checks::_seal_row(false, []);

        static::__assert_equals('FAIL', $row['status'], 'no seal on a sealed-mode box is a FAIL');
        static::__assert_contains('rsx:build --force', $row['remediation'], 'the remediation names the build');
    }

    // -------------------------------------------------------------------------
    // APP_URL
    // -------------------------------------------------------------------------

    public static function test_https_is_ok_and_anything_else_fails()
    {
        static::__assert_equals(
            'OK',
            Production_Health_Checks::_app_url_row('https://example.com')['status'],
            'https is the only acceptable scheme on a sealed build'
        );

        foreach (['http://example.com', 'example.com', ''] as $bad) {
            $row = Production_Health_Checks::_app_url_row($bad);
            static::__assert_equals('FAIL', $row['status'], "'{$bad}' is not an https application URL");
            static::__assert_contains('APP_URL', $row['remediation'], 'the remediation names the key');
        }
    }

    public static function test_the_app_url_row_explains_why_the_boot_guard_did_not_catch_it()
    {
        // The guard reads the raw environment and throws; this row reads the CONFIGURED
        // value, which on a sealed box came from the cached config the build produced -
        // so the remediation has to say that a .env edit alone changes nothing.
        $row = Production_Health_Checks::_app_url_row('http://example.com');

        static::__assert_contains('rsx:build --force', $row['remediation'], 'the cached config must be rebuilt');
    }

    // -------------------------------------------------------------------------
    // Login auto-fill - the switched-on convenience
    // -------------------------------------------------------------------------

    public static function test_login_autofill_on_a_sealed_build_is_a_fail()
    {
        static::__assert_equals(
            'OK',
            Production_Health_Checks::_login_autofill_row(false)['status'],
            'off is the expected state'
        );

        $row = Production_Health_Checks::_login_autofill_row(true);
        static::__assert_equals('FAIL', $row['status'], 'a working credential on an unauthenticated page is not advisory');
        static::__assert_contains('RSPADE_LOGIN_AUTOFILL', $row['remediation'], 'the remediation names the key');
    }

    // -------------------------------------------------------------------------
    // The variant-descriptive rows
    // -------------------------------------------------------------------------

    public static function test_console_debug_is_info_and_names_the_variant()
    {
        $strict = Production_Health_Checks::_console_debug_row(Rsx::MODE_PRODUCTION, true);
        static::__assert_equals('INFO', $strict['status'], 'neither variant is wrong, so it is INFO');
        static::__assert_contains('stripped', $strict['detail'], 'strict production strips every call site');

        $debug = Production_Health_Checks::_console_debug_row(Rsx::MODE_DEBUG, true);
        static::__assert_equals('INFO', $debug['status'], 'the debug variant is a deliberate configuration');
        static::__assert_contains('intact', $debug['detail'], 'the debug variant keeps console_debug');
    }

    // -------------------------------------------------------------------------
    // The hostname - the two outbound channels that read it
    // -------------------------------------------------------------------------

    public static function test_a_dev_hostname_fails_on_a_sealed_build()
    {
        $row = Production_Health_Checks::_hostname_row('app.dev.example.test');

        static::__assert_equals('FAIL', $row['status'], 'a production site cannot carry a .dev. hostname');
        static::__assert_contains('app.dev.example.test', $row['detail'], 'the row names the host it read');
        static::__assert_contains('catchall', $row['detail'], 'the email consequence is stated');
        static::__assert_contains('Suppressed', $row['detail'], 'the SMS consequence is stated');
        static::__assert_contains('APP_URL', $row['remediation'], 'the remediation names the key');
        static::__assert_contains('rsx:build --force', $row['remediation'], 'the cached config must be rebuilt');
    }

    public static function test_an_ordinary_hostname_is_ok()
    {
        $row = Production_Health_Checks::_hostname_row('app.example.test');

        static::__assert_equals('OK', $row['status'], 'a production hostname is not a development one');
        static::__assert_null($row['remediation'], 'nothing to remediate');
    }

    public static function test_the_test_is_the_dotted_substring_and_nothing_looser()
    {
        // Rsx::is_dev_site() is str_contains($hostname, '.dev.') - the dot on BOTH sides
        // is the whole rule, and this row asks the identical question. So a LEADING
        // 'dev.' label is not a development host, and neither is a trailing '.dev':
        // the framework would gate neither, and a row that disagreed with the gate it
        // reports on would send an operator after the wrong thing.
        foreach (['dev.example.test', 'example.dev', 'devel.example.test', 'mydev.example.test'] as $host) {
            static::__assert_equals(
                'OK',
                Production_Health_Checks::_hostname_row($host)['status'],
                "{$host} does not contain '.dev.' so Rsx::is_dev_site() is false for it"
            );
        }

        foreach (['a.dev.b', 'staging.dev.example.test'] as $host) {
            static::__assert_equals(
                'FAIL',
                Production_Health_Checks::_hostname_row($host)['status'],
                "{$host} contains '.dev.'"
            );
        }
    }

    public static function test_the_development_catcher_on_a_sealed_build_warns()
    {
        static::__assert_equals(
            'OK',
            Production_Health_Checks::_mail_delivery_row('live')['status'],
            'live delivery is the expected target'
        );

        $row = Production_Health_Checks::_mail_delivery_row('aiosmtpd');
        static::__assert_equals('WARN', $row['status'], 'a staging box catching its own mail is legitimate, so never FAIL');
        static::__assert_contains('no recipient', $row['detail'], 'the row names the silent outage');
    }
}
