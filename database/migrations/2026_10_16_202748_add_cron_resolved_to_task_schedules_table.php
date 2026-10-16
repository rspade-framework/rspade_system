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
     * `cron_resolved` is the standard five-field expression a schedule's next_run_at was
     * computed from. A phrase can resolve differently over time - "maintenance" names the
     * hour rsx.tasks.maintenance_hour holds - so the scheduler compares it on every tick and
     * re-registers a schedule whose resolution moved. NULL until the first tick records it.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE _task_schedules ADD COLUMN cron_resolved VARCHAR(100) NULL DEFAULT NULL AFTER cron_expression");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
