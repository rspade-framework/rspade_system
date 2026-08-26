<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement("ALTER TABLE users ADD COLUMN new_field VARCHAR(255)")
     * ❌ Schema::table() with Blueprint
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        // Whether this user may hold usable API keys at all.
        //
        // DEFAULT 1 so every existing user keeps the access they have today: this column is
        // being added to a live table, and defaulting it off would silently break every
        // integration already running.
        DB::statement("
            ALTER TABLE users
            ADD COLUMN is_api_access_enabled TINYINT(1) NOT NULL DEFAULT 1
        ");
    }
};
