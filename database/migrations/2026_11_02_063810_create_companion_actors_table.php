<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The system actors companion applications write as: one row per companion name, created the
 * first time a companion request needs to stamp authorship, printed `Companion:<name>`.
 * Cross-site (no site_id), because a companion is per install and addresses any site, and
 * soft-deleted like every actor so the audit columns naming a companion removed from the code
 * stay readable.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE _companion_actors (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(64) NOT NULL,
                deleted_at TIMESTAMP(3) NULL DEFAULT NULL,
                UNIQUE KEY uniq_companion_actors_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
};
