<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Maintenance\Php;

use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The build-tail environment-update trigger's decision seam.
 *
 * system/ is vendored, so a plain `git pull` DELIVERS new bin/environment_updates/*.sh but
 * nothing executes them; a SUCCESSFUL rsx:manifest:build is the bootstrap-safe trigger that
 * closes that gap. Two gates decide whether a given build may run them, and both matter:
 * DEVELOPMENT mode (a sealed debug/production build must never self-modify) and NOT under
 * maintenance (the framework pull raises that window and invokes post-update.sh itself, so
 * the gate is what makes a pull apply the updates exactly once).
 */
class Environment_Updates_Trigger_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** Restore both seams no matter how a test ends. */
    protected static function __restore_seams()
    {
        Framework_Maintenance::$force_active_for_tests = null;
        Rsx::clear_mode_cache();
    }

    /** Development, no maintenance window: the updates run. */
    public static function test_development_without_maintenance_runs_the_updates()
    {
        try {
            Rsx::_testing_set_mode(Rsx::MODE_DEVELOPMENT);
            Framework_Maintenance::$force_active_for_tests = false;

            static::__assert_true(\App\RSpade\Commands\Rsx\Manifest_Build_Command::__should_run_environment_updates());
        } finally {
            static::__restore_seams();
        }
    }

    /** Debug is a SEALED build - it must not self-modify the environment. */
    public static function test_debug_mode_never_runs_the_updates()
    {
        try {
            Rsx::_testing_set_mode(Rsx::MODE_DEBUG);
            Framework_Maintenance::$force_active_for_tests = false;

            static::__assert_false(\App\RSpade\Commands\Rsx\Manifest_Build_Command::__should_run_environment_updates());
        } finally {
            static::__restore_seams();
        }
    }

    /** Production is a SEALED build - same rule, no exceptions. */
    public static function test_production_mode_never_runs_the_updates()
    {
        try {
            Rsx::_testing_set_mode(Rsx::MODE_PRODUCTION);
            Framework_Maintenance::$force_active_for_tests = false;

            static::__assert_false(\App\RSpade\Commands\Rsx\Manifest_Build_Command::__should_run_environment_updates());
        } finally {
            static::__restore_seams();
        }
    }

    /**
     * Maintenance suppresses the trigger even in development. This is the exactly-once
     * guarantee for a framework pull: the pull holds the window across its own rebuild and
     * runs post-update.sh directly afterwards.
     */
    public static function test_maintenance_window_suppresses_the_updates()
    {
        try {
            Rsx::_testing_set_mode(Rsx::MODE_DEVELOPMENT);
            Framework_Maintenance::$force_active_for_tests = true;

            static::__assert_false(\App\RSpade\Commands\Rsx\Manifest_Build_Command::__should_run_environment_updates());
        } finally {
            static::__restore_seams();
        }
    }

    /** A sealed build under maintenance is refused by BOTH gates. */
    public static function test_sealed_build_under_maintenance_is_refused()
    {
        try {
            Rsx::_testing_set_mode(Rsx::MODE_PRODUCTION);
            Framework_Maintenance::$force_active_for_tests = true;

            static::__assert_false(\App\RSpade\Commands\Rsx\Manifest_Build_Command::__should_run_environment_updates());
        } finally {
            static::__restore_seams();
        }
    }

    /** The seams really are restored - a later test must see the real environment. */
    public static function test_seams_restore_to_the_real_environment()
    {
        static::__restore_seams();

        static::__assert_null(Framework_Maintenance::$force_active_for_tests);
        static::__assert_equals(Rsx::MODE_DEVELOPMENT, Rsx::get_mode());
    }
}
