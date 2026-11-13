<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The rederive queue: one row per table whose derived columns `migrate` recomputes in its
 * final step (Rsx_Rederive::drain()). A migration that changes what a derived column is
 * computed from calls Rsx_Rederive::enqueue('<table>'); the row is removed once that table's
 * records have been rederived, so a run that fails or is interrupted leaves it for the next.
 *
 * Framework-owned with a fixed shape: excluded from migrate:normalize_schema, so it carries
 * no audit or timestamp columns beyond its own. Read and written with DB::table() only.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE _rederive_queue (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                table_name VARCHAR(64) NOT NULL,
                enqueued_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                UNIQUE KEY uk_rederive_queue_table_name (table_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
};
