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
     * `timezone` is the IANA zone a schedule's next_run_at was computed in (the server
     * timezone at registration). The scheduler re-registers a schedule whose zone no longer
     * matches the configured one, so changing RSX_SERVER_TIMEZONE never leaves a run queued
     * on the old wall clock. Every existing row was computed in UTC, which the temporary
     * default records; the default is then dropped so every later insert names its zone.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE _task_schedules ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'UTC' AFTER cron_expression");
        DB::statement("ALTER TABLE _task_schedules ALTER COLUMN timezone DROP DEFAULT");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
