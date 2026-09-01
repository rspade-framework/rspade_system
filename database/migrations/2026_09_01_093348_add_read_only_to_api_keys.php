<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Use raw MySQL queries for clarity and auditability (DB::statement with raw SQL,
     * never Schema::table with Blueprint). Migrations must be self-contained.
     *
     * Adds read_only to _api_keys: a key carrying 1 may execute GET requests only, and
     * every non-GET request with it is refused 403 read_only_key by Api_Dispatcher.
     *
     * DEFAULT 0, so every key that already exists keeps exactly the authority it had -
     * a schema change must never quietly narrow a credential in service.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE _api_keys ADD COLUMN read_only TINYINT(1) NOT NULL DEFAULT 0");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
