<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Sys\Tasks\_Sys_Oregon_Trail_Service;
use App\RSpade\Sys\App\Sys\Tasks\_Sys_Tasks_Controller;

/**
 * The task system's sample task (_Sys_Oregon_Trail_Service::travel) and the Tasks screen's
 * button that starts it.
 *
 * Proves: played at speed 0 the script runs to Oregon with every report set - the status,
 * full progress, 14 of 14 landmarks, the wagon state with two deaths, an empty list ahead,
 * the messages, stdout and stderr, both attachments and the summary; asked to stop before
 * it starts, it makes camp at once and settles STOPPED with the journal attached; and
 * start_sample queues one run of it at full speed.
 */
class Sys_Oregon_Trail_Sample_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        static::__acting_as_user(1);
    }

    /** RP-SAMPLE-01 - the full script, at speed 0, ends in Oregon with every report set */
    public static function test_the_full_script_sets_every_report()
    {
        $run = Task::internal('_Sys_Oregon_Trail_Service', 'travel', ['speed' => 0]);

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id);
        static::__assert_equals('Arrived in Oregon', $run->status_text());
        static::__assert_equals(100.0, $run->progress_percent());
        static::__assert_equals(['done' => 14, 'total' => 14], $run->progress_count());
        static::__assert_not_null($run->eta_at());
        static::__assert_equals([], $run->queue(), 'no landmark left ahead');

        $state = $run->state();
        static::__assert_equals('dead', $state['party']['Cornelius']);
        static::__assert_equals('dead', $state['party']['Prudence']);
        static::__assert_equals('good', $state['party']['Ezekiel']);
        static::__assert_equals(2040, $state['miles']);

        $messages = array_column($run->messages_after(null), 'body');
        static::__assert_true(in_array('Cornelius has died of dysentery.', $messages, true));
        static::__assert_true(in_array('Prudence has died of dysentery.', $messages, true));
        static::__assert_contains('DeLorean', implode("\n", $messages));

        $stdout = implode("\n", array_column($run->output_after(null, ['stdout']), 'line'));
        $stderr = implode("\n", array_column($run->output_after(null, ['stderr']), 'line'));
        static::__assert_contains('THE OREGON TRAIL', $stdout);
        static::__assert_contains('THE END', $stdout);
        static::__assert_contains('! Prudence has died of dysentery.', $stderr);

        static::__assert_equals(['epitaphs.txt', 'trail_journal.txt'], array_map(fn ($a) => $a->name, $run->attachments()));
        static::__assert_contains('He said the water was fine', $run->attachment('epitaphs.txt')->read_bytes());
        static::__assert_contains('Reached the Willamette Valley', (string) $run->summary());
    }

    /** RP-SAMPLE-02 - a stop requested before the first beat makes camp at once: STOPPED, journal attached */
    public static function test_a_graceful_stop_makes_camp()
    {
        $id = Task_Runner::insert_row(_Sys_Oregon_Trail_Service::class, 'travel', ['speed' => 0], Task_Run_Model::ORIGIN_INLINE, Task_Runner::running_fields());
        Task_Run_Model::find($id)->request_stop('test');
        $instance = Task_Instance::find($id);

        Task_Runner::settle($instance, Task_Runner::execute($instance, false));

        $run = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::STATUS_STOPPED, (int) $run->status_id);
        static::__assert_contains('Stopped on request at mile 0', (string) $run->summary());
        static::__assert_not_null($run->attachment('trail_journal.txt'));
        static::__assert_contains('makes camp', implode("\n", array_column($run->output_after(null, ['stdout']), 'line')));
    }

    /** RP-SAMPLE-03 - start_sample queues one pending run of the sample task, at full speed (no params) */
    public static function test_start_sample_queues_a_run()
    {
        $response = _Sys_Tasks_Controller::start_sample(Request::create('/'), []);

        $run = Task_Run_Model::find($response['task_id']);
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) $run->status_id);
        static::__assert_equals('_Sys_Oregon_Trail_Service::travel', $run->task_name());
        static::__assert_equals([], $run->params, 'the button plays the live three-minute game');
    }
}
