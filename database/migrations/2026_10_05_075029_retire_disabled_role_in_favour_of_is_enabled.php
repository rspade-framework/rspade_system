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
     * users.role_id 800 ("Disabled") is no longer a role. Switching a member off is
     * users.is_enabled = 0, which the framework refuses at sign-in and on every request.
     * Every membership still holding 800 becomes a disabled membership at the lowest
     * real role, 700 (Viewer), so re-enabling it later grants the least access.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("UPDATE users SET is_enabled = 0, role_id = 700 WHERE role_id = 800");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
