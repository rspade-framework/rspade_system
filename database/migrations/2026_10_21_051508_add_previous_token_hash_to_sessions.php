<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * _sessions.previous_token_hash - the sha256 of the session token the browser's most recent
 * in-place rotation replaced.
 *
 * A sign-in or a sign-out rotates the browser's tokens and answers with the new cookie. A
 * request that was already in flight still carries the old token; when it arrives it must
 * be REFUSED - answered with no Set-Cookie and nothing minted - rather than treated as a
 * guest, because a guest's response would mint a fresh row and overwrite the cookie the
 * browser was just given. The hash is what recognises such a request without keeping the
 * old credential itself.
 *
 * Only the most recent rotation is remembered: the rotation before it is two responses
 * stale, and a request carrying that token is an ordinary guest.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            ALTER TABLE _sessions
                ADD COLUMN previous_token_hash CHAR(64) NULL AFTER session_token,
                ADD KEY idx_sessions_previous_token_hash (previous_token_hash)
        ");
    }
};
