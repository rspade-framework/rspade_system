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
     * Creates the three tables the revision-history subsystem stores into:
     *
     *   _transactions          one row per dispatch run that produced at least one
     *                          revisioned write - who, from where, over which endpoint.
     *   _revisions             one row per recorded record write, holding the compressed
     *                          {field: [before, after]} document in `changes`. FK to
     *                          _transactions ON DELETE CASCADE, so pruning a transaction
     *                          takes its revisions with it.
     *   _revision_dictionaries the compression dictionaries. The dictionary id travels in
     *                          ONE byte of every stored payload's two-byte prefix, so ids
     *                          above 255 are unstorable - Revision_Dictionary refuses to
     *                          mint one.
     *
     * Row 1 of _revision_dictionaries is NOT seeded here: building it reads
     * information_schema for the finished schema and the manifest for enum labels, both
     * of which only exist once the whole migrate run has completed. The post-migrate step
     * in Maint_Migrate::execute_migrations() writes it.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            CREATE TABLE _transactions (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NULL DEFAULT NULL,
                actor_id BIGINT NULL DEFAULT NULL,
                actor_type BIGINT NULL DEFAULT NULL,
                source_id BIGINT NOT NULL,
                endpoint VARCHAR(255) NULL DEFAULT NULL,
                ip VARCHAR(45) NULL DEFAULT NULL,
                api_request_log_id BIGINT NULL DEFAULT NULL,
                revision_count INT NOT NULL DEFAULT 0,
                description TEXT NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_transactions_site_created (site_id, created_at),
                INDEX idx_transactions_actor (actor_type, actor_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE _revisions (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                transaction_id BIGINT NOT NULL,
                site_id BIGINT NULL DEFAULT NULL,
                record_type BIGINT NOT NULL,
                record_id BIGINT NOT NULL,
                root_type BIGINT NOT NULL,
                root_id BIGINT NOT NULL,
                operation_id BIGINT NOT NULL,
                sequence INT NOT NULL,
                changes LONGBLOB NOT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_revisions_record (record_type, record_id),
                INDEX idx_revisions_root (root_type, root_id),
                INDEX idx_revisions_transaction (transaction_id),
                INDEX idx_revisions_site_created (site_id, created_at),
                CONSTRAINT fk_revisions_transaction
                    FOREIGN KEY (transaction_id) REFERENCES _transactions(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE _revision_dictionaries (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                bytes MEDIUMBLOB NOT NULL,
                token_hash CHAR(40) NOT NULL,
                token_count INT NOT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
