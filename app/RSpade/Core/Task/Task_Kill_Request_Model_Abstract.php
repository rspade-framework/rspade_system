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
 * Task_Kill_Request_Model - a force stop or force kill waiting for a kill worker
 * (_task_kill_requests).
 *
 * Killing a run is never done inside the request that asked for it: the request is recorded
 * here, bound to the host that runs the target's worker (a pid names a process on one machine
 * only), and a kill worker on that host (rsx:task:killer, the kill pool) carries it out. A
 * FORCE STOP's request is due after its grace period, during which the task may stop on its
 * own - the request is then MOOT; a FORCE KILL's is due at once. See Task_Kill_Worker.
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _task_kill_requests
 *
 * @property string $completed_at
 * @property string $created_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property string $explanation
 * @property string $host
 * @property int $id
 * @property string $kill_after_at
 * @property int $mode_id
 * @property string $outcome
 * @property int $requested_by_id
 * @property int $requested_by_type
 * @property int $status_id
 * @property int $target_pid
 * @property int $task_id
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property int $worker_generation
 * @property int $worker_id
 * @property int $worker_pid
 *
 * @property-read string $mode_id__label
 * @property-read string $mode_id__constant
 * @property-read string $status_id__label
 * @property-read string $status_id__constant
 *
 * @method static array mode_id__enum() Get all enum definitions with full metadata
 * @method static array mode_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array mode_id__enum_labels() Get simple id => label map
 * @method static array mode_id__enum_ids() Get array of all valid enum IDs
 * @method static array status_id__enum() Get all enum definitions with full metadata
 * @method static array status_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array status_id__enum_labels() Get simple id => label map
 * @method static array status_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
abstract class Task_Kill_Request_Model_Abstract extends Rsx_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const MODE_FORCE_STOP = 1;
    const MODE_FORCE_KILL = 2;
    const STATUS_PENDING = 1;
    const STATUS_CLAIMED = 2;
    const STATUS_DONE = 3;
    const STATUS_MOOT = 4;

    public static $realtime_silent = true;

    protected $table = '_task_kill_requests';
    protected $fillable = [];

    protected static $type_ref_columns = ['requested_by_type'];

    public static $enums = [
        'mode_id' => [
            1 => ['constant' => 'MODE_FORCE_STOP', 'label' => 'Force stop'],
            2 => ['constant' => 'MODE_FORCE_KILL', 'label' => 'Force kill'],
        ],
        'status_id' => [
            1 => ['constant' => 'STATUS_PENDING', 'label' => 'Pending'],
            2 => ['constant' => 'STATUS_CLAIMED', 'label' => 'Claimed'],
            // The worker was killed and the run settled KILLED.
            3 => ['constant' => 'STATUS_DONE', 'label' => 'Done'],
            // Nothing to kill: the run ended first, or runs where it may not be signalled.
            4 => ['constant' => 'STATUS_MOOT', 'label' => 'Moot'],
        ],
    ];

    /**
     * The run to be killed.
     */
    #[Relationship]
    public function task()
    {
        return $this->belongsTo(Task_Run_Model::class, 'task_id');
    }
}
