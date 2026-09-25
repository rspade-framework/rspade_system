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
     * _sessions: no query leads with `active` or with `last_active` alone - every active = 1
     * predicate is paired with the session token or an identity, which have their own indexes,
     * and the retention sweep reads (type_id, last_active). handoff_token is a single-use
     * token looked up as exactly one row, so it becomes UNIQUE (NULLs remain allowed).
     *
     * _flash_alerts: every page render and every Ajax response reads session_id = ? AND
     * is_portal = ? ORDER BY created_at, so (session_id, is_portal, created_at) replaces
     * (session_id). session_id still leads, so the session foreign key binds to it.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE _sessions
                DROP INDEX sessions_active_index,
                DROP INDEX sessions_active_last_active_index,
                DROP INDEX sessions_last_active_index,
                DROP INDEX idx_sessions_handoff,
                ADD UNIQUE KEY uk_sessions_handoff_token (handoff_token)
        ");

        DB::statement("
            ALTER TABLE _flash_alerts
                DROP INDEX idx_session_id,
                ADD INDEX idx_flash_alerts_session (session_id, is_portal, created_at)
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
