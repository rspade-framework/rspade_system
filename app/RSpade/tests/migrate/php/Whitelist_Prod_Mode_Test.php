<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Migrate\Php\Whitelist_Probe_Migrate;

/**
 * .migration_whitelist is WRITTEN only in development, and CONSULTED in every mode.
 *
 * The file is a stray-file tripwire (a migration in the tree that the file does not list
 * aborts the whole run) and it is SOURCE: it is committed beside the migrations it lists
 * and ships with the code. Writing it on a deployed box would mean the framework editing a
 * deployed source tree - the one ungated source write a production `migrate` still had
 * (a downstream field report, 2026-09-15).
 *
 * Driven through a subclass that redirects the command's own whitelist_locations() seam at
 * a throwaway sandbox directory, so the logic under test is the real code while no
 * .migration_whitelist anywhere on this box is read, created or touched.
 *
 * No database access - skip the per-test transaction.
 */
class Whitelist_Prod_Mode_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** @var Whitelist_Probe_Migrate[] Probes created by the current test. */
    private static $probes = [];

    /**
     * A probe whose sandbox __restore() will remove.
     */
    private static function __probe(): Whitelist_Probe_Migrate
    {
        $probe = new Whitelist_Probe_Migrate();
        self::$probes[] = $probe;

        return $probe;
    }

    private static function __restore(): void
    {
        foreach (self::$probes as $probe) {
            $probe->cleanup();
        }

        self::$probes = [];

        Rsx::clear_mode_cache();
    }

    // -------------------------------------------------------------------------
    // Creating it
    // -------------------------------------------------------------------------

    public static function test_a_production_mode_refuses_to_create_the_whitelist()
    {
        try {
            foreach ([Rsx::MODE_DEBUG, Rsx::MODE_PRODUCTION] as $mode) {
                Rsx::_testing_set_mode($mode);

                $command = self::__probe();
                $command->create_initial_whitelist();

                static::__assert_false(
                    $command->wrote_whitelist(),
                    "createInitialWhitelist() writes nothing in {$mode} mode"
                );
                static::__assert_contains(
                    'Refusing to create a migration whitelist outside development mode',
                    $command->console_text(),
                    'the refusal is loud'
                );
                static::__assert_contains(
                    'ships with the code',
                    $command->console_text(),
                    'the refusal says why the file is not the framework\'s to write here'
                );
            }
        } finally {
            self::__restore();
        }
    }

    public static function test_development_creates_the_whitelist()
    {
        try {
            Rsx::_testing_set_mode(Rsx::MODE_DEVELOPMENT);

            $command = self::__probe();
            $command->create_initial_whitelist();

            static::__assert_true(
                $command->wrote_whitelist(),
                'development is the one mode that writes the file'
            );
        } finally {
            self::__restore();
        }
    }

    // -------------------------------------------------------------------------
    // The absent-file branch of the check
    // -------------------------------------------------------------------------

    public static function test_an_absent_whitelist_in_a_production_mode_is_skipped_silently()
    {
        try {
            foreach ([Rsx::MODE_DEBUG, Rsx::MODE_PRODUCTION] as $mode) {
                Rsx::_testing_set_mode($mode);

                $command = self::__probe();
                $passed = $command->check_whitelist([]);

                static::__assert_true($passed, "an absent whitelist does not block a {$mode} migrate");
                static::__assert_false($command->wrote_whitelist(), 'and nothing is written');
                static::__assert_equals(
                    '',
                    trim($command->console_text()),
                    'the skip says nothing - there is no action for an operator to take'
                );
            }
        } finally {
            self::__restore();
        }
    }

    public static function test_an_absent_whitelist_in_development_is_created()
    {
        try {
            Rsx::_testing_set_mode(Rsx::MODE_DEVELOPMENT);

            $command = self::__probe();
            $passed = $command->check_whitelist([]);

            static::__assert_true($passed, 'the run proceeds');
            static::__assert_true($command->wrote_whitelist(), 'the file is created on the box that authors migrations');
        } finally {
            self::__restore();
        }
    }

    // -------------------------------------------------------------------------
    // The present-file branch: enforced in EVERY mode
    // -------------------------------------------------------------------------

    public static function test_a_present_whitelist_is_enforced_in_every_mode()
    {
        try {
            foreach ([Rsx::MODE_DEVELOPMENT, Rsx::MODE_DEBUG, Rsx::MODE_PRODUCTION] as $mode) {
                Rsx::_testing_set_mode($mode);

                $directory = Whitelist_Probe_Migrate::stage_migrations([
                    '2026_09_15_000001_listed.php',
                    '2026_09_15_000002_not_listed.php',
                ]);

                try {
                    $command = self::__probe();
                    $command->whitelisted = ['2026_09_15_000001_listed.php'];

                    $passed = $command->check_whitelist([$directory]);

                    static::__assert_false($passed, "the stray migration aborts the run in {$mode} mode");
                    static::__assert_contains(
                        '2026_09_15_000002_not_listed.php',
                        $command->console_text(),
                        'the stray file is named'
                    );
                } finally {
                    exec_safe('rm -rf ' . escapeshellarg($directory));
                }
            }
        } finally {
            self::__restore();
        }
    }

    public static function test_a_fully_listed_tree_passes_in_every_mode()
    {
        try {
            foreach ([Rsx::MODE_DEVELOPMENT, Rsx::MODE_DEBUG, Rsx::MODE_PRODUCTION] as $mode) {
                Rsx::_testing_set_mode($mode);

                $directory = Whitelist_Probe_Migrate::stage_migrations(['2026_09_15_000001_listed.php']);

                try {
                    $command = self::__probe();
                    $command->whitelisted = ['2026_09_15_000001_listed.php'];

                    static::__assert_true(
                        $command->check_whitelist([$directory]),
                        "a fully listed tree passes in {$mode} mode"
                    );
                    static::__assert_equals(
                        ['2026_09_15_000001_listed.php'],
                        $command->whitelisted_basenames_on_disk(),
                        'the present whitelist is read, never rewritten'
                    );
                } finally {
                    exec_safe('rm -rf ' . escapeshellarg($directory));
                }
            }
        } finally {
            self::__restore();
        }
    }
}
