<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Use raw MySQL queries for clarity and auditability (DB::statement with raw SQL,
     * never Schema::create with Blueprint). Migrations must be self-contained.
     *
     * A RUNNING row names the worker that claimed it by rsx-lockd's pool identity: the
     * integer worker id (wid) together with the daemon generation that issued it. Neither
     * is meaningful alone - a wid is unique only within one daemon lifetime. worker_host
     * is the OS hostname of the claiming worker, so a host can settle a row from a
     * previous daemon generation by its own pid evidence, and only its own. worker_pid
     * stays for that probe, the local timeout kill and display.
     *
     * All three are NULL on a row no pool member claimed.
     *
     * No index: the reaper reads them only on RUNNING rows, which idx_tasks_claim selects.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE _tasks
                DROP COLUMN worker_member_key,
                ADD COLUMN worker_id BIGINT NULL
                    COMMENT 'rsx-lockd task pool worker id (wid) of the worker executing this task'
                    AFTER worker_pid,
                ADD COLUMN worker_generation BIGINT NULL
                    COMMENT 'rsx-lockd generation that issued worker_id'
                    AFTER worker_id,
                ADD COLUMN worker_host VARCHAR(255) NULL
                    COMMENT 'Hostname of the worker executing this task'
                    AFTER worker_generation
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
