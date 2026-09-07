<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\AuthGates\Php;

use App\RSpade\Core\Session\Session;

/**
 * Callable check bodies for the Auth_Gates evaluation tests.
 *
 * Deliberately NOT a Permission_Abstract descendant and deliberately NOT marked
 * #[Auth_Check]: the real staff/portal registries are built from the Permission
 * lineages, so these fixtures can never leak into an application's check
 * vocabulary. Tests reach them through Auth_Gates::__set_index_for_testing().
 *
 * The counter proves LIVE evaluation: the engine must run a body on every ask, so
 * the count rises once per consultation and never plateaus.
 *
 * grants_only_for_the_granted_user() is the identity-dependent body: it answers from
 * the session rather than from a constant, which is what makes an answer computed for
 * one identity observably wrong for the next.
 */
class Auth_Gates_Check_Fixture
{
    /** @var int Times count_calls() has executed. */
    public static $call_count = 0;

    /**
     * A check that grants.
     */
    public static function grants(): bool
    {
        return true;
    }

    /**
     * A check that denies.
     */
    public static function denies(): bool
    {
        return false;
    }

    /**
     * A body that returns a truthy NON-true value. The engine must treat it as a
     * denial (strict === true), which is what makes a forgotten return fail closed.
     */
    public static function returns_one()
    {
        return 1;
    }

    /**
     * A body that returns the STRING 'true'. Also a denial.
     */
    public static function returns_true_string()
    {
        return 'true';
    }

    /**
     * A body with no return statement at all. Also a denial.
     */
    public static function returns_nothing()
    {
    }

    /** @var int The one user id grants_only_for_the_granted_user() admits. */
    public const GRANTED_USER_ID = 90001;

    /**
     * A check whose answer depends on WHO is asking - the shape every real check has.
     */
    public static function grants_only_for_the_granted_user(): bool
    {
        return Session::get_user_id() === self::GRANTED_USER_ID;
    }

    /**
     * A granting check that records every execution.
     */
    public static function count_calls(): bool
    {
        static::$call_count++;

        return true;
    }

    /**
     * Reset the execution counter between tests.
     */
    public static function reset_counter(): void
    {
        static::$call_count = 0;
    }
}
