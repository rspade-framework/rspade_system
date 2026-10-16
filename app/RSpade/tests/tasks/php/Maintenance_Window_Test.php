<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use App\RSpade\Core\Task\Cron_Parser;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE MAINTENANCE WINDOW: the 'maintenance' schedule phrases resolve to the top of
 * rsx.tasks.maintenance_hour, and every FRAMEWORK task that runs once a day or less is
 * declared in that window with #[Exclusive('maintenance')].
 *
 * Re-registration when the hour changes is Task_Schedule_Reconcile_Test.
 */
class Maintenance_Window_Test extends Rsx_Test_Abstract
{
    // Pure computation over the manifest - no database access.
    protected static $use_database_transactions = false;

    private static function __with_hour($hour, callable $fn)
    {
        $original = config('rsx.tasks.maintenance_hour');
        config(['rsx.tasks.maintenance_hour' => $hour]);
        try {
            return $fn();
        } finally {
            config(['rsx.tasks.maintenance_hour' => $original]);
        }
    }

    private static function __resolve(string $phrase): string
    {
        return (new Cron_Parser($phrase, 'UTC'))->get_cron_expression();
    }

    public static function test_the_window_opens_at_2am_by_default()
    {
        static::__assert_equals(2, Cron_Parser::maintenance_hour());
        static::__assert_equals('0 2 * * *', static::__resolve('maintenance'));
    }

    public static function test_the_phrases_resolve_through_the_configured_hour()
    {
        static::__with_hour(4, function () {
            static::__assert_equals('0 4 * * *', static::__resolve('maintenance'));
            static::__assert_equals('0 4 * * 0', static::__resolve('maintenance weekly on sunday'));
            static::__assert_equals('0 4 * * 3', static::__resolve('Maintenance weekly on Wednesday'));
            static::__assert_equals('0 4 1 * *', static::__resolve('maintenance monthly'));
        });
    }

    public static function test_an_invalid_hour_throws_naming_the_setting()
    {
        foreach ([24, -1, '2', null] as $hour) {
            static::__with_hour($hour, function () {
                static::__assert_throws(\InvalidArgumentException::class, function () {
                    static::__resolve('maintenance');
                }, 'rsx.tasks.maintenance_hour');
            });
        }
    }

    public static function test_an_unknown_maintenance_phrase_throws()
    {
        static::__assert_throws(\InvalidArgumentException::class, function () {
            static::__resolve('maintenance fortnightly');
        });
    }

    public static function test_every_framework_task_running_daily_or_less_is_in_the_window()
    {
        $checked = 0;

        foreach (Task::get_scheduled_tasks() as $declaration) {
            $class = $declaration['class'];
            if (!str_starts_with($class, 'App\\RSpade\\') || str_starts_with($class, 'App\\RSpade\\Tests\\')) {
                continue;
            }

            // Daily or less often: a single fixed minute AND a single fixed hour.
            $fields = preg_split('/\s+/', static::__resolve($declaration['cron_expression']));
            if (!ctype_digit($fields[0]) || !ctype_digit($fields[1])) {
                continue;
            }

            $name = "{$class}::{$declaration['method']}";
            static::__assert_true(
                str_starts_with(strtolower($declaration['cron_expression']), 'maintenance'),
                "{$name} runs daily or less often, so it is scheduled in the maintenance window, not '{$declaration['cron_expression']}'"
            );
            static::__assert_equals(
                'maintenance',
                Task_Concurrency::group_of($class, $declaration['method']),
                "{$name} declares #[Exclusive('maintenance')]"
            );
            $checked++;
        }

        static::__assert_greater_than(0, $checked, 'the framework declares maintenance tasks');
    }
}
