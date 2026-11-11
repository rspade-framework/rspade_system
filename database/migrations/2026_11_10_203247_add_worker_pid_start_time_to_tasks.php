<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * _tasks.worker_pid_start_time: the kernel start time (field 22 of /proc/<pid>/stat, clock
 * ticks since boot) of the process recorded in worker_pid, written with it when a run starts.
 * A pid alone names no process once it has been reused; the pair does (Process_Identity).
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE _tasks ADD COLUMN worker_pid_start_time BIGINT NULL DEFAULT NULL AFTER worker_pid");
    }
};
