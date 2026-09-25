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
     * _api_request_log.api_key_id becomes ON DELETE SET NULL. The request log is the audit
     * record of what a key did; purging the key (rsx:api:key:delete --purge) now keeps
     * that record, with api_key_id cleared, instead of erasing it. The rows still carry
     * user_id, site_id, handler, ip and time, and are pruned by retention like any other.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE _api_request_log
                DROP FOREIGN KEY fk_api_request_log_api_key_id
        ");

        DB::statement("
            ALTER TABLE _api_request_log
                ADD CONSTRAINT fk_api_request_log_api_key_id
                    FOREIGN KEY (api_key_id) REFERENCES _api_keys (id) ON DELETE SET NULL
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
