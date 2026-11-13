<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * _tasks.worker_seen_at: when the run's own host last found the run's process alive. Every
 * rsx:task:process tick stamps it (database clock) on each RUNNING run it judged alive by
 * evidence on its host; another host's tick reads it, with the run's own signs of life, to
 * settle a run whose host has vanished (rsx.tasks.vanished_host_timeout). Cleared with the
 * other worker columns. No index: it is read only on RUNNING rows.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE _tasks ADD COLUMN worker_seen_at TIMESTAMP(3) NULL DEFAULT NULL AFTER worker_host");
    }
};
