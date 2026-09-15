<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use ReflectionMethod;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Pins the POSITION of every migrate.normalize_schema.complete firing.
 *
 * The event's whole value is where it fires, and the position is a documented contract
 * (rsx:man migrations, POST-NORMALIZATION APP STEPS; rsx:man event_hooks, Migrate
 * Events). One method owns the dispatch - Maint_Migrate::fire_post_normalize_hook() -
 * and it is called from THREE places, one per normalize pass:
 *
 *   - after the PRE-migration pass in execute_migrations();
 *   - after each per-migration pass in run_migrations_with_normalization();
 *   - after the POST-migration pass in execute_migrations().
 *
 * Within execute_migrations() the final firing must still land AFTER the post-migration
 * migrate:normalize_schema call, BEFORE create_initial_user_if_needed() (so an app's
 * columns exist before the first row is written and before user.initial.created handlers
 * run) and BEFORE build_revision_dictionary_if_stale() (which derives itself from
 * information_schema and would otherwise describe a schema missing those columns).
 *
 * Reordering any of those silently breaks applications with no error to show for it, so
 * it is pinned by source structure the way Normalize_Schema_Rollback_Test pins the
 * normalize command's catch path. Post_Normalize_Hook_Per_Migration_Test proves the
 * per-run firing COUNT against a real migration run; this class proves the ORDER. No
 * migration is executed and no DDL is issued.
 *
 * No database access - skip the per-test transaction.
 */
class Migrate_Normalize_Complete_Event_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // Full class name as a string so the reference survives the linter's unused-import
    // pruning (a bare ::class of an imported class reads as an unused use statement).
    const COMMAND_CLASS = 'App\\RSpade\\Commands\\Migrate\\Maint_Migrate';

    const EVENT_NAME = 'migrate.normalize_schema.complete';

    /** The one method that owns the dispatch. */
    const DISPATCHER = 'fire_post_normalize_hook';

    /**
     * Read the source of one method of the migrate command.
     */
    protected static function __method_source(string $method_name): string
    {
        $method = new ReflectionMethod(self::COMMAND_CLASS, $method_name);
        $file_lines = file($method->getFileName());

        $start = $method->getStartLine() - 1;
        $length = $method->getEndLine() - $method->getStartLine() + 1;

        return implode('', array_slice($file_lines, $start, $length));
    }

    /**
     * Read the source of execute_migrations() - the method that owns the ordering.
     */
    protected static function __execute_migrations_source(): string
    {
        return static::__method_source('execute_migrations');
    }

    /**
     * Byte offset of a needle in execute_migrations(), asserted present.
     */
    protected static function __offset_of(string $source, string $needle, string $what): int
    {
        $position = strpos($source, $needle);
        static::__assert_true($position !== false, $what . ' must appear in execute_migrations()');

        return $position;
    }

    public static function test_trigger_is_present_with_an_empty_payload()
    {
        $source = static::__method_source(self::DISPATCHER);

        static::__assert_true(
            strpos($source, "trigger_action('" . self::EVENT_NAME . "', [])") !== false,
            self::DISPATCHER . '() fires ' . self::EVENT_NAME . ' as an action with an empty payload'
        );
    }

    /**
     * ONE dispatcher. A second hand-written trigger_action() for this event somewhere in
     * the command would be a second contract nobody updated.
     */
    public static function test_the_dispatch_lives_in_exactly_one_method()
    {
        $reflector = new \ReflectionMethod(self::COMMAND_CLASS, self::DISPATCHER);
        $whole_file = file_get_contents($reflector->getFileName());

        static::__assert_equals(
            1,
            substr_count($whole_file, "trigger_action('" . self::EVENT_NAME . "'"),
            'the event is dispatched from exactly one place - ' . self::DISPATCHER . '()'
        );
    }

    /**
     * Three call sites, one per normalize pass. The COUNT is the contract the man page
     * states as "N+1 firings for N pending migrations".
     */
    public static function test_the_dispatcher_is_called_after_every_normalize_pass()
    {
        $call = '$this->' . self::DISPATCHER . '()';

        $execute = static::__execute_migrations_source();
        static::__assert_equals(
            2,
            substr_count($execute, $call),
            'execute_migrations() fires the hook twice - after the pre-migration pass and after the post-migration pass'
        );

        $loop = static::__method_source('run_migrations_with_normalization');
        static::__assert_equals(
            1,
            substr_count($loop, $call),
            'run_migrations_with_normalization() fires the hook after each per-migration normalize pass'
        );
    }

    /**
     * The mid-loop firing follows the normalize call it belongs to, and follows the
     * exit-code check - the hook runs when the pass SUCCEEDED.
     */
    public static function test_the_mid_loop_firing_follows_its_normalize_pass()
    {
        $loop = static::__method_source('run_migrations_with_normalization');

        $normalize = static::__offset_of($loop, "'migrate:normalize_schema'", 'the mid-loop normalize call');
        $guard = static::__offset_of($loop, 'Normalization failed after migration', 'the mid-loop failure throw');
        $fire = static::__offset_of($loop, self::DISPATCHER . '()', 'the mid-loop hook firing');

        static::__assert_true($fire > $normalize, 'the hook fires AFTER the mid-loop normalize call');
        static::__assert_true($fire > $guard, 'the hook fires only once the pass has been checked for failure');
    }

    /**
     * The pre-migration firing follows the pre-migration normalize and precedes the
     * migrations themselves.
     */
    public static function test_the_pre_migration_firing_precedes_the_migrations()
    {
        $source = static::__execute_migrations_source();

        $pre_normalize = static::__offset_of($source, 'Pre-migration normalization failed', 'the pre-migration failure branch');
        $fire = static::__offset_of($source, self::DISPATCHER . '()', 'the first hook firing');
        $run = static::__offset_of($source, 'run_migrations_with_normalization(', 'the migration loop');

        static::__assert_true($fire > $pre_normalize, 'the first firing follows the pre-migration normalize pass');
        static::__assert_true($fire < $run, 'the first firing precedes the migration loop');
    }

    public static function test_trigger_fires_after_the_post_migration_normalize()
    {
        $source = static::__execute_migrations_source();

        // The LAST migrate:normalize_schema reference in the method is the post-migration
        // pass (the mid-loop one lives in run_migrations_with_normalization()).
        $normalize = strrpos($source, "'migrate:normalize_schema'");
        static::__assert_true($normalize !== false, 'the post-migration normalize call must appear in execute_migrations()');

        $trigger = strrpos($source, self::DISPATCHER . '()');
        static::__assert_true($trigger !== false, 'the hook firing must appear in execute_migrations()');

        static::__assert_true(
            $trigger > $normalize,
            'the final firing is AFTER the post-migration migrate:normalize_schema call'
        );
    }

    public static function test_trigger_fires_before_the_initial_user()
    {
        $source = static::__execute_migrations_source();

        $trigger = strrpos($source, self::DISPATCHER . '()');
        $initial_user = static::__offset_of($source, 'create_initial_user_if_needed(', 'the initial-user step');

        static::__assert_true(
            $trigger < $initial_user,
            'the final firing is BEFORE create_initial_user_if_needed()'
        );
    }

    public static function test_trigger_fires_before_the_revision_dictionary()
    {
        $source = static::__execute_migrations_source();

        $trigger = strrpos($source, self::DISPATCHER . '()');
        $dictionary = static::__offset_of($source, 'build_revision_dictionary_if_stale(', 'the revision-dictionary step');

        static::__assert_true(
            $trigger < $dictionary,
            'the final firing is BEFORE build_revision_dictionary_if_stale()'
        );
    }

    public static function test_handler_exceptions_are_not_caught()
    {
        $source = static::__execute_migrations_source();

        // The dispatcher itself must not catch, and neither must the span from the final
        // firing to the initial-user step: a throwing handler has to propagate so the
        // snapshot owner rolls the run back.
        static::__assert_true(
            strpos(static::__method_source(self::DISPATCHER), 'catch') === false,
            self::DISPATCHER . '() does not catch - a throwing handler must roll the migration back'
        );

        $trigger = strrpos($source, self::DISPATCHER . '()');
        $initial_user = static::__offset_of($source, 'create_initial_user_if_needed(', 'the initial-user step');

        $between = substr($source, $trigger, $initial_user - $trigger);

        static::__assert_true(
            strpos($between, 'catch') === false,
            'nothing catches around the final firing'
        );
    }
}
