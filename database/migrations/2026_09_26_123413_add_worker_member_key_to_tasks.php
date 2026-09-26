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
     * worker_member_key is the rsx-lockd task pool member id of the worker that claimed a
     * RUNNING row. The stuck-task reaper asks the daemon whether that member is still in the
     * pool, which answers "is this row's worker alive?" on every host; worker_pid stays for
     * the local timeout kill and for display. NULL on a row no pool member claimed.
     *
     * Named _key and not _id because SCHEMA-TYPE-01 reserves an _id suffix for integers, and
     * this is an opaque string handle minted by the daemon.
     *
     * No index: the reaper reads it only on RUNNING rows, which idx_tasks_claim selects.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE _tasks
                ADD COLUMN worker_member_key VARCHAR(64) NULL
                    COMMENT 'rsx-lockd task pool member id of the worker executing this task'
                    AFTER worker_pid
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
