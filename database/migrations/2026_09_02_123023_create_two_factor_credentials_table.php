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
        // Second-factor credentials. Framework-core, so the table is underscore prefixed
        // like _sessions, _api_keys and _login_history.
        //
        // ONE table holds all three factor kinds, discriminated by type_id (the enum lives
        // on Two_Factor_Credential_Model): an authenticator-app seed, a passkey, and a
        // single recovery code. They share every column that matters - who owns it, when it
        // was confirmed, when it was last used - and a login challenge has to consider all
        // of them together, so splitting them across three tables would buy nothing and
        // cost a three-way union on the hottest read.
        //
        // The FK is ON DELETE CASCADE: a credential's lifetime IS its identity's. Deleting
        // a login_users row must not leave a second factor behind that could be re-pointed
        // at a recycled id.
        //
        // The credential is attached to LOGIN_USERS, not users, because a second factor
        // proves who is holding the browser - that is the cross-site identity, the same one
        // the password belongs to. A per-site users row is an authorization scope, not a
        // person, and enrolling a phone once per tenant would be wrong.
        //
        // secret is TEXT and NULLABLE because it means something different per type:
        //   TOTP          - the RFC 6238 seed, encrypted at rest (Crypt::encryptString).
        //   RECOVERY_CODE - the bcrypt hash of one single-use code.
        //   PASSKEY       - the stored public key material, which is not a secret at all.
        //
        // credential_key is the passkey's raw credential id, base64url encoded, and is
        // globally UNIQUE: an authenticator's credential id identifies the key itself, so
        // the same one appearing under two identities would mean one of them is lying about
        // whose key it is. The column is NULL for TOTP and recovery rows, and MySQL lets a
        // UNIQUE index hold any number of NULLs, so the constraint costs those types
        // nothing.
        //
        // It is named _key and not _id deliberately: SCHEMA-TYPE-01 requires every column
        // ending in _id to be an integer, and this one is an opaque string handle minted by
        // somebody else's authenticator - the same shape, and the same naming, as the
        // attachment key File_Attachment_Model::find_by_key() takes.
        //
        // counter carries the anti-cloning state, again per type:
        //   PASSKEY - the authenticator's signature counter; an assertion that does not
        //             advance it is refused as a possible clone.
        //   TOTP    - the last ACCEPTED timestep; a code at or below it is refused as a
        //             replay, which is what stops a shoulder-surfed code being reused
        //             inside its own 30-second window.
        //
        // confirmed_at is NULL until the enrollment is PROVEN (a live code entered, an
        // attestation verified). An unconfirmed row must never be able to satisfy a login
        // challenge, so every read that gates a login filters on it.
        //
        // The composite index is (login_user_id, type_id) because every query in the
        // subsystem asks "this identity's credentials of this kind" - the login challenge,
        // the credential list, the recovery-code count.
        DB::statement("
            CREATE TABLE _two_factor_credentials (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                login_user_id BIGINT NOT NULL,
                type_id BIGINT NOT NULL,
                label VARCHAR(100) NULL,
                secret TEXT NULL,
                credential_key VARCHAR(255) NULL,
                counter BIGINT NOT NULL DEFAULT 0,
                confirmed_at TIMESTAMP(3) NULL DEFAULT NULL,
                last_used_at TIMESTAMP(3) NULL DEFAULT NULL,
                UNIQUE KEY uniq_credential_key (credential_key),
                KEY idx_login_user (login_user_id, type_id),
                CONSTRAINT two_factor_credentials_login_user_fk
                    FOREIGN KEY (login_user_id) REFERENCES login_users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
};
