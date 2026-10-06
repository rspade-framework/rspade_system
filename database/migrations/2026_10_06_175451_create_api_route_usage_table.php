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
     * primary key. All integers are signed. Migrations must be self-contained.
     *
     * Creates _api_route_usage: one row per external API route pattern an authenticated key
     * has called, with that pattern's version and when it was last called (refreshed at most
     * once a minute - Api_Route_Usage::record()). It answers "is anything still calling v1?"
     * with one query, which is the question that decides whether a version can be retired.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            CREATE TABLE _api_route_usage (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                pattern VARCHAR(255) NOT NULL,
                version BIGINT NOT NULL,
                last_called_at TIMESTAMP(3) NOT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT NULL,
                updated_at TIMESTAMP(3) NULL DEFAULT NULL,
                UNIQUE KEY uk_api_route_usage_pattern (pattern),
                INDEX idx_api_route_usage_version (version, last_called_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
