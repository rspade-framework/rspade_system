<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop _api_keys.user_role_id.
 *
 * The column was a reserved per-key role override that nothing enforced: a key always ran
 * at its holder's actual role, whatever the column held. A key is narrowed below its holder
 * by its path-pattern scopes and its read_only flag (rsx:man external_api, KEY SCOPES and
 * READ-ONLY KEYS). No index names the column. Forward-only.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE _api_keys DROP COLUMN user_role_id");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
