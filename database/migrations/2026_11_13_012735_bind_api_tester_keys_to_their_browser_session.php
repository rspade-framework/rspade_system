<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * _api_keys.tester_session_id: the browser (_sessions.id) that minted an /apidocs tester
     * key. Non-null marks the key as a tester key, which authenticates only on a request
     * carrying that browser's cookie while it is signed in as the key's user
     * (Rsx_Api_Bearer::authenticate()). No foreign key: a cascade would delete the key behind
     * the model's back (no event, no sign-out), and SET NULL would turn a bound key into a
     * standing one. A browser row that no longer exists simply matches no request.
     *
     * _sessions.api_key_id becomes ON DELETE SET NULL: deleting a key signs its user session
     * out (SIGN_OUT_API_KEY_DELETED, from the model's delete path) and the record of that
     * sign-in is kept, as for every other sign-out.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE _api_keys ADD COLUMN tester_session_id BIGINT NULL DEFAULT NULL AFTER is_temporary");
        DB::statement("ALTER TABLE _api_keys ADD INDEX idx_api_keys_tester_session (tester_session_id)");

        // Tester keys minted before the binding existed cannot be bound after the fact: they end
        // now (at most an hour early). Their user sessions are signed out as expired by the
        // next presentation or the hourly sweep.
        DB::statement(
            "UPDATE _api_keys SET expires_at = NOW(3)
             WHERE name = 'API Tester (temporary)' AND is_revoked = 0
               AND (expires_at IS NULL OR expires_at > NOW(3))"
        );

        DB::statement("ALTER TABLE _sessions DROP FOREIGN KEY sessions_api_key_fk");
        DB::statement(
            "ALTER TABLE _sessions ADD CONSTRAINT sessions_api_key_fk
             FOREIGN KEY (api_key_id) REFERENCES _api_keys (id) ON DELETE SET NULL"
        );
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
