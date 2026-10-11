<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The task system's off switch: ONE row (id 1) exists while the task system is disabled,
 * and no row exists while it is enabled. Stored in the database so the switch is a single
 * fact for every host that shares it.
 *
 * The row records the disabling: when, who asked (a printed name, or "the command line"),
 * the reason given, and the host the command ran on. Written by Task::disable(), removed by
 * Task::enable(), read by Task::disabled_state() (rsx:man tasks, TURNING THE TASK SYSTEM
 * OFF AND ON).
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE _task_system_off (
                id BIGINT NOT NULL PRIMARY KEY,
                disabled_at TIMESTAMP(3) NOT NULL,
                actor_label VARCHAR(255) NOT NULL,
                reason TEXT NULL,
                host VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
};
