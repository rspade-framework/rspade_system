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
        // Federated sign-in identities for the CLIENT PORTAL realm: "this portal_users row is
        // also this person at this identity provider". The portal twin of _sso_identities;
        // the narrative on provider_key, provider_user_key and the snapshot columns lives in
        // that table's migration and holds here word for word.
        //
        // A SEPARATE TABLE, NOT A REALM COLUMN, for the reason _portal_two_factor_credentials
        // is one: the owner is a portal_users row and a foreign key names one table. It is
        // also the realm boundary Rsx_Portal_Sso relies on - a portal ceremony only ever reads
        // this table, so it can never resolve into a staff sign-in.
        //
        // site_id IS STORED AND IS PART OF THE UNIQUE KEY. portal_users is site-scoped where
        // login_users is cross-site: the same Google account may legitimately be a portal user
        // of two different sites, as two different portal_users rows. So one provider account
        // may be connected to at most one portal user PER SITE, and the staff table's
        // (provider_key, provider_user_key) uniqueness would be wrong here. site_id is always
        // the owner's own site_id, copied at link time; a lookup is always scoped to the site
        // the application declared for the request.
        //
        // The FK is ON DELETE CASCADE: a link's lifetime IS its owner's.
        DB::statement("
            CREATE TABLE _portal_sso_identities (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                portal_user_id BIGINT NOT NULL,
                provider_key VARCHAR(50) NOT NULL,
                provider_user_key VARCHAR(255) NOT NULL,
                email VARCHAR(255) NULL,
                name VARCHAR(255) NULL,
                avatar_url TEXT NULL,
                last_login_at TIMESTAMP(3) NULL DEFAULT NULL,
                UNIQUE KEY uniq_site_provider_identity (site_id, provider_key, provider_user_key),
                KEY idx_portal_user (portal_user_id),
                CONSTRAINT portal_sso_identities_portal_user_fk
                    FOREIGN KEY (portal_user_id) REFERENCES portal_users (id) ON DELETE CASCADE,
                CONSTRAINT portal_sso_identities_site_fk
                    FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
