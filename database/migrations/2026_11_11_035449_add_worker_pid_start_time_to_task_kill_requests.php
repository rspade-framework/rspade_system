<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * _task_kill_requests.worker_pid_start_time: the kernel start time (field 22 of
 * /proc/<pid>/stat, clock ticks since boot) of the process recorded in worker_pid, written with
 * it when a kill request is claimed. A pid alone names no process once it has been reused; the
 * pair does (Process_Identity), so a claim is judged live only while both still match.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE _task_kill_requests ADD COLUMN worker_pid_start_time BIGINT NULL DEFAULT NULL AFTER worker_pid");
    }
};
