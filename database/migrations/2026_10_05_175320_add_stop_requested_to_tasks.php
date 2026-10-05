<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A cooperative stop: Task::request_stop() sets stop_requested on a pending or running
     * row, and a task that checks Task_Instance::is_stop_requested() between units of work
     * ends early. Nothing reads it on the task's behalf. A recurring task's tracker row
     * clears it when the run it applied to ends.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement("ALTER TABLE tasks ADD COLUMN new_field VARCHAR(255)")
     * ❌ Schema::table() with Blueprint
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE _tasks
                ADD COLUMN stop_requested TINYINT(1) NOT NULL DEFAULT 0
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
