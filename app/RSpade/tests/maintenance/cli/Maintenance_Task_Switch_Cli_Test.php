<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Maintenance\Cli;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The maintenance window turns the task system off on the way in and back on on the way out
 * (bin/maintenance-mode.sh calling `rsx:tasks:disable` and `rsx:tasks:enable`).
 *
 * STRUCTURAL, for the reason Maintenance_Rpc_Quiesce_Cli_Test gives: both calls live in the
 * services half of the script, and running that half on a development box stops php-fpm,
 * redis and the lock daemon. What the two commands DO is Task_System_Switch_Test; what is
 * pinned here is that the script calls them, where the sequence requires, and that a failure
 * to turn the task system back on is loud and never fails the transition.
 */
class Maintenance_Task_Switch_Cli_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __function_body(string $name): string
    {
        $source = (string) file_get_contents(base_path('bin/maintenance-mode.sh'));

        $start = strpos($source, $name . '() {');
        static::__assert_true($start !== false, "{$name}() must exist");
        $end = strpos($source, "\n}\n", $start);
        static::__assert_true($end !== false, "{$name}() must be closed");

        return substr($source, $start, $end - $start);
    }

    /**
     * Entering: the flag first, then - in the services half only - the task system off and its
     * running tasks stopped, before any service is stopped (the command needs the database
     * and signals this host's workers).
     */
    public static function test_enable_turns_the_task_system_off_before_stopping_services()
    {
        $body = static::__function_body('do_enable');

        $flag = strpos($body, '> "$FLAG"');
        $services_gate = strpos($body, '[ "$SERVICES" = true ] || return 0');
        $disable = strpos($body, 'rsx:tasks:disable');
        $first_stop = strpos($body, "stop_unit '^realtime'");

        static::__assert_true($flag !== false && $services_gate !== false && $disable !== false && $first_stop !== false, 'the four markers exist');
        static::__assert_true($flag < $disable, 'the flag is raised before the task system is turned off');
        static::__assert_true($services_gate < $disable, '--no-services touches only the flag');
        static::__assert_true($disable < $first_stop, 'the tasks are stopped before the first service is');

        static::__assert_contains('--reason="maintenance mode: $REASON"', $body, 'the switch records why');
        static::__assert_contains('--_framework-update-override', $body, 'the call is immune to the gate');

        // Best-effort: every exit code of the command is answered, none ends the transition.
        static::__assert_contains('case "$?" in', $body);
        static::__assert_contains('could not turn the task system off (continuing)', $body);
        static::__assert_false(str_contains(substr($body, $disable, $first_stop - $disable), 'exit 1'), 'a failed stop never fails the transition');
    }

    /**
     * Leaving: the task system is turned back on after the services are started and the flag
     * is gone, in the services half only, and a failure says so on stderr without failing the
     * transition.
     */
    public static function test_disable_turns_the_task_system_back_on_and_is_loud_when_it_cannot()
    {
        $body = static::__function_body('do_disable');

        $last_start = strpos($body, "start_unit '^fpc'");
        $flag_removed = strpos($body, 'rm -f "$FLAG"');
        $enable = strpos($body, 'rsx:tasks:enable ||');
        $done = strpos($body, 'ok "Maintenance mode OFF."');

        static::__assert_true($last_start !== false && $flag_removed !== false && $enable !== false && $done !== false, 'the four markers exist');
        static::__assert_true($last_start < $enable, 'the services are back first');
        static::__assert_true($flag_removed < $enable, 'the flag is gone first: the command runs as an ordinary process');
        static::__assert_true($enable < $done, 'before the transition reports done');

        $tail = substr($body, $flag_removed, $done - $flag_removed);
        static::__assert_contains('if [ "$SERVICES" = true ]; then', substr($tail, strpos($tail, 'Remove it by hand')), 'the services half only');

        $failure = substr($body, $enable, $done - $enable);
        static::__assert_contains('err "The task system could NOT be turned back on', $failure, 'loud, on stderr');
        static::__assert_contains('php artisan rsx:tasks:enable', $failure, 'naming the command');
        static::__assert_false(str_contains($failure, 'exit 1'), 'and never fails the transition');
    }
}
