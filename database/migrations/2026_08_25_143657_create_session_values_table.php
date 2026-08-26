<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement() with raw SQL
     * ❌ Schema::create() with Blueprint
     * 
     * REQUIRED: ALL tables MUST have: id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY
     * No exceptions - every table needs this exact ID column (SIGNED for easier migrations)
     * 
     * Integer types: Use BIGINT for all integers, TINYINT(1) for booleans only
     * Never use unsigned - all integers should be signed
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        // Session-scoped key/value storage. Framework-core, so the table is underscore
        // prefixed like _sessions and _flash_alerts.
        //
        // The FK is ON DELETE CASCADE, exactly as _flash_alerts does: a value's lifetime IS
        // its session's, so logout, termination and session cleanup reclaim these rows with
        // no sweeper involved and no possibility of a value outliving the browser session
        // that owned it.
        //
        // expires_at is OPTIONAL and defaults to NULL, meaning "lives as long as the
        // session". It exists for a value that must die sooner than the session does. Reads
        // filter on it, so an expired value is unreadable the instant it expires; the prune
        // task only reclaims space.
        //
        // UNIQUE(session_id, value_key) makes put() an upsert rather than an append.
        //
        // session_id is NULLABLE because SCHEMA-FK-01 requires it of an ephemeral tracking
        // identifier - _flash_alerts carries the same column the same way. Nothing here ever
        // writes NULL: Session::put_value() establishes a session first, so every row has a
        // real owner and is reclaimed by the cascade.
        DB::statement("
            CREATE TABLE _session_values (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                session_id BIGINT NULL,
                value_key VARCHAR(191) NOT NULL,
                value LONGTEXT NULL,
                expires_at TIMESTAMP(3) NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY uniq_session_value (session_id, value_key),
                KEY idx_expires_at (expires_at),
                CONSTRAINT session_values_session_fk
                    FOREIGN KEY (session_id) REFERENCES _sessions (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
};
