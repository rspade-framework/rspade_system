<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\InitialUser\Php;

use App\RSpade\Core\Env\Rsx_First_User_Setup;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The first-run screen's "Add sample data" option, driven by
 * config('rsx.first_run.sample_data_task') alone: offered only while the key names a task,
 * and on submit the named task is dispatched for the initial site. A name that resolves to no
 * task is reported on the success page, never as a failed account creation.
 *
 * The option's subject is the CONFIG seam, not any application's seeder, so the task named
 * here is a framework one (Mail_Queue_Service::send_pending_queue) and nothing it does is
 * asserted - only that it was queued with the site.
 */
class First_User_Setup_Sample_Data_Test extends Rsx_Test_Abstract
{
    private static function __with_task(?string $task, callable $fn)
    {
        $previous = config('rsx.first_run.sample_data_task');
        config(['rsx.first_run.sample_data_task' => $task]);

        try {
            return $fn();
        } finally {
            config(['rsx.first_run.sample_data_task' => $previous]);
        }
    }

    /** The form's HTML. */
    private static function __form_html(): string
    {
        return Rsx_First_User_Setup::form_body(str_repeat('ab', 16));
    }

    public static function test_the_option_follows_the_config_key()
    {
        foreach ([null, '', '   '] as $value) {
            static::__with_task($value, function () use ($value) {
                static::__assert_null(Rsx_First_User_Setup::sample_data_task(), 'no task for ' . json_encode($value));
                static::__assert_false(str_contains(static::__form_html(), 'rsx_sample_data'), 'and no checkbox for ' . json_encode($value));
            });
        }

        static::__with_task('Some_Service::seed', function () {
            static::__assert_equals('Some_Service::seed', Rsx_First_User_Setup::sample_data_task());
            static::__assert_contains('name="rsx_sample_data"', static::__form_html(), 'a named task shows the checkbox');
        });
    }

    public static function test_a_ticked_option_dispatches_the_named_task_for_the_initial_site()
    {
        $queue = new \ReflectionMethod(Rsx_First_User_Setup::class, '__queue_sample_data');

        $note = static::__with_task('Mail_Queue_Service::send_pending_queue', fn () => $queue->invoke(null));

        static::__assert_contains('being added', $note);
        $run = Task_Run_Model::query()->orderBy('id', 'desc')->first();
        static::__assert_equals('Mail_Queue_Service', $run->service_name());
        static::__assert_equals('Mail_Queue_Service::send_pending_queue', $run->task_name());
        static::__assert_true(isset(($run->params ?? [])['site']), 'queued with the initial site: ' . json_encode($run->params));
    }

    public static function test_a_task_that_does_not_exist_is_reported_not_thrown()
    {
        $queue = new \ReflectionMethod(Rsx_First_User_Setup::class, '__queue_sample_data');

        $note = static::__with_task('No_Such_Service::seed', fn () => $queue->invoke(null));

        static::__assert_contains('could not be queued', $note);
        static::__assert_contains('No_Such_Service::seed', $note);
    }
}
