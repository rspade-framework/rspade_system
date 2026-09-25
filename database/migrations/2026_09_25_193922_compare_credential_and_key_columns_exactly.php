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
     * Columns holding opaque, case-sensitive identifiers compare byte for byte.
     *
     * A _ci collation folds case, ignores trailing spaces and equates some accented letters,
     * so a UNIQUE key over one reports a false duplicate and a lookup can return the wrong row.
     *
     *   - credential_key (both second-factor tables) is a base64url WebAuthn credential id:
     *     ASCII, case-sensitive -> ascii_bin.
     *   - provider_key / provider_user_key (both SSO identity tables) are a provider's opaque
     *     subject id -> utf8mb4_bin.
     *   - _api_keys.key_hash is a lowercase SHA-256 hex digest: exactly 64 ASCII characters,
     *     looked up as one row on every API call -> VARCHAR(64) ascii_bin, now UNIQUE.
     *     (VARCHAR rather than CHAR: the framework's DDL conventions store no CHAR columns.)
     *     _api_keys.key_prefix is displayed and never queried, so its index goes.
     *
     * The UNIQUE keys over credential_key and the provider pair carry over the MODIFY.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE _two_factor_credentials
                MODIFY credential_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL
        ");

        DB::statement("
            ALTER TABLE _portal_two_factor_credentials
                MODIFY credential_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL
        ");

        DB::statement("
            ALTER TABLE _sso_identities
                MODIFY provider_key VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                MODIFY provider_user_key VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
        ");

        DB::statement("
            ALTER TABLE _portal_sso_identities
                MODIFY provider_key VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                MODIFY provider_user_key VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
        ");

        DB::statement("
            ALTER TABLE _api_keys
                DROP INDEX idx_api_keys_key_hash,
                DROP INDEX idx_api_keys_key_prefix,
                MODIFY key_hash VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                ADD UNIQUE KEY uk_api_keys_key_hash (key_hash)
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
