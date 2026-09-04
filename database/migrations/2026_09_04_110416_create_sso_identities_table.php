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
        // Federated sign-in identities: "this login_users row is also this person at this
        // identity provider". Framework-core, so the table is underscore prefixed like
        // _sessions, _api_keys, _login_history and _two_factor_credentials.
        //
        // THE OWNER IS A LOGIN IDENTITY, NOT A SITE USER, for exactly the reason a second
        // factor is: a Google account proves who is holding the browser, which is what
        // login_users is. A users row is an authorization scope inside one tenant, and
        // linking the same Google account once per tenant would be wrong.
        //
        // provider_key IS A REGISTRY STRING AND DELIBERATELY NOT AN ENUM (owner ruling,
        // 2026-09-04). The five built-ins are only the ones that ship: an application adds
        // a provider with one composer package and one config block, and a new key must not
        // require a framework migration, a constant, and a rsx:constants:regenerate pass to
        // become storable. An enum here would make the open registry closed, and the value
        // is never rendered from the column anyway - the label comes from the config entry
        // the key names.
        //
        // (provider_key, provider_user_key) IS UNIQUE, and that constraint is the whole
        // security model of the table: one provider account may be connected to at most one
        // login identity. Without it a second row could point the same Google account at a
        // second account, and "sign in with Google" would then resolve to whichever row was
        // read first - an account takeover with no password involved. Rsx_Sso refuses the
        // link before it gets here, and this index is what makes the refusal true even
        // under a race.
        //
        // provider_user_key is a VARCHAR because it is somebody else's identifier and its
        // shape is theirs to choose - a numeric string at Google, a 44-character opaque
        // handle at Apple. It is stored exactly as asserted, never parsed.
        //
        // It is named _key and not _id deliberately, the same call the passkey credential_key
        // made: SCHEMA-TYPE-01 requires every column ending in _id to be an integer, and this
        // is an opaque string handle minted by somebody else's identity provider. The name is
        // then identical at every layer above - column, model, normalized identity array, JSON
        // - because RSpade does not alias field names.
        //
        // email/name/avatar_url are what the provider ASSERTED AT LINK TIME, kept so a
        // settings screen can say which account is connected without calling the provider.
        // They are a snapshot and not a source of truth - the account's real address lives
        // on login_users - and they are NULLABLE because a provider may withhold all three
        // (X returns no email unless the app is approved for it, and Apple returns a name
        // only on the very first authorization).
        //
        // The FK is ON DELETE CASCADE: a link's lifetime IS its identity's. Deleting a
        // login_users row must not leave a connected provider account behind that could be
        // re-pointed at a recycled id.
        //
        // last_login_at records the last time this LINK completed a sign-in, which is a
        // different question from login_users.last_login ("when did this person last sign
        // in, by any means") and is what a Connected Accounts screen shows per row.
        DB::statement("
            CREATE TABLE _sso_identities (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                login_user_id BIGINT NOT NULL,
                provider_key VARCHAR(50) NOT NULL,
                provider_user_key VARCHAR(255) NOT NULL,
                email VARCHAR(255) NULL,
                name VARCHAR(255) NULL,
                avatar_url TEXT NULL,
                last_login_at TIMESTAMP(3) NULL DEFAULT NULL,
                UNIQUE KEY uniq_provider_identity (provider_key, provider_user_key),
                KEY idx_login_user (login_user_id),
                CONSTRAINT sso_identities_login_user_fk
                    FOREIGN KEY (login_user_id) REFERENCES login_users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
};
