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
        // Second factors and passkeys for the CLIENT PORTAL realm: the portal twin of
        // _two_factor_credentials, holding the same three kinds (an authenticator-app seed, a
        // passkey, a single recovery code) with the same columns meaning the same things. The
        // narrative on each column lives in the staff table's migration and holds here word
        // for word.
        //
        // A SEPARATE TABLE, NOT A REALM COLUMN. The owner is a portal_users row, and a
        // foreign key can only point at one table - so the realm boundary is the table
        // boundary. That is also what makes a passkey lookup realm-scoped for free: staff and
        // portal usually share one relying party (one host), so a browser will offer a staff
        // passkey on the portal sign-in page, and the only thing standing between that and a
        // staff credential answering a portal challenge is that the portal realm never reads
        // the staff table.
        //
        // NO site_id COLUMN. A portal user already belongs to exactly one site
        // (portal_users.site_id NOT NULL), and a credential belongs to exactly one portal
        // user, so the row is site-scoped through its owner. Rsx_Portal_Two_Factor refuses to
        // sign in a portal user of any site but the one the application declared.
        //
        // credential_key stays UNIQUE: a WebAuthn credential id is globally unique by
        // construction, whichever realm minted it.
        //
        // The FK is ON DELETE CASCADE: a credential's lifetime IS its owner's.
        DB::statement("
            CREATE TABLE _portal_two_factor_credentials (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                portal_user_id BIGINT NOT NULL,
                type_id BIGINT NOT NULL,
                label VARCHAR(100) NULL,
                secret TEXT NULL,
                credential_key VARCHAR(255) NULL,
                counter BIGINT NOT NULL DEFAULT 0,
                confirmed_at TIMESTAMP(3) NULL DEFAULT NULL,
                last_used_at TIMESTAMP(3) NULL DEFAULT NULL,
                UNIQUE KEY uniq_credential_key (credential_key),
                KEY idx_portal_user (portal_user_id, type_id),
                CONSTRAINT portal_two_factor_credentials_portal_user_fk
                    FOREIGN KEY (portal_user_id) REFERENCES portal_users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
