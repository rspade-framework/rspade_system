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
     * The writer request history on _transactions (Core/Revisions/Request_History). Every
     * writer request that commits has its _transactions row; these columns record what it
     * was sent and, when the client named the request with _request_ref, what it answered,
     * so a retry of the same request is answered from the record instead of running again.
     *
     *   request_ref          the client's _request_ref (at most 128 characters); NULL when
     *                        none was sent or the request was not recorded as replayable
     *   request_fingerprint  sha256 hex of the canonical JSON of the validated params
     *   request_payload      what was sent, redacted and capped (pruned)
     *   response_status      the HTTP status the response was sent with (pruned)
     *   response_body        the response as sent, JSON (pruned)
     *   duration_ms          how long the request took to the moment it was recorded
     *   completed_at         when it was recorded (UTC)
     *
     * The three pruned columns are nulled past rsx.request_history.payload_retention_days by
     * Revision_Cleanup_Service::prune_request_payloads(). request_history_held is a VIRTUAL
     * column true while any of them still holds a value; its index lets the prune find
     * exactly the rows it still owes work on, so a daily run never re-reads the years of
     * rows it already nulled.
     *
     * The replay identity is site + actor + reference. The UNIQUE index enforces it for
     * every row with a reference and a site; MySQL lets any number of rows share NULLs, so
     * rows without a reference are unaffected. A request on site 0 is stored with a NULL
     * site_id (the transaction convention), and its identity is held by the site's write
     * lock that serializes the lookup and the write.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE _transactions
                ADD COLUMN request_ref VARCHAR(128) NULL DEFAULT NULL AFTER description,
                ADD COLUMN request_fingerprint CHAR(64) NULL DEFAULT NULL AFTER request_ref,
                ADD COLUMN request_payload MEDIUMTEXT NULL DEFAULT NULL AFTER request_fingerprint,
                ADD COLUMN response_status SMALLINT NULL DEFAULT NULL AFTER request_payload,
                ADD COLUMN response_body MEDIUMTEXT NULL DEFAULT NULL AFTER response_status,
                ADD COLUMN duration_ms INT NULL DEFAULT NULL AFTER response_body,
                ADD COLUMN completed_at TIMESTAMP(3) NULL DEFAULT NULL AFTER duration_ms,
                ADD COLUMN request_history_held TINYINT(1) AS (
                    request_payload IS NOT NULL OR response_status IS NOT NULL OR response_body IS NOT NULL
                ) VIRTUAL AFTER completed_at,
                ADD UNIQUE INDEX uniq_transactions_request_ref (site_id, actor_type, actor_id, request_ref),
                ADD INDEX idx_transactions_request_history_held (request_history_held, created_at)
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
