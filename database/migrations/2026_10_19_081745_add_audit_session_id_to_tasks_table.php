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
     * `audit_session_id` is the acting context a run was started from
     * (Session::get_audit_session_id() at dispatch: identity, tenant, impersonators, address,
     * user agent). A task declared #[Run_As_Dispatcher] executes under it, so what the run
     * writes is attributed exactly as an inline write in the dispatching request would be.
     * NULL for runs recorded before the column existed.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE _tasks ADD COLUMN audit_session_id BIGINT NULL DEFAULT NULL AFTER dispatched_by_type");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
