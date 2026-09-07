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
 * Pins the POSITION of the migrate.normalize_schema.complete trigger inside
 * Maint_Migrate::execute_migrations().
 *
 * The event's whole value is where it fires, and the position is a documented contract
 * (rsx:man migrations, POST-NORMALIZATION APP STEPS; rsx:man event_hooks, Migrate
 * Events):
 *
 *   - AFTER the POST-migration migrate:normalize_schema call, so a handler sees the
 *     schema at its framework-normalized tip;
 *   - BEFORE create_initial_user_if_needed(), so an app's columns exist before the
 *     first row is written and before user.initial.created handlers run;
 *   - BEFORE build_revision_dictionary_if_stale(), which derives itself from
 *     information_schema and would otherwise describe a schema missing those columns.
 *
 * Reordering any of those silently breaks applications with no error to show for it, so
 * it is pinned by source structure the way Normalize_Schema_Rollback_Test pins the
 * normalize command's catch path. No migration is executed and no DDL is issued.
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

    /**
     * Read the source of execute_migrations() - the method that owns the ordering.
     */
    protected static function __execute_migrations_source(): string
    {
        $method = new ReflectionMethod(self::COMMAND_CLASS, 'execute_migrations');
        $file_lines = file($method->getFileName());

        $start = $method->getStartLine() - 1;
        $length = $method->getEndLine() - $method->getStartLine() + 1;

        return implode('', array_slice($file_lines, $start, $length));
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
        $source = static::__execute_migrations_source();

        static::__assert_true(
            strpos($source, "trigger_action('" . self::EVENT_NAME . "', [])") !== false,
            'execute_migrations() fires ' . self::EVENT_NAME . ' as an action with an empty payload'
        );
    }

    public static function test_trigger_fires_after_the_post_migration_normalize()
    {
        $source = static::__execute_migrations_source();

        // The LAST migrate:normalize_schema reference in the method is the post-migration
        // pass (the mid-loop one lives in run_migrations_with_normalization()).
        $normalize = strrpos($source, "'migrate:normalize_schema'");
        static::__assert_true($normalize !== false, 'the post-migration normalize call must appear in execute_migrations()');

        $trigger = static::__offset_of($source, self::EVENT_NAME, 'the event trigger');

        static::__assert_true(
            $trigger > $normalize,
            'the event fires AFTER the post-migration migrate:normalize_schema call'
        );
    }

    public static function test_trigger_fires_before_the_initial_user()
    {
        $source = static::__execute_migrations_source();

        $trigger = static::__offset_of($source, self::EVENT_NAME, 'the event trigger');
        $initial_user = static::__offset_of($source, 'create_initial_user_if_needed(', 'the initial-user step');

        static::__assert_true(
            $trigger < $initial_user,
            'the event fires BEFORE create_initial_user_if_needed()'
        );
    }

    public static function test_trigger_fires_before_the_revision_dictionary()
    {
        $source = static::__execute_migrations_source();

        $trigger = static::__offset_of($source, self::EVENT_NAME, 'the event trigger');
        $dictionary = static::__offset_of($source, 'build_revision_dictionary_if_stale(', 'the revision-dictionary step');

        static::__assert_true(
            $trigger < $dictionary,
            'the event fires BEFORE build_revision_dictionary_if_stale()'
        );
    }

    public static function test_handler_exceptions_are_not_caught()
    {
        $source = static::__execute_migrations_source();

        // Everything from the trigger to the initial-user step must be free of a catch:
        // a throwing handler has to propagate so the snapshot owner rolls the run back.
        $trigger = static::__offset_of($source, self::EVENT_NAME, 'the event trigger');
        $initial_user = static::__offset_of($source, 'create_initial_user_if_needed(', 'the initial-user step');

        $between = substr($source, $trigger, $initial_user - $trigger);

        static::__assert_true(
            strpos($between, 'catch') === false,
            'nothing catches around the trigger - a throwing handler must roll the migration back'
        );
    }
}
