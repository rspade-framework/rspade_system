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
     * _session_links holds the one-time codes of the linked-session handshake (rsx:man
     * portal, IMPERSONATION): the three GET legs that put a portal on its own host onto the
     * SAME _sessions row as the staff browser that started "View as Client". Framework-core,
     * so the table is underscore prefixed like _sessions and _session_values.
     *
     *   code_hash   SHA-256 hex of a 256-bit random code. The code itself is never stored;
     *               a code is redeemed by an atomic DELETE on (code_hash, leg, expires_at)
     *               that must affect exactly one row. Opaque and case-sensitive, so it
     *               compares byte for byte (ascii_bin), and UNIQUE.
     *   leg         which leg the code opens (Session_Link::LEG_*). A code minted for one
     *               leg never redeems another.
     *   session_id  the staff browser's session row the handshake links. ON DELETE CASCADE:
     *               a link dies with its session. NULLABLE because SCHEMA-FK-01 requires it
     *               of an ephemeral tracking identifier (_session_values carries the same
     *               column the same way); every row is written with a real owner.
     *   nonce_hash  SHA-256 hex of the browser-bound nonce cookie set on the first portal
     *               leg; NULL on the first leg's own code, which precedes the nonce.
     *   payload     the JSON-encoded intent the handshake applies (never in a URL).
     *   expires_at  the end of the code's security window (Session_Link::EXPIRY_SECONDS).
     *               Redemption filters on it; Session_Cleanup_Service reclaims the space.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            CREATE TABLE _session_links (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code_hash VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                leg BIGINT NOT NULL,
                session_id BIGINT NULL,
                nonce_hash VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                payload LONGTEXT NOT NULL,
                expires_at TIMESTAMP(3) NOT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY uk_session_links_code_hash (code_hash),
                KEY idx_session_links_expires_at (expires_at),
                CONSTRAINT session_links_session_fk
                    FOREIGN KEY (session_id) REFERENCES _sessions (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
