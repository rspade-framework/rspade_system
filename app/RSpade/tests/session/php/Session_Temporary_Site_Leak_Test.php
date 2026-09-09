<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A declared tenant must NOT survive the test that declared it.
 *
 * THE DEFECT THIS PINS. Session::set_temporary_site_id() DECLARES a tenant for the rest
 * of the script, and it deliberately OUTRANKS every other tier - the CLI declaration
 * included (Session_Temporary_Site_Test::test_it_outranks_a_declared_cli_context). That
 * is correct for its real callers, which are a web request or a one-shot command whose
 * script ends moments later. Rsx_Initial_User::create() is one of them and says so:
 * "It is NOT cleared afterwards, deliberately".
 *
 * A TEST RUN IS NOT THAT SCRIPT. The runner creates the baseline user in-process
 * (seed_test_baseline_user -> Rsx_Initial_User::create), and re-creates it whenever a
 * $requires_db_reset class re-provisions. Each of those leaves a declaration behind in a
 * process that then runs every remaining class. Because the declaration outranks the CLI
 * tier, __acting_as_site() still WRITES but get_site_id() keeps answering the baseline
 * site - so a fixture seeded "in another site" silently lands in the baseline one and
 * every cross-tenant assertion after it is vacuous rather than failing.
 *
 * It surfaced as two tests that pass alone and fail in the suite, with the failure moving
 * between classes as container scheduling changed: Example_Test::test_acting_as_site
 * ("Expected '42' but got '1'") and Revision_History_Test's cross-site case, which found
 * a record it had seeded into another site.
 *
 * reset_impersonation() already states the rule - "A declared tenant is script-scoped, and
 * for a test the SCRIPT is the test: leaving it set would leak the declaration into every
 * later test in the run" - but that runs only when a test calls __reset_session() itself.
 * Session::_testing_reset(), which the runner runs after EVERY test, is where the rule has
 * to hold, and this pins it there.
 *
 * THE TWO METHODS ARE ONE TEST, and their ORDER is the mechanism: the first leaves a
 * declaration behind exactly as the baseline seed does, the second proves the runner's
 * per-test reset cleared it. Reflection returns methods in declaration order, so the
 * names are prefixed to keep that visible.
 */
class Session_Temporary_Site_Leak_Test extends Rsx_Test_Abstract
{
    /**
     * Leave a declaration behind, and clean nothing up - the shape
     * Rsx_Initial_User::create() legitimately leaves in the process.
     */
    public static function test_a_declaration_left_behind_by_a_test()
    {
        Session::set_temporary_site_id(1);

        static::__assert_true(
            Session::has_temporary_site_id(),
            'the declaration is in force inside the test that made it'
        );
    }

    /**
     * The next test must start with no declaration in force, and __acting_as_site()
     * must therefore mean something again.
     */
    public static function test_b_the_next_test_does_not_inherit_it()
    {
        static::__assert_false(
            Session::has_temporary_site_id(),
            'a tenant declared by the previous test leaked into this one - the runner\'s '
            . 'per-test Session::_testing_reset() must clear it'
        );

        static::__acting_as_site(42);

        static::__assert_equals(
            42,
            Session::get_site_id(),
            'acting as a site is silently ineffective while a leaked declaration outranks it'
        );
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }
}
