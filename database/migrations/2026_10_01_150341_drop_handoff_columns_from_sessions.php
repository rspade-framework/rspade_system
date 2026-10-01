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
     * "View as Client" no longer mints a throwaway _sessions row carrying a single-use
     * handoff token. The same-host layout writes the impersonation onto the browser's own
     * row, and a portal on its own host links to that row through the one-time codes in
     * _session_links. handoff_token / handoff_expires_at (and the unique key over the
     * token) have no writer and no reader; any unclaimed handoff row is litter.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("DELETE FROM _sessions WHERE handoff_token IS NOT NULL");

        DB::statement("
            ALTER TABLE _sessions
                DROP INDEX uk_sessions_handoff_token,
                DROP COLUMN handoff_token,
                DROP COLUMN handoff_expires_at
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
