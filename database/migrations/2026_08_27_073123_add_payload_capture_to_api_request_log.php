<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Use raw MySQL queries for clarity and auditability (DB::statement with raw SQL,
     * never Schema::create with Blueprint). Every table carries a signed BIGINT id
     * primary key. All integers are signed; TINYINT(1) is reserved for booleans.
     * Migrations must be self-contained.
     *
     * Extends _api_request_log from "what was called" to "what was exchanged":
     *
     *   request_body            the redacted, capped request payload (never for a
     *                           multipart/file upload - see Api_Dispatcher)
     *   response_error_code     the error envelope's code, NULL on a success
     *   response_error_message  the error envelope's message, NULL on a success
     *   response_bytes          size of the response body actually sent
     *
     * Plus an index on ip (the log is read by caller as well as by key and by date),
     * and the foreign key that makes a purged key take its request history with it.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE _api_request_log
                ADD COLUMN request_body LONGTEXT NULL DEFAULT NULL,
                ADD COLUMN response_error_code VARCHAR(64) NULL DEFAULT NULL,
                ADD COLUMN response_error_message TEXT NULL DEFAULT NULL,
                ADD COLUMN response_bytes BIGINT NOT NULL DEFAULT 0
        ");

        // Reading the log by caller is a first-class query (who hit us from where),
        // alongside the existing by-key and by-date indexes.
        DB::statement("
            ALTER TABLE _api_request_log
                ADD INDEX idx_api_request_log_ip (ip)
        ");

        // The FK below cannot be created while rows point at keys that no longer exist.
        // Those rows are the history of keys that were hard-deleted BEFORE the cascade
        // existed - exactly what the cascade would have removed - so removing them is the
        // backfill, not a workaround. Rows with a NULL api_key_id are pre-auth failures
        // and belong to no key; they are untouched, and the nullable FK permits them.
        DB::statement("
            DELETE FROM _api_request_log
            WHERE api_key_id IS NOT NULL
              AND api_key_id NOT IN (SELECT id FROM _api_keys)
        ");

        // Purging a key destroys its request history with it. Note this fires on a real
        // DELETE only: revoking a key sets _api_keys.is_revoked and keeps the row, so a
        // revoked key's history survives - which is the point of revoking rather than
        // purging.
        DB::statement("
            ALTER TABLE _api_request_log
                ADD CONSTRAINT fk_api_request_log_api_key_id
                FOREIGN KEY (api_key_id) REFERENCES _api_keys (id)
                ON DELETE CASCADE
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
