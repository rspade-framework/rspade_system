<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Health\Php;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Health\Health_Check_Runner;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The mode axis of rsx:health: `#[Health_Check('Label', modes: ...)]`.
 *
 * A check whose modes exclude the current RSX_MODE is not invoked and prints no row -
 * a development tool reported as missing on a production box is noise an operator has
 * to learn to ignore. The skipped LABELS are still reported, so the axis is visible
 * rather than a silent omission.
 *
 * The partition is driven directly rather than through a full run(): it is the whole
 * decision, it is pure, and running the real inventory under a forced production mode
 * would fire the seal and exposure probes against a box that is not one.
 *
 * No database access - skip the per-test transaction.
 */
class Health_Mode_Axis_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** A check declared development-only, used as the real-inventory witness. */
    private const DEV_ONLY_LABEL = 'Playwright / Chromium';

    /** A check declared for the two sealed modes. */
    private const PROD_ONLY_LABEL = 'Production Seal';

    /** A check that declares no modes at all. */
    private const EVERY_MODE_LABEL = 'PHP';

    // -------------------------------------------------------------------------
    // normalize_modes(): the attribute argument
    // -------------------------------------------------------------------------

    public static function test_an_absent_modes_argument_means_every_mode()
    {
        static::__assert_null(
            Health_Check_Runner::normalize_modes(null, 'X::y'),
            'a check declaring no modes applies everywhere'
        );
    }

    public static function test_a_bare_string_and_a_list_normalize_the_same_way()
    {
        static::__assert_equals(
            ['development'],
            Health_Check_Runner::normalize_modes('development', 'X::y'),
            'a bare string is one mode'
        );

        static::__assert_equals(
            ['debug', 'production'],
            Health_Check_Runner::normalize_modes(['debug', 'production'], 'X::y'),
            'a list is taken as given'
        );
    }

    public static function test_an_unknown_mode_name_fails_loud()
    {
        // A typo would otherwise silence a check on every box, which is the one outcome
        // nobody would notice.
        static::__assert_throws(
            \RuntimeException::class,
            static fn () => Health_Check_Runner::normalize_modes('prod', 'Some_Class::some_check'),
            'Some_Class::some_check'
        );

        static::__assert_throws(
            \RuntimeException::class,
            static fn () => Health_Check_Runner::normalize_modes([], 'Some_Class::some_check'),
            'empty modes list'
        );
    }

    // -------------------------------------------------------------------------
    // applies_in_mode(): the decision itself
    // -------------------------------------------------------------------------

    public static function test_declared_modes_include_and_exclude()
    {
        static::__assert_true(
            Health_Check_Runner::applies_in_mode(null, Rsx::MODE_PRODUCTION),
            'no declaration applies in production'
        );
        static::__assert_true(
            Health_Check_Runner::applies_in_mode(['development'], Rsx::MODE_DEVELOPMENT),
            'a development check applies in development'
        );
        static::__assert_false(
            Health_Check_Runner::applies_in_mode(['development'], Rsx::MODE_PRODUCTION),
            'a development check does not apply in production'
        );
        static::__assert_false(
            Health_Check_Runner::applies_in_mode(['debug', 'production'], Rsx::MODE_DEVELOPMENT),
            'a sealed-build check does not apply in development'
        );
    }

    // -------------------------------------------------------------------------
    // partition(): the real inventory, per mode
    // -------------------------------------------------------------------------

    private static function __labels(array $checks): array
    {
        return array_map(static fn ($check) => $check['label'], $checks);
    }

    public static function test_a_development_only_check_is_skipped_in_production_and_listed()
    {
        $partition = Health_Check_Runner::partition(Rsx::MODE_PRODUCTION);

        static::__assert_false(
            in_array(self::DEV_ONLY_LABEL, self::__labels($partition['run']), true),
            self::DEV_ONLY_LABEL . ' does not run in production'
        );
        static::__assert_true(
            in_array(self::DEV_ONLY_LABEL, $partition['skipped'], true),
            self::DEV_ONLY_LABEL . ' is listed as skipped, not silently omitted'
        );
    }

    public static function test_a_sealed_build_check_is_skipped_in_development_and_listed()
    {
        $partition = Health_Check_Runner::partition(Rsx::MODE_DEVELOPMENT);

        static::__assert_false(
            in_array(self::PROD_ONLY_LABEL, self::__labels($partition['run']), true),
            self::PROD_ONLY_LABEL . ' does not run in development'
        );
        static::__assert_true(
            in_array(self::PROD_ONLY_LABEL, $partition['skipped'], true),
            self::PROD_ONLY_LABEL . ' is listed as skipped'
        );
    }

    public static function test_a_check_with_no_modes_runs_in_every_mode()
    {
        foreach ([Rsx::MODE_DEVELOPMENT, Rsx::MODE_DEBUG, Rsx::MODE_PRODUCTION] as $mode) {
            $partition = Health_Check_Runner::partition($mode);

            static::__assert_true(
                in_array(self::EVERY_MODE_LABEL, self::__labels($partition['run']), true),
                self::EVERY_MODE_LABEL . " runs in {$mode}"
            );
            static::__assert_false(
                in_array(self::EVERY_MODE_LABEL, $partition['skipped'], true),
                self::EVERY_MODE_LABEL . " is not skipped in {$mode}"
            );
        }
    }

    public static function test_every_declared_check_is_either_run_or_skipped_exactly_once()
    {
        $total = count(Health_Check_Runner::discover());

        foreach ([Rsx::MODE_DEVELOPMENT, Rsx::MODE_DEBUG, Rsx::MODE_PRODUCTION] as $mode) {
            $partition = Health_Check_Runner::partition($mode);

            static::__assert_equals(
                $total,
                count($partition['run']) + count($partition['skipped']),
                "the {$mode} partition accounts for every discovered check"
            );
        }
    }

    public static function test_partition_defaults_to_this_box_mode()
    {
        static::__assert_equals(
            Rsx::get_mode(),
            Health_Check_Runner::partition()['mode'],
            'no argument means this box'
        );
    }

    // -------------------------------------------------------------------------
    // The command surfaces the axis
    // -------------------------------------------------------------------------

    public static function test_json_carries_the_mode_and_the_skipped_labels()
    {
        Artisan::call('rsx:health', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        static::__assert_equals(Rsx::get_mode(), $decoded['mode'] ?? null, 'the payload names the mode it ran in');
        static::__assert_true(is_array($decoded['skipped'] ?? null), 'the payload carries a skipped list');

        $labels = array_column($decoded['checks'], 'label');
        foreach ($decoded['skipped'] as $skipped) {
            static::__assert_false(
                in_array($skipped, $labels, true),
                "'{$skipped}' is skipped, so it contributes no row"
            );
        }
    }

    public static function test_the_table_run_names_the_mode()
    {
        Artisan::call('rsx:health');

        static::__assert_contains('Mode: ' . Rsx::get_mode(), Artisan::output(), 'the table run states its mode');
    }
}
