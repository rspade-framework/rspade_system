<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Task\Task_Run_Model;

/**
 * Task_Schedule_Model - one declared #[Schedule] (_task_schedules).
 *
 * rsx:task:process reconciles these rows against the manifest every tick: a new declaration
 * gets a row, a changed cron expression is re-registered from scratch (its next_run_at
 * recomputed), a removed one is deleted. A scheduled-pool worker claims a due row by
 * advancing next_run_at and creating the run's _tasks row in one transaction, so a schedule
 * has a run history like any other task.
 *
 * The run statistics (last_success_at, last_error_at, last_error, consecutive_failures,
 * last_task_id) are written when a scheduled run settles. A schedule is never stopped by its
 * failures: each failing run is FAILED, and the schedule runs again at its next cadence.
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _task_schedules
 *
 * @property string $class
 * @property int $consecutive_failures
 * @property string $created_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property string $cron_expression
 * @property int $id
 * @property string $last_error
 * @property string $last_error_at
 * @property string $last_success_at
 * @property int $last_task_id
 * @property string $method
 * @property string $next_run_at
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
abstract class Task_Schedule_Model_Abstract extends Rsx_Model_Abstract
{
    // Infrastructure table, written by the scheduler; nothing subscribes to it.
    public static $realtime_silent = true;

    protected $table = '_task_schedules';
    protected $fillable = [];

    public static $enums = [];

    /**
     * The runs of this schedule.
     */
    #[Relationship]
    public function runs()
    {
        return $this->hasMany(Task_Run_Model::class, 'schedule_id');
    }
}
